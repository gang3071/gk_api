<?php

use Phinx\Migration\AbstractMigration;

/**
 * game 表新增启用大图开关，game_content 表新增游戏大图字段
 */
class AddBigPictureToGameContent extends AbstractMigration
{
    /**
     * 执行迁移
     */
    public function up()
    {
        // game 表新增 enable_big_picture
        $exists = $this->fetchRow("
            SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'game' AND COLUMN_NAME = 'enable_big_picture'
        ");
        if (!$exists['cnt']) {
            $this->execute("
                ALTER TABLE `game`
                ADD COLUMN `enable_big_picture` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否启用大图（0=否，1=是）' AFTER `display_mode`;
            ");
        }

        // game_content 表新增 big_picture
        $exists = $this->fetchRow("
            SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'game_content' AND COLUMN_NAME = 'big_picture'
        ");
        if (!$exists['cnt']) {
            $this->execute("
                ALTER TABLE `game_content`
                ADD COLUMN `big_picture` VARCHAR(500) DEFAULT NULL COMMENT '游戏大图' AFTER `picture`;
            ");
        }
    }

    /**
     * 回滚迁移
     */
    public function down()
    {
        $this->execute("
            ALTER TABLE `game_content`
            DROP COLUMN `big_picture`;
        ");

        $this->execute("
            ALTER TABLE `game`
            DROP COLUMN `enable_big_picture`;
        ");
    }
}
