<?php

use Phinx\Migration\AbstractMigration;

/**
 * 添加排行榜菜單
 */
final class AddLeaderboardMenus extends AbstractMigration
{
    public function up(): void
    {
        // ---------------------------------------- 總站 ----------------------------------------
        $parentMenu = $this->query("SELECT id FROM `admin_menus` WHERE `name` = 'user_manage' AND `type` = 1 ORDER BY id DESC LIMIT 1")->fetch();

        $this->execute("
            INSERT INTO `admin_menus` (`name`, `icon`, `url`, `plugin`, `pid`, `sort`, `type`, `status`, `open`, `created_at`, `updated_at`)
            VALUES ('leaderboard', 'far fa-circle', 'ex-admin/addons-webman-controller-LeaderboardController/index', '', {$parentMenu['id']}, 15, 1, 1, 0, NOW(), NOW())
        ");
        // ---------------------------------------- 渠道 ----------------------------------------
        $parentMenu = $this->query("SELECT id FROM `admin_menus` WHERE `name` = 'channel_player_manage' AND `type` = 2 ORDER BY id DESC LIMIT 1")->fetch();

        $this->execute("
            INSERT INTO `admin_menus` (`name`, `icon`, `url`, `plugin`, `pid`, `sort`, `type`, `status`, `open`, `created_at`, `updated_at`)
            VALUES ('leaderboard', 'far fa-circle', 'ex-admin/addons-webman-controller-ChannelLeaderboardController/index', '', {$parentMenu['id']}, 15, 2, 1, 0, NOW(), NOW())
        ");
        // ---------------------------------------- 門店 ----------------------------------------
        $parentMenu = $this->query("SELECT id FROM `admin_menus` WHERE `name` = 'store_player' AND `type` = 4 ORDER BY id DESC LIMIT 1")->fetch();

        $this->execute("
            INSERT INTO `admin_menus` (`name`, `icon`, `url`, `plugin`, `pid`, `sort`, `type`, `status`, `open`, `created_at`, `updated_at`)
            VALUES ('leaderboard', 'far fa-circle', 'ex-admin/addons-webman-controller-StoreLeaderboardController/index', '', {$parentMenu['id']}, 4, 4, 1, 0, NOW(), NOW())
        ");
    }

    public function down(): void
    {
        $this->execute("DELETE FROM `admin_menus` WHERE `name` = 'leaderboard'");
    }
}
