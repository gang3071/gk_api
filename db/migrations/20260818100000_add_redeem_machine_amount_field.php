<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 交班记录新增机台核销字段
 *
 * 新增字段：
 * - redeem_machine_amount: 机台核销金额（核销金额-入票用，status=3机台使用）
 */
class AddRedeemMachineAmountField extends AbstractMigration
{
    /**
     * Change Method.
     */
    public function change(): void
    {
        // 1. 修改 store_shift_device_detail 表（设备明细）
        $this->table('store_shift_device_detail')
            ->addColumn('redeem_machine_amount', 'decimal', [
                'default' => 0,
                'precision' => 15,
                'scale' => 2,
                'comment' => '机台核销金额(核销金额-入票用)',
                'after' => 'redeem_amount',
            ])
            ->save();

        // 2. 修改 store_agent_shift_handover_record 表（交班记录主表）
        $this->table('store_agent_shift_handover_record')
            ->addColumn('redeem_machine_amount', 'decimal', [
                'default' => 0,
                'precision' => 15,
                'scale' => 2,
                'comment' => '机台核销金额汇总(核销金额-入票用)',
                'after' => 'redeem_amount',
            ])
            ->save();
    }
}
