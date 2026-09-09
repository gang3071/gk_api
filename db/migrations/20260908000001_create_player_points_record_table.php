<?php

use Phinx\Migration\AbstractMigration;

/**
 * 创建玩家积分变动记录表
 */
class CreatePlayerPointsRecordTable extends AbstractMigration
{
    public function change()
    {
        $table = $this->table('player_points_record', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => '玩家积分变动记录表',
        ]);

        $table
            ->addColumn('id', 'biginteger', [
                'null' => false,
                'signed' => false,
                'identity' => true,
                'comment' => '主键ID',
            ])
            ->addColumn('player_id', 'integer', [
                'null' => false,
                'signed' => false,
                'comment' => '玩家ID',
            ])
            ->addColumn('department_id', 'integer', [
                'null' => false,
                'signed' => false,
                'comment' => '部门/渠道ID',
            ])
            ->addColumn('type', 'boolean', [
                'null' => false,
                'comment' => '变动类型（1=打码汇总，2=兑换消耗，3=过期扣除，4=后台调整，5=活动奖励，6=订单退款）',
            ])
            ->addColumn('points', 'biginteger', [
                'null' => false,
                'signed' => true,
                'comment' => '积分变动量（正数=增加，负数=减少）',
            ])
            ->addColumn('points_before', 'biginteger', [
                'null' => false,
                'signed' => false,
                'comment' => '变动前积分',
            ])
            ->addColumn('points_after', 'biginteger', [
                'null' => false,
                'signed' => false,
                'comment' => '变动后积分',
            ])
            ->addColumn('source', 'string', [
                'null' => false,
                'limit' => 50,
                'comment' => '来源标识',
            ])
            ->addColumn('remark', 'string', [
                'null' => true,
                'limit' => 255,
                'default' => null,
                'comment' => '备注说明',
            ])
            ->addColumn('admin_id', 'integer', [
                'null' => true,
                'signed' => false,
                'default' => null,
                'comment' => '操作管理员ID（后台调整时记录）',
            ])
            ->addColumn('admin_name', 'string', [
                'null' => true,
                'limit' => 100,
                'default' => null,
                'comment' => '操作管理员名称',
            ])
            ->addColumn('admin_ip', 'string', [
                'null' => true,
                'limit' => 50,
                'default' => null,
                'comment' => '操作IP地址',
            ])
            ->addColumn('batch_id', 'string', [
                'null' => true,
                'limit' => 100,
                'default' => null,
                'comment' => '批次ID（用于去重）',
            ])
            ->addColumn('created_at', 'datetime', [
                'null' => false,
                'default' => 'CURRENT_TIMESTAMP',
                'comment' => '创建时间',
            ])
            ->addIndex(['player_id'], [
                'name' => 'idx_player',
            ])
            ->addIndex(['department_id'], [
                'name' => 'idx_department',
            ])
            ->addIndex(['type'], [
                'name' => 'idx_type',
            ])
            ->addIndex(['batch_id'], [
                'unique' => true,
                'name' => 'uk_batch_id',
            ])
            ->addIndex(['created_at'], [
                'name' => 'idx_created_at',
            ])
            ->create();
    }
}
