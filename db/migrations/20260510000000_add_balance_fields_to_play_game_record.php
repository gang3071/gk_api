<?php

use Phinx\Migration\AbstractMigration;

/**
 * 为play_game_record表添加下注前后余额字段
 * 用于记录玩家下注时的余额变化情况
 */
class AddBalanceFieldsToPlayGameRecord extends AbstractMigration
{
    /**
     * 执行迁移
     */
    public function up()
    {
        // 检查字段是否已存在，避免重复添加
        $sql = "SELECT COUNT(*) as count FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'play_game_record'
                AND COLUMN_NAME = 'balance_before'";

        $result = $this->fetchRow($sql);

        if ($result['count'] == 0) {
            // 添加下注前后余额字段
            $this->execute("
                ALTER TABLE `play_game_record`
                ADD COLUMN `balance_before` DECIMAL(20,2) NULL DEFAULT NULL COMMENT '下注前余额' AFTER `diff`,
                ADD COLUMN `balance_after` DECIMAL(20,2) NULL DEFAULT NULL COMMENT '下注后余额' AFTER `balance_before`,
                ADD KEY `idx_balance_before` (`balance_before`),
                ADD KEY `idx_balance_after` (`balance_after`)
            ");
        }
    }

    /**
     * 回滚迁移
     */
    public function down()
    {
        // 检查字段是否存在
        $sql = "SELECT COUNT(*) as count FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'play_game_record'
                AND COLUMN_NAME = 'balance_before'";

        $result = $this->fetchRow($sql);

        if ($result['count'] > 0) {
            // 删除下注前后余额字段
            $this->execute("
                ALTER TABLE `play_game_record`
                DROP INDEX `idx_balance_before`,
                DROP INDEX `idx_balance_after`,
                DROP COLUMN `balance_after`,
                DROP COLUMN `balance_before`
            ");
        }
    }
}
