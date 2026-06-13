<?php

use Phinx\Migration\AbstractMigration;

class LotteryActivityStatusAndFields extends AbstractMigration
{
    /**
     * 摸奖券活动表新增字段
     * 支持开奖和发放分离流程
     */
    public function change()
    {
        $table = $this->table('lottery_ticket_activity');

        // 检查表是否存在
        if (!$table->exists()) {
            $this->output->writeln('<error>表 lottery_ticket_activity 不存在</error>');
            return;
        }

        // 新增字段：开奖完成时间
        if (!$table->hasColumn('draw_completed_at')) {
            $table->addColumn('draw_completed_at', 'datetime', [
                'null' => true,
                'comment' => '开奖完成时间',
                'after' => 'ball_result'
            ]);
        }

        // 新增字段：奖励发放完成时间
        if (!$table->hasColumn('prize_distributed_at')) {
            $table->addColumn('prize_distributed_at', 'datetime', [
                'null' => true,
                'comment' => '奖励发放完成时间',
                'after' => 'draw_completed_at'
            ]);
        }

        // 新增字段：总奖金金额
        if (!$table->hasColumn('total_prize_amount')) {
            $table->addColumn('total_prize_amount', 'decimal', [
                'precision' => 15,
                'scale' => 2,
                'default' => 0.00,
                'comment' => '总奖金金额',
                'after' => 'prize_distributed_at'
            ]);
        }

        // 新增字段：已发放奖金金额
        if (!$table->hasColumn('distributed_prize_amount')) {
            $table->addColumn('distributed_prize_amount', 'decimal', [
                'precision' => 15,
                'scale' => 2,
                'default' => 0.00,
                'comment' => '已发放奖金金额',
                'after' => 'total_prize_amount'
            ]);
        }

        $table->update();

        $this->output->writeln('<info>活动表字段新增完成</info>');
        $this->output->writeln('<comment>注意: status字段需新增值7 (DRAWN 已开奖待发放)</comment>');
        $this->output->writeln('<comment>需在Model中定义常量: const STATUS_DRAWN = 7;</comment>');
    }
}
