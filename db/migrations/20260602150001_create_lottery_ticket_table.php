<?php

use Phinx\Migration\AbstractMigration;

/**
 * 创建摸奖券表
 *
 * @date 2026-06-02
 */
class CreateLotteryTicketTable extends AbstractMigration
{
    /**
     * Change Method.
     */
    public function change()
    {
        $table = $this->table('lottery_ticket', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => '摸奖券表',
        ]);

        $table
            ->addColumn('id', 'integer', [
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
                'comment' => '所属渠道部门ID',
            ])
            ->addColumn('activity_id', 'integer', [
                'null' => false,
                'signed' => false,
                'comment' => '所属活动ID',
            ])
            ->addColumn('ticket_no', 'string', [
                'limit' => 50,
                'null' => false,
                'comment' => '摸奖券编号(唯一)',
            ])
            ->addColumn('status', 'integer', [
                'limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_TINY,
                'null' => false,
                'default' => 0,
                'comment' => '状态(0:未使用,1:已使用,2:已过期)',
            ])
            ->addColumn('source', 'string', [
                'limit' => 50,
                'null' => true,
                'comment' => '来源(recharge:充值赠送,activity:活动赠送,manual:手动发放)',
            ])
            ->addColumn('source_id', 'integer', [
                'null' => true,
                'signed' => false,
                'comment' => '来源记录ID',
            ])
            ->addColumn('used_at', 'datetime', [
                'null' => true,
                'comment' => '使用时间',
            ])
            ->addColumn('expired_at', 'datetime', [
                'null' => true,
                'comment' => '过期时间',
            ])
            ->addColumn('created_at', 'timestamp', [
                'default' => 'CURRENT_TIMESTAMP',
                'comment' => '创建时间',
            ])
            ->addColumn('updated_at', 'timestamp', [
                'default' => 'CURRENT_TIMESTAMP',
                'update' => 'CURRENT_TIMESTAMP',
                'comment' => '更新时间',
            ])
            ->addIndex(['ticket_no'], ['unique' => true, 'name' => 'idx_ticket_no_unique'])
            ->addIndex(['player_id'], ['name' => 'idx_player_id'])
            ->addIndex(['department_id'], ['name' => 'idx_department_id'])
            ->addIndex(['activity_id'], ['name' => 'idx_activity_id'])
            ->addIndex(['status'], ['name' => 'idx_status'])
            ->create();
    }
}
