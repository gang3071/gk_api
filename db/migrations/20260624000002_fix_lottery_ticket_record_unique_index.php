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

        // 直接使用 SQL 删除旧索引（因为 hasIndex 可能检测不准确）
        try {
            $this->execute("ALTER TABLE yjb_lottery_ticket_record DROP INDEX idx_ticket_no_unique");
            echo "✓ 删除旧索引: idx_ticket_no_unique (ticket_no)\n";
        } catch (\Exception $e) {
            echo "ℹ️ 旧索引不存在或已删除: " . $e->getMessage() . "\n";
        }

        // 添加新的联合唯一索引
        $table = $this->table('lottery_ticket_record');

        // 检查新索引是否已存在
        if (!$this->indexExists('lottery_ticket_record', 'idx_activity_ticket_no_unique')) {
            echo "✓ 添加新索引: idx_activity_ticket_no_unique (activity_id, ticket_no)\n";
            $table->addIndex(['activity_id', 'ticket_no'], [
                'unique' => true,
                'name' => 'idx_activity_ticket_no_unique'
            ]);
            $table->save();
        } else {
            echo "ℹ️ 新索引已存在，跳过\n";
        }

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
        if (!$this->hasTable($tableName)) {
            return false;
        }

        $table = $this->table($tableName);
        return $table->hasIndex($indexName);
    }
}
