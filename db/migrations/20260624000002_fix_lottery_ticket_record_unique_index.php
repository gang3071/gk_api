<?php

use Phinx\Migration\AbstractMigration;

/**
 * 修复中奖记录表的唯一索引
 *
 * 问题：如果 ticket_no 是全局唯一，会导致不同活动之间券号冲突
 * 解决：确保 (activity_id, ticket_no) 联合唯一索引
 */
class FixLotteryTicketRecordUniqueIndex extends AbstractMigration
{
    public function up()
    {
        echo "================================================================================\n";
        echo "修复 lottery_ticket_record 表的唯一索引\n";
        echo "================================================================================\n";

        $table = $this->table('lottery_ticket_record');

        // 删除旧的单字段唯一索引（如果存在）
        if ($this->indexExists('lottery_ticket_record', 'idx_ticket_no_unique')) {
            echo "✓ 删除旧索引: idx_ticket_no_unique (ticket_no)\n";
            $table->removeIndexByName('idx_ticket_no_unique');
        }

        // 添加联合唯一索引
        echo "✓ 添加新索引: idx_activity_ticket_no_unique (activity_id, ticket_no)\n";
        $table->addIndex(['activity_id', 'ticket_no'], [
            'unique' => true,
            'name' => 'idx_activity_ticket_no_unique'
        ]);

        $table->save();

        echo "================================================================================\n";
        echo "迁移完成！现在每个活动的券号独立，互不冲突。\n";
        echo "================================================================================\n";
    }

    public function down()
    {
        $table = $this->table('lottery_ticket_record');

        // 删除联合索引
        if ($this->indexExists('lottery_ticket_record', 'idx_activity_ticket_no_unique')) {
            $table->removeIndexByName('idx_activity_ticket_no_unique');
        }

        // 恢复单字段索引
        $table->addIndex(['ticket_no'], [
            'unique' => true,
            'name' => 'idx_ticket_no_unique'
        ]);

        $table->save();
    }

    /**
     * 检查索引是否存在
     */
    private function indexExists($tableName, $indexName)
    {
        $rows = $this->fetchAll("SHOW INDEX FROM yjb_{$tableName} WHERE Key_name = '{$indexName}'");
        return !empty($rows);
    }
}
