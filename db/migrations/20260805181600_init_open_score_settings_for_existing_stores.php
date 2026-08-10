<?php

use Phinx\Migration\AbstractMigration;

class InitOpenScoreSettingsForExistingStores extends AbstractMigration
{
    /**
     * 为存量店家初始化开分配置
     */
    public function up(): void
    {
        // 获取所有店家类型的admin_user（type=4）
        $stores = $this->fetchAll("
            SELECT id
            FROM admin_users
            WHERE type = 4
            AND deleted_at IS NULL
        ");

        if (empty($stores)) {
            $this->output->writeln("没有找到需要迁移的店家");
            return;
        }

        $migrated = 0;
        $skipped = 0;

        foreach ($stores as $store) {
            $storeId = $store['id'];

            // 检查是否已有开分配置
            $exists = $this->fetchRow("
                SELECT id
                FROM open_score_setting
                WHERE admin_user_id = {$storeId}
            ");

            if ($exists) {
                $skipped++;
                $this->output->writeln("跳过店家ID {$storeId}：已有开分配置");
                continue;
            }

            // 插入默认开分配置
            $this->execute("
                INSERT INTO open_score_setting
                (admin_user_id, score_1, score_2, score_3, score_4, score_5, score_6, default_scores, created_at, updated_at)
                VALUES
                ({$storeId}, 100, 500, 1000, 5000, 10000, 20000, 100, NOW(), NOW())
            ");

            $migrated++;
            $this->output->writeln("为店家ID {$storeId} 创建开分配置");
        }

        $this->output->writeln("");
        $this->output->writeln("迁移完成！");
        $this->output->writeln("成功: {$migrated} 个店家");
        $this->output->writeln("跳过: {$skipped} 个店家（已有配置）");
    }

    /**
     * 回滚：删除所有自动创建的开分配置
     */
    public function down(): void
    {
        $this->output->writeln("警告：此操作将删除所有开分配置");
        $this->output->writeln("回滚已取消，请手动处理");

        // 如果确实需要回滚，取消下面的注释
        // $this->execute("TRUNCATE TABLE open_score_setting");
    }
}
