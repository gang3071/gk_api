<?php

use Phinx\Migration\AbstractMigration;

class AddBetAmountFieldsToShift extends AbstractMigration
{
    /**
     * Change Method.
     */
    public function change(): void
    {
        // 交班记录表新增打码量字段
        $table = $this->table('store_agent_shift_handover_record');

        $table->addColumn('electronic_game_bet_amount', 'decimal', [
            'precision' => 20,
            'scale' => 2,
            'default' => 0,
            'comment' => '电子游戏打码量',
            'after' => 'lottery_ticket_reward_amount',
        ]);

        $table->addColumn('machine_bet_amount', 'decimal', [
            'precision' => 20,
            'scale' => 2,
            'default' => 0,
            'comment' => '机器打码量',
            'after' => 'electronic_game_bet_amount',
        ]);

        $table->save();

        // 设备明细表新增打码量字段
        $detailTable = $this->table('store_shift_device_detail');

        $detailTable->addColumn('electronic_game_bet_amount', 'decimal', [
            'precision' => 20,
            'scale' => 2,
            'default' => 0,
            'comment' => '电子游戏打码量',
            'after' => 'lottery_ticket_reward_amount',
        ]);

        $detailTable->addColumn('machine_bet_amount', 'decimal', [
            'precision' => 20,
            'scale' => 2,
            'default' => 0,
            'comment' => '机器打码量',
            'after' => 'electronic_game_bet_amount',
        ]);

        $detailTable->save();
    }
}
