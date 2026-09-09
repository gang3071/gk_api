<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 添加玩家积分管理菜单
 */
final class AddPlayerPointsMenus extends AbstractMigration
{
    /**
     * 菜单类型常量
     */
    private const TYPE_MAIN = 1;     // 主站后台
    private const TYPE_CHANNEL = 2;  // 渠道后台
    private const TYPE_AGENT = 3;    // 代理后台
    private const TYPE_STORE = 4;    // 门店后台

    /**
     * Migrate Up.
     */
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // 准备菜单数据
        $menus = [
            // 主站后台 - 积分记录
            [
                'name' => 'player_points_records',
                'icon' => 'far fa-circle',
                'url' => 'ex-admin/addons-webman-controller-PlayerPointsController/index',
                'type' => self::TYPE_MAIN,
                'parent_name' => 'player_manage',
                'sort' => 100,
            ],
            // 渠道后台 - 积分记录
            [
                'name' => 'player_points_records',
                'icon' => 'far fa-circle',
                'url' => 'ex-admin/addons-webman-controller-ChannelPlayerPointsController/index',
                'type' => self::TYPE_CHANNEL,
                'parent_name' => 'channel_player_manage',
                'sort' => 100,
            ],
            // 代理后台 - 积分记录
            [
                'name' => 'player_points_records',
                'icon' => 'far fa-circle',
                'url' => 'ex-admin/addons-webman-controller-AgentPlayerPointsController/index',
                'type' => self::TYPE_AGENT,
                'parent_name' => 'agent_game_log',
                'sort' => 100,
            ],
            // 门店后台 - 积分记录
            [
                'name' => 'player_points_records',
                'icon' => 'far fa-circle',
                'url' => 'ex-admin/addons-webman-controller-StorePlayerPointsController/index',
                'type' => self::TYPE_STORE,
                'parent_name' => 'store_player_manage',
                'sort' => 100,
            ],
        ];

        $insertData = [];

        foreach ($menus as $menu) {
            // 检查菜单是否已存在
            $existing = $this->query(
                "SELECT id FROM admin_menus
                 WHERE name = '{$menu['name']}'
                 AND type = {$menu['type']}
                 LIMIT 1"
            )->fetch();

            if (!$existing) {
                // 查找父菜单ID
                $parent = $this->query(
                    "SELECT id FROM admin_menus
                     WHERE name = '{$menu['parent_name']}'
                     AND type = {$menu['type']}
                     LIMIT 1"
                )->fetch();

                $pid = $parent ? $parent['id'] : 0;

                $insertData[] = [
                    'name' => $menu['name'],
                    'icon' => $menu['icon'],
                    'url' => $menu['url'],
                    'plugin' => '',
                    'pid' => $pid,
                    'sort' => $menu['sort'],
                    'status' => 1,
                    'open' => 1,
                    'type' => $menu['type'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if (!empty($insertData)) {
            $this->table('admin_menus')->insert($insertData)->saveData();
        }
    }

    /**
     * Migrate Down.
     */
    public function down(): void
    {
        // 删除积分菜单
        $this->execute("
            DELETE FROM admin_menus
            WHERE name = 'player_points_records'
              AND type IN (" . self::TYPE_MAIN . ", " . self::TYPE_CHANNEL . ", " . self::TYPE_AGENT . ", " . self::TYPE_STORE . ")
        ");

        // 清理孤立的角色菜单关联
        $this->execute("
            DELETE FROM admin_role_menus
            WHERE menu_id NOT IN (SELECT id FROM admin_menus)
        ");
    }
}
