<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 添加店家后台线下机台菜单
 *
 * 菜单结构：
 * - store_offline_machine (父菜单)
 *   - store_offline_machine_list (机台列表)
 *   - store_offline_machine_info (机台资讯)
 *
 * 说明：
 * - 店家后台菜单（type=4）
 * - 只显示绑定到当前店家的线下机台
 * - 机台资讯显示正在游戏中的机台
 */
final class AddStoreOfflineMachineMenus extends AbstractMigration
{
    /**
     * 菜单类型常量
     */
    private const TYPE_STORE = 4; // 店家后台

    /**
     * Migrate Up.
     */
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // 检查父级菜单是否已存在
        $existingParent = $this->query(
            "SELECT id FROM admin_menus WHERE name = 'store_offline_machine' AND type = " . self::TYPE_STORE . " LIMIT 1"
        )->fetch();

        if ($existingParent) {
            $parentId = $existingParent['id'];
        } else {
            // 插入父级菜单：线下机台
            $this->execute("
                INSERT INTO admin_menus (name, icon, url, plugin, pid, sort, status, open, type, created_at, updated_at)
                VALUES ('store_offline_machine', 'LaptopOutlined', '', '', 0, 160, 1, 0, " . self::TYPE_STORE . ", '{$now}', '{$now}')
            ");

            $lastId = $this->query("SELECT LAST_INSERT_ID() as id")->fetch();
            $parentId = $lastId['id'];
        }

        // 准备子菜单数据
        $childMenus = [
            [
                'name' => 'store_offline_machine_list',
                'icon' => 'UnorderedListOutlined',
                'url' => 'ex-admin/addons-webman-controller-StoreOfflineMachineController/index',
                'sort' => 1,
            ],
            [
                'name' => 'store_offline_machine_info',
                'icon' => 'DashboardOutlined',
                'url' => 'ex-admin/addons-webman-controller-StoreOfflineMachineController/infoList',
                'sort' => 2,
            ],
        ];

        // 插入子菜单
        $insertData = [];
        foreach ($childMenus as $child) {
            // 检查子菜单是否已存在
            $existingChild = $this->query(
                "SELECT id FROM admin_menus WHERE name = '{$child['name']}' AND type = " . self::TYPE_STORE . " LIMIT 1"
            )->fetch();

            if (!$existingChild) {
                $insertData[] = [
                    'name' => $child['name'],
                    'icon' => $child['icon'],
                    'url' => $child['url'],
                    'plugin' => '',
                    'pid' => $parentId,
                    'sort' => $child['sort'],
                    'status' => 1,
                    'open' => 1,
                    'type' => self::TYPE_STORE,
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
        // 删除子菜单
        $this->execute("
            DELETE FROM admin_menus
            WHERE name IN ('store_offline_machine_list', 'store_offline_machine_info')
              AND type = " . self::TYPE_STORE . "
        ");

        // 删除父级菜单
        $this->execute("
            DELETE FROM admin_menus
            WHERE name = 'store_offline_machine'
              AND type = " . self::TYPE_STORE . "
        ");

        // 清理孤立的角色菜单关联
        $this->execute("
            DELETE FROM admin_role_menus
            WHERE menu_id NOT IN (SELECT id FROM admin_menus)
        ");
    }
}
