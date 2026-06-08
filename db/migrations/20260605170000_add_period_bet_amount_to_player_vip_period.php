<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * player_vip_period 表新增 period_bet_amount 字段
 * 用于记录周期内打码量总和
 */
final class AddPeriodBetAmountToPlayerVipPeriod extends AbstractMigration
{
    /**
     * Migrate Up.
     */
    public function up(): void
    {
        // 检查字段是否已存在
        $exists = $this->query(
            "SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'player_vip_period' AND COLUMN_NAME = 'period_bet_amount'"
        )->fetch();

        if (!$exists['cnt']) {
            $this->execute("
                ALTER TABLE `player_vip_period`
                ADD COLUMN `period_bet_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT '周期内打码量总和' AFTER `start_bet_amount`
            ");
        }
    }

    /**
     * Migrate Down.
     */
    public function down(): void
    {
        $this->execute("
            ALTER TABLE `player_vip_period`
            DROP COLUMN IF EXISTS `period_bet_amount`
        ");
    }
}
