<?php

namespace process;

use app\service\PlayerPointsService;
use support\Log;
use Workerman\Crontab\Crontab;
use Workerman\Worker;

/**
 * 积分每日汇总定时任务
 *
 * 功能：
 * - 每天凌晨2点执行
 * - 汇总昨天的积分数据
 * - 同步 Redis 数据到 MySQL
 *
 * 配置：
 * - 在 config/process.php 中注册
 * - 进程数：1（只需一个进程）
 *
 * @author Claude Code
 * @date 2026-09-08
 */
class PointsDailySummary
{
    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $log;

    /**
     * @var Crontab[] 保存定时任务实例，防止被GC回收
     */
    private array $crontabs = [];

    public function __construct()
    {
        $this->log = Log::channel('player_points');
    }

    /**
     * Worker 启动时回调
     */
    public function onWorkerStart(Worker $worker): void
    {
        $this->log->info('[积分定时任务] 进程启动', [
            'worker_id' => $worker->id,
        ]);

        // 获取配置
        $config = config('points_config');
        $enabled = $config['daily_summary_enabled'] ?? true;
        $cron = $config['summary_cron'] ?? '0 2 * * *';

        if (!$enabled) {
            $this->log->warning('[积分定时任务] 每日汇总已禁用');
            return;
        }

        // 注册定时任务
        // 默认：每天凌晨 2:00 执行
        // ⚠️ 必须保存 Crontab 实例，否则会被GC回收导致进程退出
        $this->crontabs[] = new Crontab($cron, function () {
            $this->executeDailySummary();
        });

        $this->log->info('[积分定时任务] 定时任务已注册', [
            'cron' => $cron,
        ]);

        // 每分钟批量同步脏数据（高频打码的玩家）
        $this->crontabs[] = new Crontab('* * * * *', function () {
            $this->executeSyncDirtyPlayers();
        });

        $this->log->info('[积分定时任务] 脏数据同步任务已注册', [
            'cron' => '* * * * *',
            'note' => '每分钟批量同步，降低数据库压力',
        ]);

        // 每小时全量同步一次 Redis 到 MySQL（兜底保证数据一致性）
        $this->crontabs[] = new Crontab('0 * * * *', function () {
            $this->executeSyncToMySQL();
        });

        $this->log->info('[积分定时任务] Redis全量同步任务已注册', [
            'cron' => '0 * * * *',
            'note' => '每小时全量同步兜底',
        ]);
    }

    /**
     * 执行每日汇总
     */
    private function executeDailySummary(): void
    {
        try {
            $this->log->info('[积分定时任务] 开始执行每日汇总');

            $startTime = microtime(true);

            // 调用汇总服务
            $count = PlayerPointsService::dailySummary();

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            $this->log->info('[积分定时任务] 每日汇总执行完成', [
                'player_count' => $count,
                'duration_ms' => $duration,
            ]);

        } catch (\Throwable $e) {
            $this->log->error('[积分定时任务] 每日汇总执行失败', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            // 可选：发送告警通知
            // $this->sendAlert('积分每日汇总失败', $e->getMessage());
        }
    }

    /**
     * 执行脏数据批量同步（每分钟执行）
     *
     * 性能优势：
     * - 只同步有变化的玩家（脏数据）
     * - 批量处理（每次100个）
     * - 降低数据库压力 10 倍以上
     */
    private function executeSyncDirtyPlayers(): void
    {
        try {
            $startTime = microtime(true);

            // 调用脏数据同步服务（批量大小100）
            $count = PlayerPointsService::syncDirtyPlayersToMySQL(100);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            // 只有成功同步时才记录日志（避免日志刷屏）
            if ($count > 0) {
                $this->log->info('[积分定时任务] 脏数据同步完成', [
                    'synced_count' => $count,
                    'duration_ms' => $duration,
                ]);
            }

        } catch (\Throwable $e) {
            $this->log->error('[积分定时任务] 脏数据同步失败', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 执行 Redis 全量同步到 MySQL（每小时执行）
     *
     * 兜底机制：
     * - 确保所有玩家数据最终一致
     * - 防止脏数据同步遗漏
     */
    private function executeSyncToMySQL(): void
    {
        try {
            $this->log->info('[积分定时任务] 开始全量同步Redis到MySQL');

            $startTime = microtime(true);

            // 调用全量同步服务
            $count = PlayerPointsService::syncAllPlayersToMySQL();

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            $this->log->info('[积分定时任务] Redis全量同步完成', [
                'synced_count' => $count,
                'duration_ms' => $duration,
            ]);

        } catch (\Throwable $e) {
            $this->log->error('[积分定时任务] Redis全量同步失败', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Worker 停止时回调
     */
    public function onWorkerStop(Worker $worker): void
    {
        $this->log->info('[积分定时任务] 进程停止', [
            'worker_id' => $worker->id,
        ]);
    }
}
