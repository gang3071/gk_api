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
        echo "================================================================================\n";
        echo "修复 lottery_ticket 表的唯一索引\n";
        echo "================================================================================\n";

        $table = $this->table('lottery_ticket');

        // 检查并删除旧的单字段唯一索引
        if ($table->hasIndex('ticket_no')) {
            echo "✓ 删除旧索引: idx_ticket_no_unique (ticket_no)\n";
            $table->removeIndexByName('idx_ticket_no_unique');
        }

        // 添加新的联合唯一索引
        echo "✓ 添加新索引: idx_activity_ticket_no_unique (activity_id, ticket_no)\n";
        $table->addIndex(['activity_id', 'ticket_no'], [
            'unique' => true,
            'name' => 'idx_activity_ticket_no_unique'
        ]);

        $table->update();

        echo "================================================================================\n";
        echo "迁移完成！现在每个活动的券号独立，互不冲突。\n";
        echo "================================================================================\n";
    }
}
