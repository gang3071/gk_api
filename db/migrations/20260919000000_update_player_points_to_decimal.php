<?php

use Phinx\Migration\AbstractMigration;

/**
 * 将 player_points 表的积分字段从 bigint 改为 decimal(14,4)
 * 支持小数积分计算
 */
class UpdatePlayerPointsToDecimal extends AbstractMigration
{
    public function up()
    {
        $table = $this->table('player_points');

        $columns = ['available_points', 'frozen_points', 'total_points', 'used_points'];

        foreach ($columns as $column) {
            $table->changeColumn($column, 'decimal', [
                'precision' => 14,
                'scale' => 4,
                'null' => false,
                'default' => 0,
                'comment' => $this->getComment($column),
            ]);
        }

        $table->update();
    }

    public function down()
    {
        $table = $this->table('player_points');

        $columns = ['available_points', 'frozen_points', 'total_points', 'used_points'];

        foreach ($columns as $column) {
            $table->changeColumn($column, 'bigint', [
                'null' => false,
                'default' => 0,
                'signed' => false,
                'comment' => $this->getComment($column),
            ]);
        }

        $table->update();
    }

    private function getComment(string $column): string
    {
        $map = [
            'available_points' => '可用积分',
            'frozen_points' => '冻结积分',
            'total_points' => '累计获得总积分',
            'used_points' => '累计已用积分',
        ];

        return $map[$column] ?? $column;
    }
}
