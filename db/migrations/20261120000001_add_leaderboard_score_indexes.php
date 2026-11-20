<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 為排行榜分數計算查詢新增覆蓋索引
 *
 * 問題：排行榜結算查詢（store_weekly / golden_weekly 等）掃描 play_game_record 和
 * player_game_log，現有索引以 department_id 為首欄，排行榜查詢沒有過濾 department_id，
 * 導致全表掃描，結算任務耗時過長。
 *
 * 修復：新增以 created_at 為首欄的覆蓋索引，讓 MySQL 能用 index range scan
 * 直接讀出目標時段的記錄，不用 filesort / 全表掃描。
 */
final class AddLeaderboardScoreIndexes extends AbstractMigration
{
    public function up(): void
    {
        // play_game_record：排行榜查詢條件 created_at + settlement_status，
        // 加上 player_id、bet 組成覆蓋索引，避免回表
        if ($this->hasTable('play_game_record')) {
            $table = $this->table('play_game_record');
            if (!$table->hasIndex('idx_leaderboard_score')) {
                $table
                    ->addIndex(
                        ['created_at', 'settlement_status', 'player_id', 'bet'],
                        ['name' => 'idx_leaderboard_score']
                    )
                    ->save();
            }
        }

        // player_game_log：排行榜查詢條件 created_at，
        // 加上 player_id、chip_amount 組成覆蓋索引
        if ($this->hasTable('player_game_log')) {
            $table = $this->table('player_game_log');
            if (!$table->hasIndex('idx_leaderboard_score')) {
                $table
                    ->addIndex(
                        ['created_at', 'player_id', 'chip_amount'],
                        ['name' => 'idx_leaderboard_score']
                    )
                    ->save();
            }
        }

        // machine_lottery_record：鋼珠排行榜查詢條件 created_at + machine_id，
        // 加上 player_id、lottery_win 組成覆蓋索引
        if ($this->hasTable('machine_lottery_record')) {
            $table = $this->table('machine_lottery_record');
            if (!$table->hasIndex('idx_leaderboard_score')) {
                $table
                    ->addIndex(
                        ['created_at', 'machine_id', 'player_id', 'lottery_win'],
                        ['name' => 'idx_leaderboard_score']
                    )
                    ->save();
            }
        }
    }

    public function down(): void
    {
        foreach (['play_game_record', 'player_game_log', 'machine_lottery_record'] as $tableName) {
            if ($this->hasTable($tableName)) {
                $table = $this->table($tableName);
                if ($table->hasIndex('idx_leaderboard_score')) {
                    $table->removeIndexByName('idx_leaderboard_score')->save();
                }
            }
        }
    }
}
