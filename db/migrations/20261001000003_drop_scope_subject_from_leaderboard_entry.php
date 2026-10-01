<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 調整排行榜名次表：移除 scope_id、subject_id
 *
 * 說明：
 * - 只有「全站 / 店內」兩種範圍，且被排名對象固定為玩家；
 *   店家區分改由 store_admin_id 判斷（店內榜=分組；全站榜=玩家所屬店家）。
 * - unique 由 (leaderboard_id, scope_id, player_id) 改為 (leaderboard_id, player_id)。
 * - 查詢索引由 (leaderboard_id, scope_id, rank) 改為 (leaderboard_id, store_admin_id, rank)。
 */
final class DropScopeSubjectFromLeaderboardEntry extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('leaderboard_entry');

        $table
            ->removeIndexByName('uk_leaderboard_scope_player')
            ->removeIndexByName('idx_leaderboard_scope_rank')
            ->removeColumn('scope_id')
            ->removeColumn('subject_id')
            ->addIndex(['leaderboard_id', 'player_id'], ['unique' => true, 'name' => 'uk_leaderboard_player'])
            ->addIndex(['leaderboard_id', 'store_admin_id', 'rank'], ['name' => 'idx_leaderboard_store_rank'])
            ->update();
    }

    public function down(): void
    {
        $table = $this->table('leaderboard_entry');

        $table
            ->removeIndexByName('uk_leaderboard_player')
            ->removeIndexByName('idx_leaderboard_store_rank')
            ->addColumn('scope_id', 'integer', [
                'null' => false,
                'default' => 0,
                'after' => 'leaderboard_id',
                'comment' => '名次所屬範圍：全站=0、店內=store_admin_id',
            ])
            ->addColumn('subject_id', 'biginteger', [
                'signed' => false,
                'null' => false,
                'default' => 0,
                'after' => 'scope_id',
                'comment' => '被排名對象ID（玩家ID）',
            ])
            ->addIndex(['leaderboard_id', 'scope_id', 'player_id'], ['unique' => true, 'name' => 'uk_leaderboard_scope_player'])
            ->addIndex(['leaderboard_id', 'scope_id', 'rank'], ['name' => 'idx_leaderboard_scope_rank'])
            ->update();
    }
}
