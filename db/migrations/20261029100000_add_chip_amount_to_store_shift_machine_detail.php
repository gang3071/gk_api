<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 交班实体机台详情表补充打码量字段
 *
 * 原迁移 20261028100000_add_chip_amount_to_store_shift_machine_detail.php
 * 只在本地跑过、文件没进仓库，导致生产库缺列、自动交班写入失败。
 * 用新版本号重建，hasColumn 幂等守卫，哪个环境都不会被静默跳过。
 */
final class AddChipAmountToStoreShiftMachineDetail extends AbstractMigration
{
    /**
     * Change Method.
     */
    public function change(): void
    {
        $table = $this->table('store_shift_machine_detail');

        if (!$table->hasColumn('chip_amount')) {
            $table->addColumn('chip_amount', 'decimal', [
                'null' => false,
                'precision' => 12,
                'scale' => 2,
                'default' => '0.00',
                'comment' => '打码量（player_game_log.chip_amount 汇总）',
                'after' => 'pressure',
            ]);
        }

        $table->update();
    }
}
