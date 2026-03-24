<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 清理 admin_users 表
 *
 * 只保留：
 * - id = 1 的超级管理员账号
 */
final class CleanAdminUsers extends AbstractMigration
{
    /**
     * Migrate Up - 清理管理员数据
     */
    public function up(): void
    {
        echo "开始清理 admin_users 表...\n";

        // 禁用外键检查
        $this->execute("SET FOREIGN_KEY_CHECKS = 0");

        try {
            if ($this->hasTable('admin_users')) {
                // 删除所有非超级管理员账号（只保留 id = 1）
                $this->execute("
                    DELETE FROM admin_users
                    WHERE id > 1
                ");
                echo "  ✓ 已清理: admin_users (只保留 id=1 的超级管理员)\n";

                // 显示保留的账号数量
                $result = $this->fetchRow("SELECT COUNT(*) as count FROM admin_users");
                echo "  - 保留账号数量: {$result['count']} 个\n";

                // 显示保留的账号信息
                $admin = $this->fetchRow("SELECT id, username, name FROM admin_users WHERE id = 1");
                if ($admin) {
                    echo "  - 保留账号: ID={$admin['id']}, Username={$admin['username']}, Name={$admin['name']}\n";
                } else {
                    echo "  ⚠ 警告: id=1 的账号不存在！\n";
                }
            } else {
                echo "  - 跳过(表不存在): admin_users\n";
            }

            echo "admin_users 表清理完成！\n";

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
        echo "管理员数据清理是不可逆操作，请确保已备份数据库！\n";
    }
}
