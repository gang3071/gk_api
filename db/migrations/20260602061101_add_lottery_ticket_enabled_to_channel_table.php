<?php

use Phinx\Migration\AbstractMigration;

/**
 * 添加摸奖券功能开关字段到渠道表
 *
 * 该迁移为 channel 表添加 lottery_ticket_enabled 字段
 * 用于控制渠道是否启用摸奖券功能
 *
 * @date 2026-06-02
 */
class AddLotteryTicketEnabledToChannelTable extends AbstractMigration
{
    /**
     * Change Method.
     *
     * Write your reversible migrations using this method.
     */
    public function change()
    {
        $table = $this->table('channel');

        // 检查字段是否已存在（避免重复迁移错误）
        if (!$table->hasColumn('lottery_ticket_enabled')) {
            $table->addColumn('lottery_ticket_enabled', 'integer', [
                'limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_TINY,
                'default' => 0,
                'null' => false,
                'comment' => '摸奖券功能开关(0:禁用,1:启用)',
                'after' => 'lottery_status'
            ])
            ->update();
        }
    }

    /**
     * 回滚迁移
     */
    public function down()
    {
        $table = $this->table('channel');

        if ($table->hasColumn('lottery_ticket_enabled')) {
            $table->removeColumn('lottery_ticket_enabled')
                  ->update();
        }
    }
}
