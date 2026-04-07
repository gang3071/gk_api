<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class PlayerPlatformCash
 * @property int id 主键
 * @property int player_id 玩家id
 * @property string player_account 玩家账户
 * @property int platform_id 平台id
 * @property string platform_name 平台名称
 * @property float money 点数
 * @property int status 遊戲平台狀態 0=鎖定 1=正常
 * @property bool is_crashed 是否爆机 0=正常 1=已爆机
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 *
 * @property Player player 玩家
 * @package app\model
 */
class PlayerPlatformCash extends Model
{
    use HasDateTimeFormatter;

    const PLATFORM_SELF = 1; // 实体机平台

    protected $fillable = ['player_id', 'platform_id', 'platform_name', 'money'];
    protected $table = 'player_platform_cash';

    /**
     * 余额访问器 - 从 Redis 缓存读取余额
     *
     * 优先级：
     * 1. 如果 money 字段有脏数据（刚修改未保存），返回修改后的值
     * 2. 否则从 Redis 缓存读取余额
     * 3. 缓存未命中则从数据库 player_platform_cash.money 读取
     *
     * @param mixed $value 数据库原始值
     * @return float 余额
     */
    public function getMoneyAttribute($value): float
    {
        // 如果 money 字段有脏数据（刚修改还未保存），直接返回当前值
        if ($this->isDirty('money')) {
            return (float)$this->attributes['money'];
        }

        // 从缓存读取余额
        try {
            return \app\service\WalletService::getBalance($this->player_id, 1);
        } catch (\Throwable $e) {
            // 缓存异常时降级到数据库 player_platform_cash.money
            \support\Log::warning('PlayerPlatformCash::getMoneyAttribute: 缓存读取失败，降级到数据库', [
                'player_id' => $this->player_id,
                'error' => $e->getMessage(),
            ]);

            // 降级：直接查询 player_platform_cash.money（使用原生查询避免访问器循环）
            $balance = \support\Db::table('player_platform_cash')
                ->where('player_id', $this->player_id)
                ->where('platform_id', $this->platform_id ?? 1)
                ->value('money');

            return $balance !== null ? (float)$balance : 0.0;
        }
    }

    /**
     * 玩家信息
     * @return BelongsTo
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'player_id')->withTrashed();
    }

    /**
     * 模型的 "booted" 方法
     * 监听余额变化，同步 Redis 缓存，检查爆机状态
     *
     * @return void
     */
    protected static function booted()
    {
        // 监听余额更新事件
        static::updated(function (PlayerPlatformCash $wallet) {
            // 检查 money 字段是否变化
            if (!$wallet->wasChanged('money')) {
                return;
            }

            $newBalance = (float)$wallet->money;

            // ✅ 同步 Redis 缓存
            try {
                $cacheUpdated = \app\service\WalletService::updateCache(
                    $wallet->player_id,
                    1, // platform_id 保留兼容性，实际不使用
                    $newBalance
                );

                // 🚨 缓存同步失败告警
                if (!$cacheUpdated) {
                    \support\Log::critical('PlayerPlatformCash: Redis cache sync failed!', [
                        'player_id' => $wallet->player_id,
                        'old_balance' => $wallet->getOriginal('money'),
                        'new_balance' => $newBalance,
                        'timestamp' => date('Y-m-d H:i:s'),
                    ]);
                }
            } catch (\Throwable $e) {
                // Redis 缓存同步异常
                \support\Log::critical('PlayerPlatformCash: Redis cache sync exception!', [
                    'player_id' => $wallet->player_id,
                    'new_balance' => $newBalance,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }

            // 只处理实体机平台的余额变化（爆机检测）
            if ($wallet->platform_id != self::PLATFORM_SELF) {
                return;
            }

            try {
                // 获取玩家信息
                $player = $wallet->player;
                if (!$player) {
                    return;
                }

                // ✅ 从 Redis 读取余额（唯一可信源）
                $previousAmount = floatval($wallet->getOriginal('money'));  // 数据库旧值（参考）

                try {
                    // ✅ 使用 Redis 余额判断爆机
                    $currentAmount = \app\service\WalletService::getBalance($wallet->player_id, 1);
                } catch (\Throwable $e) {
                    // Redis 异常时降级到数据库值
                    \support\Log::warning('爆机检测: Redis读取失败，降级到数据库', [
                        'player_id' => $wallet->player_id,
                        'error' => $e->getMessage(),
                    ]);
                    $currentAmount = floatval($wallet->money);
                }

                // 获取爆机配置
                $adminUserId = $player->store_admin_id ?? null;
                if (!$adminUserId) {
                    return;
                }

                $crashSetting = \app\model\StoreSetting::getSetting(
                    'machine_crash_amount',
                    $player->department_id,
                    null,
                    $adminUserId
                );

                // 如果没有配置或配置被禁用，不处理
                if (!$crashSetting || $crashSetting->status != 1) {
                    return;
                }

                $crashAmount = $crashSetting->num ?? 0;
                if ($crashAmount <= 0) {
                    return;
                }

                // 检查爆机状态变化
                $wasCrashed = $previousAmount >= $crashAmount;
                $isCrashed = $currentAmount >= $crashAmount;

                // 更新爆机状态字段（如果状态有变化）
                if ($wallet->is_crashed != $isCrashed) {
                    // 使用 withoutEvents 避免递归触发 updated 事件
                    $wallet->withoutEvents(function () use ($wallet, $isCrashed) {
                        $wallet->is_crashed = $isCrashed;
                        $wallet->save();
                    });

                    // 🚀 优化：清除爆机状态缓存，确保下次检查时使用最新状态
                    try {
                        clearMachineCrashCache($wallet->player_id);

                        Log::info('PlayerPlatformCash: 自动清除爆机缓存', [
                            'player_id' => $wallet->player_id,
                            'old_status' => $wasCrashed ? '已爆机' : '未爆机',
                            'new_status' => $isCrashed ? '已爆机' : '未爆机',
                            'current_amount' => $currentAmount,
                            'crash_amount' => $crashAmount,
                        ]);
                    } catch (\Exception $e) {
                        Log::error('PlayerPlatformCash: 清除爆机缓存失败', [
                            'player_id' => $wallet->player_id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // 从未爆机变为爆机 -> 发送爆机通知
                if (!$wasCrashed && $isCrashed) {
                    $crashInfo = [
                        'crashed' => true,
                        'crash_amount' => $crashAmount,
                        'current_amount' => $currentAmount,
                    ];
                    notifyMachineCrash($player, $crashInfo);
                }

                // 从爆机变为未爆机 -> 发送解锁通知
                if ($wasCrashed && !$isCrashed) {
                    checkAndNotifyCrashUnlock($player, $previousAmount);
                }
            } catch (\Exception $e) {
                \support\Log::error('PlayerPlatformCash: Failed to check machine crash', [
                    'player_id' => $wallet->player_id,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * 保存模型但不触发事件（用于从 Redis 同步到数据库时避免循环）
     *
     * @param array $options
     * @return bool
     */
    public function saveWithoutEvents(array $options = []): bool
    {
        return static::withoutEvents(function () use ($options) {
            return $this->save($options);
        });
    }
}
