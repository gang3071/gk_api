<?php

use Phinx\Migration\AbstractMigration;

/**
 * 创建摸奖券中奖记录表
 *
 * @date 2026-06-02
 */
class CreateLotteryTicketRecordTable extends AbstractMigration
{
    /**
     * Change Method.
     */
    public function change()
    {
        $table = $this->table('lottery_ticket_record', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => '摸奖券中奖记录表',
        ]);

        $table
            ->addColumn('id', 'integer', [
                'null' => false,
                'signed' => false,
                'identity' => true,
                'comment' => '主键ID',
            ])
            ->addColumn('activity_id', 'integer', [
                'null' => false,
                'signed' => false,
                'comment' => '活动ID',
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
            ->addColumn('ticket_id', 'integer', [
                'null' => false,
                'signed' => false,
                'comment' => '使用的摸奖券ID',
            ])
            ->addColumn('ticket_no', 'string', [
                'limit' => 50,
                'null' => false,
                'comment' => '摸奖券编号',
            ])
            ->addColumn('prize_type', 'string', [
                'limit' => 50,
                'null' => false,
                'comment' => '奖品类型(cash:现金,bonus:红利,item:实物,empty:未中奖)',
            ])
            ->addColumn('prize_name', 'string', [
                'limit' => 100,
                'null' => true,
                'comment' => '奖品名称',
            ])
            ->addColumn('prize_amount', 'decimal', [
                'precision' => 10,
                'scale' => 2,
                'null' => true,
                'default' => 0.00,
                'comment' => '奖品金额(现金/红利类型)',
            ])
            ->addColumn('status', 'integer', [
                'limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_TINY,
                'null' => false,
                'default' => 0,
                'comment' => '状态(0:待发放,1:已发放,2:发放失败)',
            ])
            ->addColumn('remark', 'string', [
                'limit' => 255,
                'null' => true,
                'comment' => '备注',
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
            ->addIndex(['activity_id'], ['name' => 'idx_activity_id'])
            ->addIndex(['player_id'], ['name' => 'idx_player_id'])
            ->addIndex(['department_id'], ['name' => 'idx_department_id'])
            ->addIndex(['ticket_id'], ['name' => 'idx_ticket_id'])
            ->addIndex(['prize_type'], ['name' => 'idx_prize_type'])
            ->addIndex(['status'], ['name' => 'idx_status'])
            ->addIndex(['created_at'], ['name' => 'idx_created_at'])
            ->create();
    }
}
