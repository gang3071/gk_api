<?php

use Phinx\Migration\AbstractMigration;

/**
 * 为channel表添加会员等级开关字段
 */
class AddVipLevelStatusToChannel extends AbstractMigration
{
    /**
     * 执行迁移
     */
    public function up()
    {
        $sql = "SELECT COUNT(*) as count FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'channel'
                AND COLUMN_NAME = 'vip_level_status'";

        $result = $this->fetchRow($sql);

        if ($result['count'] == 0) {
            $this->execute("
                ALTER TABLE `channel`
                ADD COLUMN `vip_level_status` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '会员等级开关(0:禁用,1:启用)' AFTER `status_machine`
            ");
        }
    }

    /**
     * 回滚迁移
     */
    public function down()
    {
        $sql = "SELECT COUNT(*) as count FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'channel'
                AND COLUMN_NAME = 'vip_level_status'";

        $result = $this->fetchRow($sql);

        if ($result['count'] > 0) {
            $this->execute("
                ALTER TABLE `channel`
                DROP COLUMN `vip_level_status`
            ");
        }
    }
}
