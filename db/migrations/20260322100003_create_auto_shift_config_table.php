<?php

use Phinx\Migration\AbstractMigration;

/**
 * 创建自动交班配置表
 */
class CreateAutoShiftConfigTable extends AbstractMigration
{
    /**
     * Change Method.
     *
     * Write your reversible migrations using this method.
     */
    public function change()
    {
        $table = $this->table('store_auto_shift_config', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => '自动交班配置表',
        ]);

        $table
            ->addColumn('id', 'integer', [
                'null' => false,
                'signed' => false,
                'identity' => true,
                'comment' => '主键ID',
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
            ->addColumn('is_enabled', 'boolean', [
                'null' => false,
                'default' => 0,
                'comment' => '是否启用（0=未启用，1=已启用）',
            ])
            ->addColumn('shift_mode', 'boolean', [
                'null' => false,
                'default' => 1,
                'comment' => '交班模式（1=每日，2=每周，3=自定义周期）',
            ])
            ->addColumn('shift_time', 'time', [
                'null' => false,
                'default' => '02:00:00',
                'comment' => '交班时间（格式：HH:mm:ss）',
            ])
            ->addColumn('shift_weekdays', 'string', [
                'null' => true,
                'limit' => 20,
                'default' => null,
                'comment' => '每周交班日期（逗号分隔，0=周日，1=周一...6=周六）',
            ])
            ->addColumn('shift_interval_hours', 'integer', [
                'null' => true,
                'signed' => false,
                'default' => 24,
                'comment' => '自定义交班周期（单位：小时）',
            ])
            ->addColumn('auto_settlement', 'boolean', [
                'null' => false,
                'default' => 1,
                'comment' => '是否自动结算（0=否，1=是）',
            ])
            ->addColumn('notify_on_failure', 'boolean', [
                'null' => false,
                'default' => 1,
                'comment' => '失败时是否通知（0=否，1=是）',
            ])
            ->addColumn('notify_phones', 'string', [
                'null' => true,
                'limit' => 255,
                'default' => null,
                'comment' => '通知手机号（逗号分隔）',
            ])
            ->addColumn('last_shift_time', 'datetime', [
                'null' => true,
                'default' => null,
                'comment' => '上次交班时间',
            ])
            ->addColumn('next_shift_time', 'datetime', [
                'null' => true,
                'default' => null,
                'comment' => '下次交班时间（系统自动计算）',
            ])
            ->addColumn('status', 'boolean', [
                'null' => false,
                'default' => 1,
                'comment' => '状态（1=正常，2=暂停，3=异常）',
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
            ->addColumn('deleted_at', 'datetime', [
                'null' => true,
                'default' => null,
                'comment' => '软删除时间',
            ])
            // 索引
            ->addIndex(['bind_admin_user_id', 'deleted_at'], [
                'unique' => true,
                'name' => 'uk_bind_admin',
            ])
            ->addIndex(['department_id'], [
                'name' => 'idx_department',
            ])
            ->addIndex(['next_shift_time'], [
                'name' => 'idx_next_shift',
            ])
            ->addIndex(['is_enabled', 'status'], [
                'name' => 'idx_enabled_status',
            ])
            ->create();
    }
}
