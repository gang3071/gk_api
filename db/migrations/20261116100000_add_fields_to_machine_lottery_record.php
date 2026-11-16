<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * machine_lottery_record 补充开奖记录字段
 *
 * gk_work 在 release/machine-lettory 分支中新增了这些字段，
 * 但对应的 Phinx 迁移文件一直缺失，导致生产库 INSERT/SELECT 报
 * "Unknown column" 错误，并连带阻断了钢珠下珠指令。
 *
 * 字段说明：
 *   odds_x     — 机台比值分子（开奖时快照，避免事后改比值影响历史）
 *   odds_y     — 机台比值分母
 *   lottery_win — 中奖得分（钢珠=中奖珠数；Slot=开奖期间净得分）
 *   lottery_bet — Slot 开奖期间总压分变化量（开奖结束时 bet - start_bet）
 *   has_rush   — 是否为 rush 确变状态触发的开奖
 */
final class AddFieldsToMachineLotteryRecord extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('machine_lottery_record');

        if (!$table->hasColumn('odds_x')) {
            $table->addColumn('odds_x', 'integer', [
                'null'    => false,
                'default' => 1,
                'comment' => '机台比值分子',
                'after'   => 'use_turn',
            ]);
        }

        if (!$table->hasColumn('odds_y')) {
            $table->addColumn('odds_y', 'integer', [
                'null'    => false,
                'default' => 1,
                'comment' => '机台比值分母',
                'after'   => 'odds_x',
            ]);
        }

        if (!$table->hasColumn('lottery_win')) {
            $table->addColumn('lottery_win', 'integer', [
                'null'    => true,
                'default' => null,
                'comment' => '中奖得分（钢珠=珠数；Slot=开奖期间净得分）',
                'after'   => 'odds_y',
            ]);
        }

        if (!$table->hasColumn('lottery_bet')) {
            $table->addColumn('lottery_bet', 'integer', [
                'null'    => true,
                'default' => null,
                'comment' => 'Slot开奖期间总压分变化量',
                'after'   => 'lottery_win',
            ]);
        }

        if (!$table->hasColumn('has_rush')) {
            $table->addColumn('has_rush', 'boolean', [
                'null'    => false,
                'default' => false,
                'comment' => '是否为 rush 确变触发的开奖',
                'after'   => 'lottery_bet',
            ]);
        }

        $table->update();
    }
}
