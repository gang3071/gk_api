<?php

use Phinx\Migration\AbstractMigration;

/**
 * vip_level 表新增 department_id 字段
 * 用于区分不同渠道的VIP等级配置
 */
class AddDepartmentIdToVipLevel extends AbstractMigration
{
    /**
     * 执行迁移
     */
    public function up()
    {
        // 检查字段是否已存在
        $exists = $this->fetchRow("
            SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vip_level' AND COLUMN_NAME = 'department_id'
        ");

        if (!$exists['cnt']) {
            $this->execute("
                ALTER TABLE `vip_level`
                ADD COLUMN `department_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '所属渠道部门ID(0=全局)' AFTER `id`;
            ");

            // 添加索引以优化查询性能
            $this->execute("
                ALTER TABLE `vip_level`
                ADD INDEX `idx_department_id` (`department_id`);
            ");
        }
    }

    /**
     * 回滚迁移
     */
    public function down()
    {
        $this->execute("
            ALTER TABLE `vip_level`
            DROP INDEX IF EXISTS `idx_department_id`;
        ");

        $this->execute("
            ALTER TABLE `vip_level`
            DROP COLUMN IF EXISTS `department_id`;
        ");
    }
}
