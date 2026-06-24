<?php

use Phinx\Migration\AbstractMigration;

class FixLotteryTicketRecordUniqueIndex extends AbstractMigration
{
    /**
     * 修复中奖记录表的唯一索引
     *
     * 问题：如果 ticket_no 是全局唯一，会导致不同活动之间券号冲突
     * 解决：确保 (activity_id, ticket_no) 联合唯一索引
     */
    public function change()
    {
        $table = $this->table('yjb_lottery_ticket_record');

        // 检查是否有旧的单字段唯一索引
        if ($this->hasIndex('yjb_lottery_ticket_record', 'idx_ticket_no_unique')) {
            $table->removeIndex(['ticket_no'], ['unique' => true, 'name' => 'idx_ticket_no_unique']);
        }

        // 添加联合唯一索引（如果不存在）
        if (!$this->hasIndex('yjb_lottery_ticket_record', 'idx_activity_ticket_no_unique')) {
            $table->addIndex(['activity_id', 'ticket_no'], [
                'unique' => true,
                'name' => 'idx_activity_ticket_no_unique'
            ]);
        }

        $table->update();
    }

    /**
     * 回滚操作
     */
    public function down()
    {
        $table = $this->table('yjb_lottery_ticket_record');

        // 删除联合索引
        if ($this->hasIndex('yjb_lottery_ticket_record', 'idx_activity_ticket_no_unique')) {
            $table->removeIndex(['activity_id', 'ticket_no'], [
                'unique' => true,
                'name' => 'idx_activity_ticket_no_unique'
            ]);
        }

        $table->update();
    }

    /**
     * 检查索引是否存在
     */
    private function hasIndex($tableName, $indexName)
    {
        $rows = $this->fetchAll("SHOW INDEX FROM {$tableName} WHERE Key_name = '{$indexName}'");
        return !empty($rows);
    }
}
