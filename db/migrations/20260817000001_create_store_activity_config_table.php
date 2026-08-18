<?php

use Phinx\Migration\AbstractMigration;

/**
 * 创建店机活动配置表
 * 用于配置福利券和体验券的店机级别活动参数
 */
class CreateStoreActivityConfigTable extends AbstractMigration
{
    /**
     * Change Method.
     */
    public function change()
    {
        $table = $this->table('store_activity_config', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => '店机活动配置表（福利券/体验券）',
        ]);

        $table
            // 基础字段
            ->addColumn('id', 'integer', [
                'null' => false,
                'signed' => false,
                'identity' => true,
                'comment' => '主键ID',
            ])
            ->addColumn('admin_user_id', 'integer', [
                'null' => false,
                'signed' => false,
                'comment' => '店机管理员用户ID',
            ])
            ->addColumn('department_id', 'integer', [
                'null' => false,
                'signed' => false,
                'comment' => '部门/渠道ID',
            ])
            // 活动基本信息
            ->addColumn('start_time', 'datetime', [
                'null' => true,
                'default' => null,
                'comment' => '活动开始时间',
            ])
            ->addColumn('end_time', 'datetime', [
                'null' => true,
                'default' => null,
                'comment' => '活动结束时间',
            ])
            ->addColumn('status', 'boolean', [
                'null' => false,
                'default' => 1,
                'comment' => '状态（0=禁用，1=启用）',
            ])
            // 活动时间配置
            ->addColumn('activity_end_time', 'datetime', [
                'null' => true,
                'default' => null,
                'comment' => '券发放结束时间（到达后暂停发放）',
            ])
            // 体验券配置
            ->addColumn('experience_enabled', 'boolean', [
                'null' => false,
                'default' => 1,
                'comment' => '是否启用体验券',
            ])
            ->addColumn('experience_register_after', 'datetime', [
                'null' => true,
                'default' => null,
                'comment' => '新用户注册时间阈值',
            ])
            ->addColumn('experience_daily_limit', 'integer', [
                'null' => false,
                'signed' => false,
                'default' => 1,
                'comment' => '体验券每天可领取次数',
            ])
            ->addColumn('experience_total_limit', 'integer', [
                'null' => false,
                'signed' => false,
                'default' => 6,
                'comment' => '体验券总可领取次数',
            ])
            ->addColumn('experience_score', 'integer', [
                'null' => false,
                'signed' => false,
                'default' => 1000,
                'comment' => '体验券每次领取分数',
            ])
            ->addColumn('experience_expire_hours', 'integer', [
                'null' => false,
                'signed' => false,
                'default' => 24,
                'comment' => '体验券有效时间（小时）',
            ])
            // 福利券配置
            ->addColumn('welfare_enabled', 'boolean', [
                'null' => false,
                'default' => 1,
                'comment' => '是否启用福利券',
            ])
            ->addColumn('welfare_daily_limit', 'integer', [
                'null' => false,
                'signed' => false,
                'default' => 1,
                'comment' => '福利券每天可领取次数（0=不限制）',
            ])
            ->addColumn('welfare_rules', 'text', [
                'null' => true,
                'default' => null,
                'comment' => '福利券档位规则（JSON格式，包含day_type/bet_amount）',
            ])
            ->addColumn('welfare_expire_hours', 'integer', [
                'null' => false,
                'signed' => false,
                'default' => 24,
                'comment' => '福利券有效时间（小时）',
            ])
            // 订单前缀配置
            ->addColumn('order_prefix_experience', 'string', [
                'null' => false,
                'limit' => 10,
                'default' => 'TY',
                'comment' => '体验券订单号前缀',
            ])
            ->addColumn('order_prefix_welfare', 'string', [
                'null' => false,
                'limit' => 10,
                'default' => 'FL',
                'comment' => '福利券订单号前缀',
            ])
            ->addColumn('order_prefix_recharge', 'string', [
                'null' => false,
                'limit' => 10,
                'default' => 'TK',
                'comment' => '开分订单号前缀',
            ])
            ->addColumn('order_prefix_withdraw', 'string', [
                'null' => false,
                'limit' => 10,
                'default' => 'TK',
                'comment' => '洗分订单号前缀',
            ])
            // 时间戳
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
            ->addIndex(['admin_user_id', 'deleted_at'], [
                'unique' => true,
                'name' => 'uk_admin_user',
            ])
            ->addIndex(['department_id'], [
                'name' => 'idx_department',
            ])
            ->addIndex(['start_time', 'end_time'], [
                'name' => 'idx_time_range',
            ])
            ->addIndex(['status'], [
                'name' => 'idx_status',
            ])
            ->create();
    }
}
