<?php

use Phinx\Migration\AbstractMigration;

/**
 * 创建摸奖券VIP等级配置表
 * 配置每个VIP等级对应的打码量和发放券数量
 *
 * @date 2026-06-08
 */
class CreateLotteryTicketVipConfigTable extends AbstractMigration
{
    /**
     * Change Method.
     */
    public function change()
    {
        $table = $this->table('lottery_ticket_vip_config', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => '摸奖券VIP等级配置表',
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
            ->addColumn('vip_level_id', 'integer', [
                'null' => false,
                'signed' => false,
                'comment' => 'VIP等级ID',
            ])
            ->addColumn('bet_amount_required', 'decimal', [
                'precision' => 15,
                'scale' => 2,
                'null' => false,
                'default' => 0.00,
                'comment' => '所需打码量',
            ])
            ->addColumn('ticket_count', 'integer', [
                'null' => false,
                'signed' => false,
                'default' => 1,
                'comment' => '发放摸奖券数量',
            ])
            ->addColumn('status', 'integer', [
                'limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_TINY,
                'null' => false,
                'default' => 1,
                'comment' => '状态(0:禁用,1:启用)',
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
            ->addIndex(['vip_level_id'], ['name' => 'idx_vip_level_id'])
            ->addIndex(['activity_id', 'vip_level_id'], ['unique' => true, 'name' => 'idx_activity_vip_unique'])
            ->addIndex(['status'], ['name' => 'idx_status'])
            ->create();
    }
}
