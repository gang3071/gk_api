<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * vip_level 表新增 department_id 字段
 * 用于区分不同渠道的VIP等级配置
 */
final class AddDepartmentIdToVipLevel extends AbstractMigration
{
    /**
     * Migrate Up.
     */
    public function up(): void
    {
        // 检查字段是否已存在
        $exists = $this->query(
            "SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vip_level' AND COLUMN_NAME = 'department_id'"
        )->fetch();

        if (!$exists['cnt']) {
            $this->execute("
                ALTER TABLE `vip_level`
                ADD COLUMN `department_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '所属渠道部门ID(0=全局)' AFTER `id`
            ");

            // 添加索引以优化查询性能
            $this->execute("
                ALTER TABLE `vip_level`
                ADD INDEX `idx_department_id` (`department_id`)
            ");
        }
    }

    /**
     * Migrate Down.
     */
    public function down(): void
    {
        $this->execute("
            ALTER TABLE `vip_level`
            DROP INDEX IF EXISTS `idx_department_id`
        ");

        $this->execute("
            ALTER TABLE `vip_level`
            DROP COLUMN IF EXISTS `department_id`
        ");
    }
}
