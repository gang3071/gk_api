<?php

use Phinx\Migration\AbstractMigration;

/**
 * 增加自动交班日志表金额字段精度
 * 解决：Numeric value out of range 错误
 * 将 decimal(10,2) 改为 decimal(20,2)，支持更大的金额
 */
class IncreaseAutoShiftLogAmountPrecision extends AbstractMigration
{
    /**
     * Change Method.
     */
    public function change()
    {
        $table = $this->table('store_auto_shift_log');

        // 修改金额字段精度，从 decimal(10,2) 改为 decimal(20,2)
        $table
            ->changeColumn('machine_amount', 'decimal', [
                'null' => false,
                'precision' => 20,
                'scale' => 2,
                'default' => '0.00',
                'comment' => '投钞金额（纸币金额）',
            ])
            ->changeColumn('total_in', 'decimal', [
                'null' => false,
                'precision' => 20,
                'scale' => 2,
                'default' => '0.00',
                'comment' => '总收入（送分金额）',
            ])
            ->changeColumn('total_out', 'decimal', [
                'null' => false,
                'precision' => 20,
                'scale' => 2,
                'default' => '0.00',
                'comment' => '总支出（取分金额）',
            ])
            ->changeColumn('lottery_amount', 'decimal', [
                'null' => false,
                'precision' => 20,
                'scale' => 2,
                'default' => '0.00',
                'comment' => '彩金发放金额（TYPE_LOTTERY=13）',
            ])
            ->changeColumn('total_profit', 'decimal', [
                'null' => false,
                'precision' => 20,
                'scale' => 2,
                'default' => '0.00',
                'comment' => '总利润（总收入 - 总支出）',
            ])
            // 修改投钞点数为 biginteger，支持更大的数值
            ->changeColumn('machine_point', 'biginteger', [
                'null' => false,
                'signed' => true,
                'default' => 0,
                'comment' => '投钞点数',
            ])
            ->update();
    }
}
