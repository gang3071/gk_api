<?php

use Phinx\Migration\AbstractMigration;

/**
 * 交班记录/设备明细新增活动外增金额（TYPE_ACTIVITY_GIVE=38）
 */
class AddActivityGiveAmountToShiftRecord extends AbstractMigration
{
    /**
     * Change Method.
     */
    public function change()
    {
        // 交班汇总表
        $table = $this->table('store_agent_shift_handover_record');
        if (!$table->hasColumn('activity_give_amount')) {
            $table->addColumn('activity_give_amount', 'decimal', [
                'null' => false,
                'precision' => 12,
                'scale' => 2,
                'default' => '0.00',
                'comment' => '活动外增金额（TYPE_ACTIVITY_GIVE=38）',
                'after' => 'upgrade_bonus_amount',
            ]);
        }
        $table->update();

        // 设备明细表（导出用）
        $detailTable = $this->table('store_shift_device_detail');
        if (!$detailTable->hasColumn('activity_give_amount')) {
            $detailTable->addColumn('activity_give_amount', 'decimal', [
                'null' => false,
                'precision' => 12,
                'scale' => 2,
                'default' => '0.00',
                'comment' => '活动外增金额（TYPE_ACTIVITY_GIVE=38）',
                'after' => 'upgrade_bonus_amount',
            ]);
        }
        $detailTable->update();
    }

    /**
     * Migrate Up.
     */
    public function up()
    {
        parent::up();

        $this->execute("
            UPDATE `store_agent_shift_handover_record`
            SET `activity_give_amount` = 0.00
            WHERE `activity_give_amount` IS NULL
        ");
        $this->execute("
            UPDATE `store_shift_device_detail`
            SET `activity_give_amount` = 0.00
            WHERE `activity_give_amount` IS NULL
        ");
    }
}
