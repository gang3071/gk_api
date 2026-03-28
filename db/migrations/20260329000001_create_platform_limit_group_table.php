<?php

use Phinx\Migration\AbstractMigration;

/**
 * 创建限红组表
 */
class CreatePlatformLimitGroupTable extends AbstractMigration
{
    /**
     * 执行迁移
     */
    public function up()
    {
        $this->execute("
            CREATE TABLE `platform_limit_group` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
                `department_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '所属部门ID',
                `code` VARCHAR(50) NOT NULL COMMENT '限红组编码',
                `name` VARCHAR(100) NOT NULL COMMENT '限红组名称',
                `description` VARCHAR(500) NULL DEFAULT NULL COMMENT '限红组描述',
                `status` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '状态：1=启用，0=禁用',
                `sort` INT NOT NULL DEFAULT 0 COMMENT '排序值',
                `created_at` DATETIME NULL DEFAULT NULL COMMENT '创建时间',
                `updated_at` DATETIME NULL DEFAULT NULL COMMENT '更新时间',
                `deleted_at` DATETIME NULL DEFAULT NULL COMMENT '软删除时间',
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_dept_code` (`department_id`, `code`, `deleted_at`),
                KEY `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='限红组表';
        ");
    }

    /**
     * 回滚迁移
     */
    public function down()
    {
        $this->execute("DROP TABLE IF EXISTS `platform_limit_group`");
    }
}
