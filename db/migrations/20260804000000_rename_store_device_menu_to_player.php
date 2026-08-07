<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 将店家后台菜单从"设备管理/设备列表"改为"玩家管理/玩家列表"（多语言支持）
 *
 * 通过子菜单 URL 定位，然后找到父级菜单进行更新
 *
 * @date 2026-08-04
 */
class RenameStoreDeviceMenuToPlayer extends AbstractMigration
{
    /**
     * 执行迁移
     */
    public function up()
    {
        // 第一步：通过子菜单 URL 找到子菜单记录，获取其 pid（父级菜单ID）
        $childMenu = $this->fetchRow("
            SELECT `id`, `pid`
            FROM `admin_menus`
            WHERE `url` = 'ex-admin/addons-webman-controller-StorePlayerController/index'
              AND `plugin` = 'webman'
              AND `type` = 4
            LIMIT 1
        ");

        if (!$childMenu) {
            // 子菜单不存在，跳过迁移
            return;
        }

        $childMenuId = $childMenu['id'];
        $parentMenuId = $childMenu['pid'];

        // 第二步：更新父级菜单名称为翻译键（不带 menu. 前缀）
        $this->execute("
            UPDATE `admin_menus`
            SET `name` = 'store_player',
                `updated_at` = NOW()
            WHERE `id` = {$parentMenuId}
        ");

        // 第三步：更新子菜单名称为翻译键（不带 menu. 前缀）
        $this->execute("
            UPDATE `admin_menus`
            SET `name` = 'store_player_list',
                `updated_at` = NOW()
            WHERE `id` = {$childMenuId}
        ");
    }

    /**
     * 回滚迁移
     */
    public function down()
    {
        // 回滚时恢复为设备相关名称
        $childMenu = $this->fetchRow("
            SELECT `id`, `pid`
            FROM `admin_menus`
            WHERE `url` = 'ex-admin/addons-webman-controller-StorePlayerController/index'
              AND `plugin` = 'webman'
              AND `type` = 4
            LIMIT 1
        ");

        if (!$childMenu) {
            return;
        }

        $childMenuId = $childMenu['id'];
        $parentMenuId = $childMenu['pid'];

        // 恢复父级菜单名称（假设之前是 store_device）
        $this->execute("
            UPDATE `admin_menus`
            SET `name` = 'store_device',
                `updated_at` = NOW()
            WHERE `id` = {$parentMenuId}
        ");

        // 恢复子菜单名称（假设之前是 store_device_list）
        $this->execute("
            UPDATE `admin_menus`
            SET `name` = 'store_device_list',
                `updated_at` = NOW()
            WHERE `id` = {$childMenuId}
        ");
    }
}
