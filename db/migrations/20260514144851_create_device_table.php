<?php

use Phinx\Migration\AbstractMigration;

/**
 * 创建设备表
 */
class CreateDeviceTable extends AbstractMigration
{
    /**
     * 执行迁移
     */
    public function up()
    {
        // 检查表是否已存在
        if ($this->hasTable('device')) {
            return;
        }

        $this->execute("
            CREATE TABLE `device` (
                `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
                `channel_id` INT(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '所属渠道ID',
                `department_id` INT(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '所属部门ID',
                `agent_admin_id` INT(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '所属代理ID',
                `store_admin_id` INT(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '所属店家ID',
                `device_name` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '设备名称',
                `device_no` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '设备号（安卓设备唯一标识）',
                `device_model` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '设备型号',
                `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '状态(0:禁用,1:启用)',
                `remark` TEXT COMMENT '备注',
                `created_at` TIMESTAMP NULL DEFAULT NULL COMMENT '创建时间',
                `updated_at` TIMESTAMP NULL DEFAULT NULL COMMENT '更新时间',
                `deleted_at` TIMESTAMP NULL DEFAULT NULL COMMENT '删除时间',
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_device_no` (`device_no`),
                KEY `idx_channel_id` (`channel_id`),
                KEY `idx_department_id` (`department_id`),
                KEY `idx_agent_admin_id` (`agent_admin_id`),
                KEY `idx_store_admin_id` (`store_admin_id`),
                KEY `idx_status` (`status`),
                KEY `idx_deleted_at` (`deleted_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='设备表';
        ");
    }

    /**
     * 回滚迁移
     */
    public function down()
    {
        $this->execute("DROP TABLE IF EXISTS `device`;");
    }
}
