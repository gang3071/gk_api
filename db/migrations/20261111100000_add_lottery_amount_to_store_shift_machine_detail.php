<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 交班实体机台详情表补充彩金字段
 *
 * 交班报表实体机工作表新增「彩金」列（当次班別各台拉彩累積），
 * 小計公式改为 上分 - 下分 - 彩金。
 * 彩金来源：player_lottery_record 按 machine_id + game_type 聚合，
 * status=完成(3)，game_type 识别斯洛/钢珠（机台彩金记录不设 source，不可靠）。
 */
final class AddLotteryAmountToStoreShiftMachineDetail extends AbstractMigration
{
    /**
     * Change Method.
     */
    public function change(): void
    {
        $table = $this->table('store_shift_machine_detail');

        if (!$table->hasColumn('lottery_amount')) {
            $table->addColumn('lottery_amount', 'decimal', [
                'null' => false,
                'precision' => 12,
                'scale' => 2,
                'default' => '0.00',
                'comment' => '彩金（当次班別各台拉彩累積，player_lottery_record.amount 汇总）',
                'after' => 'wash_point',
            ]);
        }

        $table->update();
    }
}
