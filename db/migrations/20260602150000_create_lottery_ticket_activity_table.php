<?php

use Phinx\Migration\AbstractMigration;

/**
 * 创建摸奖券活动表
 *
 * @date 2026-06-02
 */
class CreateLotteryTicketActivityTable extends AbstractMigration
{
    /**
     * Change Method.
     */
    public function change()
    {
        $table = $this->table('lottery_ticket_activity', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => '摸奖券活动表',
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
                'comment' => '所属渠道部门ID',
            ])
            ->addColumn('name', 'string', [
                'limit' => 100,
                'null' => false,
                'comment' => '活动名称',
            ])
            ->addColumn('description', 'text', [
                'null' => true,
                'comment' => '活动说明',
            ])
            ->addColumn('start_time', 'datetime', [
                'null' => false,
                'comment' => '活动开始时间',
            ])
            ->addColumn('end_time', 'datetime', [
                'null' => false,
                'comment' => '活动结束时间',
            ])
            ->addColumn('status', 'integer', [
                'limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_TINY,
                'null' => false,
                'default' => 0,
                'comment' => '活动状态(0:未开始,1:进行中,2:已结束,3:已关闭)',
            ])
            ->addColumn('total_tickets', 'integer', [
                'null' => false,
                'signed' => false,
                'default' => 0,
                'comment' => '总发放摸奖券数量',
            ])
            ->addColumn('used_tickets', 'integer', [
                'null' => false,
                'signed' => false,
                'default' => 0,
                'comment' => '已使用摸奖券数量',
            ])
            ->addColumn('prize_config', 'text', [
                'null' => true,
                'comment' => '奖品配置(JSON格式)',
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
            ->addColumn('deleted_at', 'timestamp', [
                'null' => true,
                'comment' => '删除时间',
            ])
            ->addIndex(['department_id'], ['name' => 'idx_department_id'])
            ->addIndex(['status'], ['name' => 'idx_status'])
            ->addIndex(['start_time', 'end_time'], ['name' => 'idx_time_range'])
            ->create();
    }
}
