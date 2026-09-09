<?php

namespace process;

use app\service\PlayerPointsService;
use support\Log;
use Workerman\Crontab\Crontab;

/**
 * 积分每日汇总任务
 *
 * 作用：汇总昨天的积分数据，生成统计报表
 * 执行时间：每天凌晨2点
 * 数据处理：同步 Redis 数据到 MySQL，生成每日统计
 */
class PlayerPointsSyncDaily
{
    public function onWorkerStart()
    {
        // 获取配置
        $config = config('points_config');
        $enabled = $config['daily_summary_enabled'] ?? true;
        $cron = $config['summary_cron'] ?? '0 0 2 * * *';

        if (!$enabled) {
            Log::channel('player_points')->warning('PlayerPointsSyncDaily: 每日汇总已禁用');
            return;
        }

        // ✅ 显式绑定 $this，避免闭包作用域问题
        $self = $this;

        // 每天凌晨2点执行
        new Crontab($cron, function () use ($self) {
            $self->sync();
        });

        Log::channel('player_points')->info('PlayerPointsSyncDaily: 已启动', [
            'schedule' => $cron . ' (秒 分 时 日 月 周)',
            'description' => '每日汇总统计',
        ]);
    }

    /**
     * 执行每日汇总
     */
    private function sync()
    {
        try {
            Log::channel('player_points')->info('[积分汇总] 开始执行每日汇总');

            $startTime = microtime(true);

            // 调用汇总服务
            $count = PlayerPointsService::dailySummary();

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Log::channel('player_points')->info('[积分汇总] 每日汇总执行完成', [
                'player_count' => $count,
                'duration_ms' => $duration,
            ]);

        } catch (\Throwable $e) {
            Log::channel('player_points')->error('[积分汇总] 每日汇总执行失败', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
