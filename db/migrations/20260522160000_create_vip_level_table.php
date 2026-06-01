<?php

use Phinx\Migration\AbstractMigration;

/**
 * 创建 VIP 等级表
 */
class CreateVipLevelTable extends AbstractMigration
{
    /**
     * 执行迁移
     */
    public function up()
    {
        // 检查表是否已存在
        if ($this->hasTable('vip_level')) {
            return;
        }

        $this->execute("
            CREATE TABLE `vip_level` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
                `name` VARCHAR(50) NOT NULL COMMENT '等级名称',
                `upgrade_limit_days` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '升级限制时间（天数）',
                `retain_level_days` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '保级时间（天数）',
                `retain_level_bet_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT '保级所需打码量',
                `upgrade_bet_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT '升级所需打码量',
                `min_claim_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT '最小领取额',
                `birthday_bonus` DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT '生日礼金',
                `sort` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '排序',
                `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '状态（0=禁用，1=启用）',
                `created_at` DATETIME DEFAULT NULL COMMENT '创建时间',
                `updated_at` DATETIME DEFAULT NULL COMMENT '更新时间',
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='VIP等级表';
        ");
    }

    /**
     * 回滚迁移
     */
    public function down()
    {
        $this->execute("DROP TABLE IF EXISTS `vip_level`");
    }
}
