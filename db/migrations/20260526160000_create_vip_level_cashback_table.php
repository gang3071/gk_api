<?php

use Phinx\Migration\AbstractMigration;

/**
 * 创建VIP等级反水比例表
 */
class CreateVipLevelCashbackTable extends AbstractMigration
{
    /**
     * 执行迁移
     */
    public function up()
    {
        // 检查表是否已存在
        if ($this->hasTable('vip_level_cashback')) {
            return;
        }

        $this->execute("
            CREATE TABLE `vip_level_cashback` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
                `vip_level_id` INT UNSIGNED NOT NULL COMMENT 'VIP等级ID',
                `platform_id` INT UNSIGNED NOT NULL COMMENT '游戏平台ID',
                `cashback_ratio` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '反水比例（100=100%，0.1=0.1%）',
                `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '状态（0=禁用，1=启用）',
                `created_at` DATETIME DEFAULT NULL COMMENT '创建时间',
                `updated_at` DATETIME DEFAULT NULL COMMENT '更新时间',
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_level_platform` (`vip_level_id`, `platform_id`),
                INDEX `idx_vip_level_id` (`vip_level_id`),
                INDEX `idx_platform_id` (`platform_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='VIP等级反水比例表';
        ");
    }

    /**
     * 回滚迁移
     */
    public function down()
    {
        $this->execute("DROP TABLE IF EXISTS `vip_level_cashback`");
    }
}
