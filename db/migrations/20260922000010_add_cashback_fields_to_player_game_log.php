<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * player_game_log 表新增VIP反水字段
 */
final class AddCashbackFieldsToPlayerGameLog extends AbstractMigration
{
    /**
     * Change Method.
     */
    public function change(): void
    {
        $table = $this->table('player_game_log');

        if (!$table->hasColumn('vip_level_id')) {
            $table->addColumn('vip_level_id', 'integer', [
                'null' => true,
                'default' => null,
                'signed' => false,
                'comment' => 'VIP等级ID',
                'after' => 'chip_amount',
            ]);
        }

        if (!$table->hasColumn('cashback_ratio')) {
            $table->addColumn('cashback_ratio', 'decimal', [
                'null' => true,
                'default' => null,
                'precision' => 10,
                'scale' => 4,
                'comment' => '机台反水比例',
                'after' => 'vip_level_id',
            ]);
        }

        if (!$table->hasColumn('cashback_amount')) {
            $table->addColumn('cashback_amount', 'decimal', [
                'null' => true,
                'default' => null,
                'precision' => 20,
                'scale' => 4,
                'comment' => '机台反水金额',
                'after' => 'cashback_ratio',
            ]);
        }

        if (!$table->hasIndex(['vip_level_id'])) {
            $table->addIndex(['vip_level_id'], ['name' => 'idx_vip_level_id']);
        }
        $table->update();
    }
}
