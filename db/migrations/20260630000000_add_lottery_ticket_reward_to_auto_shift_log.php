<?php

use Phinx\Migration\AbstractMigration;

class AddLotteryTicketRewardToAutoShiftLog extends AbstractMigration
{
    /**
     * Change Method.
     */
    public function change(): void
    {
        // 自动交班日志表新增彩票奖励金额字段
        $table = $this->table('store_auto_shift_log');

        $table->addColumn('lottery_ticket_reward_amount', 'decimal', [
            'precision' => 20,
            'scale' => 2,
            'default' => 0,
            'comment' => '彩票奖励金额',
            'after' => 'lottery_amount',
        ]);

        $table->save();
    }
}
