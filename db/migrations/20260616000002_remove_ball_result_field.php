<?php

use Phinx\Migration\AbstractMigration;

/**
 * 删除 lottery_ticket_activity 表的 ball_result 字段
 *
 * 原因：
 * - ball_result 字段用于系统自动摇球，存储摇球结果（如 "1,5,8,12,33"）
 * - 实际业务是线下物理摇球机摇球，不需要系统生成球号
 * - 管理员线下摇球后，通过 recordWinByTickets() 录入中奖券号即可
 * - 保留此字段会造成逻辑混乱和误用
 *
 * 影响：
 * - 删除 ball_result 字段
 * - 相关代码已改为检查活动状态而非此字段
 */
class RemoveBallResultField extends AbstractMigration
{
    /**
     * 执行迁移
     */
    public function up()
    {
        $table = $this->table('lottery_ticket_activity');

        // 检查字段是否存在再删除
        if ($table->hasColumn('ball_result')) {
            $table->removeColumn('ball_result');
            $this->output->writeln('<info>已删除 ball_result 字段（系统自动摇球结果字段，线下摸奖不需要）</info>');
        } else {
            $this->output->writeln('<comment>ball_result 字段不存在，跳过删除</comment>');
        }

        $table->save();
    }

    /**
     * 回滚迁移
     */
    public function down()
    {
        $table = $this->table('lottery_ticket_activity');

        // 恢复字段（仅用于回滚，不建议使用）
        $table->addColumn('ball_result', 'string', [
            'limit' => 255,
            'null' => true,
            'comment' => '摇球结果（已废弃，线下摸奖不使用此字段）',
            'after' => 'live_url',
        ]);

        $table->save();

        $this->output->writeln('<info>已恢复 ball_result 字段（不建议，仅用于数据回滚）</info>');
    }
}
