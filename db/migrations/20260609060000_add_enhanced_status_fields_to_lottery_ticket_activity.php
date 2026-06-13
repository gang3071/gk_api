<?php

use Phinx\Migration\AbstractMigration;

class AddEnhancedStatusFieldsToLotteryTicketActivity extends AbstractMigration
{
    /**
     * 增加活动状态字段和开奖时间字段
     *
     * 扩展状态:
     * - 0: 未开始
     * - 1: 进行中
     * - 2: 已结束
     * - 3: 已关闭
     * - 4: 预热期 (新增)
     * - 5: 打码中 (新增)
     * - 6: 开奖中 (新增)
     *
     * 直播状态:
     * - 0: 未开播
     * - 1: 直播中
     * - 2: 已结束
     */
    public function change()
    {
        $table = $this->table('lottery_ticket_activity');

        // 添加开奖时间
        if (!$table->hasColumn('draw_time')) {
            $table->addColumn('draw_time', 'datetime', [
                'null' => true,
                'comment' => '开奖时间',
                'after' => 'end_time'
            ])->update();
        }

        // 添加直播状态
        if (!$table->hasColumn('live_status')) {
            $table->addColumn('live_status', 'integer', [
                'limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_TINY,
                'default' => 0,
                'null' => false,
                'comment' => '直播状态 0=未开播 1=直播中 2=已结束',
                'after' => 'live_url'
            ])->update();
        }

        // 添加预热开始时间（活动开始前N天开始预热）
        if (!$table->hasColumn('preheat_start_time')) {
            $table->addColumn('preheat_start_time', 'datetime', [
                'null' => true,
                'comment' => '预热开始时间',
                'after' => 'draw_time'
            ])->update();
        }

        // 添加是否自动开奖标识
        if (!$table->hasColumn('auto_draw')) {
            $table->addColumn('auto_draw', 'integer', [
                'limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_TINY,
                'default' => 0,
                'null' => false,
                'comment' => '是否自动开奖 0=否 1=是',
                'after' => 'draw_time'
            ])->update();
        }

        // 添加状态变更记录（JSON格式记录状态变更历史）
        if (!$table->hasColumn('status_history')) {
            $table->addColumn('status_history', 'text', [
                'null' => true,
                'comment' => '状态变更历史(JSON)',
                'after' => 'live_status'
            ])->update();
        }
    }
}
