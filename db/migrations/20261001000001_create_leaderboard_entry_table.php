<?php

declare(strict_types=1);

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;

/**
 * 建立排行榜名次表
 */
final class CreateLeaderboardEntryTable extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('leaderboard_entry', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => '排行榜名次表',
        ]);

        $table
            ->addColumn('id', 'biginteger', [
                'identity' => true,
                'signed' => false,
                'comment' => '主鍵',
            ])
            ->addColumn('leaderboard_id', 'biginteger', [
                'signed' => false,
                'null' => false,
                'default' => 0,
                'comment' => '對應 leaderboard.id',
            ])
            ->addColumn('scope_id', 'integer', [
                'null' => false,
                'default' => 0,
                'comment' => '名次所屬範圍：全站=0、店內=store_admin_id',
            ])
            ->addColumn('subject_id', 'biginteger', [
                'signed' => false,
                'null' => false,
                'default' => 0,
                'comment' => '被排名對象ID（玩家ID）',
            ])
            ->addColumn('player_id', 'biginteger', [
                'signed' => false,
                'null' => false,
                'default' => 0,
                'comment' => '玩家ID',
            ])
            ->addColumn('store_admin_id', 'integer', [
                'signed' => false,
                'null' => false,
                'default' => 0,
                'comment' => '店家ID',
            ])
            ->addColumn('rank', 'integer', [
                'null' => false,
                'default' => 0,
                'comment' => '名次',
            ])
            ->addColumn('score', 'decimal', [
                'precision' => 20,
                'scale' => 2,
                'null' => false,
                'default' => '0.00',
                'comment' => '分數（遊戲量，元）',
            ])
            ->addColumn('prize_amount', 'decimal', [
                'precision' => 16,
                'scale' => 2,
                'null' => false,
                'default' => '0.00',
                'comment' => '可領獎勵金額（元；沒獎=0）',
            ])
            ->addColumn('grant_status', 'integer', [
                'limit' => MysqlAdapter::INT_TINY,
                'null' => false,
                'default' => 0,
                'comment' => '發放狀態：0=未發 1=已發',
            ])
            ->addColumn('granted_at', 'datetime', [
                'null' => true,
                'comment' => '發放時間',
            ])
            ->addColumn('created_at', 'datetime', [
                'null' => true,
                'comment' => '建立時間',
            ])
            ->addColumn('updated_at', 'datetime', [
                'null' => true,
                'comment' => '更新時間',
            ])
            ->addIndex(['leaderboard_id', 'scope_id', 'player_id'], ['unique' => true, 'name' => 'uk_leaderboard_scope_player'])
            ->addIndex(['leaderboard_id', 'scope_id', 'rank'], ['name' => 'idx_leaderboard_scope_rank'])
            ->addIndex(['player_id'], ['name' => 'idx_player'])
            ->addIndex(['grant_status', 'leaderboard_id'], ['name' => 'idx_grant'])
            ->create();
    }
}
