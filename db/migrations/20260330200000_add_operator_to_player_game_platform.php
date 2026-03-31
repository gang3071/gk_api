<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * ATG平台多营运账号支持迁移
 *
 * 背景：
 * ATG平台的限红组是基于营运账号(operator)的，每个营运账号下的玩家数据是独立的。
 * 当玩家切换限红组时，实际上是切换到了不同的营运账号，需要在新的营运账号下单独注册。
 *
 * 修改：
 * 1. 添加 operator 字段，记录玩家在哪个营运账号下注册
 * 2. 添加唯一索引，确保同一玩家在同一平台的同一营运账号下只有一条记录
 * 3. 添加普通索引，优化查询性能
 */
final class AddOperatorToPlayerGamePlatform extends AbstractMigration
{
    /**
     * Migrate Up.
     */
    public function up(): void
    {
        $table = $this->table('player_game_platform');

        echo "\n========== ATG平台多营运账号支持迁移 ==========\n";

        // 检查字段是否已存在
        if (!$table->hasColumn('operator')) {
            echo "\n[步骤 1/4] 添加 operator 字段...\n";
            // 添加 operator 字段
            // ATG平台：记录 operator (如 Testsup9-2)
            // 其他平台：默认使用空字符串，确保唯一索引正常工作
            $table->addColumn('operator', 'string', [
                'limit' => 100,
                'default' => '',
                'comment' => '营运账号标识（ATG平台使用）',
                'after' => 'platform_id',
            ])->update();
            echo "✓ operator 字段添加成功\n";
        } else {
            echo "\n[步骤 1/4] operator 字段已存在，跳过\n";
        }

        // 步骤 2: 检查并清理重复数据（在添加唯一索引之前）
        echo "\n[步骤 2/4] 检查并清理重复数据...\n";
        $duplicates = $this->fetchAll("
            SELECT
                player_id,
                platform_id,
                COUNT(*) as count,
                GROUP_CONCAT(id ORDER BY id) as ids
            FROM player_game_platform
            GROUP BY player_id, platform_id
            HAVING COUNT(*) > 1
        ");

        if (!empty($duplicates)) {
            echo "⚠️  发现 " . count($duplicates) . " 组重复记录，开始清理...\n";

            $deletedCount = 0;
            foreach ($duplicates as $dup) {
                $ids = explode(',', $dup['ids']);
                $maxId = max($ids);
                $idsToDelete = array_diff($ids, [$maxId]);

                if (!empty($idsToDelete)) {
                    $idsStr = implode(',', $idsToDelete);
                    $this->execute("
                        DELETE FROM player_game_platform
                        WHERE id IN ($idsStr)
                    ");
                    $deletedCount += count($idsToDelete);
                    echo "   ✓ 玩家 {$dup['player_id']}, 平台 {$dup['platform_id']}: 删除旧记录 {$idsStr}, 保留 ID {$maxId}\n";
                }
            }

            echo "✓ 共删除 {$deletedCount} 条重复记录\n";
        } else {
            echo "✓ 无重复记录\n";
        }

        // 检查唯一索引是否已存在
        if (!$table->hasIndex(['player_id', 'platform_id', 'operator'])) {
            echo "\n[步骤 3/4] 添加唯一索引 uk_player_platform_operator...\n";
            // 添加唯一索引：同一玩家在同一平台的同一营运账号下只能有一条记录
            $table->addIndex(
                ['player_id', 'platform_id', 'operator'],
                ['unique' => true, 'name' => 'uk_player_platform_operator']
            )->update();
            echo "✓ 唯一索引添加成功\n";
        } else {
            echo "\n[步骤 3/4] 唯一索引已存在，跳过\n";
        }

        // 检查普通索引是否已存在
        if (!$table->hasIndex(['platform_id', 'operator'])) {
            echo "\n[步骤 4/4] 添加索引 idx_platform_operator...\n";
            // 添加索引优化查询
            $table->addIndex(
                ['platform_id', 'operator'],
                ['name' => 'idx_platform_operator']
            )->update();
            echo "✓ 索引添加成功\n";
        } else {
            echo "\n[步骤 4/4] 索引已存在，跳过\n";
        }

        echo "\n========== 迁移完成 ==========\n\n";
    }

    /**
     * Migrate Down.
     */
    public function down(): void
    {
        $table = $this->table('player_game_platform');

        // 删除索引
        if ($table->hasIndex(['platform_id', 'operator'])) {
            $table->removeIndex(['platform_id', 'operator'])->update();
        }

        if ($table->hasIndex(['player_id', 'platform_id', 'operator'])) {
            $table->removeIndex(['player_id', 'platform_id', 'operator'])->update();
        }

        // 删除字段
        if ($table->hasColumn('operator')) {
            $table->removeColumn('operator')->update();
        }
    }
}
