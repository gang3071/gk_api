<?php

namespace app\queue\redis\fast;

use app\service\PlayerPointsService;
use Carbon\Carbon;
use Exception;
use support\Log;
use Webman\RedisQueue\Consumer;

/**
 * 玩家积分队列消费者
 *
 * 职责：
 * - 接收 gk_work 发送的打码量消息
 * - 计算玩家应得积分（根据平台、VIP等级、活动倍数）
 * - 实时累加积分到 Redis（Lua原子操作）
 * - 防止重复消费（batch_id 去重）
 *
 * 性能特性：
 * - Redis原子操作（并发安全）
 * - 异步处理（不阻塞游戏流程）
 * - 去重机制（防止重复累加）
 * - 失败重试（队列自动重试）
 *
 * @author Claude Code
 * @date 2026-09-08
 */
class PlayerPoints implements Consumer
{
    /**
     * 队列名（需要和 gk_work 发送的队列名一致）
     */
    public $queue = 'player-points';

    /**
     * 连接名（使用 default 连接）
     */
    public $connection = 'default';

    /**
     * 日志通道
     * @var \Monolog\Logger
     */
    private $log;

    public function __construct()
    {
        $this->log = Log::channel(config('points_config.log_channel', 'player_points'));
    }

    /**
     * 消费消息
     *
     * @param array $data 消息数据
     * @return void
     * @throws Exception
     */
    public function consume($data)
    {
        $playerId = intval($data['player_id'] ?? 0);
        $batchId = $data['batch_id'] ?? '';
        $platformAmounts = $data['platform_amounts'] ?? [];

        // 兼容旧格式：bet_amount → platform_amounts
        if (empty($platformAmounts) && !empty($data['bet_amount'])) {
            $platformAmounts = [0 => floatval($data['bet_amount'])];
        }

        $totalBetAmount = array_sum($platformAmounts);

        $this->log->debug('[积分队列] 收到消息', [
            'player_id' => $playerId,
            'total_bet_amount' => $totalBetAmount,
            'platform_amounts' => $platformAmounts,
            'batch_id' => $batchId,
        ]);

        try {
            // 验证必要字段
            if ($playerId <= 0 || $totalBetAmount <= 0) {
                $this->log->error('[积分队列] 字段无效，丢弃消息', [
                    'player_id' => $playerId,
                    'platform_amounts' => $platformAmounts,
                    'raw_data' => $data,
                ]);
                return;
            }

            // 转换为本地时区（Asia/Shanghai）
            $createdAt = !empty($data['created_at'])
                ? Carbon::parse($data['created_at'])->setTimezone('Asia/Shanghai')
                : Carbon::now('Asia/Shanghai');

            // 防止重复消费
            if ($this->isDuplicate($batchId)) {
                $this->log->info('[积分队列] 重复批次，跳过', [
                    'player_id' => $playerId,
                    'batch_id' => $batchId,
                ]);
                return;
            }

            // 调用积分服务累加
            $result = PlayerPointsService::addPointsFromBetting(
                $playerId,
                $platformAmounts,
                $data['record_ids'] ?? [],
                $batchId,
                $createdAt
            );

            // 推送通知（可选）
            $this->pushNotification($playerId, $result);

            if ($result['points_earned'] > 0) {
                $this->log->debug('[积分队列] 消费完成', [
                    'player_id' => $playerId,
                    'points_earned' => $result['points_earned'],
                    'total_points' => $result['total_points'],
                ]);
            } else {
                $this->log->debug('[积分队列] 消费完成（积分为0）', [
                    'player_id' => $playerId,
                ]);
            }

        } catch (Exception $e) {
            $this->log->error('[积分队列] 消费异常，将重试', [
                'player_id' => $playerId,
                'total_bet_amount' => $totalBetAmount,
                'batch_id' => $batchId,
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            // 抛出异常触发重试（webman/redis-queue 会自动重试）
            throw $e;
        }
    }

    /**
     * 检测是否重复消费
     *
     * ✅ 使用 SETNX 原子操作防止并发重复
     *
     * @param string $batchId 批次ID
     * @return bool
     */
    private function isDuplicate(string $batchId): bool
    {
        if (empty($batchId)) {
            return false;
        }

        $cacheKey = 'gk_api:points_processed_batch_' . $batchId;

        try {
            $redis = \support\Redis::connection()->client();
            $isFirst = $redis->set($cacheKey, time(), 'EX', 3600, 'NX');

            // set() 返回 true=首次设置（非重复），false/null=key已存在（重复）
            // set() 返回 false 也可能是连接异常，记录日志区分
            if ($isFirst === false || $isFirst === null) {
                $this->log->debug('[积分队列] 去重key已存在', [
                    'batch_id' => $batchId,
                    'key' => $cacheKey,
                ]);
            }

            return !$isFirst;

        } catch (\Throwable $e) {
            // Redis 异常时放行，避免误判导致消息丢失
            $this->log->error('[积分队列] 去重检查异常，放行消息', [
                'batch_id' => $batchId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    // ⚠️ 已移除 cleanupDuplicateFlag() 方法
    // 原因：去重标记不应该立即清理，需要保留到 TTL 自动过期
    // 这样可以防止消息重复投递时重复累加积分

    /**
     * 推送积分变动通知（可选）
     *
     * @param int $playerId 玩家ID
     * @param array $result 积分结果
     * @return void
     */
    private function pushNotification(int $playerId, array $result): void
    {
        $config = config('points_config');

        // 检查是否启用推送
        if (empty($config['push_enabled'])) {
            return;
        }

        // 检查推送阈值
        $threshold = $config['push_threshold'] ?? 10;
        if ($result['points_earned'] < $threshold) {
            return; // 积分变动太小，不推送
        }

        try {
            // TODO: 实现推送逻辑
            // 可以使用 webman/push 推送到玩家客户端
            // \Webman\Push\Api::trigger('points', ['player_id' => $playerId], [
            //     'event' => 'points_earned',
            //     'points' => $result['points_earned'],
            //     'available_points' => $result['available_points'],
            // ]);

            $this->log->debug('[积分队列] 推送通知（未实现）', [
                'player_id' => $playerId,
                'points_earned' => $result['points_earned'],
            ]);

        } catch (\Exception $e) {
            // 推送失败不影响业务，只记录日志
            $this->log->warning('[积分队列] 推送通知失败', [
                'player_id' => $playerId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
