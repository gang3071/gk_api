<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 添加线下机台管理菜单
 *
 * 菜单结构：
 * - offline_machine_manage (父菜单)
 *   - offline_machine_list (机台列表)
 *
 * 说明：
 * - 管理后台菜单（type=1）
 * - 线下机台独立于线上机台管理
 * - 必须绑定店家才能创建
 */
final class AddOfflineMachineMenus extends AbstractMigration
{
    /**
     * 菜单类型常量
     */
    private const TYPE_ADMIN = 1; // 管理后台

    /**
     * Migrate Up.
     */
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // 检查父级菜单是否已存在
        $existingParent = $this->query(
            "SELECT id FROM admin_menus WHERE name = 'offline_machine_manage' LIMIT 1"
        )->fetch();

        if ($existingParent) {
            $parentId = $existingParent['id'];
        } else {
            // 插入父级菜单：线下机台管理
            $this->execute("
                INSERT INTO admin_menus (name, icon, url, plugin, pid, sort, status, open, type, created_at, updated_at)
                VALUES ('offline_machine_manage', 'LaptopOutlined', '', '', 0, 95, 1, 0, " . self::TYPE_ADMIN . ", '{$now}', '{$now}')
            ");

            $lastId = $this->query("SELECT LAST_INSERT_ID() as id")->fetch();
            $parentId = $lastId['id'];
        }

        // 准备子菜单数据
        $childMenus = [
            [
                'name' => 'offline_machine_list',
                'icon' => 'UnorderedListOutlined',
                'url' => 'ex-admin/addons-webman-controller-AdminOfflineMachineController/index',
                'sort' => 1,
            ],
        ];

        // 插入子菜单
        $insertData = [];
        foreach ($childMenus as $child) {
            // 检查子菜单是否已存在
            $existingChild = $this->query(
                "SELECT id FROM admin_menus WHERE name = '{$child['name']}' LIMIT 1"
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
                    'type' => self::TYPE_ADMIN,
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
            DELETE FROM admin_menus WHERE name = 'offline_machine_list' AND type = " . self::TYPE_ADMIN . "
        ");

        // 删除父级菜单
        $this->execute("
            DELETE FROM admin_menus WHERE name = 'offline_machine_manage' AND type = " . self::TYPE_ADMIN . "
        ");
    }
}
