<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 新增推廣紅利相關欄位
 */
final class AddChildrenCashbackToPlayerGameLog extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('player_game_log');

        if (! $table->hasColumn('parent_cashback_ratio')) {
            $table->addColumn('parent_cashback_ratio', 'decimal', [
                'precision' => 10,
                'scale' => 2,
                'null' => false,
                'default' => '0.00',
                'comment' => '上級推廣紅利比例 (%)',
                'after' => 'cashback_amount',
            ]);
        }

        if (! $table->hasColumn('parent_cashback_amount')) {
            $table->addColumn('parent_cashback_amount', 'decimal', [
                'precision' => 20,
                'scale' => 5,
                'null' => false,
                'default' => '0.00000',
                'comment' => '上級推廣紅利金額',
                'after' => 'parent_cashback_ratio',
            ]);
        }

        $table->update();
    }

    public function down()
    {
        $table = $this->table('player_game_log');

        if ($table->hasColumn('parent_cashback_ratio')) {
            $table->removeColumn('parent_cashback_ratio');
        }

        if ($table->hasColumn('parent_cashback_amount')) {
            $table->removeColumn('parent_cashback_amount');
        }

        $table->update();
    }
}
