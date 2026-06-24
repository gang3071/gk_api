<?php

use Phinx\Migration\AbstractMigration;

class FixLotteryTicketUniqueIndex extends AbstractMigration
{
    /**
     * 修复摸奖券表的唯一索引
     *
     * 问题：当前 ticket_no 是全局唯一，导致不同活动之间券号冲突
     * 解决：改为 (activity_id, ticket_no) 联合唯一索引，每个活动内券号唯一
     */
    public function change()
    {
        $table = $this->table('yjb_lottery_ticket');

        // 1. 删除旧的单字段唯一索引
        if ($this->hasIndex('yjb_lottery_ticket', 'idx_ticket_no_unique')) {
            $table->removeIndex(['ticket_no'], ['unique' => true, 'name' => 'idx_ticket_no_unique']);
        }

        // 2. 添加新的联合唯一索引
        $table->addIndex(['activity_id', 'ticket_no'], [
            'unique' => true,
            'name' => 'idx_activity_ticket_no_unique'
        ]);

        $table->update();
    }

    /**
     * 回滚操作
     */
    public function down()
    {
        $table = $this->table('yjb_lottery_ticket');

        // 删除联合索引
        if ($this->hasIndex('yjb_lottery_ticket', 'idx_activity_ticket_no_unique')) {
            $table->removeIndex(['activity_id', 'ticket_no'], [
                'unique' => true,
                'name' => 'idx_activity_ticket_no_unique'
            ]);
        }

        // 恢复单字段索引（仅在回滚时）
        $table->addIndex(['ticket_no'], [
            'unique' => true,
            'name' => 'idx_ticket_no_unique'
        ]);

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
