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
        // 记录收到的消息（用于调试）
        $this->log->info('[积分队列] 收到打码量消息', [
            'player_id' => $data['player_id'] ?? null,
            'bet_amount' => $data['bet_amount'] ?? null,
            'source' => $data['source'] ?? null,
            'batch_id' => $data['batch_id'] ?? null,
            'created_at' => $data['created_at'] ?? null,
        ]);

        try {
            // 验证必要字段
            if (empty($data['player_id']) || empty($data['bet_amount'])) {
                $this->log->error('[积分队列] 消息字段缺失', [
                    'data' => $data,
                ]);
                return; // 数据不完整，直接丢弃
            }

            $playerId = intval($data['player_id']);
            $betAmount = floatval($data['bet_amount']);
            $batchId = $data['batch_id'] ?? '';
            $source = $data['source'] ?? 'betting';

            // 转换为本地时区（Asia/Shanghai）
            $createdAt = !empty($data['created_at'])
                ? Carbon::parse($data['created_at'])->setTimezone('Asia/Shanghai')
                : Carbon::now('Asia/Shanghai');

            $this->log->debug('[积分队列] 解析时间', [
                'raw_created_at' => $data['created_at'] ?? 'null',
                'timezone' => $createdAt->timezone->getName(),
                'parsed_datetime' => $createdAt->format('Y-m-d H:i:s'),
            ]);

            // 防止重复消费
            if ($this->isDuplicate($batchId)) {
                $this->log->info('[积分队列] 检测到重复批次，已跳过', [
                    'player_id' => $playerId,
                    'batch_id' => $batchId,
                ]);
                return;
            }

            $this->log->debug('[积分队列] 开始累加积分', [
                'player_id' => $playerId,
                'bet_amount' => $betAmount,
                'source' => $source,
            ]);

            // 调用积分服务累加
            $result = PlayerPointsService::addPointsFromBetting(
                $playerId,
                $betAmount,
                $data['record_ids'] ?? [],
                $batchId,
                $createdAt
            );

            $this->log->info('[积分队列] 积分累加成功', [
                'player_id' => $playerId,
                'bet_amount' => $betAmount,
                'points_earned' => $result['points_earned'],
                'total_points' => $result['total_points'],
                'available_points' => $result['available_points'],
                'batch_id' => $batchId,
            ]);

            // ⚠️ 不再立即清理去重标记
            // 原因：去重标记需要保留，防止消息重复投递时重复累加
            // TTL 会自动过期，无需手动清理

            // 推送通知（可选）
            $this->pushNotification($playerId, $result);

        } catch (Exception $e) {
            $this->log->error('[积分队列] 消费失败', [
                'data' => $data,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
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
        // 没有 batch_id，不去重（风险自担）
        if (empty($batchId)) {
            return false;
        }

        $cacheKey = 'gk_api:points_processed_batch_' . $batchId;
        $redis = \support\Redis::connection()->client();

        // ✅ 使用 SET NX EX 原子操作：只有首次能设置成功
        // 返回值：true=首次处理（未重复），false=重复
        $isFirst = $redis->set($cacheKey, time(), ['NX', 'EX' => 3600]);

        // 返回是否重复：首次返回false（不重复），重复返回true
        return !$isFirst;
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
