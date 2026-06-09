<?php

use Phinx\Migration\AbstractMigration;

/**
 * 为摸奖券奖品等级表添加奖品数量字段
 *
 * @date 2026-06-09
 */
class AddPrizeCountToLotteryTicketPrizeLevel extends AbstractMigration
{
    /**
     * Migrate Up.
     */
    public function up()
    {
        $table = $this->table('lottery_ticket_prize_level');

        // 检查字段是否已存在
        if (!$table->hasColumn('prize_count')) {
            $table->addColumn('prize_count', 'integer', [
                'default' => 0,
                'null' => false,
                'comment' => '奖品数量',
                'after' => 'prize_amount'
            ])->update();
        }
    }

    /**
     * Migrate Down.
     */
    public function down()
    {
        $table = $this->table('lottery_ticket_prize_level');

        if ($table->hasColumn('prize_count')) {
            $table->removeColumn('prize_count')->update();
        }
    }
}
