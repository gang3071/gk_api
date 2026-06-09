<?php

use Phinx\Migration\AbstractMigration;

/**
 * 创建摸奖券打码进度表
 * 用于追踪玩家在活动期间的打码进度和自动发券
 *
 * @date 2026-06-09
 */
class CreateLotteryTicketBetProgress extends AbstractMigration
{
    /**
     * Migrate Up.
     */
    public function up()
    {
        $table = $this->table('lottery_ticket_bet_progress', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => '摸奖券打码进度表'
        ]);

        $table->addColumn('id', 'integer', [
                'identity' => true,
                'signed' => false,
                'comment' => '主键ID'
            ])
            ->addColumn('activity_id', 'integer', [
                'signed' => false,
                'comment' => '活动ID'
            ])
            ->addColumn('player_id', 'integer', [
                'signed' => false,
                'comment' => '玩家ID'
            ])
            ->addColumn('department_id', 'integer', [
                'signed' => false,
                'comment' => '所属渠道部门ID'
            ])
            ->addColumn('vip_level_id', 'integer', [
                'signed' => false,
                'comment' => 'VIP等级ID'
            ])
            ->addColumn('bet_amount_required', 'decimal', [
                'precision' => 15,
                'scale' => 2,
                'default' => '0.00',
                'comment' => '基础打码量要求（从VIP配置读取）'
            ])
            ->addColumn('current_bet_amount', 'decimal', [
                'precision' => 15,
                'scale' => 2,
                'default' => '0.00',
                'comment' => '当前累计打码量'
            ])
            ->addColumn('ticket_count_per_cycle', 'integer', [
                'signed' => false,
                'default' => 1,
                'comment' => '每次达标发放的券数'
            ])
            ->addColumn('cycles_completed', 'integer', [
                'signed' => false,
                'default' => 0,
                'comment' => '已完成的周期数（达标次数）'
            ])
            ->addColumn('total_tickets_issued', 'integer', [
                'signed' => false,
                'default' => 0,
                'comment' => '总共已发放的券数'
            ])
            ->addColumn('last_issued_at', 'datetime', [
                'null' => true,
                'comment' => '最后发券时间'
            ])
            ->addColumn('status', 'integer', [
                'signed' => false,
                'default' => 1,
                'limit' => 1,
                'comment' => '状态(0:已结束,1:进行中)'
            ])
            ->addColumn('created_at', 'datetime', [
                'null' => true,
                'comment' => '创建时间'
            ])
            ->addColumn('updated_at', 'datetime', [
                'null' => true,
                'comment' => '更新时间'
            ])
            ->addIndex(['activity_id'], ['name' => 'idx_activity_id'])
            ->addIndex(['player_id'], ['name' => 'idx_player_id'])
            ->addIndex(['department_id'], ['name' => 'idx_department_id'])
            ->addIndex(['activity_id', 'player_id'], ['name' => 'idx_activity_player', 'unique' => true])
            ->addIndex(['status'], ['name' => 'idx_status'])
            ->create();
    }

    /**
     * Migrate Down.
     */
    public function down()
    {
        $this->table('lottery_ticket_bet_progress')->drop()->save();
    }
}
