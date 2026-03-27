<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 在 player_platform_cash 表中添加爆机状态字段
 */
final class AddCrashStatusToPlayerPlatformCash extends AbstractMigration
{
    /**
     * Migrate Up.
     */
    public function up(): void
    {
        echo "================================================================================\n";
        echo "在 player_platform_cash 表中添加爆机状态字段\n";
        echo "================================================================================\n";

        $table = $this->table('player_platform_cash');

        // 检查字段是否已存在
        if (!$table->hasColumn('is_crashed')) {
            $table->addColumn('is_crashed', 'boolean', [
                'default' => false,
                'null' => false,
                'comment' => '是否爆机 0=正常 1=已爆机',
                'after' => 'status'
            ])
            ->addIndex(['is_crashed'], [
                'name' => 'idx_is_crashed'
            ])
            ->update();

            echo "✓ 字段 is_crashed 已成功添加到 player_platform_cash 表\n";
            echo "✓ 已添加索引: idx_is_crashed\n";
        } else {
            echo "✓ 字段 is_crashed 已存在，跳过\n";
        }

        echo "================================================================================\n";
        echo "迁移完成！\n";
        echo "================================================================================\n";
    }

    /**
     * Migrate Down.
     */
    public function down(): void
    {
        echo "================================================================================\n";
        echo "移除 player_platform_cash 表的爆机状态字段\n";
        echo "================================================================================\n";

        $table = $this->table('player_platform_cash');

        if ($table->hasColumn('is_crashed')) {
            $table->removeIndex(['is_crashed'], ['name' => 'idx_is_crashed'])
                  ->removeColumn('is_crashed')
                  ->update();

            echo "✓ 已移除字段 is_crashed\n";
        }

        echo "================================================================================\n";
    }
}
