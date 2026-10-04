<?php

use Phinx\Migration\AbstractMigration;

/**
 * 添加玩家身份证黑名单菜单（渠道后台）
 */
class AddPlayerBlacklistMenu extends AbstractMigration
{
    public function up(): void
    {
        $existing = $this->fetchRow(
            "SELECT id FROM `admin_menus`
             WHERE `name` = 'player_id_card_blacklist' AND `type` = 2
             LIMIT 1"
        );

        if ($existing) {
            return;
        }

        $parent = $this->fetchRow(
            "SELECT id FROM `admin_menus`
             WHERE `name` = 'channel_player_manage' AND `type` = 2
             LIMIT 1"
        );

        $pid = $parent ? $parent['id'] : 0;

        $this->execute("
            INSERT INTO `admin_menus` (`name`, `icon`, `url`, `plugin`, `pid`, `sort`, `status`, `open`, `type`, `created_at`, `updated_at`)
            VALUES ('player_id_card_blacklist', 'far fa-circle', 'ex-admin/addons-webman-controller-ChannelPlayerIdCardBlacklistController/index', '', {$pid}, 100, 1, 0, 2, NOW(), NOW())
        ");
    }

    public function down(): void
    {
        $this->execute(
            "DELETE FROM `admin_menus` WHERE `name` = 'player_id_card_blacklist' AND `type` = 2"
        );

        $this->execute(
            "DELETE FROM `admin_role_menus` WHERE `menu_id` NOT IN (SELECT `id` FROM `admin_menus`)"
        );
    }
}
