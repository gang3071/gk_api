<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 迁移开分配置：player_id -> admin_user_id，并为存量店家生成配置
 */
final class MigrateOpenScoreSettingPlayerToAdmin extends AbstractMigration
{
    /**
     * Migrate Up.
     */
    public function up(): void
    {
        // 1. 迁移现有 player_id 数据到 admin_user_id
        // 通过 player 表的 store_admin_id 字段找到对应的店家后台账号
        $this->execute("
            UPDATE open_score_setting os
            INNER JOIN player p ON os.player_id = p.id
            SET os.admin_user_id = p.store_admin_id
            WHERE os.player_id > 0
              AND os.admin_user_id = 0
              AND p.store_admin_id > 0
        ");

        // 2. 为所有店家账号（type=4）生成默认开分配置（如果还没有配置的话）
        $now = date('Y-m-d H:i:s');

        // 获取所有店家账号
        $storeAdmins = $this->query(
            "SELECT id, department_id FROM admin_users WHERE type = 4"
        )->fetchAll();

        if (!empty($storeAdmins)) {
            $insertData = [];

            foreach ($storeAdmins as $admin) {
                // 检查是否已有配置
                $existing = $this->query(
                    "SELECT id FROM open_score_setting
                     WHERE admin_user_id = {$admin['id']}
                     LIMIT 1"
                )->fetch();

                if (!$existing) {
                    $insertData[] = [
                        'admin_user_id' => $admin['id'],
                        'player_id' => 0, // 废弃字段，设为0
                        'score_1' => 100,
                        'score_2' => 500,
                        'score_3' => 1000,
                        'score_4' => 5000,
                        'score_5' => 10000,
                        'score_6' => 20000,
                        'default_scores' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            // 批量插入
            if (!empty($insertData)) {
                $this->table('open_score_setting')->insert($insertData)->saveData();
            }
        }

        // 3. 将已迁移的 player_id 清零（标记为已迁移）
        $this->execute("
            UPDATE open_score_setting
            SET player_id = 0
            WHERE admin_user_id > 0 AND player_id > 0
        ");
    }

    /**
     * Migrate Down.
     */
    public function down(): void
    {
        // 回滚：删除自动生成的配置（保留手动创建的）
        // 这里不做完全回滚，因为无法区分哪些是自动生成的
        echo "Warning: Down migration will not delete auto-generated configurations.\n";
    }
}
