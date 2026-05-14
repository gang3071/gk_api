<?php

use Phinx\Migration\AbstractMigration;

/**
 * 添加玩家来源字段
 * 用于区分线上/线下玩家
 */
class AddPlayerSourceToPlayer extends AbstractMigration
{
    /**
     * 执行迁移
     */
    public function up()
    {
        $this->execute("
            ALTER TABLE `player`
            ADD COLUMN `player_source` TINYINT(1) NOT NULL DEFAULT 2 COMMENT '玩家来源 1-线上 2-线下' AFTER `player_type`;
        ");

        // 添加索引以优化查询性能
        $this->execute("
            ALTER TABLE `player`
            ADD INDEX `idx_player_source` (`player_source`);
        ");
    }

    /**
     * 回滚迁移
     */
    public function down()
    {
        $this->execute("
            ALTER TABLE `player`
            DROP INDEX `idx_player_source`;
        ");

        $this->execute("
            ALTER TABLE `player`
            DROP COLUMN `player_source`;
        ");
    }
}
