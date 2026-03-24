<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 为渠道 ID=34 创建超级管理员账号
 *
 * 创建账号信息：
 * - 用户名: admin_dept34
 * - 密码: admin123456 (需要登录后修改)
 * - 类型: 主站管理员 (type=1)
 * - 部门: department_id=34
 * - 超级管理员: is_super=1
 */
final class CreateAdminForDepartment34 extends AbstractMigration
{
    /**
     * Migrate Up - 创建管理员账号
     */
    public function up(): void
    {
        echo "开始为渠道 ID=34 创建超级管理员账号...\n";

        if (!$this->hasTable('admin_users')) {
            echo "  - 跳过(表不存在): admin_users\n";
            return;
        }

        // 检查 department_id=34 是否存在
        if ($this->hasTable('admin_department')) {
            $dept = $this->fetchRow("SELECT id, name FROM admin_department WHERE id = 34");
            if (!$dept) {
                echo "  ⚠ 警告: 部门 ID=34 不存在，请先创建部门！\n";
                echo "  - 跳过创建管理员账号\n";
                return;
            }
            echo "  - 找到部门: ID={$dept['id']}, Name={$dept['name']}\n";
        }

        // 检查用户名是否已存在
        $existingUser = $this->fetchRow("SELECT id, username FROM admin_users WHERE username = 'admin_dept34'");
        if ($existingUser) {
            echo "  - 用户名 'admin_dept34' 已存在 (ID={$existingUser['id']})，跳过创建\n";
            return;
        }

        // 密码: admin123456 (bcrypt加密)
        // 使用 password_hash('admin123456', PASSWORD_BCRYPT)
        $passwordHash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

        // 插入管理员账号
        $this->execute("
            INSERT INTO admin_users (
                username,
                password,
                nickname,
                email,
                status,
                type,
                department_id,
                is_super,
                created_at,
                updated_at
            ) VALUES (
                'admin_dept34',
                '{$passwordHash}',
                '部门34超级管理员',
                'admin34@example.com',
                1,
                1,
                34,
                1,
                NOW(),
                NOW()
            )
        ");

        // 获取新创建的账号信息
        $newUser = $this->fetchRow("SELECT id, username, nickname, department_id FROM admin_users WHERE username = 'admin_dept34'");

        echo "  ✓ 成功创建管理员账号\n";
        echo "  - ID: {$newUser['id']}\n";
        echo "  - 用户名: {$newUser['username']}\n";
        echo "  - 昵称: {$newUser['nickname']}\n";
        echo "  - 部门ID: {$newUser['department_id']}\n";
        echo "  - 默认密码: admin123456 (请登录后立即修改)\n";

        // 创建角色关联
        if ($this->hasTable('admin_role_users')) {
            $this->execute("
                INSERT INTO admin_role_users (
                    role_id,
                    user_id
                ) VALUES (
                    3,
                    {$newUser['id']}
                )
            ");
            echo "  ✓ 已关联角色 ID=3\n";
        } else {
            echo "  - 跳过(表不存在): admin_role_users\n";
        }

        echo "\n";
        echo "管理员账号创建完成！\n";
    }

    /**
     * Migrate Down - 删除创建的管理员账号
     */
    public function down(): void
    {
        echo "开始删除为渠道 ID=34 创建的管理员账号...\n";

        if (!$this->hasTable('admin_users')) {
            echo "  - 跳过(表不存在): admin_users\n";
            return;
        }

        // 获取要删除的用户ID
        $user = $this->fetchRow("SELECT id FROM admin_users WHERE username = 'admin_dept34' AND department_id = 34");

        if ($user) {
            // 删除角色关联
            if ($this->hasTable('admin_role_users')) {
                $this->execute("DELETE FROM admin_role_users WHERE user_id = {$user['id']}");
                echo "  ✓ 已删除角色关联\n";
            }

            // 删除管理员账号
            $this->execute("DELETE FROM admin_users WHERE id = {$user['id']}");
            echo "  ✓ 已删除管理员账号: admin_dept34\n";
        } else {
            echo "  - 未找到要删除的账号\n";
        }
    }
}
