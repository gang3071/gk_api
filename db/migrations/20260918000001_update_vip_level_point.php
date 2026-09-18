<?php

use Phinx\Migration\AbstractMigration;

/**
 * 更新VIP等級積分表
 */
class UpdateVipLevelPoint extends AbstractMigration
{
    public function up()
    {
        // ---------------------------------------- 調整欄位 ----------------------------------------
        $table = $this->table('vip_level_point');

        if ($table->hasColumn('ratio_bet_amount')) {
            $table->removeColumn('ratio_bet_amount');
        }

        if ($table->hasColumn('min_bet_amount')) {
            $table->removeColumn('min_bet_amount');
        }

        $table->changeColumn('ratio_point', 'decimal', [
            'precision' => 16,
            'scale' => 5,
            'null' => false,
            'default' => 0.0667,  // 1 ÷ 1500 ≒ 0.0006666667
            'after' => 'status',
            'comment' => '比例 (%)'
        ]);

        $table->update();
        // ---------------------------------------- 調整資料 ----------------------------------------
        // VIP 1 & VIP 2
        $this->execute("UPDATE `vip_level_point` SET `ratio_point` = 0.0667, `updated_at` = NOW() WHERE `vip_level_id` = 7 OR `vip_level_id` = 8");
        // VIP 3 & VIP 4
        $this->execute("UPDATE `vip_level_point` SET `ratio_point` = 0.1334, `updated_at` = NOW() WHERE `vip_level_id` = 9 OR `vip_level_id` = 10");
        // VIP 5 & VIP 6
        $this->execute("UPDATE `vip_level_point` SET `ratio_point` = 0.2001, `updated_at` = NOW() WHERE `vip_level_id` = 11 OR `vip_level_id` = 12");
        // VIP 7 & VIP 8
        $this->execute("UPDATE `vip_level_point` SET `ratio_point` = 0.2668, `updated_at` = NOW() WHERE `vip_level_id` = 13 OR `vip_level_id` = 14");
        // VIP 9 & VIP 10
        $this->execute("UPDATE `vip_level_point` SET `ratio_point` = 0.3335, `updated_at` = NOW() WHERE `vip_level_id` = 15 OR `vip_level_id` = 16");
    }

    public function down()
    {
        $table = $this->table('vip_level_point');

        $table->changeColumn('ratio_point', 'decimal', [
            'precision' => 16,
            'scale' => 2,
            'null' => false,
            'default' => 1,
            'after' => 'status',
            'comment' => '比例-積分'
        ])->addColumn('ratio_bet_amount', 'decimal', [
            'precision' => 16,
            'scale' => 2,
            'null' => false,
            'default' => 1500,
            'after' => 'ratio_point',
            'comment' => '比例-打碼量'
        ])->addColumn('min_bet_amount', 'decimal', [
            'precision' => 16,
            'scale' => 2,
            'null' => false,
            'default' => 1,
            'after' => 'ratio_bet_amount',
            'comment' => '有效最小打碼量'
        ]);

        $table->update();

        $this->execute("UPDATE `vip_level_point` SET `ratio_point` = 1, `updated_at` = NOW()");
    }
}
