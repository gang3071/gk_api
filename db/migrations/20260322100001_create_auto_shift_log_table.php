<?php

use Phinx\Migration\AbstractMigration;

/**
 * 创建自动交班执行日志表
 */
class CreateAutoShiftLogTable extends AbstractMigration
{
    /**
     * Change Method.
     *
     * Write your reversible migrations using this method.
     */
    public function change()
    {
        $table = $this->table('store_auto_shift_log', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => '自动交班执行日志表',
        ]);

        $table
            ->addColumn('id', 'biginteger', [
                'null' => false,
                'signed' => false,
                'identity' => true,
                'comment' => '主键ID',
            ])
            ->addColumn('config_id', 'integer', [
                'null' => false,
                'signed' => false,
                'comment' => '配置ID（关联 store_auto_shift_config.id）',
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
            ->addColumn('shift_record_id', 'biginteger', [
                'null' => true,
                'signed' => false,
                'default' => null,
                'comment' => '交班记录ID（关联 store_agent_shift_handover_record.id）',
            ])
            ->addColumn('start_time', 'datetime', [
                'null' => false,
                'comment' => '统计开始时间',
            ])
            ->addColumn('end_time', 'datetime', [
                'null' => false,
                'comment' => '统计结束时间',
            ])
            ->addColumn('execute_time', 'datetime', [
                'null' => false,
                'comment' => '执行时间（交班任务实际执行的时间）',
            ])
            ->addColumn('status', 'boolean', [
                'null' => false,
                'default' => 1,
                'comment' => '执行状态（1=成功，2=失败，3=部分成功）',
            ])
            ->addColumn('error_message', 'text', [
                'null' => true,
                'default' => null,
                'comment' => '错误信息（失败时记录）',
            ])
            ->addColumn('execution_duration', 'integer', [
                'null' => true,
                'signed' => false,
                'default' => 0,
                'comment' => '执行耗时（单位：毫秒）',
            ])
            ->addColumn('machine_amount', 'decimal', [
                'null' => false,
                'precision' => 10,
                'scale' => 2,
                'default' => '0.00',
                'comment' => '投钞金额（纸币金额）',
            ])
            ->addColumn('machine_point', 'integer', [
                'null' => false,
                'signed' => true,
                'default' => 0,
                'comment' => '投钞点数',
            ])
            ->addColumn('total_in', 'decimal', [
                'null' => false,
                'precision' => 10,
                'scale' => 2,
                'default' => '0.00',
                'comment' => '总收入（送分金额）',
            ])
            ->addColumn('total_out', 'decimal', [
                'null' => false,
                'precision' => 10,
                'scale' => 2,
                'default' => '0.00',
                'comment' => '总支出（取分金额）',
            ])
            ->addColumn('lottery_amount', 'decimal', [
                'null' => false,
                'precision' => 10,
                'scale' => 2,
                'default' => '0.00',
                'comment' => '彩金发放金额（TYPE_LOTTERY=13）',
            ])
            ->addColumn('total_profit', 'decimal', [
                'null' => false,
                'precision' => 10,
                'scale' => 2,
                'default' => '0.00',
                'comment' => '总利润（总收入 - 总支出）',
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
            ->addIndex(['config_id'], [
                'name' => 'idx_config',
            ])
            ->addIndex(['department_id'], [
                'name' => 'idx_department',
            ])
            ->addIndex(['bind_admin_user_id'], [
                'name' => 'idx_bind_admin',
            ])
            ->addIndex(['shift_record_id'], [
                'name' => 'idx_shift_record',
            ])
            ->addIndex(['execute_time'], [
                'name' => 'idx_execute_time',
            ])
            ->addIndex(['status'], [
                'name' => 'idx_status',
            ])
            ->addIndex(['created_at'], [
                'name' => 'idx_created_at',
            ])
            ->create();
    }
}
