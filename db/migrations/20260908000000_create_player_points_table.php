<?php

use Phinx\Migration\AbstractMigration;

/**
 * 创建玩家积分主表
 */
class CreatePlayerPointsTable extends AbstractMigration
{
    public function change()
    {
        $table = $this->table('player_points', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => '玩家积分余额表',
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
            ->addColumn('available_points', 'biginteger', [
                'null' => false,
                'signed' => false,
                'default' => 0,
                'comment' => '可用积分',
            ])
            ->addColumn('frozen_points', 'biginteger', [
                'null' => false,
                'signed' => false,
                'default' => 0,
                'comment' => '冻结积分',
            ])
            ->addColumn('total_points', 'biginteger', [
                'null' => false,
                'signed' => false,
                'default' => 0,
                'comment' => '累计获得总积分',
            ])
            ->addColumn('used_points', 'biginteger', [
                'null' => false,
                'signed' => false,
                'default' => 0,
                'comment' => '累计已用积分',
            ])
            ->addColumn('version', 'integer', [
                'null' => false,
                'signed' => false,
                'default' => 0,
                'comment' => '版本号（乐观锁）',
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
            ->addIndex(['player_id'], [
                'unique' => true,
                'name' => 'uk_player_id',
            ])
            ->addIndex(['department_id'], [
                'name' => 'idx_department',
            ])
            ->create();
    }
}
