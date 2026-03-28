<?php

use Phinx\Migration\AbstractMigration;

/**
 * 创建限红组平台配置表
 */
class CreatePlatformLimitGroupConfigTable extends AbstractMigration
{
    /**
     * 执行迁移
     */
    public function up()
    {
        $this->execute("
            CREATE TABLE `platform_limit_group_config` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
                `limit_group_id` BIGINT UNSIGNED NOT NULL COMMENT '限红组ID',
                `platform_id` INT UNSIGNED NOT NULL COMMENT '游戏平台ID',
                `platform_code` VARCHAR(50) NOT NULL COMMENT '游戏平台代码',
                `config_data` JSON NULL DEFAULT NULL COMMENT '配置数据（包含所有平台相关参数）',
                `status` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '状态：1=启用，0=禁用',
                `created_at` DATETIME NULL DEFAULT NULL COMMENT '创建时间',
                `updated_at` DATETIME NULL DEFAULT NULL COMMENT '更新时间',
                `deleted_at` DATETIME NULL DEFAULT NULL COMMENT '软删除时间',
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_group_platform` (`limit_group_id`, `platform_id`, `deleted_at`),
                KEY `idx_platform` (`platform_id`),
                KEY `idx_platform_code` (`platform_code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='限红组平台配置表';
        ");
    }

    /**
     * 回滚迁移
     */
    public function down()
    {
        $this->execute("DROP TABLE IF EXISTS `platform_limit_group_config`");
    }
}
