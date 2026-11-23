<?php

use Phinx\Migration\AbstractMigration;

/**
 * 添加AS电子（AS Gaming）游戏平台数据
 * 参考 game_platform 表中 RSG 平台数据结构
 */
class AddAsGamePlatform extends AbstractMigration
{
    /**
     * 执行迁移
     */
    public function up()
    {
        // 插入AS电子平台数据
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
                `default_limit_group_id`,
                `maintenance_week`,
                `maintenance_start_time`,
                `maintenance_end_time`,
                `maintenance_status`,
                `sort`,
                `created_at`,
                `updated_at`,
                `deleted_at`,
                `player_code`,
                `player_password`,
                `picture`
            ) VALUES (
                'AS',
                'AS电子',
                NULL,
                3.00,
                '',
                '[4,7,8]',
                3,
                0,
                1,
                19,
                7,
                '00:00:00',
                '23:00:00',
                1,
                7,
                NOW(),
                NOW(),
                NULL,
                NULL,
                NULL,
                '{\"zh-CN\":{\"picture\":\"\"},\"zh-TW\":{\"picture\":\"\"},\"en\":{\"picture\":\"\"},\"jp\":{\"picture\":\"\"}}'
            )
        ");
    }

    /**
     * 回滚迁移
     */
    public function down()
    {
        // 删除AS电子平台数据
        $this->execute("
            DELETE FROM `game_platform` WHERE `code` = 'AS'
        ");
    }
}
