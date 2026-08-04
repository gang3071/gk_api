<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 将店家后台菜单从"设备管理/设备列表"改回"玩家管理/玩家列表"
 *
 * 虽然在线下渠道中 Player 模型代表物理设备，但从用户角度：
 * - 店家管理的是"玩家账号"，而非"物理设备"
 * - 使用"玩家管理"更符合业务语义和用户理解
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
        // 更新父级菜单名称：设备管理 -> 玩家管理
        $this->execute("
            UPDATE `admin_menus`
            SET `name` = '玩家管理', `updated_at` = NOW()
            WHERE `name` = '设备管理'
              AND `plugin` = 'webman'
              AND `type` = 4
              AND `pid` = 0
        ");

        // 更新子菜单名称：设备列表 -> 玩家列表
        $this->execute("
            UPDATE `admin_menus`
            SET `name` = '玩家列表', `updated_at` = NOW()
            WHERE `name` = '设备列表'
              AND `plugin` = 'webman'
              AND `type` = 4
              AND `url` = 'ex-admin/addons-webman-controller-StorePlayerController/index'
        ");
    }

    /**
     * 回滚迁移
     */
    public function down()
    {
        // 恢复父级菜单名称：玩家管理 -> 设备管理
        $this->execute("
            UPDATE `admin_menus`
            SET `name` = '设备管理', `updated_at` = NOW()
            WHERE `name` = '玩家管理'
              AND `plugin` = 'webman'
              AND `type` = 4
              AND `pid` = 0
        ");

        // 恢复子菜单名称：玩家列表 -> 设备列表
        $this->execute("
            UPDATE `admin_menus`
            SET `name` = '设备列表', `updated_at` = NOW()
            WHERE `name` = '玩家列表'
              AND `plugin` = 'webman'
              AND `type` = 4
              AND `url` = 'ex-admin/addons-webman-controller-StorePlayerController/index'
        ");
    }
}
