<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 新增门店后台设备管理菜单（StoreDeviceController）
 */
final class AddStoreDeviceMenu extends AbstractMigration
{
    private const MENU_NAME = 'store_device_list';
    private const MENU_URL  = 'ex-admin/addons-webman-controller-StoreDeviceController/index';

    public function up(): void
    {
        // 幂等检查
        $existing = $this->fetchRow(
            "SELECT id FROM `admin_menus` WHERE `name` = '" . self::MENU_NAME . "' LIMIT 1"
        );
        if ($existing) {
            echo "- 菜单已存在，跳过: " . self::MENU_NAME . "\n";
            return;
        }

        // 找到门店玩家列表所在的父级菜单（同级插入）
        $storePlayerMenu = $this->fetchRow(
            "SELECT pid FROM `admin_menus`
             WHERE `url` = 'ex-admin/addons-webman-controller-StorePlayerController/index'
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
                'plugin'     => '',
                'pid'        => $pid,
                'sort'       => $sort,
                'status'     => 1,
                'open'       => 0,
                'type'       => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
        ])->saveData();

        $newMenuId = (int)$this->getAdapter()->getConnection()->lastInsertId();
        echo "✓ 门店设备列表菜单已新增（id={$newMenuId}, pid={$pid}, sort={$sort}）\n";

        // 将拥有门店玩家列表权限的角色同步授予此菜单
        $storePlayerMenuRow = $this->fetchRow(
            "SELECT id FROM `admin_menus`
             WHERE `url` = 'ex-admin/addons-webman-controller-StorePlayerController/index'
             LIMIT 1"
        );
        if (!$storePlayerMenuRow) {
            echo "- 未找到 StorePlayerController 菜单，跳过角色授权\n";
            return;
        }
        $refMenuId = (int)$storePlayerMenuRow['id'];

        // 检查 admin_role_menus 表名（可能是 admin_role_menu 或 admin_role_menus）
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

        // 清理角色授权
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
