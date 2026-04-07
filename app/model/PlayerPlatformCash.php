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
}
