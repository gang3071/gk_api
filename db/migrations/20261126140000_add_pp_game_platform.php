<?php

use Phinx\Migration\AbstractMigration;

/**
 * 添加PP电子（Pragmatic Play）游戏平台数据
 * 参考 20261123144100_add_as_game_platform.php（AS电子）数据结构
 * cate_id '[2]' 与线上 AS/T9/SlotMill/Hacksaw 等电子类平台一致
 */
class AddPpGamePlatform extends AbstractMigration
{
    /**
     * 执行迁移
     */
    public function up()
    {
        // 插入PP电子平台数据
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
                'PP',
                'PP电子',
                NULL,
                3.00,
                '',
                '[2]',
                3,
                0,
                1,
                19,
                7,
                '00:00:00',
                '23:00:00',
                1,
                8,
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
        // 删除PP电子平台数据
        $this->execute("
            DELETE FROM `game_platform` WHERE `code` = 'PP'
        ");
    }
}
