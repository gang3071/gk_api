<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 新增推廣紅利相關欄位
 */
final class AddChildrenCashbackRelated extends AbstractMigration
{
    public function up(): void
    {
        // ---------------------------------------- 玩家錢包 ----------------------------------------
        $table = $this->table('player_extend');

        if (! $table->hasColumn('pending_children_cashback_amount')) {
            $table->addColumn('pending_children_cashback_amount', 'decimal', [
                'precision' => 20,
                'scale' => 5,
                'null' => false,
                'default' => '0.00000',
                'comment' => '待領取推廣紅利',
                'after' => 'total_cashback_amount',
            ]);
        }

        if (! $table->hasColumn('total_children_cashback_amount')) {
            $table->addColumn('total_children_cashback_amount', 'decimal', [
                'precision' => 20,
                'scale' => 5,
                'null' => false,
                'default' => '0.00000',
                'comment' => '總推廣紅利',
                'after' => 'pending_children_cashback_amount',
            ]);
        }

        $table->update();
        // ---------------------------------------- 推廣紅利比例 ----------------------------------------
        $table = $this->table('vip_level');

        if (! $table->hasColumn('children_cashback_ratio')) {
            $table->addColumn('children_cashback_ratio', 'decimal', [
                'precision' => 10,
                'scale' => 2,
                'null' => false,
                'default' => '0.00',
                'comment' => '推廣紅利比例 (%)',
                'after' => 'min_claim_amount',
            ]);
        }

        $table->update();
        // ---------------------------------------- 遊戲紀錄 ----------------------------------------
        $table = $this->table('play_game_record');

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
        // ---------------------------------------- 玩家錢包 ----------------------------------------
        $table = $this->table('player_extend');

        if ($table->hasColumn('pending_children_cashback_amount')) {
            $table->removeColumn('pending_children_cashback_amount');
        }

        if ($table->hasColumn('total_children_cashback_amount')) {
            $table->removeColumn('total_children_cashback_amount');
        }

        $table->update();
        // ---------------------------------------- 推廣紅利比例 ----------------------------------------
        $table = $this->table('vip_level');

        if ($table->hasColumn('children_cashback_ratio')) {
            $table->removeColumn('children_cashback_ratio');
        }

        $table->update();
        // ---------------------------------------- 遊戲紀錄 ----------------------------------------
        $table = $this->table('play_game_record');

        if ($table->hasColumn('parent_cashback_ratio')) {
            $table->removeColumn('parent_cashback_ratio');
        }

        if ($table->hasColumn('parent_cashback_amount')) {
            $table->removeColumn('parent_cashback_amount');
        }

        $table->update();
    }
}
