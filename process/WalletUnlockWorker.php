<?php

namespace process;

use support\Log;
use Workerman\Worker;

/**
 * 钱包解锁进程（订阅余额变化）
 *
 * 职责：
 * - 订阅 gk_work 的 Redis Pub/Sub 频道 (balance:change)
 * - 收到余额变化消息后，检查是否需要解锁钱包
 * - 延迟 < 50ms，实时解锁
 *
 * 优势：
 * - 与 gk_work 完全解耦（不影响核心业务性能）
 * - 实时性好（比定时任务快 120 倍）
 * - 代码简洁（复用 Pub/Sub 基础设施）
 *
 * 工作原理：
 * 1. gk_work 发布余额变化消息到 Redis Pub/Sub
 * 2. 本进程订阅并接收消息
 * 3. 解析消息获取 player_id 和 new_balance
 * 4. 检查该玩家是否有锁定的钱包
 * 5. 如果余额达到解锁条件，执行解锁并通知玩家
 */
class WalletUnlockWorker
{
    /**
     * @var Worker
     */
    private Worker $worker;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $log;

    /**
     * Redis 订阅连接
     */
    private $redis;

    public function __construct()
    {
        // 使用独立的日志通道
        $this->log = Log::channel('wallet_unlock');
    }

    /**
     * Worker 启动时回调
     */
    public function onWorkerStart(Worker $worker): void
    {
        $this->worker = $worker;

        $this->log->info("钱包解锁进程启动", [
            'worker_id' => $worker->id,
            'subscribe_channel' => 'balance:change',
        ]);

        // 延迟启动订阅，确保 Redis 服务已就绪（重启场景）
        \Workerman\Timer::add(1, function () {
            $this->subscribeWithRetry();
        }, [], false);  // 只执行一次
    }

    /**
     * 带重试的订阅逻辑
     */
    private function subscribeWithRetry(int $attempt = 1): void
    {
        $maxRetries = 10;

        try {
            // ✅ 连接到 gk_work 的 Redis（使用 work_remote 连接池）
            $config = config('redis.work_remote');

            if (!$config) {
                throw new \Exception('Redis work_remote 配置不存在，请在 config/redis.php 中添加');
            }

            $this->redis = new \Redis();
            $connected = $this->redis->connect(
                $config['host'],
                $config['port'],
                $config['timeout'] ?? 2
            );

            if (!$connected) {
                throw new \Exception('Redis 连接失败');
            }

            // 认证
            if (!empty($config['password'])) {
                $this->redis->auth($config['password']);
            }

            // 选择数据库
            if (isset($config['database'])) {
                $this->redis->select($config['database']);
            }

            // 设置为阻塞模式（Redis Pub/Sub 需要）
            $this->redis->setOption(\Redis::OPT_READ_TIMEOUT, -1);

            $this->log->info("开始订阅 Redis 频道: balance:change", [
                'attempt' => $attempt,
                'redis_host' => $config['host'],
                'redis_port' => $config['port'],
                'redis_db' => $config['database'] ?? 0,
            ]);

            // 订阅频道（阻塞模式）
            // ⚠️ 此调用永不返回，除非连接断开
            $this->redis->subscribe(['balance:change'], [$this, 'handleMessage']);

            // 如果执行到这里，说明订阅中断了
            $this->log->warning("Redis 订阅意外中断，尝试重连", [
                'attempt' => $attempt,
            ]);

            // 订阅中断后立即重连
            $this->scheduleReconnect($attempt + 1);

        } catch (\Throwable $e) {
            $this->log->error("Redis 订阅失败", [
                'attempt' => $attempt,
                'max_retries' => $maxRetries,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            // 重试逻辑
            if ($attempt < $maxRetries) {
                $this->scheduleReconnect($attempt + 1);
            } else {
                $this->log->critical("Redis 订阅失败次数过多，放弃重试", [
                    'max_retries' => $maxRetries,
                ]);
                // 触发 Worker 退出，让 Workerman 主进程重启此进程
                $this->worker->stop();
            }
        }
    }

    /**
     * 安排下次重连
     */
    private function scheduleReconnect(int $nextAttempt): void
    {
        // 指数退避：2^attempt 秒，最大 60 秒
        $delay = min(pow(2, $nextAttempt - 1), 60);

        $this->log->info("将在 {$delay} 秒后尝试重新订阅", [
            'next_attempt' => $nextAttempt,
        ]);

        \Workerman\Timer::add($delay, function () use ($nextAttempt) {
            $this->subscribeWithRetry($nextAttempt);
        }, [], false);  // 只执行一次
    }

    /**
     * 处理订阅消息
     *
     * @param \Redis $redis
     * @param string $channel
     * @param string $message
     */
    public function handleMessage(\Redis $redis, string $channel, string $message): void
    {
        try {
            // 解析消息
            $data = json_decode($message, true);
            if (!$data) {
                $this->log->warning("余额消息解析失败", [
                    'message' => substr($message, 0, 200),
                    'json_error' => json_last_error_msg(),
                ]);
                return;
            }

            // 验证必要字段
            if (!isset($data['player_id'], $data['new_balance'])) {
                $this->log->warning("余额消息缺少必要字段", [
                    'data' => $data,
                ]);
                return;
            }

            // 🎯 核心逻辑：尝试解锁钱包
            $this->tryUnlockWallet($data);

        } catch (\Throwable $e) {
            $this->log->error("处理解锁消息异常", [
                'error' => $e->getMessage(),
                'message' => substr($message, 0, 200),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }

    /**
     * 尝试解锁钱包
     *
     * 逻辑：
     * 1. 只在充值/结算/加款时检查（下注时余额减少，不需要检查）
     * 2. 计算总余额 = 钱包余额 + 所有机台上的分数
     * 3. 总余额 >= issue_threshold（默认 5000）时自动解锁
     *
     * @param array $data 余额变化消息数据
     */
    private function tryUnlockWallet(array $data): void
    {
        $playerId = (int)$data['player_id'];
        $newBalance = (float)$data['new_balance'];
        $reason = $data['reason'] ?? '';

        // ✅ 只在充值/结算/加款时检查解锁（避免不必要的数据库查询）
        // 下注时余额减少，不需要检查解锁
        if (!in_array($reason, ['recharge', 'settle', 'win', 'cancel'])) {
            return;
        }

        // 🎯 调用钱包解锁服务
        $result = \app\service\WalletUnlockService::tryUnlock($playerId, $newBalance, $reason);

        if ($result['unlocked']) {
            // ✅ 解锁成功
            $this->log->info("钱包已解锁", array_merge(
                ['player_id' => $playerId, 'reason' => $reason],
                $result['details']
            ));
        } else {
            // ⏭️ 未解锁（钱包未锁定 或 余额不足）
            if (!empty($result['details'])) {
                // 记录调试信息（余额不足的情况）
                $this->log->debug("钱包解锁条件未满足", array_merge(
                    ['player_id' => $playerId, 'reason' => $reason, 'message' => $result['message']],
                    $result['details']
                ));
            }
        }
    }

    /**
     * Worker 停止时回调
     */
    public function onWorkerStop(Worker $worker): void
    {
        $this->log->info("钱包解锁进程停止", [
            'worker_id' => $worker->id,
        ]);

        if ($this->redis) {
            try {
                $this->redis->close();
            } catch (\Throwable $e) {
                // 忽略关闭错误
            }
        }
    }
}