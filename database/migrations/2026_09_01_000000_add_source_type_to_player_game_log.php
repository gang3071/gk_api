<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use support\Db;

/**
 * 为 player_game_log 表添加来源类型字段
 *
 * 用于区分：
 * - 线上开洗分（通过系统界面操作）
 * - 线下实体按键开洗分（B5/B7协议）
 *
 * @author Claude Code
 * @date 2026-09-01
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        $schema = Db::schema();

        // 为 player_game_log 添加来源类型字段
        $schema->table('player_game_log', function (Blueprint $table) {
            $table->tinyInteger('source_type')
                ->default(1)
                ->after('action')
                ->comment('来源类型：1=线上系统 2=线下实体按键（B5/B7协议）');
        });

        echo "✅ 已为 player_game_log 表添加 source_type 字段\n";

        // 为 player_game_record 添加来源类型字段（可选，用于汇总统计）
        $schema->table('player_game_record', function (Blueprint $table) {
            $table->tinyInteger('has_external_button')
                ->default(0)
                ->after('type')
                ->comment('是否包含实体按键操作：0=否 1=是');
        });

        echo "✅ 已为 player_game_record 表添加 has_external_button 字段\n";
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        $schema = Db::schema();

        $schema->table('player_game_log', function (Blueprint $table) {
            $table->dropColumn('source_type');
        });

        $schema->table('player_game_record', function (Blueprint $table) {
            $table->dropColumn('has_external_button');
        });

        echo "✅ 已回滚字段\n";
    }
};
