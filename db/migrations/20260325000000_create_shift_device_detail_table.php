<?php

use Phinx\Migration\AbstractMigration;

/**
 * 创建交班设备明细表
 *
 * 用于存储每个班次中每台设备的详细统计数据
 */
class CreateShiftDeviceDetailTable extends AbstractMigration
{
    public function change()
    {
        $table = $this->table('store_shift_device_detail', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => '交班设备明细表',
        ]);

        $table
            ->addColumn('id', 'integer', [
                'null' => false,
                'signed' => false,
                'identity' => true,
                'comment' => '主键ID',
            ])
            ->addColumn('shift_record_id', 'integer', [
                'null' => false,
                'signed' => false,
                'comment' => '交班记录ID（关联 store_agent_shift_handover_record.id）',
            ])
            ->addColumn('department_id', 'integer', [
                'null' => false,
                'signed' => false,
                'comment' => '部门/渠道ID',
            ])
            ->addColumn('bind_admin_user_id', 'integer', [
                'null' => false,
                'signed' => false,
                'comment' => '绑定的管理员用户ID（代理/店家）',
            ])
            ->addColumn('player_id', 'integer', [
                'null' => false,
                'signed' => false,
                'comment' => '设备ID（玩家ID）',
            ])
            ->addColumn('player_name', 'string', [
                'null' => true,
                'limit' => 100,
                'comment' => '设备名称',
            ])
            ->addColumn('player_phone', 'string', [
                'null' => true,
                'limit' => 50,
                'comment' => '设备编号',
            ])
            // 统计数据
            ->addColumn('machine_point', 'integer', [
                'null' => false,
                'signed' => false,
                'default' => 0,
                'comment' => '投钞点数',
            ])
            ->addColumn('recharge_amount', 'decimal', [
                'null' => false,
                'precision' => 15,
                'scale' => 2,
                'default' => 0,
                'comment' => '开分金额（TYPE_RECHARGE）',
            ])
            ->addColumn('withdrawal_amount', 'decimal', [
                'null' => false,
                'precision' => 15,
                'scale' => 2,
                'default' => 0,
                'comment' => '洗分金额（TYPE_WITHDRAWAL）',
            ])
            ->addColumn('modified_add_amount', 'decimal', [
                'null' => false,
                'precision' => 15,
                'scale' => 2,
                'default' => 0,
                'comment' => '后台加点金额（TYPE_MODIFIED_AMOUNT_ADD）',
            ])
            ->addColumn('modified_deduct_amount', 'decimal', [
                'null' => false,
                'precision' => 15,
                'scale' => 2,
                'default' => 0,
                'comment' => '后台扣点金额（TYPE_MODIFIED_AMOUNT_DEDUCT）',
            ])
            ->addColumn('lottery_amount', 'decimal', [
                'null' => false,
                'precision' => 15,
                'scale' => 2,
                'default' => 0,
                'comment' => '彩金发放金额（TYPE_LOTTERY）',
            ])
            ->addColumn('total_in', 'decimal', [
                'null' => false,
                'precision' => 15,
                'scale' => 2,
                'default' => 0,
                'comment' => '总收入（开分 + 后台加点）',
            ])
            ->addColumn('total_out', 'decimal', [
                'null' => false,
                'precision' => 15,
                'scale' => 2,
                'default' => 0,
                'comment' => '总支出（洗分 + 后台扣点）',
            ])
            ->addColumn('profit', 'decimal', [
                'null' => false,
                'precision' => 15,
                'scale' => 2,
                'default' => 0,
                'comment' => '利润（投钞 + 总收入 - 总支出 - 彩金）',
            ])
            ->addColumn('created_at', 'datetime', [
                'null' => false,
                'default' => 'CURRENT_TIMESTAMP',
                'comment' => '创建时间',
            ])
            ->addColumn('updated_at', 'datetime', [
                'null' => false,
                'default' => 'CURRENT_TIMESTAMP',
                'update' => 'CURRENT_TIMESTAMP',
                'comment' => '更新时间',
            ])
            // 索引
            ->addIndex(['shift_record_id'], [
                'name' => 'idx_shift_record',
            ])
            ->addIndex(['player_id'], [
                'name' => 'idx_player',
            ])
            ->addIndex(['department_id'], [
                'name' => 'idx_department',
            ])
            ->addIndex(['bind_admin_user_id'], [
                'name' => 'idx_bind_admin',
            ])
            ->addIndex(['shift_record_id', 'player_id'], [
                'unique' => true,
                'name' => 'uk_shift_player',
            ])
            ->create();
    }
}
