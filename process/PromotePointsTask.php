<?php

namespace process;

use app\service\PromotePointsService;
use support\Log;
use Workerman\Crontab\Crontab;

/**
 * 推广积分补算任务（每分钟）
 *
 * 扫描近期已结算的游戏记录，计算推广积分（下級實際積分 × 上級 children_points_ratio%）
 * 并入账：上級直接入账 + 下級同額扣回。全部处理都在 gk_api（admin 无任何参与）。
 *
 * 幂等/重试：player_points_record.batch_id 即游标，处理失败下一轮自动重试。
 */
class PromotePointsTask
{
    public function onWorkerStart()
    {
        // ✅ 显式绑定 $this，避免闭包作用域问题
        $self = $this;

        // 每分钟的第20秒执行（避开整点的其他积分任务）
        new Crontab('20 * * * * *', function () use ($self) {
            $self->run();
        });

        Log::channel('player_points')->info('PromotePointsTask: 已启动', [
            'schedule' => '每分钟',
            'description' => '推广积分补算（上級入账 + 下級同額扣回）',
        ]);
    }

    /**
     * 执行一轮补算
     */
    private function run()
    {
        try {
            $startTime = microtime(true);

            $result = PromotePointsService::run();

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            $total = ($result['game'] ?? 0) + ($result['machine'] ?? 0);
            if ($total > 0) {
                Log::channel('player_points')->info('[推广积分] 补算完成', [
                    'processed'   => $result,
                    'duration_ms' => $duration,
                ]);
            }
        } catch (\Throwable $e) {
            Log::channel('player_points')->error('[推广积分] 补算失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
