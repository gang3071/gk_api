<?php

use Phinx\Migration\AbstractMigration;

/**
 * 添加 prize_level_id 字段到摸奖券中奖记录表
 *
 * 用于精确追踪奖品等级的发放数量，防止超发
 *
 * @date 2026-08-01
 */
class AddPrizeLevelIdToLotteryTicketRecord extends AbstractMigration
{
    /**
     * Change Method.
     */
    public function change()
    {
        $table = $this->table('lottery_ticket_record');

        // 添加 prize_level_id 字段
        $table->addColumn('prize_level_id', 'integer', [
                'null' => true,
                'signed' => false,
                'after' => 'ticket_no',
                'comment' => '奖品等级ID（关联 lottery_ticket_prize_level.id）',
            ])
            ->addIndex(['prize_level_id'], ['name' => 'idx_prize_level_id'])
            ->update();
    }
}