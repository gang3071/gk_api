<?php

use Phinx\Migration\AbstractMigration;

/**
 * 修复自动交班配置表的唯一索引问题
 *
 * 问题描述：
 * 1. 原唯一索引 uk_bind_admin (bind_admin_user_id, deleted_at) 设计错误
 * 2. 缺少 department_id 字段，导致不同渠道下的同名管理员ID冲突
 * 3. 包含 deleted_at 字段，导致软删除后可以重复创建记录
 *
 * 修复方案：
 * 1. 清理重复记录（保留最新的一条）
 * 2. 删除错误的唯一索引 uk_bind_admin
 * 3. 创建正确的唯一索引 uk_dept_admin (department_id, bind_admin_user_id)
 */
class FixAutoShiftUniqueIndex extends AbstractMigration
{
    /**
     * 执行迁移
     */
    public function up()
    {
        // 获取表对象
        $table = $this->table('store_auto_shift_config');

        echo "\n========== 开始修复自动交班配置表唯一索引 ==========\n";

        // 步骤 1: 检查重复记录
        echo "\n[步骤 1/4] 检查重复记录...\n";
        $duplicates = $this->fetchAll("
            SELECT
                department_id,
                bind_admin_user_id,
                COUNT(*) as count,
                GROUP_CONCAT(id ORDER BY id) as ids
            FROM store_auto_shift_config
            WHERE deleted_at IS NULL
            GROUP BY department_id, bind_admin_user_id
            HAVING COUNT(*) > 1
        ");

        if (!empty($duplicates)) {
            echo "⚠️  发现 " . count($duplicates) . " 组重复记录\n";
            foreach ($duplicates as $dup) {
                echo "   - 渠道 {$dup['department_id']}, 管理员 {$dup['bind_admin_user_id']}: {$dup['count']} 条记录 (IDs: {$dup['ids']})\n";
            }
        } else {
            echo "✓ 没有发现重复记录\n";
        }

        // 步骤 2: 清理重复记录（保留最新的）
        if (!empty($duplicates)) {
            echo "\n[步骤 2/4] 清理重复记录（保留ID最大的记录）...\n";

            $deletedCount = 0;
            foreach ($duplicates as $dup) {
                $ids = explode(',', $dup['ids']);
                $maxId = max($ids);
                $idsToDelete = array_diff($ids, [$maxId]);

                if (!empty($idsToDelete)) {
                    $idsStr = implode(',', $idsToDelete);
                    $this->execute("
                        DELETE FROM store_auto_shift_config
                        WHERE id IN ($idsStr)
                    ");
                    $deletedCount += count($idsToDelete);
                    echo "   ✓ 删除旧记录: IDs {$idsStr}, 保留 ID {$maxId}\n";
                }
            }

            echo "✓ 共删除 {$deletedCount} 条重复记录\n";
        } else {
            echo "\n[步骤 2/4] 跳过清理（无重复记录）\n";
        }

        // 步骤 3: 删除错误的唯一索引
        echo "\n[步骤 3/4] 删除错误的唯一索引 uk_bind_admin...\n";

        // 检查索引是否存在
        $hasOldIndex = $this->hasIndex('store_auto_shift_config', 'uk_bind_admin');

        if ($hasOldIndex) {
            // 使用 Phinx 的 removeIndex 方法
            $table->removeIndex(['bind_admin_user_id', 'deleted_at'], [
                'unique' => true,
                'name' => 'uk_bind_admin'
            ])->update();
            echo "✓ 已删除旧索引 uk_bind_admin\n";
        } else {
            echo "⚠️  索引 uk_bind_admin 不存在，跳过删除\n";
        }

        // 步骤 4: 创建正确的唯一索引
        echo "\n[步骤 4/4] 创建正确的唯一索引 uk_dept_admin...\n";

        // 检查新索引是否已存在
        $hasNewIndex = $this->hasIndex('store_auto_shift_config', 'uk_dept_admin');

        if (!$hasNewIndex) {
            $table->addIndex(['department_id', 'bind_admin_user_id'], [
                'unique' => true,
                'name' => 'uk_dept_admin'
            ])->update();
            echo "✓ 已创建新索引 uk_dept_admin (department_id, bind_admin_user_id)\n";
        } else {
            echo "⚠️  索引 uk_dept_admin 已存在，跳过创建\n";
        }

        // 最终验证
        echo "\n========== 验证修复结果 ==========\n";

        $finalCheck = $this->fetchRow("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN deleted_at IS NULL THEN 1 ELSE 0 END) as active
            FROM store_auto_shift_config
        ");
        echo "✓ 总配置数: {$finalCheck['total']}, 有效配置数: {$finalCheck['active']}\n";

        $stillDuplicate = $this->fetchAll("
            SELECT
                department_id,
                bind_admin_user_id,
                COUNT(*) as count
            FROM store_auto_shift_config
            WHERE deleted_at IS NULL
            GROUP BY department_id, bind_admin_user_id
            HAVING COUNT(*) > 1
        ");

        if (empty($stillDuplicate)) {
            echo "✓ 确认无重复记录\n";
        } else {
            echo "❌ 警告：仍存在 " . count($stillDuplicate) . " 组重复记录！\n";
        }

        echo "\n========== 修复完成 ==========\n\n";
    }

    /**
     * 检查索引是否存在
     */
    private function hasIndex($tableName, $indexName)
    {
        $indexes = $this->fetchAll("
            SELECT INDEX_NAME
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = '{$tableName}'
              AND INDEX_NAME = '{$indexName}'
            LIMIT 1
        ");

        return !empty($indexes);
    }

    /**
     * 回滚迁移
     */
    public function down()
    {
        echo "\n========== 回滚自动交班配置表索引修改 ==========\n";

        $table = $this->table('store_auto_shift_config');

        // 删除新索引
        if ($this->hasIndex('store_auto_shift_config', 'uk_dept_admin')) {
            echo "删除索引 uk_dept_admin...\n";
            $table->removeIndex(['department_id', 'bind_admin_user_id'], [
                'unique' => true,
                'name' => 'uk_dept_admin'
            ])->update();
        }

        // 恢复旧索引
        if (!$this->hasIndex('store_auto_shift_config', 'uk_bind_admin')) {
            echo "恢复索引 uk_bind_admin...\n";
            $table->addIndex(['bind_admin_user_id', 'deleted_at'], [
                'unique' => true,
                'name' => 'uk_bind_admin'
            ])->update();
        }

        echo "回滚完成\n\n";
    }
}
