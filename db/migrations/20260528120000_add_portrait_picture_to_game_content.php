<?php

use Phinx\Migration\AbstractMigration;

/**
 * game 表新增启用竖图开关，game_content 表新增游戏竖图字段
 */
class AddPortraitPictureToGameContent extends AbstractMigration
{
    /**
     * 执行迁移
     */
    public function up()
    {
        // game 表新增 enable_portrait_picture
        $exists = $this->fetchRow("
            SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'game' AND COLUMN_NAME = 'enable_portrait_picture'
        ");
        if (!$exists['cnt']) {
            $this->execute("
                ALTER TABLE `game`
                ADD COLUMN `enable_portrait_picture` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否启用竖图（0=否，1=是）' AFTER `enable_big_picture`;
            ");
        }

        // game_content 表新增 portrait_picture
        $exists = $this->fetchRow("
            SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'game_content' AND COLUMN_NAME = 'portrait_picture'
        ");
        if (!$exists['cnt']) {
            $this->execute("
                ALTER TABLE `game_content`
                ADD COLUMN `portrait_picture` VARCHAR(500) DEFAULT NULL COMMENT '游戏竖图' AFTER `big_picture`;
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
            DROP COLUMN `portrait_picture`;
        ");

        $this->execute("
            ALTER TABLE `game`
            DROP COLUMN `enable_portrait_picture`;
        ");
    }
}
