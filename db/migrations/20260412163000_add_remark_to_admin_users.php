<?php

use Phinx\Migration\AbstractMigration;

/**
 * 为admin_users表添加备注字段
 * 用于渠道后台店家分润报表中的备注功能
 */
class AddRemarkToAdminUsers extends AbstractMigration
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
                AND COLUMN_NAME = 'remark'";

        $result = $this->fetchRow($sql);

        if ($result['count'] == 0) {
            // 添加备注字段
            $this->execute("
                ALTER TABLE `admin_users`
                ADD COLUMN `remark` varchar(500) DEFAULT '' COMMENT '备注' AFTER `channel_commission`
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
                AND COLUMN_NAME = 'remark'";

        $result = $this->fetchRow($sql);

        if ($result['count'] > 0) {
            // 删除备注字段
            $this->execute("
                ALTER TABLE `admin_users`
                DROP COLUMN `remark`
            ");
        }
    }
}
