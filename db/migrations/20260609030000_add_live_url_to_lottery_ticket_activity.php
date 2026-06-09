<?php

use Phinx\Migration\AbstractMigration;

/**
 * 为摸奖券活动表添加直播地址字段
 *
 * @date 2026-06-09
 */
class AddLiveUrlToLotteryTicketActivity extends AbstractMigration
{
    /**
     * Migrate Up.
     */
    public function up()
    {
        $table = $this->table('lottery_ticket_activity');

        // 检查字段是否已存在
        if (!$table->hasColumn('live_url')) {
            $table->addColumn('live_url', 'string', [
                'limit' => 500,
                'null' => true,
                'comment' => '直播流地址',
                'after' => 'cover_image'
            ])->update();
        }
    }

    /**
     * Migrate Down.
     */
    public function down()
    {
        $table = $this->table('lottery_ticket_activity');

        if ($table->hasColumn('live_url')) {
            $table->removeColumn('live_url')->update();
        }
    }
}
