<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 添加玩家身份证黑名单菜单（渠道后台）
 */
final class AddPlayerBlacklistMenu extends AbstractMigration
{
    private const TYPE_CHANNEL = 2;

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $existing = $this->query(
            "SELECT id FROM admin_menus
             WHERE name = 'player_id_card_blacklist'
               AND type = " . self::TYPE_CHANNEL . "
             LIMIT 1"
        )->fetch();

        if (!$existing) {
            $parent = $this->query(
                "SELECT id FROM admin_menus
                 WHERE name = 'channel_player_manage'
                   AND type = " . self::TYPE_CHANNEL . "
                 LIMIT 1"
            )->fetch();

            $pid = $parent ? $parent['id'] : 0;

            $this->table('admin_menus')->insert([
                [
                    'name'       => 'player_id_card_blacklist',
                    'icon'       => 'far fa-circle',
                    'url'        => 'ex-admin/addons-webman-controller-ChannelPlayerIdCardBlacklistController/index',
                    'plugin'     => '',
                    'pid'        => $pid,
                    'sort'       => 100,
                    'status'     => 1,
                    'open'       => 1,
                    'type'       => self::TYPE_CHANNEL,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ])->saveData();
        }
    }

    public function down(): void
    {
        $this->execute(
            "DELETE FROM admin_menus
             WHERE name = 'player_id_card_blacklist'
               AND type = " . self::TYPE_CHANNEL
        );

        $this->execute(
            "DELETE FROM admin_role_menus
             WHERE menu_id NOT IN (SELECT id FROM admin_menus)"
        );
    }
}
