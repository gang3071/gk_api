<?php

use Phinx\Migration\AbstractMigration;

/**
 * 为摸奖券活动表添加封面图片字段
 *
 * @date 2026-06-09
 */
class AddCoverImageToLotteryTicketActivity extends AbstractMigration
{
    /**
     * Migrate Up.
     */
    public function up()
    {
        $table = $this->table('lottery_ticket_activity');

        // 检查字段是否已存在
        if (!$table->hasColumn('cover_image')) {
            $table->addColumn('cover_image', 'string', [
                'limit' => 255,
                'null' => true,
                'comment' => '活动封面图片URL',
                'after' => 'description'
            ])->update();
        }
    }

    /**
     * Migrate Down.
     */
    public function down()
    {
        $table = $this->table('lottery_ticket_activity');

        if ($table->hasColumn('cover_image')) {
            $table->removeColumn('cover_image')->update();
        }
    }
}
