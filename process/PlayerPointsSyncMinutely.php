<?php

namespace process;

use app\service\PlayerPointsService;
use support\Log;
use Workerman\Crontab\Crontab;

/**
 * 积分脏数据同步任务（每分钟）
 *
 * 作用：将 Redis 中有变化的玩家积分批量同步到 MySQL
 * 执行时间：每分钟
 * 性能优化：只同步脏数据，降低数据库压力 10 倍以上
 */
class PlayerPointsSyncMinutely
{
    public function onWorkerStart()
    {
        // 每分钟执行
        new Crontab('* * * * *', function () {
            $this->sync();
        });

        Log::channel('player_points')->info('PlayerPointsSyncMinutely: 已启动', [
            'schedule' => '每分钟',
            'description' => '批量同步脏数据到MySQL',
        ]);
    }

    /**
     * 同步脏数据
     */
    private function sync()
    {
        try {
            $startTime = microtime(true);

            // 调用脏数据同步服务（批量大小100）
            $count = PlayerPointsService::syncDirtyPlayersToMySQL(100);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            // 只有成功同步时才记录日志（避免日志刷屏）
            if ($count > 0) {
                Log::channel('player_points')->info('[积分同步] 脏数据同步完成', [
                    'synced_count' => $count,
                    'duration_ms' => $duration,
                ]);
            }

        } catch (\Throwable $e) {
            Log::channel('player_points')->error('[积分同步] 脏数据同步失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
