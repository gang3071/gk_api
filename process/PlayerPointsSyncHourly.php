<?php

namespace process;

use app\service\PlayerPointsService;
use support\Log;
use Workerman\Crontab\Crontab;

/**
 * 积分全量同步任务（每小时）
 *
 * 作用：将 Redis 中的所有玩家积分全量同步到 MySQL
 * 执行时间：每小时整点
 * 兜底机制：确保所有玩家数据最终一致，防止脏数据同步遗漏
 */
class PlayerPointsSyncHourly
{
    public function onWorkerStart()
    {
        // ✅ 显式绑定 $this，避免闭包作用域问题
        $self = $this;

        // 每小时整点的第0秒执行
        new Crontab('0 0 * * * *', function () use ($self) {
            $self->sync();
        });

        Log::channel('player_points')->info('PlayerPointsSyncHourly: 已启动', [
            'schedule' => '每小时整点',
            'description' => 'Redis全量同步兜底',
        ]);
    }

    /**
     * 全量同步
     */
    private function sync()
    {
        try {
            Log::channel('player_points')->info('[积分同步] 开始全量同步Redis到MySQL');

            $startTime = microtime(true);

            // 调用全量同步服务
            $count = PlayerPointsService::syncAllPlayersToMySQL();

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Log::channel('player_points')->info('[积分同步] Redis全量同步完成', [
                'synced_count' => $count,
                'duration_ms' => $duration,
            ]);

        } catch (\Throwable $e) {
            Log::channel('player_points')->error('[积分同步] Redis全量同步失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
