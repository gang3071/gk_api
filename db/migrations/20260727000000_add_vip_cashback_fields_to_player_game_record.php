<?php

use Phinx\Migration\AbstractMigration;

class AddVipCashbackFieldsToPlayerGameRecord extends AbstractMigration
{
    /**
     * Change Method.
     */
    public function change(): void
    {
        $table = $this->table('player_game_record');

        // 获取现有列名
        $columns = $this->fetchAll("SHOW COLUMNS FROM `player_game_record`");
        $existingColumns = array_column($columns, 'Field');

        // VIP等级ID（NULL=未处理，0=跳过/关闭，>0=已处理）
        if (!in_array('vip_level_id', $existingColumns)) {
            $table->addColumn('vip_level_id', 'integer', [
                'signed' => false,
                'null' => true,
                'default' => null,
                'comment' => 'VIP等级ID（NULL=未处理，0=跳过，>0=已处理）',
                'after' => 'national_damage_ratio',
            ]);
        }

        // 反水比例
        if (!in_array('cashback_ratio', $existingColumns)) {
            $table->addColumn('cashback_ratio', 'decimal', [
                'precision' => 10,
                'scale' => 2,
                'null' => true,
                'default' => null,
                'comment' => '反水比例（100=100%，0.1=0.1%）',
                'after' => 'vip_level_id',
            ]);
        }

        // 反水金额
        if (!in_array('cashback_amount', $existingColumns)) {
            $table->addColumn('cashback_amount', 'decimal', [
                'precision' => 20,
                'scale' => 2,
                'null' => true,
                'default' => null,
                'comment' => '反水金额',
                'after' => 'cashback_ratio',
            ]);
        }

        // 添加索引（用于定时任务查询未处理记录）
        $indexes = $this->fetchAll("SHOW INDEX FROM `player_game_record`");
        $existingIndexes = array_unique(array_column($indexes, 'Key_name'));

        if (!in_array('idx_vip_level_id', $existingIndexes)) {
            $table->addIndex(['vip_level_id'], [
                'name' => 'idx_vip_level_id',
                'unique' => false,
            ]);
        }

        $table->save();
    }
}
