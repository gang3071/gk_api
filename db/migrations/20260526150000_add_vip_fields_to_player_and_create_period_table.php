<?php

use Phinx\Migration\AbstractMigration;

/**
 * 玩家表新增VIP字段 + 创建玩家VIP周期记录表
 */
class AddVipFieldsToPlayerAndCreatePeriodTable extends AbstractMigration
{
    /**
     * 执行迁移
     */
    public function up()
    {
        // player 表新增 vip_level_id
        $exists = $this->fetchRow("
            SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'player' AND COLUMN_NAME = 'vip_level_id'
        ");
        if (!$exists['cnt']) {
            $this->execute("
                ALTER TABLE `player`
                ADD COLUMN `vip_level_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '当前VIP等级ID' AFTER `player_source`;
            ");
        }

        // player 表新增 total_bet_amount
        $exists = $this->fetchRow("
            SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'player' AND COLUMN_NAME = 'total_bet_amount'
        ");
        if (!$exists['cnt']) {
            $this->execute("
                ALTER TABLE `player`
                ADD COLUMN `total_bet_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT '总打码量（累计不清零）' AFTER `vip_level_id`;
            ");
        }

        // 创建 player_vip_period 表
        if (!$this->hasTable('player_vip_period')) {
            $this->execute("
                CREATE TABLE `player_vip_period` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
                    `player_id` INT UNSIGNED NOT NULL COMMENT '玩家ID',
                    `vip_level_id` INT UNSIGNED NOT NULL COMMENT 'VIP等级ID',
                    `period_type` VARCHAR(20) NOT NULL COMMENT '周期类型（upgrade=升级，retain=保级）',
                    `start_bet_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT '周期开始时的总打码量',
                    `started_at` DATETIME NOT NULL COMMENT '周期开始时间',
                    `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '状态（0=已过期，1=进行中，2=已完成）',
                    `created_at` DATETIME DEFAULT NULL COMMENT '创建时间',
                    `updated_at` DATETIME DEFAULT NULL COMMENT '更新时间',
                    PRIMARY KEY (`id`),
                    INDEX `idx_player_id` (`player_id`),
                    INDEX `idx_player_status` (`player_id`, `status`),
                    INDEX `idx_period_type` (`period_type`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='玩家VIP周期记录表';
            ");
        }
    }

    /**
     * 回滚迁移
     */
    public function down()
    {
        $this->execute("DROP TABLE IF EXISTS `player_vip_period`");

        $this->execute("
            ALTER TABLE `player`
            DROP COLUMN `total_bet_amount`,
            DROP COLUMN `vip_level_id`;
        ");
    }
}
