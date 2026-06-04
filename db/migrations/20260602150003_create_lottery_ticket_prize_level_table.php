<?php

use Phinx\Migration\AbstractMigration;

/**
 * 创建摸奖券中奖等级配置表
 *
 * @date 2026-06-02
 */
class CreateLotteryTicketPrizeLevelTable extends AbstractMigration
{
    /**
     * Change Method.
     */
    public function change()
    {
        $table = $this->table('lottery_ticket_prize_level', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => '摸奖券中奖等级配置表',
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
            ->addColumn('level_rank', 'integer', [
                'null' => false,
                'signed' => false,
                'comment' => '等级排名(1:特等奖,2:一等奖...最多10级)',
            ])
            ->addColumn('level_name', 'string', [
                'limit' => 50,
                'null' => false,
                'comment' => '等级名称(如:特等奖,一等奖)',
            ])
            ->addColumn('prize_type', 'string', [
                'limit' => 50,
                'null' => false,
                'comment' => '奖品类型(cash:现金,bonus:红利,item:实物,points:积分)',
            ])
            ->addColumn('prize_amount', 'decimal', [
                'precision' => 15,
                'scale' => 2,
                'null' => true,
                'default' => 0.00,
                'comment' => '奖品金额(现金/红利/积分类型)',
            ])
            ->addColumn('prize_item_name', 'string', [
                'limit' => 100,
                'null' => true,
                'comment' => '实物奖品名称',
            ])
            ->addColumn('prize_item_image', 'string', [
                'limit' => 500,
                'null' => true,
                'comment' => '实物奖品图片URL',
            ])
            ->addColumn('prize_count', 'integer', [
                'null' => false,
                'signed' => false,
                'default' => 1,
                'comment' => '该等级奖品数量',
            ])
            ->addColumn('win_probability', 'decimal', [
                'precision' => 5,
                'scale' => 2,
                'null' => true,
                'default' => 0.00,
                'comment' => '中奖概率(%,如5.50表示5.5%)',
            ])
            ->addColumn('sort_order', 'integer', [
                'null' => false,
                'default' => 0,
                'comment' => '排序(数字越小越靠前)',
            ])
            ->addColumn('status', 'integer', [
                'limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_TINY,
                'null' => false,
                'default' => 1,
                'comment' => '状态(0:禁用,1:启用)',
            ])
            ->addColumn('description', 'text', [
                'null' => true,
                'comment' => '奖品描述',
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
            ->addIndex(['activity_id', 'level_rank'], ['unique' => true, 'name' => 'idx_activity_level_unique'])
            ->addIndex(['status'], ['name' => 'idx_status'])
            ->addIndex(['sort_order'], ['name' => 'idx_sort_order'])
            ->create();
    }
}
