<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 清理通知公告数据
 *
 * 清理内容：
 * - notice - 公告表
 * - system_notice - 系统通知
 * - admin_notice - 管理员通知
 * - player_notice - 玩家通知记录
 */
final class CleanNoticeData extends AbstractMigration
{
    /**
     * Migrate Up - 清理通知公告数据
     */
    public function up(): void
    {
        echo "开始清理通知公告数据...\n";

        // 禁用外键检查
        $this->execute("SET FOREIGN_KEY_CHECKS = 0");

        try {
            $noticeTables = [
                'notice',              // 公告表
                'system_notice',       // 系统通知
                'admin_notice',        // 管理员通知
                'player_notice',       // 玩家通知记录
            ];

            foreach ($noticeTables as $table) {
                if ($this->hasTable($table)) {
                    $this->execute("TRUNCATE TABLE `{$table}`");
                    echo "  ✓ 已清理: {$table}\n";
                } else {
                    echo "  - 跳过(表不存在): {$table}\n";
                }
            }

            echo "\n";
            echo "========================================\n";
            echo "通知公告数据清理完成！\n";
            echo "========================================\n";

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
        echo "数据清理是不可逆操作，请确保已备份数据库！\n";
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
