<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 新增门店后台设备管理菜单（StoreDeviceController）
 * type = 4 表示店家菜单（AdminDepartment::TYPE_STORE）
 */
final class AddStoreDeviceMenu extends AbstractMigration
{
    private const MENU_NAME = 'store_device_list';
    private const MENU_URL  = 'ex-admin/addons-webman-controller-StoreDeviceController/index';

    public function up(): void
    {
        // 若已存在但 type/plugin 有误（如之前以 type=1 插入），直接修正后返回
        $existing = $this->fetchRow(
            "SELECT id, type, plugin FROM `admin_menus` WHERE `name` = '" . self::MENU_NAME . "' LIMIT 1"
        );
        if ($existing) {
            if ((int)$existing['type'] !== 4 || $existing['plugin'] !== 'webman') {
                $this->execute(
                    "UPDATE `admin_menus` SET `type` = 4, `plugin` = 'webman', `updated_at` = NOW()
                     WHERE `id` = {$existing['id']}"
                );
                echo "✓ 已修正菜单 type=4, plugin=webman（id={$existing['id']}）\n";
            } else {
                echo "- 菜单已存在且正确，跳过: " . self::MENU_NAME . "\n";
            }
            return;
        }

        // 找到 store_player_list 菜单的父级（pid 即店机后台父菜单 ID）
        $storePlayerMenu = $this->fetchRow(
            "SELECT pid FROM `admin_menus`
             WHERE `url` = 'ex-admin/addons-webman-controller-StorePlayerController/index'
             AND `plugin` = 'webman'
             LIMIT 1"
        );
        $pid = $storePlayerMenu ? (int)$storePlayerMenu['pid'] : 0;

        // 计算 sort：当前父级下最大 sort + 1
        $maxSort = $this->fetchRow(
            "SELECT IFNULL(MAX(`sort`), 0) AS max_sort FROM `admin_menus` WHERE `pid` = {$pid}"
        );
        $sort = (int)($maxSort['max_sort'] ?? 0) + 1;

        $this->table('admin_menus')->insert([
            [
                'name'       => self::MENU_NAME,
                'icon'       => 'LaptopOutlined',
                'url'        => self::MENU_URL,
                'plugin'     => 'webman',
                'pid'        => $pid,
                'sort'       => $sort,
                'status'     => 1,
                'open'       => 0,
                'type'       => 4,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
        ])->saveData();

        $newMenuId = (int)$this->getAdapter()->getConnection()->lastInsertId();
        echo "✓ 门店设备列表菜单已新增（id={$newMenuId}, pid={$pid}, type=4）\n";

        // 同步授权：将 store_player_list 的角色权限复制给本菜单
        $storePlayerMenuRow = $this->fetchRow(
            "SELECT id FROM `admin_menus`
             WHERE `url` = 'ex-admin/addons-webman-controller-StorePlayerController/index'
             AND `plugin` = 'webman'
             LIMIT 1"
        );
        if (!$storePlayerMenuRow) {
            echo "- 未找到 StorePlayerController 菜单，跳过角色授权\n";
            return;
        }
        $refMenuId = (int)$storePlayerMenuRow['id'];

        // 自动检测角色菜单关联表名
        $tableCheck = $this->fetchRow(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('admin_role_menus','admin_role_menu')
             LIMIT 1"
        );
        if (!$tableCheck) {
            echo "- 未找到角色菜单关联表，跳过角色授权\n";
            return;
        }
        $roleMenuTable = $tableCheck['TABLE_NAME'];

        $this->execute(
            "INSERT INTO `{$roleMenuTable}` (role_id, menu_id)
             SELECT role_id, {$newMenuId}
             FROM `{$roleMenuTable}`
             WHERE menu_id = {$refMenuId}
               AND NOT EXISTS (
                   SELECT 1 FROM `{$roleMenuTable}` r2
                   WHERE r2.role_id = `{$roleMenuTable}`.role_id AND r2.menu_id = {$newMenuId}
               )"
        );
        echo "✓ 已同步授权给拥有门店玩家列表权限的角色\n";
    }

    public function down(): void
    {
        $menu = $this->fetchRow(
            "SELECT id FROM `admin_menus` WHERE `name` = '" . self::MENU_NAME . "' LIMIT 1"
        );
        if (!$menu) {
            return;
        }
        $menuId = (int)$menu['id'];

        foreach (['admin_role_menus', 'admin_role_menu'] as $table) {
            $exists = $this->fetchRow(
                "SELECT 1 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}'"
            );
            if ($exists) {
                $this->execute("DELETE FROM `{$table}` WHERE `menu_id` = {$menuId}");
                break;
            }
        }

        $this->execute("DELETE FROM `admin_menus` WHERE `id` = {$menuId}");
        echo "✓ 门店设备列表菜单已回滚\n";
    }
}
