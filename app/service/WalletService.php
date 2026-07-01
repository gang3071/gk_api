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
     * ⚠️ 注意：余额缓存永不过期（Redis as Single Source of Truth）
     * 此值仅用于兼容 Lua 脚本参数，实际上 Lua 脚本会忽略此参数
     */
    private const CACHE_TTL = 0; // 0 表示永不过期

    /**
     * 获取玩家余额（带 Redis 缓存）
     *
     * @param int $playerId 玩家ID
     * @param int $platformId 平台ID，默认1（实体机平台）
     * @param bool $forceRefresh 是否强制刷新缓存
     * @return float 余额
     */
    /**
     * 获取玩家余额（带 Redis 缓存）
     *
     * 🔧 整数化改造（2026-05-10）：
     * - Redis 存储单位：分（整数）
     * - 数据库存储单位：元（浮点）
     * - 返回单位：元（浮点）
     *
     * @param int $playerId 玩家ID
     * @param int $platformId 平台ID，默认1（实体机平台）
     * @param bool $forceRefresh 是否强制刷新缓存
     * @return float 余额（单位：元）
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
                    // 🔧 Redis 存储的是"分"（整数），需要 ÷ 100 转为"元"
                    $balanceInCents = (int)$cached;
                    return round($balanceInCents / 100, 2);
                }
            }

            // 缓存未命中或强制刷新，从数据库读取
            $balance = self::getBalanceFromDB($playerId, $platformId);  // 数据库返回"元"

            // 🔧 更新缓存：元 → 分（× 100）
            // ⚠️ 永不过期：Redis 是余额的唯一实时标准
            $balanceInCents = (int)round($balance * 100);
            Redis::set($cacheKey, $balanceInCents);

            return round($balance, 2);
        } catch (\Throwable $e) {
            // Redis 异常时自动降级到数据库
            Log::warning('WalletService: Redis failed, fallback to DB', [
                'player_id' => $playerId,
                'platform_id' => $platformId,
                'error' => $e->getMessage(),
            ]);

            return round(self::getBalanceFromDB($playerId, $platformId), 2);
        }
    }

    /**
     * 🚨 紧急开关：禁用 Redis 缓存
     * 在 .env 中设置 WALLET_CACHE_ENABLED=false 可立即禁用缓存
     * 用于紧急情况下快速回滚到纯数据库查询
     */
    private static function isCacheEnabled(): bool
    {
        return config('services.wallet.cache_enabled', true);
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

        return round($result !== null ? (float)$result : 0.0, 2);
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
     * 🔧 整数化改造（2026-05-10）：
     * - 接收参数：元（浮点）
     * - Redis 存储：分（整数）
     *
     * @param int $playerId 玩家ID
     * @param int $platformId 平台ID
     * @param float $balance 余额（单位：元）
     * @return bool 是否成功
     */
    public static function updateCache(int $playerId, int $platformId, float $balance): bool
    {
        try {
            $cacheKey = self::getCacheKey($playerId);

            // 🔧 转换为"分"（整数）存储
            // ⚠️ 永不过期：Redis 是余额的唯一实时标准
            $balanceInCents = (int)round($balance * 100);
            Redis::set($cacheKey, $balanceInCents);

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
                    // 🔧 Redis 存储的是"分"（整数），转为"元"
                    $balanceInCents = (int)$cached[$index];
                    $result[$playerId] = round($balanceInCents / 100, 2);
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
                $balance = round((float)$wallet->money, 2);  // 数据库返回"元"
                $result[$wallet->player_id] = $balance;

                // 🔧 回填缓存：元 → 分
                // ⚠️ 永不过期：Redis 是余额的唯一实时标准
                try {
                    $cacheKey = self::getCacheKey($wallet->player_id);
                    $balanceInCents = (int)round($balance * 100);
                    Redis::set($cacheKey, $balanceInCents);
                } catch (\Throwable $e) {
                    // 忽略缓存回填失败
                }
            }

            // 补充不存在的玩家（余额为0）
            foreach ($missedIds as $playerId) {
                if (!isset($result[$playerId])) {
                    $result[$playerId] = 0.00;
                    // 🔧 缓存不存在的玩家（避免缓存穿透）：存储 0 分
                    try {
                        $cacheKey = self::getCacheKey($playerId);
                        // ⚠️ 永不过期：Redis 是余额的唯一实时标准
                        Redis::set($cacheKey, 0);  // 0 分 = 0 元
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

        // 🔧 整数化改造：元 × 100 → 分
        $amountInCents = (int)round($amount * 100);

        try {
            // 使用整数化的 LUA_ATOMIC_DECREMENT 脚本
            $result = \support\Redis::eval(self::LUA_ATOMIC_DECREMENT, 1, $cacheKey, $amountInCents, self::CACHE_TTL);

            // 解析返回的 JSON
            $balanceData = json_decode($result, true);
            if (!$balanceData || !isset($balanceData['ok'])) {
                throw new \Exception('Invalid balance data returned from Redis');
            }

            // 检查 Lua 脚本返回的错误
            if ($balanceData['ok'] === 0) {
                $error = $balanceData['error'] ?? 'unknown_error';
                if ($error === 'key_not_found') {
                    throw new \Exception(trans('wallet_not_found', [], 'message'));
                }
                if ($error === 'insufficient_balance') {
                    throw new \Exception(trans('game_amount_insufficient', [], 'message'));
                }
                throw new \Exception("Atomic decrement failed: {$error}");
            }

            // 🔧 整数化改造：分 ÷ 100 → 元
            $oldBalance = round($balanceData['old'] / 100, 2);
            $newBalance = round($balanceData['balance'] / 100, 2);

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

        // 🔧 整数化改造：元 × 100 → 分
        $amountInCents = (int)round($amount * 100);

        try {
            // 使用整数化的 LUA_ATOMIC_INCREMENT 脚本
            $result = \support\Redis::eval(self::LUA_ATOMIC_INCREMENT, 1, $cacheKey, $amountInCents, self::CACHE_TTL);

            // 解析返回的 JSON
            $balanceData = json_decode($result, true);
            if (!$balanceData || !isset($balanceData['ok'])) {
                throw new \Exception('Invalid balance data returned from Redis');
            }

            // 检查 Lua 脚本返回的错误
            if ($balanceData['ok'] === 0) {
                $error = $balanceData['error'] ?? 'unknown_error';
                throw new \Exception("Atomic increment failed: {$error}");
            }

            // 🔧 整数化改造：分 ÷ 100 → 元
            $oldBalance = round($balanceData['old'] / 100, 2);
            $newBalance = round($balanceData['balance'] / 100, 2);

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
            \support\Log::info('WalletService: checkMachineCrash 开始检查', [
                'player_id' => $playerId,
                'current_balance' => $currentBalance,
                'previous_balance' => $previousBalance,
            ]);

            // 获取玩家信息
            $player = \app\model\Player::find($playerId);
            if (!$player) {
                \support\Log::warning('WalletService: checkMachineCrash 玩家不存在', [
                    'player_id' => $playerId,
                ]);
                return;
            }

            // 获取爆机配置
            $adminUserId = $player->store_admin_id ?? null;
            if (!$adminUserId) {
                \support\Log::warning('WalletService: checkMachineCrash 玩家无store_admin_id', [
                    'player_id' => $playerId,
                    'store_admin_id' => $adminUserId,
                ]);
                return;
            }

            $crashSetting = \app\model\StoreSetting::getSetting(
                'machine_crash_amount',
                $player->department_id,
                null,
                $adminUserId
            );

            \support\Log::info('WalletService: checkMachineCrash 获取爆机配置', [
                'player_id' => $playerId,
                'admin_user_id' => $adminUserId,
                'department_id' => $player->department_id,
                'crash_setting' => $crashSetting ? (array)$crashSetting : null,
            ]);

            // 如果没有配置或配置被禁用，不处理
            if (!$crashSetting || $crashSetting->status != 1) {
                \support\Log::info('WalletService: checkMachineCrash 爆机配置未启用或不存在', [
                    'player_id' => $playerId,
                    'setting_exists' => !!$crashSetting,
                    'setting_status' => $crashSetting->status ?? null,
                ]);
                return;
            }

            $crashAmount = $crashSetting->num ?? 0;
            if ($crashAmount <= 0) {
                \support\Log::info('WalletService: checkMachineCrash 爆机金额配置为0', [
                    'player_id' => $playerId,
                    'crash_amount' => $crashAmount,
                ]);
                return;
            }

            // 检查爆机状态变化
            $wasCrashed = $previousBalance !== null && $previousBalance >= $crashAmount;
            $isCrashed = $currentBalance >= $crashAmount;

            \support\Log::info('WalletService: checkMachineCrash 状态判断', [
                'player_id' => $playerId,
                'current_balance' => $currentBalance,
                'previous_balance' => $previousBalance,
                'crash_amount' => $crashAmount,
                'was_crashed' => $wasCrashed,
                'is_crashed' => $isCrashed,
                'status_changed' => $wasCrashed !== $isCrashed,
            ]);

            // 检查数据库的 is_crashed 字段是否正确
            $dbWallet = \support\Db::table('player_platform_cash')
                ->where('player_id', $playerId)
                ->where('platform_id', 1)
                ->first(['is_crashed']);

            $dbIsCrashed = $dbWallet && $dbWallet->is_crashed == 1;

            \support\Log::info('WalletService: checkMachineCrash 数据库状态', [
                'player_id' => $playerId,
                'db_is_crashed' => $dbIsCrashed,
                'realtime_is_crashed' => $isCrashed,
                'need_fix' => $dbIsCrashed !== $isCrashed,
            ]);

            // 状态没有变化，但需要检查数据库是否正确
            if ($wasCrashed === $isCrashed) {
                // 如果数据库状态不正确，修复它
                if ($dbIsCrashed !== $isCrashed) {
                    \support\Log::warning('WalletService: checkMachineCrash 修复数据库状态', [
                        'player_id' => $playerId,
                        'db_is_crashed' => $dbIsCrashed,
                        'correct_is_crashed' => $isCrashed,
                    ]);

                    $updateResult = \support\Db::table('player_platform_cash')
                        ->where('player_id', $playerId)
                        ->where('platform_id', 1)
                        ->update(['is_crashed' => $isCrashed ? 1 : 0]);

                    clearMachineCrashCache($playerId);

                    \support\Log::info('WalletService: checkMachineCrash 数据库修复完成', [
                        'player_id' => $playerId,
                        'update_result' => $updateResult,
                    ]);
                }
                return;
            }

            // 更新爆机状态字段
            $updateResult = \support\Db::table('player_platform_cash')
                ->where('player_id', $playerId)
                ->where('platform_id', 1) // 实体机平台
                ->update(['is_crashed' => $isCrashed ? 1 : 0]);

            \support\Log::info('WalletService: checkMachineCrash 更新数据库', [
                'player_id' => $playerId,
                'is_crashed_value' => $isCrashed ? 1 : 0,
                'update_result' => $updateResult,
            ]);

            // 清除爆机状态缓存
            clearMachineCrashCache($playerId);

            \support\Log::info('WalletService: 爆机状态变化', [
                'player_id' => $playerId,
                'old_status' => $wasCrashed ? '已爆机' : '未爆机',
                'new_status' => $isCrashed ? '已爆机' : '未爆机',
                'current_balance' => $currentBalance,
                'previous_balance' => $previousBalance,
                'crash_amount' => $crashAmount,
            ]);

            // 从未爆机变为爆机 -> 发送爆机通知
            if (!$wasCrashed && $isCrashed) {
                \support\Log::info('WalletService: checkMachineCrash 触发爆机通知', [
                    'player_id' => $playerId,
                    'player_name' => $player->name ?? '',
                    'current_balance' => $currentBalance,
                    'crash_amount' => $crashAmount,
                ]);
                $crashInfo = [
                    'crashed' => true,
                    'crash_amount' => $crashAmount,
                    'current_amount' => $currentBalance,
                ];
                notifyMachineCrash($player, $crashInfo);
            }

            // 从爆机变为未爆机 -> 发送解锁通知
            if ($wasCrashed && !$isCrashed) {
                \support\Log::info('WalletService: checkMachineCrash 触发解锁通知', [
                    'player_id' => $playerId,
                    'player_name' => $player->name ?? '',
                    'previous_balance' => $previousBalance,
                    'current_balance' => $currentBalance,
                    'crash_amount' => $crashAmount,
                ]);
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
                    // 🔧 整数化改造：元 × 100 → 分
                    // ⚠️ 永不过期：Redis 是余额的唯一实时标准
                    $balanceInCents = (int)round($balance * 100);
                    Redis::set($cacheKey, $balanceInCents);
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
                    // 🔧 整数化改造：0 分（整数）
                    // ⚠️ 永不过期：Redis 是余额的唯一实时标准
                    Redis::set($cacheKey, 0);
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
    /**
     * Lua 脚本：原子性增加余额（整数化版本）
     *
     * 🔧 整数化改造（2026-05-10）：
     * - Redis 存储单位：分（整数）
     * - ARGV[1] 传入单位：分（整数）
     * - 返回单位：分（整数）
     */
    private const LUA_ATOMIC_INCREMENT = <<<'LUA'
local key = KEYS[1]
local amountInCents = math.floor(tonumber(ARGV[1]) + 0.5)  -- 确保整数
local ttl = tonumber(ARGV[2]) or 3600

-- Redis 存储的是"分"（整数）
local currentBalanceInCents = tonumber(redis.call('GET', key)) or 0

-- 整数加法
local newBalanceInCents = currentBalanceInCents + amountInCents

-- 存储"分"（整数，永不过期）
redis.call('SET', key, newBalanceInCents)

return cjson.encode({
    ok = 1,
    balance = newBalanceInCents,
    old = currentBalanceInCents,
    new = newBalanceInCents
})
LUA;

    /**
     * Lua 脚本：原子性减少余额（带余额检查）
     *
     * 修复说明（2026-05-01）：
     * - 添加 0.01 元精度容差，解决浮点数精度丢失问题
     * - 添加负数余额防护，确保余额不会为负数
     * - 解决余额等于扣款金额时因精度问题导致的扣款失败
     */
    /**
     * Lua 脚本：原子性减少余额（带余额检查）（整数化版本）
     *
     * 🔧 整数化改造（2026-05-10）：
     * - Redis 存储单位：分（整数）
     * - ARGV[1] 传入单位：分（整数）
     * - 返回单位：分（整数）
     * - 移除浮点容差（整数运算无需容差）
     */
    private const LUA_ATOMIC_DECREMENT = <<<'LUA'
local key = KEYS[1]
local amountInCents = math.floor(tonumber(ARGV[1]) + 0.5)
local ttl = tonumber(ARGV[2]) or 3600

-- Redis 存储的是"分"（整数）
local currentBalanceInCents = tonumber(redis.call('GET', key)) or 0

-- 余额不足检查（整数比较，无需容差）
if currentBalanceInCents < amountInCents then
    return cjson.encode({
        ok = 0,
        error = "insufficient_balance",
        balance = currentBalanceInCents
    })
end

-- 整数减法
local newBalanceInCents = currentBalanceInCents - amountInCents

-- 防止负数余额（双重保险）
if newBalanceInCents < 0 then
    newBalanceInCents = 0
end

-- ⚠️ 永不过期：Redis 是余额的唯一实时标准
redis.call('SET', key, newBalanceInCents)
return cjson.encode({
    ok = 1,
    balance = newBalanceInCents,
    old = currentBalanceInCents,
    new = newBalanceInCents
})
LUA;

    /**
     * Lua 脚本：原子性洗分操作
     *
     * 功能：在 Redis 中原子性完成"读取-计算-扣款"
     * - 读取当前余额
     * - 计算可洗分金额（向下取整到百位）
     * - 检查余额是否足够
     * - 原子性扣款
     *
     * 优势：
     * - 完全避免 TOCTOU 问题
     * - 保证并发安全
     * - 自动处理浮点数精度问题
     */
    /**
     * Lua 脚本：原子性洗分操作（整数化版本）
     *
     * 🔧 整数化改造（2026-05-10）：
     * - Redis 存储单位：分（整数）
     * - ARGV[1] 传入单位：分（整数）
     * - 返回单位：分（整数）
     * - 彻底解决"余额 2000 元只能洗 1900"的精度问题
     */
    private const LUA_ATOMIC_WASH = <<<'LUA'
local key = KEYS[1]
local washPointConfigInCents = math.floor(tonumber(ARGV[1]) + 0.5)  -- 洗分配置（分，整数）
local ttl = tonumber(ARGV[2]) or 3600

-- 防御性检查：确保配置值大于0
if washPointConfigInCents <= 0 then
    washPointConfigInCents = 10000  -- 默认 100 元
end

-- Redis 存储的是"分"（整数）
local currentBalanceInCents = tonumber(redis.call('GET', key)) or 0

-- 🎯 根据配置计算可洗分金额：取配置的整倍数（整数运算）
-- 例如：配置 60000 分（600元），余额 160000 分（1600元） → washAmount = 120000 分（1200元）
-- 例如：配置 50000 分（500元），余额 160000 分（1600元） → washAmount = 150000 分（1500元）
local washAmountInCents = math.floor(currentBalanceInCents / washPointConfigInCents) * washPointConfigInCents

-- 检查是否达到最小洗分金额（至少要有1倍配置金额）
if washAmountInCents < washPointConfigInCents then
    return cjson.encode({
        ok = 0,
        error = "insufficient_wash_amount",
        balance = currentBalanceInCents,
        wash_amount = 0,
        min_required = washPointConfigInCents
    })
end

-- 检查余额是否足够（整数比较，无需容差）
if currentBalanceInCents < washAmountInCents then
    return cjson.encode({
        ok = 0,
        error = "insufficient_balance",
        balance = currentBalanceInCents,
        wash_amount = washAmountInCents
    })
end

-- 扣除洗分金额（整数减法）
local newBalanceInCents = currentBalanceInCents - washAmountInCents

-- 防止负数余额
if newBalanceInCents < 0 then
    newBalanceInCents = 0
end

-- ⚠️ 永不过期：Redis 是余额的唯一实时标准
redis.call('SET', key, newBalanceInCents)

return cjson.encode({
    ok = 1,
    balance = newBalanceInCents,           -- 扣款后余额（分）
    old_balance = currentBalanceInCents,   -- 扣款前余额（分）
    wash_amount = washAmountInCents        -- 实际洗分金额（分）
})
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
     */
    /**
     * 原子性增加余额（使用 Lua 脚本）（整数化版本）
     *
     * 🔧 整数化改造（2026-05-10）：
     * - 接收参数：元（浮点）
     * - 传入 Lua：分（整数）
     * - 返回值：元（浮点）
     *
     * @param int $playerId 玩家ID
     * @param float $amount 增加金额（单位：元，必须 > 0）
     * @param int $ttl Redis 缓存过期时间（秒），默认 3600
     * @return array ['ok' => 1, 'balance' => 新余额（元）, 'old' => 旧余额（元）, 'new' => 新余额（元）]
     */
    public static function atomicIncrement(int $playerId, float $amount, int $ttl = 0): array
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be greater than 0');
        }

        try {
            $cacheKey = self::getCacheKey($playerId);

            // 🔧 转换为"分"（整数）
            $amountInCents = (int)round($amount * 100);

            // 执行 Lua 脚本，原子性增加余额
            $resultJson = Redis::eval(
                self::LUA_ATOMIC_INCREMENT,
                1,  // KEYS 数量
                $cacheKey,         // KEYS[1]
                $amountInCents,    // ARGV[1]（分）
                $ttl               // ARGV[2]
            );

            $result = json_decode($resultJson, true);

            // 🔧 转换为"元"（浮点）
            if (isset($result['balance'])) {
                $result['balance'] = round($result['balance'] / 100, 2);
            }
            if (isset($result['old'])) {
                $result['old'] = round($result['old'] / 100, 2);
            }
            if (isset($result['new'])) {
                $result['new'] = round($result['new'] / 100, 2);
            }

            Log::info('WalletService: Atomic increment success', [
                'player_id' => $playerId,
                'amount' => $amount,
                'old_balance' => $result['old'] ?? 0,
                'new_balance' => $result['balance'],
            ]);

            return $result;

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
     * 原子性减少余额（使用 Lua 脚本，带余额检查）（整数化版本）
     *
     * 🔧 整数化改造（2026-05-10）：
     * - 接收参数：元（浮点）
     * - 传入 Lua：分（整数）
     * - 返回值：元（浮点）
     *
     * @param int $playerId 玩家ID
     * @param float $amount 减少金额（单位：元，必须 > 0）
     * @param int $ttl Redis 缓存过期时间（秒），默认 3600
     * @return array ['ok' => 1, 'balance' => 新余额（元）] 或 ['ok' => 0, 'error' => 'insufficient_balance', 'balance' => 当前余额（元）]
     */
    public static function atomicDecrement(int $playerId, float $amount, int $ttl = 0): array
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be greater than 0');
        }

        try {
            $cacheKey = self::getCacheKey($playerId);

            // 🔧 转换为"分"（整数）
            $amountInCents = (int)round($amount * 100);

            // 执行 Lua 脚本，原子性减少余额
            $resultJson = Redis::eval(
                self::LUA_ATOMIC_DECREMENT,
                1,  // KEYS 数量
                $cacheKey,         // KEYS[1]
                $amountInCents,    // ARGV[1]（分）
                $ttl               // ARGV[2]
            );

            $result = json_decode($resultJson, true);

            // 🔧 转换为"元"（浮点）
            if (isset($result['balance'])) {
                $result['balance'] = round($result['balance'] / 100, 2);
            }
            if (isset($result['old'])) {
                $result['old'] = round($result['old'] / 100, 2);
            }
            if (isset($result['new'])) {
                $result['new'] = round($result['new'] / 100, 2);
            }

            if ($result['ok'] == 1) {
                Log::info('WalletService: Atomic decrement success', [
                    'player_id' => $playerId,
                    'amount' => $amount,
                    'old_balance' => $result['old'] ?? 0,
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

    /**
     * 原子性洗分操作（完全避免 TOCTOU 问题）
     *
     * 在 Redis 中原子性完成：
     * 1. 读取当前余额
     * 2. 根据后台配置计算可洗分金额（取配置的整倍数）
     * 3. 检查余额是否足够
     * 4. 原子性扣款
     *
     * 计算规则：
     * - washAmount = floor(余额 / washPointConfig) * washPointConfig
     * - 例如：配置600，余额1600 → washAmount = 1200
     * - 例如：配置500，余额1600 → washAmount = 1500
     * - 余额小于配置值时，washAmount = 0（余额不足）
     *
     * 优势：
     * - 完全避免 Time-of-Check to Time-of-Use (TOCTOU) 竞态条件
     * - 保证并发安全
     * - 自动处理浮点数精度问题
     * - 消除两次读取余额之间的时间窗口
     *
     * @param int $playerId 玩家ID
     * @param int $washPointConfig 洗分配置（从后台admin_users.wash_point_config获取，默认100，单位：元）
     * @param int $ttl Redis 缓存过期时间（秒），默认 3600
     * @return array [
     *   'ok' => 1,                 // 成功标志
     *   'balance' => 新余额（元）,
     *   'old_balance' => 扣款前余额（元）,
     *   'wash_amount' => 实际洗分金额（元）
     * ] 或 [
     *   'ok' => 0,
     *   'error' => 错误类型,
     *   'balance' => 当前余额（元）
     * ]
     */
    /**
     * 🔧 整数化改造（2026-05-10）：
     * - 接收参数：元（整数）
     * - 传入 Lua：分（整数）
     * - 返回值：元（浮点）
     * - 彻底修复"余额 2000 元只能洗 1900"的问题 ✅
     * - 保留灵活的洗分配置，支持后台配置不同金额（100、500、600等）
     */
    public static function atomicWash(int $playerId, float $washPointConfig = 100, int $ttl = 0): array
    {
        try {
            $cacheKey = self::getCacheKey($playerId);

            // 🔧 转换为"分"（整数）
            $washPointConfigInCents = $washPointConfig * 100;

            // 执行 Lua 脚本，原子性完成洗分操作
            $resultJson = Redis::eval(
                self::LUA_ATOMIC_WASH,
                1,  // KEYS 数量
                $cacheKey,                 // KEYS[1]
                $washPointConfigInCents,   // ARGV[1] - 洗分配置（分，整数）
                $ttl                       // ARGV[2]
            );

            $result = json_decode($resultJson, true);

            // 🔧 转换为"元"（浮点）
            if (isset($result['balance'])) {
                $result['balance'] = round($result['balance'] / 100, 2);
            }
            if (isset($result['old_balance'])) {
                $result['old_balance'] = round($result['old_balance'] / 100, 2);
            }
            if (isset($result['wash_amount'])) {
                $result['wash_amount'] = round($result['wash_amount'] / 100, 2);
            }
            if (isset($result['min_required'])) {
                $result['min_required'] = round($result['min_required'] / 100, 2);
            }

            if ($result['ok'] == 1) {
                Log::info('WalletService: Atomic wash success', [
                    'player_id' => $playerId,
                    'old_balance' => $result['old_balance'],
                    'wash_amount' => $result['wash_amount'],
                    'new_balance' => $result['balance'],
                ]);
            } else {
                Log::warning('WalletService: Atomic wash failed', [
                    'player_id' => $playerId,
                    'error' => $result['error'] ?? 'unknown',
                    'current_balance' => $result['balance'] ?? 0,
                    'wash_amount' => $result['wash_amount'] ?? 0,
                    'min_required' => $result['min_required'] ?? $washPointConfig,
                ]);
            }

            return $result;

        } catch (\Throwable $e) {
            Log::error('WalletService: Atomic wash exception', [
                'player_id' => $playerId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
