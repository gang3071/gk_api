<?php

use Phinx\Migration\AbstractMigration;

/**
 * 为admin_users表添加洗分配置字段
 * 用于渠道后台店家管理中配置店家的洗分数值
 */
class AddWashPointConfigToAdminUsers extends AbstractMigration
{
    /**
     * 执行迁移
     */
    public function up()
    {
        // 检查字段是否已存在，避免重复添加
        $sql = "SELECT COUNT(*) as count FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'admin_users'
                AND COLUMN_NAME = 'wash_point_config'";

        $result = $this->fetchRow($sql);

        if ($result['count'] == 0) {
            // 添加洗分配置字段
            $this->execute("
                ALTER TABLE `admin_users`
                ADD COLUMN `wash_point_config` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '洗分配置（店家洗分数值配置）' AFTER `channel_commission`
            ");
        }
    }

    /**
     * 回滚迁移
     */
    public function down()
    {
        // 检查字段是否存在
        $sql = "SELECT COUNT(*) as count FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'admin_users'
                AND COLUMN_NAME = 'wash_point_config'";

        $result = $this->fetchRow($sql);

        if ($result['count'] > 0) {
            // 删除洗分配置字段
            $this->execute("
                ALTER TABLE `admin_users`
                DROP COLUMN `wash_point_config`
            ");
        }
    }
}
