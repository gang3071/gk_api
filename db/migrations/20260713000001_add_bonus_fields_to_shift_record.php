<?php

use Phinx\Migration\AbstractMigration;

class AddBonusFieldsToShiftRecord extends AbstractMigration
{
    /**
     * Change Method.
     */
    public function change(): void
    {
        // 交班记录表新增生日礼金和升级礼金字段
        $table = $this->table('store_agent_shift_handover_record');

        if (!$table->hasColumn('birthday_bonus_amount')) {
            $table->addColumn('birthday_bonus_amount', 'decimal', [
                'precision' => 20,
                'scale' => 2,
                'default' => 0,
                'comment' => 'VIP生日礼金金额',
                'after' => 'lottery_ticket_reward_amount',
            ]);
        }

        if (!$table->hasColumn('upgrade_bonus_amount')) {
            $table->addColumn('upgrade_bonus_amount', 'decimal', [
                'precision' => 20,
                'scale' => 2,
                'default' => 0,
                'comment' => 'VIP升级礼金金额',
                'after' => 'birthday_bonus_amount',
            ]);
        }

        $table->save();

        // 交班设备明细表新增生日礼金和升级礼金字段
        $detailTable = $this->table('store_shift_device_detail');

        if (!$detailTable->hasColumn('birthday_bonus_amount')) {
            $detailTable->addColumn('birthday_bonus_amount', 'decimal', [
                'precision' => 20,
                'scale' => 2,
                'default' => 0,
                'comment' => 'VIP生日礼金金额',
                'after' => 'lottery_ticket_reward_amount',
            ]);
        }

        if (!$detailTable->hasColumn('upgrade_bonus_amount')) {
            $detailTable->addColumn('upgrade_bonus_amount', 'decimal', [
                'precision' => 20,
                'scale' => 2,
                'default' => 0,
                'comment' => 'VIP升级礼金金额',
                'after' => 'birthday_bonus_amount',
            ]);
        }

        $detailTable->save();
    }
}
