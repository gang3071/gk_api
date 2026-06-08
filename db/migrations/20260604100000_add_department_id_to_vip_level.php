<?php

use Phinx\Migration\AbstractMigration;

/**
 * 为vip_level表添加渠道ID字段，实现渠道级别的VIP等级隔离
 */
class AddDepartmentIdToVipLevel extends AbstractMigration
{
    /**
     * 执行迁移
     */
    public function up()
    {
        // 检查字段是否已存在
        $sql = "SELECT COUNT(*) as count FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'vip_level'
                AND COLUMN_NAME = 'department_id'";

        $result = $this->fetchRow($sql);

        if ($result['count'] == 0) {
            $this->execute("
                ALTER TABLE `vip_level`
                ADD COLUMN `department_id` INT(11) NOT NULL DEFAULT 0 COMMENT '所属渠道部门ID(0=全局)' AFTER `id`,
                ADD INDEX `idx_department_id` (`department_id`)
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
                AND TABLE_NAME = 'vip_level'
                AND COLUMN_NAME = 'department_id'";

        $result = $this->fetchRow($sql);

        if ($result['count'] > 0) {
            $this->execute("
                ALTER TABLE `vip_level`
                DROP INDEX `idx_department_id`,
                DROP COLUMN `department_id`
            ");
        }
    }
}
