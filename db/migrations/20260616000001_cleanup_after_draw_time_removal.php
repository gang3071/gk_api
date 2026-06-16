<?php

use Phinx\Migration\AbstractMigration;

/**
 * 删除 draw_time 字段后的数据清理迁移
 *
 * 说明：
 * 1. 本迁移在 20260616000000_remove_draw_time_field.php 之后执行
 * 2. draw_time 和 preheat_start_time 字段已被删除
 * 3. 本迁移确保所有相关数据完整性
 *
 * 数据影响：
 * - 删除字段不影响现有活动数据
 * - 活动状态流转改为完全手动控制
 * - 所有时间判断基于 start_time 和 end_time
 */
class CleanupAfterDrawTimeRemoval extends AbstractMigration
{
    /**
     * 执行迁移
     */
    public function up()
    {
        // 1. 检查是否有活动处于异常状态（理论上不应该有）
        $abnormalActivities = $this->fetchAll(
            "SELECT id, name, status FROM lottery_ticket_activity
             WHERE status NOT IN (0, 1, 2, 3, 6)"
        );

        if (!empty($abnormalActivities)) {
            $this->output->writeln('<error>发现异常状态的活动：</error>');
            foreach ($abnormalActivities as $activity) {
                $this->output->writeln(sprintf(
                    '  - ID: %d, 名称: %s, 状态: %d',
                    $activity['id'],
                    $activity['name'],
                    $activity['status']
                ));
            }

            // 自动修复：将异常状态改为"进行中"或"已结束"
            $this->execute(
                "UPDATE lottery_ticket_activity
                 SET status = CASE
                     WHEN NOW() < end_time THEN 1  -- 进行中
                     ELSE 2  -- 已结束
                 END
                 WHERE status NOT IN (0, 1, 2, 3, 6)"
            );

            $this->output->writeln('<info>已自动修复异常状态活动</info>');
        }

        // 2. 统计当前活动状态分布
        $statusCounts = $this->fetchAll(
            "SELECT status, COUNT(*) as count
             FROM lottery_ticket_activity
             GROUP BY status"
        );

        $this->output->writeln('<info>当前活动状态分布：</info>');
        $statusLabels = [
            0 => '未开始',
            1 => '进行中',
            2 => '已结束',
            3 => '已关闭',
            6 => '开奖中',
        ];
        foreach ($statusCounts as $row) {
            $label = $statusLabels[$row['status']] ?? '未知';
            $this->output->writeln(sprintf('  - %s: %d 个', $label, $row['count']));
        }

        // 3. 检查是否有"开奖中"但未摇球的活动（需要管理员关注）
        $drawingWithoutBalls = $this->fetchAll(
            "SELECT id, name, created_at
             FROM lottery_ticket_activity
             WHERE status = 6
             AND (ball_result IS NULL OR ball_result = '')"
        );

        if (!empty($drawingWithoutBalls)) {
            $this->output->writeln('<comment>⚠️  以下活动处于"开奖中"但未摇球，需要管理员手动处理：</comment>');
            foreach ($drawingWithoutBalls as $activity) {
                $this->output->writeln(sprintf(
                    '  - ID: %d, 名称: %s, 创建时间: %s',
                    $activity['id'],
                    $activity['name'],
                    $activity['created_at']
                ));
            }
            $this->output->writeln('<comment>  建议：进入后台手动摇球或停止开奖</comment>');
        }

        // 4. 数据完整性验证
        $invalidTimeRange = $this->fetchAll(
            "SELECT id, name, start_time, end_time
             FROM lottery_ticket_activity
             WHERE start_time >= end_time"
        );

        if (!empty($invalidTimeRange)) {
            $this->output->writeln('<error>发现时间范围无效的活动（start_time >= end_time）：</error>');
            foreach ($invalidTimeRange as $activity) {
                $this->output->writeln(sprintf(
                    '  - ID: %d, 名称: %s, 开始: %s, 结束: %s',
                    $activity['id'],
                    $activity['name'],
                    $activity['start_time'],
                    $activity['end_time']
                ));
            }
            $this->output->writeln('<comment>  建议：进入后台手动修正时间范围</comment>');
        }

        $this->output->writeln('<info>✓ draw_time 字段删除后的数据清理完成</info>');
        $this->output->writeln('<info>✓ 活动状态流转已改为手动控制模式</info>');
        $this->output->writeln('');
        $this->output->writeln('<comment>重要提示：</comment>');
        $this->output->writeln('<comment>  1. 开奖操作需要管理员手动点击"开奖"按钮</comment>');
        $this->output->writeln('<comment>  2. 结束活动需要管理员手动点击"停止开奖"按钮</comment>');
        $this->output->writeln('<comment>  3. end_time 到达后仅停止发券，不会自动改变状态</comment>');
    }

    /**
     * 回滚迁移
     */
    public function down()
    {
        // 无需回滚操作（字段恢复在上一个迁移中处理）
        $this->output->writeln('<info>无需回滚数据清理操作</info>');
    }
}
