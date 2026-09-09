<?php

use Phinx\Migration\AbstractMigration;
use support\Db;

/**
 * 创建玩家积分变动记录表
 *
 * ⚠️ 重要优化：打码获得积分不记录在此表
 *
 * 记录以下重要操作：
 * - 积分兑换消耗（需要详细记录）
 * - 积分过期扣除（需要详细记录）
 * - 后台手动调整（需要审计记录，包含操作人员信息）
 * - 活动奖励发放（需要详细记录）
 * - 订单退款（需要详细记录）
 * - 打码获得积分汇总（每日/每周汇总一条）
 *
 * 打码明细由 play_game_record 表提供（已有完整下注记录）
 *
 * 操作人员信息字段（admin_id, admin_name, admin_ip）：
 * - 仅在后台调整时填写
 * - 用于审计追踪和责任追溯
 *
 * @author Claude Code
 * @date 2026-09-08
 */
class CreatePlayerPointsRecordTable extends AbstractMigration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        $schema = Db::schema();

        if ($schema->hasTable('player_points_record')) {
            echo "⚠️  表 player_points_record 已存在，跳过创建\n";
            return;
        }

        $schema->create('player_points_record', function (Blueprint $table) {
            $table->id()->comment('主键ID');
            $table->unsignedInteger('player_id')->comment('玩家ID');
            $table->unsignedInteger('department_id')->default(0)->comment('渠道ID');

            // 变动信息
            $table->tinyInteger('type')->comment('类型：1=打码汇总 2=兑换消耗 3=过期扣除 4=后台调整 5=活动奖励 6=订单退款');
            $table->string('source', 50)->default('')->comment('来源：betting_summary|exchange|expire|admin|activity|refund');
            $table->bigInteger('points')->comment('积分变动（正数=增加，负数=减少）');
            $table->unsignedBigInteger('points_before')->comment('变动前积分');  // ✅ unsigned
            $table->unsignedBigInteger('points_after')->comment('变动后积分');   // ✅ unsigned

            // 打码汇总信息（type=1时使用）
            $table->decimal('bet_amount', 15, 2)->nullable()->comment('汇总打码量（元，仅打码汇总时填写）');
            $table->string('summary_period', 20)->nullable()->comment('汇总周期：daily|weekly|monthly');
            $table->date('summary_date')->nullable()->comment('汇总日期');
            $table->unsignedInteger('game_count')->nullable()->comment('游戏局数');

            // 兑换信息（type=2时使用）
            $table->unsignedBigInteger('exchange_order_id')->nullable()->comment('兑换订单ID');
            $table->string('exchange_item', 100)->nullable()->comment('兑换物品');

            // 规则信息
            $table->string('rule_code', 50)->nullable()->comment('规则代码');
            $table->decimal('rate', 10, 4)->nullable()->comment('转换比率（打码→积分，仅打码汇总时填写）');

            // 其他
            $table->string('remark', 255)->nullable()->comment('备注');
            $table->string('batch_id', 50)->nullable()->comment('批次ID（用于幂等）');

            // 操作人员信息（后台调整时填写）
            $table->unsignedInteger('admin_id')->nullable()->comment('操作员ID（后台调整时填写）');
            $table->string('admin_name', 50)->nullable()->comment('操作员名称（后台调整时填写）');
            $table->string('admin_ip', 50)->nullable()->comment('操作IP地址（后台调整时填写）');

            // 时间戳
            $table->timestamp('created_at')->nullable()->comment('创建时间');

            // 索引
            $table->index('player_id', 'idx_player_id');
            $table->index(['player_id', 'type'], 'idx_player_type');
            $table->index('type', 'idx_type');
            $table->index('created_at', 'idx_created_at');
            $table->index(['summary_period', 'summary_date'], 'idx_summary');
            $table->index('exchange_order_id', 'idx_exchange_order_id');
            $table->index('admin_id', 'idx_admin_id');

            // ⚠️ 注意：batch_id 允许 NULL，MySQL 中多个 NULL 值不冲突
            // 只有非 NULL 的 batch_id 才强制唯一
            $table->unique('batch_id', 'uk_batch_id');
        });

        echo "✅ 成功创建 player_points_record 表\n";
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        $schema = Db::schema();
        $schema->dropIfExists('player_points_record');
        echo "✅ 已删除 player_points_record 表\n";
    }
}
