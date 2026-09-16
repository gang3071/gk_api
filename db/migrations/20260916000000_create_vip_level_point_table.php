<?php

use Phinx\Migration\AbstractMigration;

/**
 * 建立VIP等級積分表
 */
class CreateVipLevelPointTable extends AbstractMigration
{
    public function up()
    {
        if ($this->hasTable('vip_level_point')) {
            return;
        }

        // ---------------------------------------- 複製 vip_level_cashback 表 ----------------------------------------
        $this->execute("CREATE TABLE `vip_level_point` LIKE `vip_level_cashback`");
        $this->execute("ALTER TABLE `vip_level_point` COMMENT = 'VIP等級積分比例表'");
        $this->execute("INSERT INTO `vip_level_point` SELECT * FROM `vip_level_cashback`");
        // ---------------------------------------- 調整欄位 ----------------------------------------
        $table = $this->table('vip_level_point');

        if ($table->hasIndexByName('idx_vip_level_id')) {
            $table->removeIndexByName('idx_vip_level_id');
        }

        if ($table->hasIndexByName('idx_platform_id')) {
            $table->removeIndexByName('idx_platform_id');
        }

        if ($table->hasColumn('cashback_ratio')) {
            $table->removeColumn('cashback_ratio');
        }

        $table->changeColumn('status', 'tinyinteger', [
            'null' => false,
            'default' => 1,
            'comment' => '0=停用 1=啟用'
        ])->addColumn('ratio_point', 'decimal', [
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
        // ---------------------------------------- 調整資料 ----------------------------------------
        // VIP 3 & VIP 4
        $this->execute("UPDATE `vip_level_point` SET `ratio_point` = 2, `updated_at` = NOW() WHERE `vip_level_id` = 9 OR `vip_level_id` = 10");
        // VIP 5 & VIP 6
        $this->execute("UPDATE `vip_level_point` SET `ratio_point` = 3, `updated_at` = NOW() WHERE `vip_level_id` = 11 OR `vip_level_id` = 12");
        // VIP 7 & VIP 8
        $this->execute("UPDATE `vip_level_point` SET `ratio_point` = 4, `updated_at` = NOW() WHERE `vip_level_id` = 13 OR `vip_level_id` = 14");
        // VIP 9 & VIP 10
        $this->execute("UPDATE `vip_level_point` SET `ratio_point` = 5, `updated_at` = NOW() WHERE `vip_level_id` = 15 OR `vip_level_id` = 16");
    }

    public function down()
    {
        $this->table('vip_level_point')->drop()->save();
    }
}
