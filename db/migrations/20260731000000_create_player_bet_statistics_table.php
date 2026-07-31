<?php

use Phinx\Migration\AbstractMigration;

/**
 * 创建玩家打码量统计表
 *
 * 用于统计玩家在实体机台和电子游戏的打码量（日/周/月维度）
 * 数据来源：gk_work 实时统计后定时同步到 gk_api
 */
class CreatePlayerBetStatisticsTable extends AbstractMigration
{
    /**
     * 执行迁移
     */
    public function change()
    {
        echo "================================================================================\n";
        echo "创建 player_bet_statistics 表（玩家打码量统计）\n";
        echo "================================================================================\n";

        $table = $this->table('player_bet_statistics', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => '玩家打码量统计表'
        ]);

        $table
            // 主键
            ->addColumn('id', 'biginteger', [
                'signed' => false,
                'identity' => true,
                'comment' => 'ID'
            ])
            // 玩家ID
            ->addColumn('player_id', 'integer', [
                'signed' => false,
                'null' => false,
                'comment' => '玩家ID'
            ])
            // 统计类型
            ->addColumn('stat_type', 'string', [
                'limit' => 20,
                'null' => false,
                'comment' => '统计类型：machine=实体机台, game=电子游戏'
            ])
            // 统计维度
            ->addColumn('dimension', 'string', [
                'limit' => 20,
                'null' => false,
                'comment' => '维度：daily=日, weekly=周, monthly=月'
            ])
            // 统计日期
            ->addColumn('stat_date', 'string', [
                'limit' => 20,
                'null' => false,
                'comment' => '统计日期：2026-07-31, 2026-W31, 2026-07'
            ])
            // 打码量
            ->addColumn('bet_amount', 'decimal', [
                'precision' => 18,
                'scale' => 2,
                'null' => false,
                'default' => '0.00',
                'comment' => '打码量（元）'
            ])
            // 投注次数
            ->addColumn('bet_count', 'integer', [
                'signed' => false,
                'null' => false,
                'default' => 0,
                'comment' => '投注次数'
            ])
            // 时间戳
            ->addColumn('created_at', 'datetime', [
                'null' => false,
                'comment' => '创建时间'
            ])
            ->addColumn('updated_at', 'datetime', [
                'null' => false,
                'comment' => '更新时间'
            ])
            // 唯一索引：防止重复统计
            ->addIndex(['player_id', 'stat_type', 'dimension', 'stat_date'], [
                'unique' => true,
                'name' => 'uk_player_type_dimension_date'
            ])
            // 索引：玩家查询
            ->addIndex(['player_id'], [
                'name' => 'idx_player_id'
            ])
            // 索引：排行榜查询（stat_type + dimension + stat_date + bet_amount DESC）
            ->addIndex(['stat_type', 'dimension', 'stat_date', 'bet_amount'], [
                'name' => 'idx_ranking'
            ])
            ->create();

        echo "✓ player_bet_statistics 表创建成功\n";
        echo "✓ 已添加唯一索引: uk_player_type_dimension_date (player_id, stat_type, dimension, stat_date)\n";
        echo "✓ 已添加索引: idx_player_id (player_id)\n";
        echo "✓ 已添加索引: idx_ranking (stat_type, dimension, stat_date, bet_amount)\n";
        echo "\n";
        echo "表字段说明：\n";
        echo "  - player_id: 玩家ID\n";
        echo "  - stat_type: machine（实体机台）或 game（电子游戏）\n";
        echo "  - dimension: daily（日）、weekly（周）、monthly（月）\n";
        echo "  - stat_date: 日期字符串（2026-07-31、2026-W31、2026-07）\n";
        echo "  - bet_amount: 打码量（元，DECIMAL(18,2)）\n";
        echo "  - bet_count: 投注次数\n";
        echo "\n";
        echo "================================================================================\n";
        echo "迁移完成！\n";
        echo "================================================================================\n";
    }
}
