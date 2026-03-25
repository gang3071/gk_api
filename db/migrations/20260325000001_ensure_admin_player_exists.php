<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 确保存在管理员玩家账户
 *
 * 检查 player 表是否有 is_admin=1 的数据
 * 如果没有，则将第一条数据设置为管理员账户
 */
final class EnsureAdminPlayerExists extends AbstractMigration
{
    /**
     * Migrate Up - 确保管理员玩家存在
     */
    public function up(): void
    {
        echo "开始检查管理员玩家账户...\n";

        try {
            if (!$this->hasTable('player')) {
                echo "  - 跳过(表不存在): player\n";
                return;
            }

            // 检查是否已有管理员账户
            $adminPlayer = $this->fetchRow("SELECT id FROM player WHERE is_admin = 1 LIMIT 1");

            if ($adminPlayer) {
                echo "  ✓ 已存在管理员账户: ID={$adminPlayer['id']}\n";
                echo "  - 无需修改\n";
            } else {
                echo "  ! 未找到管理员账户，将设置第一条数据为管理员...\n";

                // 获取第一条数据
                $firstPlayer = $this->fetchRow("SELECT id FROM player ORDER BY id ASC LIMIT 1");

                if ($firstPlayer) {
                    // 将第一条数据设置为管理员
                    $this->execute("
                        UPDATE player
                        SET is_admin = 1
                        WHERE id = {$firstPlayer['id']}
                    ");

                    echo "  ✓ 已设置管理员账户: ID={$firstPlayer['id']}\n";
                } else {
                    echo "  ⚠ 警告: player 表为空，无法设置管理员账户！\n";
                }
            }

            echo "\n";
            echo "========================================\n";
            echo "管理员玩家账户检查完成！\n";
            echo "========================================\n";

        } catch (\Exception $e) {
            echo "  ✗ 错误: {$e->getMessage()}\n";
            throw $e;
        }
    }

    /**
     * Migrate Down - 回滚（不支持）
     */
    public function down(): void
    {
        echo "警告：此操作不支持回滚！\n";
        echo "如需修改，请手动更新 player 表的 is_admin 字段！\n";
    }

    /**
     * 检查表是否存在
     */
    public function hasTable(string $tableName): bool
    {
        $rows = $this->fetchAll("SHOW TABLES LIKE '{$tableName}'");
        return !empty($rows);
    }
}
