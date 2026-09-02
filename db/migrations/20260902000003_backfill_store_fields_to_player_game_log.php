<?php

use Phinx\Migration\AbstractMigration;

/**
 * 回填 player_game_log 表的门店字段（存量数据处理）
 *
 * 目的：为已存在的 player_game_log 记录补充 store_id 和 store_agent_id
 *
 * 数据来源逻辑：
 * 1. 有玩家时（player_id > 0）：
 *    - store_id = player.store_admin_id（优先）
 *    - store_agent_id = player.agent_admin_id（优先）
 *    - 降级：从 channel_machine 和 admin_users 获取
 *
 * 2. 无玩家时（player_id = 0）：
 *    - store_id = channel_machine.store_admin_id
 *    - store_agent_id = admin_users.parent_admin_id
 *
 * 处理策略：
 * - 分批处理，每批10000条，避免锁表过久
 * - 只处理 store_id 和 store_agent_id 为 NULL 的记录
 * - 使用 LEFT JOIN 确保没有门店绑定的记录不会报错
 *
 * 影响范围：
 * - 所有历史 player_game_log 记录
 * - 包括线上和线下机台的记录
 *
 * 执行时间：根据数据量，可能需要几分钟到几十分钟
 *
 * @author Claude Code
 * @date 2026-09-02
 */
class BackfillStoreFieldsToPlayerGameLog extends AbstractMigration
{
    /**
     * Up Method.
     *
     * 执行数据回填
     */
    public function up(): void
    {
        $this->output->writeln('');
        $this->output->writeln('<info>========================================</info>');
        $this->output->writeln('<info>  开始回填 player_game_log 门店字段</info>');
        $this->output->writeln('<info>========================================</info>');
        $this->output->writeln('');

        // 检查字段是否存在
        $table = $this->table('player_game_log');
        if (!$table->hasColumn('store_id') || !$table->hasColumn('store_agent_id')) {
            $this->output->writeln('<error>错误：store_id 或 store_agent_id 字段不存在</error>');
            $this->output->writeln('<error>请先执行 20260902000002_add_store_fields_to_player_game_log.php</error>');
            return;
        }

        // 获取需要处理的总记录数
        $totalCount = $this->fetchRow(
            "SELECT COUNT(*) as count
             FROM player_game_log
             WHERE store_id IS NULL"
        );
        $total = $totalCount['count'];

        $this->output->writeln("<comment>待处理记录数：{$total}</comment>");
        $this->output->writeln('');

        if ($total == 0) {
            $this->output->writeln('<info>没有需要处理的记录</info>');
            return;
        }

        // 分批处理配置
        $batchSize = 10000;
        $processedCount = 0;
        $updatedCount = 0;
        $errorCount = 0;

        $this->output->writeln("<comment>分批处理，每批 {$batchSize} 条</comment>");
        $this->output->writeln('');

        // 分批回填数据
        while ($processedCount < $total) {
            try {
                $this->output->write(sprintf(
                    "\r<info>处理进度: %d/%d (%.1f%%)</info>",
                    $processedCount,
                    $total,
                    ($processedCount / $total) * 100
                ));

                // 批量更新SQL
                // 优先从 Player 表获取门店信息（有玩家时）
                // 降级从 channel_machine 获取（无玩家时）
                $affected = $this->execute("
                    UPDATE player_game_log pgl
                    LEFT JOIN (
                        SELECT
                            pgl2.id,
                            -- 优先从 player 表获取（有玩家时）
                            COALESCE(p.store_admin_id, cm.store_admin_id) as store_id,
                            -- 代理ID：优先从 player.agent_admin_id，降级从门店的 parent_admin_id
                            COALESCE(p.agent_admin_id, au.parent_admin_id) as store_agent_id
                        FROM player_game_log pgl2
                        LEFT JOIN player p ON pgl2.player_id = p.id
                        INNER JOIN machine m ON pgl2.machine_id = m.id
                        LEFT JOIN channel_machine cm ON m.id = cm.machine_id AND pgl2.department_id = cm.department_id
                        LEFT JOIN admin_users au ON COALESCE(p.store_admin_id, cm.store_admin_id) = au.id
                        WHERE pgl2.store_id IS NULL
                        LIMIT {$batchSize}
                    ) AS store_data ON pgl.id = store_data.id
                    SET
                        pgl.store_id = store_data.store_id,
                        pgl.store_agent_id = store_data.store_agent_id
                    WHERE pgl.id = store_data.id
                ");

                $updatedCount += $affected;
                $processedCount += $batchSize;

                // 避免处理过快导致数据库压力
                usleep(100000); // 休眠100ms

            } catch (\Exception $e) {
                $errorCount++;
                $this->output->writeln('');
                $this->output->writeln('<error>批次处理失败：' . $e->getMessage() . '</error>');

                if ($errorCount > 10) {
                    $this->output->writeln('<error>错误次数过多，停止处理</error>');
                    break;
                }
            }

            // 检查是否还有未处理的记录
            $remaining = $this->fetchRow(
                "SELECT COUNT(*) as count FROM player_game_log WHERE store_id IS NULL"
            );

            if ($remaining['count'] == 0) {
                break;
            }
        }

        $this->output->writeln('');
        $this->output->writeln('');
        $this->output->writeln('<info>========================================</info>');
        $this->output->writeln('<info>  回填完成</info>');
        $this->output->writeln('<info>========================================</info>');
        $this->output->writeln('');
        $this->output->writeln("<info>更新记录数：{$updatedCount}</info>");
        $this->output->writeln("<info>错误次数：{$errorCount}</info>");
        $this->output->writeln('');

        // 统计结果
        $stats = $this->query("
            SELECT
                COUNT(*) as total,
                COUNT(store_id) as has_store,
                COUNT(store_agent_id) as has_agent,
                COUNT(*) - COUNT(store_id) as no_store
            FROM player_game_log
        ")->fetch(\PDO::FETCH_ASSOC);

        $this->output->writeln('<comment>数据统计：</comment>');
        $this->output->writeln("  总记录数：{$stats['total']}");
        $this->output->writeln("  有门店：{$stats['has_store']}");
        $this->output->writeln("  有代理：{$stats['has_agent']}");
        $this->output->writeln("  无门店：{$stats['no_store']}");
        $this->output->writeln('');

        $this->output->writeln('<comment>说明：</comment>');
        $this->output->writeln('  - 无门店记录：机台未绑定门店，或非门店机台');
        $this->output->writeln('  - 无代理记录：门店未设置上级代理');
        $this->output->writeln('');
    }

    /**
     * Down Method.
     *
     * 回滚操作：清除回填的数据
     */
    public function down(): void
    {
        $this->output->writeln('');
        $this->output->writeln('<comment>========================================</comment>');
        $this->output->writeln('<comment>  警告：即将清除所有门店字段数据</comment>');
        $this->output->writeln('<comment>========================================</comment>');
        $this->output->writeln('');

        // 清除回填的数据
        $this->execute("
            UPDATE player_game_log
            SET
                store_id = NULL,
                store_agent_id = NULL
        ");

        $this->output->writeln('<info>✓ 已清除所有 store_id 和 store_agent_id 数据</info>');
    }
}
