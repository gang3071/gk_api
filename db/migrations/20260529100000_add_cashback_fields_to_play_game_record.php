<?php

use Phinx\Migration\AbstractMigration;

/**
 * 为play_game_record表添加VIP反水相关字段
 * 记录玩家每次下注时的VIP等级、反水比例和反水金额
 */
class AddCashbackFieldsToPlayGameRecord extends AbstractMigration
{
    /**
     * 执行迁移
     */
    public function up()
    {
        $sql = "SELECT COUNT(*) as count FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'play_game_record'
                AND COLUMN_NAME = 'vip_level_id'";

        $result = $this->fetchRow($sql);

        if ($result['count'] == 0) {
            $this->execute("
                ALTER TABLE `play_game_record`
                ADD COLUMN `vip_level_id` INT UNSIGNED NULL DEFAULT NULL COMMENT 'VIP等级ID' AFTER `type`,
                ADD COLUMN `cashback_ratio` DECIMAL(10,2) NULL DEFAULT NULL COMMENT '反水比例（100=100%，0.1=0.1%）' AFTER `vip_level_id`,
                ADD COLUMN `cashback_amount` DECIMAL(20,2) NULL DEFAULT NULL COMMENT '反水金额' AFTER `cashback_ratio`,
                ADD KEY `idx_vip_level_id` (`vip_level_id`)
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
                AND TABLE_NAME = 'play_game_record'
                AND COLUMN_NAME = 'vip_level_id'";

        $result = $this->fetchRow($sql);

        if ($result['count'] > 0) {
            $this->execute("
                ALTER TABLE `play_game_record`
                DROP INDEX `idx_vip_level_id`,
                DROP COLUMN `cashback_amount`,
                DROP COLUMN `cashback_ratio`,
                DROP COLUMN `vip_level_id`
            ");
        }
    }
}
