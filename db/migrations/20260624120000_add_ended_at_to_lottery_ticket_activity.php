<?php

use Phinx\Migration\AbstractMigration;

/**
 * 添加活动结束时间字段
 * 用于记录活动变为 STATUS_ENDED 状态的准确时间
 *
 * @date 2026-06-24
 */
class AddEndedAtToLotteryTicketActivity extends AbstractMigration
{
    /**
     * Change Method.
     */
    public function change()
    {
        $table = $this->table('lottery_ticket_activity');

        // 添加 ended_at 字段
        $table->addColumn('ended_at', 'timestamp', [
            'null' => true,
            'after' => 'end_time',
            'comment' => '活动实际结束时间（变为已结束状态的时间）',
        ])
        ->addIndex(['ended_at'], ['name' => 'idx_ended_at'])
        ->update();
    }
}
