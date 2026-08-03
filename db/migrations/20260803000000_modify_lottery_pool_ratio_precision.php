<?php

use Phinx\Migration\AbstractMigration;

/**
 * 修改彩金表 pool_ratio 字段精度
 *
 * 将 pool_ratio 字段从 DECIMAL(10,2) 改为 DECIMAL(10,4)
 * 支持更精细的入池比值配置（最小 0.0001%）
 *
 * @date 2026-08-03
 */
class ModifyLotteryPoolRatioPrecision extends AbstractMigration
{
    /**
     * 获取表名（带前缀）
     */
    private function getTable(string $tableName): string
    {
        return 'yjb_' . $tableName;
    }

    /**
     * Change Method.
     */
    public function change()
    {
        // 修改 lottery 表（实体机台彩金）
        $lotteryTable = $this->table($this->getTable('lottery'));

        $lotteryTable->changeColumn('pool_ratio', 'decimal', [
                'precision' => 10,
                'scale' => 4,  // ✅ 从2位改为4位小数
                'null' => false,
                'default' => 0,
                'comment' => '入池比值（百分比，支持4位小数）',
            ])
            ->update();

        // 修改 game_lottery 表（电子游戏彩金）- 暂不修改，按照用户要求只改实体机台
        // $gameLotteryTable = $this->table($this->getTable('game_lottery'));
        // $gameLotteryTable->changeColumn('pool_ratio', 'decimal', [
        //         'precision' => 10,
        //         'scale' => 4,
        //         'null' => false,
        //         'default' => 0,
        //         'comment' => '入池比值（百分比，支持4位小数）',
        //     ])
        //     ->update();
    }
}