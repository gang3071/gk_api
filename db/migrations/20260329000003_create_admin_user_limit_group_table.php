<?php

use Phinx\Migration\AbstractMigration;

/**
 * 创建店家限红分配表
 */
class CreateAdminUserLimitGroupTable extends AbstractMigration
{
    /**
     * 执行迁移
     */
    public function up()
    {
        $this->execute("
            CREATE TABLE `admin_user_limit_group` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
                `admin_user_id` BIGINT UNSIGNED NOT NULL COMMENT '店家ID',
                `limit_group_id` BIGINT UNSIGNED NOT NULL COMMENT '限红组ID',
                `platform_id` INT UNSIGNED NULL DEFAULT NULL COMMENT '游戏平台ID',
                `platform_code` VARCHAR(50) NULL DEFAULT NULL COMMENT '游戏平台代码',
                `assigned_by` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '分配人ID',
                `assigned_at` DATETIME NULL DEFAULT NULL COMMENT '分配时间',
                `remark` VARCHAR(500) NULL DEFAULT NULL COMMENT '备注',
                `status` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '状态：1=启用，0=禁用',
                `created_at` DATETIME NULL DEFAULT NULL COMMENT '创建时间',
                `updated_at` DATETIME NULL DEFAULT NULL COMMENT '更新时间',
                `deleted_at` DATETIME NULL DEFAULT NULL COMMENT '软删除时间',
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_admin_platform` (`admin_user_id`, `platform_id`, `deleted_at`),
                KEY `idx_limit_group` (`limit_group_id`),
                KEY `idx_assigned` (`assigned_by`, `assigned_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='店家限红分配表';
        ");
    }

    /**
     * 回滚迁移
     */
    public function down()
    {
        $this->execute("DROP TABLE IF EXISTS `admin_user_limit_group`");
    }
}
