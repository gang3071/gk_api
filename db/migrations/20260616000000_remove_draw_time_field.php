<?php

use Phinx\Migration\AbstractMigration;

/**
 * 删除 lottery_ticket_activity 表的 draw_time 字段
 *
 * 原因：
 * - draw_time 自动流转不符合实际业务需求
 * - 线下摸奖活动需要管理员手动控制开奖时机
 * - 新增手动"开奖"按钮，由管理员主动触发进入开奖阶段
 *
 * 影响：
 * - 删除 draw_time 字段
 * - 删除 preheat_start_time 字段（预热期概念已废弃）
 */
class RemoveDrawTimeField extends AbstractMigration
{
    /**
     * 执行迁移
     */
    public function up()
    {
        $table = $this->table('lottery_ticket_activity');

        // 检查字段是否存在再删除
        if ($table->hasColumn('draw_time')) {
            $table->removeColumn('draw_time');
        }

        if ($table->hasColumn('preheat_start_time')) {
            $table->removeColumn('preheat_start_time');
        }

        $table->save();

        $this->output->writeln('<info>已删除 draw_time 和 preheat_start_time 字段</info>');
    }

    /**
     * 回滚迁移
     */
    public function down()
    {
        $table = $this->table('lottery_ticket_activity');

        // 恢复字段
        $table->addColumn('draw_time', 'datetime', [
            'null' => true,
            'comment' => '开奖时间（已废弃）',
            'after' => 'end_time',
        ]);

        $table->addColumn('preheat_start_time', 'datetime', [
            'null' => true,
            'comment' => '预热开始时间（已废弃）',
            'after' => 'end_time',
        ]);

        $table->save();

        $this->output->writeln('<info>已恢复 draw_time 和 preheat_start_time 字段</info>');
    }
}
