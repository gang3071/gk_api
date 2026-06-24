<?php

use Phinx\Migration\AbstractMigration;

/**
 * 为摸奖券打码量扫描添加索引优化
 *
 * 项目：gk_api
 * 日期：2026-06-22
 *
 * 优化目标：
 * - play_game_record 表的打码量统计查询
 * - player_game_log 表的打码量统计查询
 * - 提升 LotteryBetProgressScanTask 的执行效率（gk_admin项目的后台任务）
 *
 * 预期性能提升：
 * - 查询时间从 10-30秒 降低到 1-3秒
 * - 扫描任务总执行时间提升 85%+
 */
class AddIndexForLotteryBetScan extends AbstractMigration
{
    /**
     * 添加索引
     */
    public function up()
    {
        // 1. play_game_record 表 - 电子游戏打码量统计专用索引
        // 覆盖查询条件：department_id, created_at, settlement_status
        if (!$this->indexExists('play_game_record', 'idx_dept_time_status_for_lottery')) {
            $this->table('play_game_record')
                ->addIndex(
                    ['department_id', 'created_at', 'settlement_status'],
                    [
                        'name' => 'idx_dept_time_status_for_lottery',
                        'unique' => false,
                    ]
                )
                ->save();

            echo "✅ 已为 play_game_record 添加索引: idx_dept_time_status_for_lottery\n";
        } else {
            echo "ℹ️ play_game_record 索引已存在，跳过\n";
        }

        // 2. player_game_log 表 - 机台游戏打码量统计专用索引
        // 覆盖查询条件：department_id, created_at
        if ($this->hasTable('player_game_log')) {
            if (!$this->indexExists('player_game_log', 'idx_dept_time_for_lottery')) {
                $this->table('player_game_log')
                    ->addIndex(
                        ['department_id', 'created_at'],
                        [
                            'name' => 'idx_dept_time_for_lottery',
                            'unique' => false,
                        ]
                    )
                    ->save();

                echo "✅ 已为 player_game_log 添加索引: idx_dept_time_for_lottery\n";
            } else {
                echo "ℹ️ player_game_log 索引已存在，跳过\n";
            }
        }

        echo "\n索引创建完成！\n";
        echo "建议：\n";
        echo "1. 检查索引是否生效：SHOW INDEX FROM yjb_play_game_record;\n";
        echo "2. 测试查询性能：在 gk_admin 项目运行 php test_scan_task_performance.php\n";
        echo "3. 重启 gk_admin 服务：php windows.php restart\n";
    }

    /**
     * 检查索引是否存在
     */
    private function indexExists($tableName, $indexName)
    {
        $rows = $this->fetchAll("SHOW INDEX FROM yjb_{$tableName} WHERE Key_name = '{$indexName}'");
        return !empty($rows);
    }

    /**
     * 删除索引
     */
    public function down()
    {
        // 删除 play_game_record 索引
        if ($this->indexExists('play_game_record', 'idx_dept_time_status_for_lottery')) {
            $this->table('play_game_record')
                ->removeIndexByName('idx_dept_time_status_for_lottery')
                ->save();

            echo "✅ 已删除 play_game_record 索引: idx_dept_time_status_for_lottery\n";
        }

        // 删除 player_game_log 索引
        if ($this->hasTable('player_game_log') &&
            $this->indexExists('player_game_log', 'idx_dept_time_for_lottery')) {
            $this->table('player_game_log')
                ->removeIndexByName('idx_dept_time_for_lottery')
                ->save();

            echo "✅ 已删除 player_game_log 索引: idx_dept_time_for_lottery\n";
        }

        echo "\n索引删除完成！\n";
    }
}
