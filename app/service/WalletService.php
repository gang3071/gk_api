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

        $cacheKey = self::getCacheKey($playerId, $platformId);

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
     * 从数据库获取余额
     *
     * @param int $playerId
     * @param int $platformId
     * @return float
     */
    private static function getBalanceFromDB(int $playerId, int $platformId): float
    {
        $wallet = PlayerPlatformCash::query()
            ->where('player_id', $playerId)
            ->where('platform_id', $platformId)
            ->first();

        return $wallet ? (float)$wallet->money : 0.0;
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
     * @param int $platformId 平台ID（保留参数兼容性，实际不使用）
     * @return string Redis 缓存键
     */
    private static function getCacheKey(int $playerId, int $platformId): string
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
            $cacheKey = self::getCacheKey($playerId, $platformId);
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
            $cacheKey = self::getCacheKey($playerId, $platformId);
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
                $cacheKeys[] = self::getCacheKey($playerId, $platformId);
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
                $cacheKeys[$playerId] = self::getCacheKey($playerId, $platformId);
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
                    $cacheKey = self::getCacheKey($wallet->player_id, $platformId);
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
                        $cacheKey = self::getCacheKey($playerId, $platformId);
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
     * 扣款（事务安全）
     *
     * @param int $playerId
     * @param float $amount
     * @param int $platformId
     * @return bool
     * @throws \Exception
     */
    public static function deduct(int $playerId, float $amount, int $platformId = 1): bool
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be positive');
        }

        $wallet = PlayerPlatformCash::query()
            ->where('player_id', $playerId)
            ->where('platform_id', $platformId)
            ->lockForUpdate()
            ->first();

        if (!$wallet) {
            throw new \Exception('Wallet not found');
        }

        if ($wallet->money < $amount) {
            throw new \Exception('Insufficient balance');
        }

        $wallet->money = bcsub($wallet->money, $amount, 2);
        $wallet->save();

        // 缓存会通过模型事件自动更新

        return true;
    }

    /**
     * 加款（事务安全）
     *
     * @param int $playerId
     * @param float $amount
     * @param int $platformId
     * @return bool
     * @throws \Exception
     */
    public static function add(int $playerId, float $amount, int $platformId = 1): bool
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be positive');
        }

        $wallet = PlayerPlatformCash::query()
            ->where('player_id', $playerId)
            ->where('platform_id', $platformId)
            ->lockForUpdate()
            ->first();

        if (!$wallet) {
            throw new \Exception('Wallet not found');
        }

        $wallet->money = bcadd($wallet->money, $amount, 2);
        $wallet->save();

        // 缓存会通过模型事件自动更新

        return true;
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
                    $cacheKey = self::getCacheKey($wallet->player_id, $platformId);
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
                    $cacheKey = self::getCacheKey($playerId, $platformId);
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
}
