<?php

use Phinx\Migration\AbstractMigration;
use support\Db;

/**
 * 创建玩家积分主表
 *
 * 用于记录玩家的积分余额和累计统计
 * - 通过打码量获得积分
 * - 支持积分兑换、冻结、过期等操作
 * - 使用乐观锁保证并发安全
 *
 * @author Claude Code
 * @date 2026-09-08
 */
class CreatePlayerPointsTable extends AbstractMigration
{
    /**
     * Run the migrations.
     *
     *
     *
     * @return void
     */
    public function up(): void
    {
        $schema = Db::schema();

        if ($schema->hasTable('player_points')) {
            echo "⚠️  表 player_points 已存在，跳过创建\n";
            return;
        }

        $schema->create('player_points', function (Blueprint $table) {
            $table->id()->comment('主键ID');
            $table->unsignedInteger('player_id')->comment('玩家ID');
            $table->unsignedInteger('department_id')->default(0)->comment('渠道ID');

            // ✅ 积分统计字段（使用unsigned，积分不应为负）
            $table->unsignedBigInteger('total_points')->default(0)->comment('总积分（历史累计，只增不减）');
            $table->unsignedBigInteger('available_points')->default(0)->comment('可用积分（当前余额）');
            $table->unsignedBigInteger('frozen_points')->default(0)->comment('冻结积分（兑换处理中）');
            $table->unsignedBigInteger('used_points')->default(0)->comment('已使用积分（兑换消耗累计）');
            $table->unsignedBigInteger('expired_points')->default(0)->comment('已过期积分（过期扣除累计）');

            // 并发控制
            $table->unsignedInteger('version')->default(0)->comment('乐观锁版本号');

            // 时间戳
            $table->timestamps();

            // 索引
            $table->unique('player_id', 'uk_player_id');
            $table->index('department_id', 'idx_department_id');
            $table->index('available_points', 'idx_available_points');
            $table->index('created_at', 'idx_created_at');
        });

        echo "✅ 成功创建 player_points 表\n";
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        $schema = Db::schema();
        $schema->dropIfExists('player_points');
        echo "✅ 已删除 player_points 表\n";
    }
}
