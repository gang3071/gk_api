<?php

use Phinx\Migration\AbstractMigration;

/**
 * 强制修复摸奖券表的唯一索引
 *
 * 之前的迁移文件 hasIndex() 检测不准确，导致旧索引未删除
 * 本次直接使用 SQL 强制删除旧索引并添加新索引
 */
class ForceFixLotteryTicketUniqueIndex extends AbstractMigration
{
    public function up()
    {
        echo "================================================================================\n";
        echo "强制修复 lottery_ticket 表的唯一索引\n";
        echo "================================================================================\n";

        // 获取完整表名（包含前缀）
        $table = $this->table('lottery_ticket');
        $tableName = $table->getTable();

        // 1. 删除旧的单字段唯一索引（直接SQL，忽略错误）
        try {
            $this->execute("ALTER TABLE {$tableName} DROP INDEX idx_ticket_no_unique");
            echo "✓ 成功删除旧索引: idx_ticket_no_unique (ticket_no)\n";
        } catch (\Exception $e) {
            echo "ℹ️ 旧索引不存在或已删除\n";
        }

        // 2. 检查并添加新的联合唯一索引
        $hasNewIndex = false;
        try {
            $rows = $this->fetchAll("SHOW INDEX FROM {$tableName} WHERE Key_name = 'idx_activity_ticket_no_unique'");
            $hasNewIndex = !empty($rows);
        } catch (\Exception $e) {
            // 忽略
        }

        if (!$hasNewIndex) {
            $this->execute("ALTER TABLE {$tableName} ADD UNIQUE INDEX idx_activity_ticket_no_unique (activity_id, ticket_no)");
            echo "✓ 成功添加新索引: idx_activity_ticket_no_unique (activity_id, ticket_no)\n";
        } else {
            echo "ℹ️ 新索引已存在，跳过\n";
        }

        echo "================================================================================\n";
        echo "lottery_ticket 表索引修复完成！\n";
        echo "================================================================================\n";
    }

    public function down()
    {
        echo "回滚操作：恢复原有索引结构\n";

        // 获取完整表名
        $table = $this->table('lottery_ticket');
        $tableName = $table->getTable();

        // 删除联合索引
        try {
            $this->execute("ALTER TABLE {$tableName} DROP INDEX idx_activity_ticket_no_unique");
            echo "✓ 删除联合索引\n";
        } catch (\Exception $e) {
            echo "ℹ️ 联合索引不存在\n";
        }

        // 恢复单字段索引
        try {
            $this->execute("ALTER TABLE {$tableName} ADD UNIQUE INDEX idx_ticket_no_unique (ticket_no)");
            echo "✓ 恢复单字段索引\n";
        } catch (\Exception $e) {
            echo "ℹ️ 单字段索引已存在\n";
        }
    }
}
