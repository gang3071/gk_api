<?php

use Phinx\Migration\AbstractMigration;

/**
 * 将 player_points_record 表的积分字段从 bigint 改为 decimal(14,4)
 *
 * 与 player_points 表对齐（见 20260919000000_update_player_points_to_decimal），
 * 流水的变动量/变动前/变动后保留 4 位小数，避免账变记录把小数部分截断。
 */
class UpdatePlayerPointsRecordToDecimal extends AbstractMigration
{
    public function up()
    {
        $table = $this->table('player_points_record');

        $columns = ['points', 'points_before', 'points_after'];

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
        $table = $this->table('player_points_record');

        // points 允许负数，points_before/points_after 为非负
        $table->changeColumn('points', 'biginteger', [
            'null' => false,
            'signed' => true,
            'comment' => $this->getComment('points'),
        ]);

        foreach (['points_before', 'points_after'] as $column) {
            $table->changeColumn($column, 'biginteger', [
                'null' => false,
                'signed' => false,
                'comment' => $this->getComment($column),
            ]);
        }

        $table->update();
    }

    private function getComment(string $column): string
    {
        $map = [
            'points' => '积分变动量（正数=增加，负数=减少）',
            'points_before' => '变动前积分',
            'points_after' => '变动后积分',
        ];

        return $map[$column] ?? $column;
    }
}
