<?php

namespace app\service;

use app\model\PlayerPlatformCash;
use support\Log;
use support\Redis;

/**
 * 钱包服务 - Redis 缓存版本
 *
 * 提供高性能的钱包余额查询和更新功能
 * - 使用 Redis 缓存减少数据库查询
 * - 自动降级到数据库（Redis 故障时）
 * - 通过模型事件自动同步缓存
 */
class WalletService
{
    /**
     * 缓存键前缀（与 Lua 原子脚本统一）
     * 修改说明：统一使用 wallet:balance:{player_id} 格式
     * 与 gk_work RedisLuaScripts 保持一致，避免缓存不一致
     */
    private const CACHE_PREFIX = 'wallet:balance:';

    /**
     * 缓存过期时间（秒）
     * 1小时，足够长以提高命中率，余额通过模型事件自动同步保证一致性
     */
    private const CACHE_TTL = 3600;

    /**
     * 获取玩家余额（带 Redis 缓存）
     *
     * @param int $playerId 玩家ID
     * @param int $platformId 平台ID，默认1（实体机平台）
     * @param bool $forceRefresh 是否强制刷新缓存
     * @return float 余额
     */
    public static function getBalance(int $playerId, int $platformId = 1, bool $forceRefresh = false): float
    {
        // 🚨 紧急开关：缓存被禁用时直接查询数据库
        if (!self::isCacheEnabled()) {
            return self::getBalanceFromDB($playerId, $platformId);
        }

        $cacheKey = self::getCacheKey($playerId);

        try {
            // 如果不是强制刷新，先尝试从缓存读取
            if (!$forceRefresh) {
                $cached = Redis::get($cacheKey);
                if ($cached !== null && $cached !== false) {
                    return (float)$cached;
                }
            }

            // 缓存未命中或强制刷新，从数据库读取
            $balance = self::getBalanceFromDB($playerId, $platformId);

            // 更新缓存
            Redis::setex($cacheKey, self::CACHE_TTL, $balance);

            return $balance;
        } catch (\Throwable $e) {
            // Redis 异常时自动降级到数据库
            Log::warning('WalletService: Redis failed, fallback to DB', [
                'player_id' => $playerId,
                'platform_id' => $platformId,
                'error' => $e->getMessage(),
            ]);

            return self::getBalanceFromDB($playerId, $platformId);
        }
    }

    /**
     * 🚨 紧急开关：禁用 Redis 缓存
     * 在 .env 中设置 WALLET_CACHE_ENABLED=false 可立即禁用缓存
     * 用于紧急情况下快速回滚到纯数据库查询
     */
    private static function isCacheEnabled(): bool
    {
        return env('WALLET_CACHE_ENABLED', true);
    }

    /**
     * 从数据库获取余额（单一钱包模式）
     *
     * 直接从 player.money 读取，避免通过模型访问器（防止循环调用）
     *
     * @param int $playerId
     * @param int $platformId 保留参数兼容性，实际不使用
     * @return float
     */
    private static function getBalanceFromDB(int $playerId, int $platformId): float
    {
        // 从 player_platform_cash 表读取余额
        $result = \support\Db::table('player_platform_cash')
            ->where('player_id', $playerId)
            ->where('platform_id', $platformId)
            ->value('money');

        return $result !== null ? (float)$result : 0.0;
    }

    /**
     * 生成缓存键（包含版本号）
     *
     * @param int $playerId
     * @param int $platformId
     * @return string
     */
    /**
     * 获取缓存键（与 Lua 原子脚本统一格式）
     *
     * @param int $playerId 玩家ID
     * @return string Redis 缓存键
     */
    private static function getCacheKey(int $playerId): string
    {
        // 统一使用 wallet:balance:{player_id} 格式
        // 与 gk_work RedisLuaScripts::atomicBet/atomicSettle 保持一致
        return self::CACHE_PREFIX . $playerId;
    }

    /**
     * 更新缓存（由模型事件自动调用）
     *
     * @param int $playerId
     * @param int $platformId
     * @param float $balance
     * @return bool
     */
    public static function updateCache(int $playerId, int $platformId, float $balance): bool
    {
        try {
            $cacheKey = self::getCacheKey($playerId);
            Redis::setex($cacheKey, self::CACHE_TTL, $balance);
            return true;
        } catch (\Throwable $e) {
            Log::warning('WalletService: Failed to update cache', [
                'player_id' => $playerId,
                'platform_id' => $platformId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * 清除缓存
     *
     * @param int $playerId
     * @param int $platformId
     * @return bool
     */
    public static function clearCache(int $playerId, int $platformId = 1): bool
    {
        try {
            $cacheKey = self::getCacheKey($playerId);
            Redis::del($cacheKey);
            return true;
        } catch (\Throwable $e) {
            Log::warning('WalletService: Failed to clear cache', [
                'player_id' => $playerId,
                'platform_id' => $platformId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * 批量清除缓存
     *
     * @param array $playerIds 玩家ID数组
     * @param int $platformId 平台ID
     * @return int 成功清除的数量
     */
    public static function clearBatchCache(array $playerIds, int $platformId = 1): int
    {
        if (empty($playerIds)) {
            return 0;
        }

        try {
            $cacheKeys = [];
            foreach ($playerIds as $playerId) {
                $cacheKeys[] = self::getCacheKey($playerId);
            }

            // 批量删除
            $deletedCount = Redis::del(...$cacheKeys);

            Log::info('WalletService: Batch cache cleared', [
                'count' => count($playerIds),
                'deleted' => $deletedCount,
                'platform_id' => $platformId,
            ]);

            return $deletedCount;

        } catch (\Throwable $e) {
            Log::warning('WalletService: Failed to clear batch cache', [
                'player_ids' => $playerIds,
                'platform_id' => $platformId,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * 批量获取余额
     *
     * @param array $playerIds 玩家ID数组
     * @param int $platformId 平台ID
     * @return array [player_id => balance]
     */
    public static function getBatchBalance(array $playerIds, int $platformId = 1): array
    {
        if (empty($playerIds)) {
            return [];
        }

        // 重建索引确保数组键是连续的 0, 1, 2...
        $playerIds = array_values($playerIds);

        $result = [];
        $missedIds = [];

        try {
            // 批量从 Redis 获取
            $cacheKeys = [];
            foreach ($playerIds as $playerId) {
                $cacheKeys[$playerId] = self::getCacheKey($playerId);
            }

            $cached = Redis::mget(array_values($cacheKeys));

            foreach ($playerIds as $index => $playerId) {
                if (isset($cached[$index]) && $cached[$index] !== false && $cached[$index] !== null) {
                    $result[$playerId] = (float)$cached[$index];
                } else {
                    $missedIds[] = $playerId;
                }
            }
        } catch (\Throwable $e) {
            // Redis 失败，全部从数据库查询
            Log::warning('WalletService: Batch Redis failed, fallback to DB', [
                'error' => $e->getMessage(),
            ]);
            $missedIds = $playerIds;
        }

        // 从数据库补充未命中的数据
        if (!empty($missedIds)) {
            $wallets = PlayerPlatformCash::query()
                ->whereIn('player_id', $missedIds)
                ->where('platform_id', $platformId)
                ->get();

            foreach ($wallets as $wallet) {
                $balance = (float)$wallet->money;
                $result[$wallet->player_id] = $balance;

                // 回填缓存
                try {
                    $cacheKey = self::getCacheKey($wallet->player_id);
                    Redis::setex($cacheKey, self::CACHE_TTL, $balance);
                } catch (\Throwable $e) {
                    // 忽略缓存回填失败
                }
            }

            // 补充不存在的玩家（余额为0）
            foreach ($missedIds as $playerId) {
                if (!isset($result[$playerId])) {
                    $result[$playerId] = 0.0;
                    // 缓存不存在的玩家（避免缓存穿透）
                    try {
                        $cacheKey = self::getCacheKey($playerId);
                        Redis::setex($cacheKey, self::CACHE_TTL, 0.0);
                    } catch (\Throwable $e) {
                        // 忽略缓存回填失败
                    }
                }
            }
        }

        return $result;
    }

    /**
     * 原子扣款（使用 Lua 脚本，Redis 作为唯一实时标准）
     *
     * @param int $playerId 玩家ID
     * @param float $amount 扣款金额
     * @param int $platformId 平台ID（保留兼容性）
     * @return float 扣款后的新余额
     * @throws \Exception
     */
    public static function deduct(int $playerId, float $amount, int $platformId = 1): float
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be positive');
        }

        return self::atomicDeduct($playerId, $amount);
    }

    /**
     * 原子扣款 - Lua 脚本保证原子性（与 gk_work 保持一致）
     *
     * @param int $playerId
     * @param float $amount
     * @return float 新余额
     * @throws \Exception
     */
    private static function atomicDeduct(int $playerId, float $amount): float
    {
        $cacheKey = self::getCacheKey($playerId);

        // Lua 脚本：原子扣款，返回旧余额和新余额（用于爆机检测）
        $script = <<<'LUA'
            local balance = tonumber(redis.call('GET', KEYS[1]))
            if not balance then
                return {err = 'BALANCE_NOT_FOUND'}
            end
            if balance < tonumber(ARGV[1]) then
                return {err = 'INSUFFICIENT_BALANCE'}
            end
            local newBalance = balance - tonumber(ARGV[1])
            redis.call('SET', KEYS[1], newBalance)
            redis.call('EXPIRE', KEYS[1], tonumber(ARGV[2]))
            return cjson.encode({old = balance, new = newBalance})
        LUA;

        try {
            $result = \support\Redis::eval($script, 1, $cacheKey, $amount, self::CACHE_TTL);

            if (is_array($result) && isset($result['err'])) {
                if ($result['err'] === 'BALANCE_NOT_FOUND') {
                    throw new \Exception(trans('wallet_not_found', [], 'message'));
                }
                if ($result['err'] === 'INSUFFICIENT_BALANCE') {
                    throw new \Exception(trans('game_amount_insufficient', [], 'message'));
                }
            }

            // 解析返回的 JSON：{old: 旧余额, new: 新余额}
            $balanceData = json_decode($result, true);
            if (!$balanceData || !isset($balanceData['new'])) {
                throw new \Exception('Invalid balance data returned from Redis');
            }

            $oldBalance = (float)($balanceData['old'] ?? 0);
            $newBalance = (float)$balanceData['new'];

            // 异步更新数据库并触发爆机检测
            self::asyncUpdateDB($playerId, $newBalance, $oldBalance);

            return $newBalance;
        } catch (\Throwable $e) {
            \support\Log::error('WalletService::atomicDeduct failed', [
                'player_id' => $playerId,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * 原子加款（使用 Lua 脚本，Redis 作为唯一实时标准）
     *
     * @param int $playerId 玩家ID
     * @param float $amount 加款金额
     * @param int $platformId 平台ID（保留兼容性）
     * @return float 加款后的新余额
     * @throws \Exception
     */
    public static function add(int $playerId, float $amount, int $platformId = 1): float
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be positive');
        }

        return self::atomicAdd($playerId, $amount);
    }

    /**
     * 原子加款 - Lua 脚本保证原子性（与 gk_work 保持一致）
     *
     * @param int $playerId
     * @param float $amount
     * @return float 新余额
     * @throws \Exception
     */
    private static function atomicAdd(int $playerId, float $amount): float
    {
        $cacheKey = self::getCacheKey($playerId);

        // Lua 脚本：原子加款，返回旧余额和新余额（用于爆机检测）
        $script = <<<'LUA'
            local balance = tonumber(redis.call('GET', KEYS[1])) or 0
            local newBalance = balance + tonumber(ARGV[1])
            redis.call('SET', KEYS[1], newBalance)
            redis.call('EXPIRE', KEYS[1], tonumber(ARGV[2]))
            return cjson.encode({old = balance, new = newBalance})
        LUA;

        try {
            $result = \support\Redis::eval($script, 1, $cacheKey, $amount, self::CACHE_TTL);

            // 解析返回的 JSON：{old: 旧余额, new: 新余额}
            $balanceData = json_decode($result, true);
            if (!$balanceData || !isset($balanceData['new'])) {
                throw new \Exception('Invalid balance data returned from Redis');
            }

            $oldBalance = (float)($balanceData['old'] ?? 0);
            $newBalance = (float)$balanceData['new'];

            // 异步更新数据库并触发爆机检测
            self::asyncUpdateDB($playerId, $newBalance, $oldBalance);

            return $newBalance;
        } catch (\Throwable $e) {
            \support\Log::error('WalletService::atomicAdd failed', [
                'player_id' => $playerId,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * 异步更新数据库（Redis 已更新，数据库仅作持久化）
     *
     * @param int $playerId
     * @param float $newBalance
     * @param float|null $oldBalance 旧余额（用于爆机检测）
     * @return void
     */
    private static function asyncUpdateDB(int $playerId, float $newBalance, ?float $oldBalance = null): void
    {
        try {
            // 只更新 player_platform_cash 表（player 表没有 money 字段）
            \support\Db::table('player_platform_cash')
                ->where('player_id', $playerId)
                ->update(['money' => $newBalance]);

            // ⚠️ 不在这里触发爆机检测，避免嵌套事务冲突
            // 爆机检测需要在业务层事务提交后手动调用 WalletService::checkMachineCrashAfterTransaction()
        } catch (\Throwable $e) {
            // 数据库同步失败不影响 Redis（Redis 是唯一实时标准）
            \support\Log::error('WalletService: asyncUpdateDB failed', [
                'player_id' => $playerId,
                'balance' => $newBalance,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 在事务提交后检查爆机状态
     *
     * ⚠️ 必须在数据库事务提交后调用，避免嵌套事务冲突
     *
     * @param int $playerId 玩家ID
     * @param float $currentBalance 当前余额（来自 Redis）
     * @param float|null $previousBalance 之前的余额（用于判断状态变化）
     * @return void
     */
    public static function checkMachineCrashAfterTransaction(int $playerId, float $currentBalance, ?float $previousBalance = null): void
    {
        try {
            // 获取玩家信息
            $player = \app\model\Player::find($playerId);
            if (!$player) {
                return;
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
            $wasCrashed = $previousBalance !== null ? $previousBalance >= $crashAmount : false;
            $isCrashed = $currentBalance >= $crashAmount;

            // 状态没有变化，不处理
            if ($wasCrashed === $isCrashed) {
                return;
            }

            // 更新爆机状态字段
            \support\Db::table('player_platform_cash')
                ->where('player_id', $playerId)
                ->where('platform_id', 1) // 实体机平台
                ->update(['is_crashed' => $isCrashed ? 1 : 0]);

            // 清除爆机状态缓存
            clearMachineCrashCache($playerId);

            \support\Log::info('WalletService: 爆机状态变化', [
                'player_id' => $playerId,
                'old_status' => $wasCrashed ? '已爆机' : '未爆机',
                'new_status' => $isCrashed ? '已爆机' : '未爆机',
                'current_balance' => $currentBalance,
                'crash_amount' => $crashAmount,
            ]);

            // 从未爆机变为爆机 -> 发送爆机通知
            if (!$wasCrashed && $isCrashed) {
                $crashInfo = [
                    'crashed' => true,
                    'crash_amount' => $crashAmount,
                    'current_amount' => $currentBalance,
                ];
                notifyMachineCrash($player, $crashInfo);
            }

            // 从爆机变为未爆机 -> 发送解锁通知
            if ($wasCrashed && !$isCrashed) {
                checkAndNotifyCrashUnlock($player, $previousBalance);
            }
        } catch (\Throwable $e) {
            \support\Log::error('WalletService: checkMachineCrash failed', [
                'player_id' => $playerId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 缓存预热（批量加载玩家余额到缓存）
     *
     * @param array $playerIds 玩家ID数组
     * @param int $platformId 平台ID
     * @return array ['success' => int, 'failed' => int]
     */
    public static function warmupCache(array $playerIds, int $platformId = 1): array
    {
        if (empty($playerIds)) {
            return ['success' => 0, 'failed' => 0];
        }

        $successCount = 0;
        $failedCount = 0;

        try {
            // 从数据库批量查询
            $wallets = PlayerPlatformCash::query()
                ->whereIn('player_id', $playerIds)
                ->where('platform_id', $platformId)
                ->get(['player_id', 'money']);

            $foundPlayerIds = [];

            // 批量写入缓存
            foreach ($wallets as $wallet) {
                $balance = (float)$wallet->money;
                $foundPlayerIds[] = $wallet->player_id;

                try {
                    $cacheKey = self::getCacheKey($wallet->player_id);
                    Redis::setex($cacheKey, self::CACHE_TTL, $balance);
                    $successCount++;
                } catch (\Throwable $e) {
                    $failedCount++;
                }
            }

            // 为不存在的玩家缓存 0 余额
            $notFoundPlayerIds = array_diff($playerIds, $foundPlayerIds);
            foreach ($notFoundPlayerIds as $playerId) {
                try {
                    $cacheKey = self::getCacheKey($playerId);
                    Redis::setex($cacheKey, self::CACHE_TTL, 0.0);
                    $successCount++;
                } catch (\Throwable $e) {
                    $failedCount++;
                }
            }

            Log::info('WalletService: Cache warmup completed', [
                'requested' => count($playerIds),
                'success' => $successCount,
                'failed' => $failedCount,
                'platform_id' => $platformId,
            ]);

        } catch (\Throwable $e) {
            Log::warning('WalletService: Cache warmup failed', [
                'player_ids' => $playerIds,
                'platform_id' => $platformId,
                'error' => $e->getMessage(),
            ]);
            $failedCount = count($playerIds) - $successCount;
        }

        return ['success' => $successCount, 'failed' => $failedCount];
    }

    /**
     * Lua 脚本：原子性增加余额
     */
    private const LUA_ATOMIC_INCREMENT = <<<'LUA'
local key = KEYS[1]
local amount = tonumber(ARGV[1])
local ttl = tonumber(ARGV[2]) or 3600

local currentBalance = tonumber(redis.call('GET', key)) or 0
local newBalance = currentBalance + amount

redis.call('SETEX', key, ttl, newBalance)
return newBalance
LUA;

    /**
     * Lua 脚本：原子性减少余额（带余额检查）
     */
    private const LUA_ATOMIC_DECREMENT = <<<'LUA'
local key = KEYS[1]
local amount = tonumber(ARGV[1])
local ttl = tonumber(ARGV[2]) or 3600

local currentBalance = tonumber(redis.call('GET', key)) or 0

-- 余额不足检查
if currentBalance < amount then
    return cjson.encode({ok = 0, error = "insufficient_balance", balance = currentBalance})
end

local newBalance = currentBalance - amount
redis.call('SETEX', key, ttl, newBalance)
return cjson.encode({ok = 1, balance = newBalance})
LUA;

    /**
     * 原子性增加余额（使用 Lua 脚本）
     *
     * 核心功能：
     * - 在 Redis 中原子性地增加玩家余额
     * - 保证并发安全（单个 Lua 脚本执行是原子的）
     * - 自动更新缓存过期时间
     *
     * 使用场景：
     * - 充值
     * - 活动奖励发放
     * - 游戏赢钱
     * - 彩金发放
     *
     * @param int $playerId 玩家ID
     * @param float $amount 增加金额（必须 > 0）
     * @param int $ttl Redis 缓存过期时间（秒），默认 3600
     * @return float 新余额
     */
    public static function atomicIncrement(int $playerId, float $amount, int $ttl = 3600): float
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be greater than 0');
        }

        try {
            $cacheKey = self::getCacheKey($playerId);

            // 执行 Lua 脚本，原子性增加余额
            $newBalance = Redis::eval(
                self::LUA_ATOMIC_INCREMENT,
                1,  // KEYS 数量
                $cacheKey,  // KEYS[1]
                $amount,    // ARGV[1]
                $ttl        // ARGV[2]
            );

            Log::info('WalletService: Atomic increment success', [
                'player_id' => $playerId,
                'amount' => $amount,
                'new_balance' => $newBalance,
            ]);

            return (float)$newBalance;

        } catch (\Throwable $e) {
            Log::error('WalletService: Atomic increment failed', [
                'player_id' => $playerId,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * 原子性减少余额（使用 Lua 脚本，带余额检查）
     *
     * 核心功能：
     * - 在 Redis 中原子性地减少玩家余额
     * - 保证并发安全（单个 Lua 脚本执行是原子的）
     * - 自动检查余额是否充足
     * - 余额不足时返回错误，不会扣款
     *
     * 使用场景：
     * - 提现
     * - 游戏下注
     * - 转账到游戏平台
     *
     * @param int $playerId 玩家ID
     * @param float $amount 减少金额（必须 > 0）
     * @param int $ttl Redis 缓存过期时间（秒），默认 3600
     * @return array ['ok' => 1, 'balance' => 新余额] 或 ['ok' => 0, 'error' => 'insufficient_balance', 'balance' => 当前余额]
     */
    public static function atomicDecrement(int $playerId, float $amount, int $ttl = 3600): array
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be greater than 0');
        }

        try {
            $cacheKey = self::getCacheKey($playerId);

            // 执行 Lua 脚本，原子性减少余额
            $resultJson = Redis::eval(
                self::LUA_ATOMIC_DECREMENT,
                1,  // KEYS 数量
                $cacheKey,  // KEYS[1]
                $amount,    // ARGV[1]
                $ttl        // ARGV[2]
            );

            $result = json_decode($resultJson, true);

            if ($result['ok'] == 1) {
                Log::info('WalletService: Atomic decrement success', [
                    'player_id' => $playerId,
                    'amount' => $amount,
                    'new_balance' => $result['balance'],
                ]);
            } else {
                Log::warning('WalletService: Atomic decrement failed - insufficient balance', [
                    'player_id' => $playerId,
                    'amount' => $amount,
                    'current_balance' => $result['balance'],
                ]);
            }

            return $result;

        } catch (\Throwable $e) {
            Log::error('WalletService: Atomic decrement exception', [
                'player_id' => $playerId,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
