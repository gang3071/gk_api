<?php

use Phinx\Migration\AbstractMigration;

/**
 * 添加店机后台设备列表菜单权限
 */
class AddStorePlayerMenu extends AbstractMigration
{
    /**
     * 执行迁移
     */
    public function up()
    {
        // 插入设备管理菜单（父级菜单）
        $this->execute("
            INSERT INTO `admin_menus` (`name`, `icon`, `url`, `plugin`, `pid`, `sort`, `status`, `open`, `created_at`, `updated_at`)
            VALUES ('store_player', 'el-icon-s-grid', '', 'webman', 191, 100, 1, 0, NOW(), NOW())
        ");

        // 获取刚插入的父级菜单ID
        $parentMenuId = $this->fetchRow("SELECT id FROM `admin_menus` WHERE `name` = 'store_player' AND `plugin` = 'webman' ORDER BY id DESC LIMIT 1")['id'];

        // 插入设备列表子菜单
        $this->execute("
            INSERT INTO `admin_menus` (`name`, `icon`, `url`, `plugin`, `pid`, `sort`, `status`, `open`, `created_at`, `updated_at`)
            VALUES ('store_player_list', '', 'ex-admin/addons-webman-controller-StorePlayerController/index', 'webman', {$parentMenuId}, 1, 1, 0, NOW(), NOW())
        ");
    }

    /**
     * 回滚迁移
     */
    public function down()
    {
        // 删除设备列表子菜单
        $this->execute("
            DELETE FROM `admin_menus` WHERE `name` = 'store_player_list' AND `plugin` = 'webman'
        ");

        // 删除设备管理父级菜单
        $this->execute("
            DELETE FROM `admin_menus` WHERE `name` = 'store_player' AND `plugin` = 'webman'
        ");
    }
}
