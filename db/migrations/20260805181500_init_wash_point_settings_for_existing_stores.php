<?php

use think\migration\Migrator;
use think\migration\db\Column;

class InitWashPointSettingsForExistingStores extends Migrator
{
    /**
     * 为存量店家初始化洗分配置
     */
    public function up()
    {
        // 获取所有店家类型的admin_user（type=4）
        $stores = $this->fetchAll("
            SELECT id, wash_point_config
            FROM admin_users
            WHERE type = 4
            AND deleted_at IS NULL
        ");

        if (empty($stores)) {
            echo "没有找到需要迁移的店家\n";
            return;
        }

        $migrated = 0;
        $skipped = 0;

        foreach ($stores as $store) {
            $storeId = $store['id'];
            $oldWashConfig = $store['wash_point_config'];

            // 检查是否已有洗分配置
            $exists = $this->fetchRow("
                SELECT id
                FROM wash_point_setting
                WHERE admin_user_id = {$storeId}
            ");

            if ($exists) {
                $skipped++;
                echo "跳过店家ID {$storeId}：已有洗分配置\n";
                continue;
            }

            // 确定默认洗分基数
            // 如果admin_users表中有配置且大于0，使用该值；否则使用100
            $defaultWashPoint = (!empty($oldWashConfig) && $oldWashConfig > 0)
                ? $oldWashConfig
                : 100.00;

            // 插入默认洗分配置
            $this->execute("
                INSERT INTO wash_point_setting
                (admin_user_id, wash_1, wash_2, wash_3, wash_4, wash_5, wash_6, default_wash_point, created_at, updated_at)
                VALUES
                ({$storeId}, 100.00, 500.00, 700.00, 1000.00, 5000.00, 10000.00, {$defaultWashPoint}, NOW(), NOW())
            ");

            $migrated++;
            echo "为店家ID {$storeId} 创建洗分配置（默认基数: {$defaultWashPoint}）\n";
        }

        echo "\n迁移完成！\n";
        echo "成功: {$migrated} 个店家\n";
        echo "跳过: {$skipped} 个店家（已有配置）\n";
    }

    /**
     * 回滚：删除所有自动创建的洗分配置
     */
    public function down()
    {
        // 注意：此操作会删除所有洗分配置，请谨慎使用！
        echo "警告：此操作将删除所有洗分配置\n";
        echo "回滚已取消，请手动处理\n";

        // 如果确实需要回滚，取消下面的注释
        // $this->execute("TRUNCATE TABLE wash_point_setting");
    }
}
