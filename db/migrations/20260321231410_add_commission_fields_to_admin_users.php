<?php

use Phinx\Migration\AbstractMigration;

/**
 * 为admin_users表添加代理抽成和渠道抽成字段
 * 用于渠道后台创建店家时设置抽成比例
 */
class AddCommissionFieldsToAdminUsers extends AbstractMigration
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
                AND COLUMN_NAME = 'agent_commission'";

        $result = $this->fetchRow($sql);

        if ($result['count'] == 0) {
            // 添加代理抽成和渠道抽成字段
            $this->execute("
                ALTER TABLE `admin_users`
                ADD COLUMN `agent_commission` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '代理抽成比例（百分比，仅店家使用）' AFTER `ratio`,
                ADD COLUMN `channel_commission` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '渠道抽成比例（百分比，仅店家使用）' AFTER `agent_commission`
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
                AND COLUMN_NAME = 'agent_commission'";

        $result = $this->fetchRow($sql);

        if ($result['count'] > 0) {
            // 删除代理抽成和渠道抽成字段
            $this->execute("
                ALTER TABLE `admin_users`
                DROP COLUMN `channel_commission`,
                DROP COLUMN `agent_commission`
            ");
        }
    }
}
