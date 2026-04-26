<?php

use Phinx\Migration\AbstractMigration;

/**
 * 为game_platform表添加维护时间字段
 * 用于配置各游戏平台的定期维护时间
 */
class AddMaintenanceFieldsToGamePlatform extends AbstractMigration
{
    /**
     * 执行迁移
     */
    public function up()
    {
        // 检查字段是否已存在，避免重复添加
        $sql = "SELECT COUNT(*) as count FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'game_platform'
                AND COLUMN_NAME = 'maintenance_week'";

        $result = $this->fetchRow($sql);

        if ($result['count'] == 0) {
            // 添加维护时间相关字段
            $this->execute("
                ALTER TABLE `game_platform`
                ADD COLUMN `maintenance_week` TINYINT UNSIGNED NULL DEFAULT NULL COMMENT '维护星期（1-7，1=周一，7=周日）' AFTER `default_limit_group_id`,
                ADD COLUMN `maintenance_start_time` TIME NULL DEFAULT NULL COMMENT '维护开始时间' AFTER `maintenance_week`,
                ADD COLUMN `maintenance_end_time` TIME NULL DEFAULT NULL COMMENT '维护结束时间' AFTER `maintenance_start_time`,
                ADD COLUMN `maintenance_status` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '维护功能状态（0=关闭，1=开启）' AFTER `maintenance_end_time`,
                ADD KEY `idx_maintenance_status` (`maintenance_status`)
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
                AND TABLE_NAME = 'game_platform'
                AND COLUMN_NAME = 'maintenance_week'";

        $result = $this->fetchRow($sql);

        if ($result['count'] > 0) {
            // 删除维护时间相关字段
            $this->execute("
                ALTER TABLE `game_platform`
                DROP INDEX `idx_maintenance_status`,
                DROP COLUMN `maintenance_status`,
                DROP COLUMN `maintenance_end_time`,
                DROP COLUMN `maintenance_start_time`,
                DROP COLUMN `maintenance_week`
            ");
        }
    }
}
