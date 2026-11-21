<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * player_lottery_record 增加 win_condition 字段
 *
 * 记录固定彩金派发时的触发条件（机台积分/打分），便于事后核查
 * 中奖时玩家的分数是否符合彩金档位要求。
 *
 * win_condition — 中彩金时的触发条件值（Slot=洗分时 card_score；钢珠=打分）
 */
final class AddWinConditionToPlayerLotteryRecord extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('player_lottery_record');

        if (!$table->hasColumn('win_condition')) {
            $table->addColumn('win_condition', 'integer', [
                'null'    => false,
                'default' => 0,
                'comment' => '中彩金时触发条件值（Slot=card_score；钢珠=打分）',
                'after'   => 'lottery_sort',
            ]);
        }

        $table->update();
    }
}