<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 清理 admin_department 表
 *
 * 只保留：
 * - type = 1 的部门
 * - id = 34 的部门
 */
final class CleanAdminDepartment extends AbstractMigration
{
    /**
     * Migrate Up - 清理部门数据
     */
    public function up(): void
    {
        echo "开始清理 admin_department 表...\n";

        // 禁用外键检查
        $this->execute("SET FOREIGN_KEY_CHECKS = 0");

        try {
            if ($this->hasTable('admin_department')) {
                // 删除不符合条件的部门
                $this->execute("
                    DELETE FROM admin_department
                    WHERE type != 1
                      AND id != 34
                ");
                echo "  ✓ 已清理: admin_department (保留 type=1 和 id=34)\n";

                // 显示保留的部门数量
                $result = $this->fetchRow("SELECT COUNT(*) as count FROM admin_department");
                echo "  - 保留部门数量: {$result['count']} 个\n";
            } else {
                echo "  - 跳过(表不存在): admin_department\n";
            }

            echo "admin_department 表清理完成！\n";

        } finally {
            // 恢复外键检查
            $this->execute("SET FOREIGN_KEY_CHECKS = 1");
        }
    }

    /**
     * Migrate Down - 回滚（不支持）
     */
    public function down(): void
    {
        echo "警告：此操作不支持回滚！\n";
        echo "部门数据清理是不可逆操作，请确保已备份数据库！\n";
    }
}
