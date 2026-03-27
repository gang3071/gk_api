<?php

use Phinx\Migration\AbstractMigration;

/**
 * 添加QT游戏平台数据
 */
class AddQtGamePlatform extends AbstractMigration
{
    /**
     * 执行迁移
     */
    public function up()
    {
        // 插入QT平台数据
        $this->execute("
            INSERT INTO `game_platform` (
                `code`,
                `name`,
                `config`,
                `ratio`,
                `logo`,
                `cate_id`,
                `display_mode`,
                `has_lobby`,
                `status`,
                `sort`,
                `created_at`,
                `updated_at`,
                `deleted_at`,
                `player_code`,
                `player_password`,
                `picture`
            ) VALUES (
                'QT',
                'QT',
                NULL,
                0.00,
                'https://storage.googleapis.com/yjbfile/test/images/qt_logo.png',
                '[2]',
                3,
                0,
                1,
                0,
                NOW(),
                NOW(),
                NULL,
                NULL,
                NULL,
                '{\"zh-CN\":{\"picture\":\"https://storage.googleapis.com/yjbfile/test/images/qt_picture_cn.png\"},\"zh-TW\":{\"picture\":\"https://storage.googleapis.com/yjbfile/test/images/qt_picture_tw.png\"},\"en\":{\"picture\":\"https://storage.googleapis.com/yjbfile/test/images/qt_picture_en.png\"},\"jp\":{\"picture\":\"https://storage.googleapis.com/yjbfile/test/images/qt_picture_jp.png\"}}'
            )
        ");
    }

    /**
     * 回滚迁移
     */
    public function down()
    {
        // 删除QT平台数据
        $this->execute("
            DELETE FROM `game_platform` WHERE `code` = 'QT'
        ");
    }
}
