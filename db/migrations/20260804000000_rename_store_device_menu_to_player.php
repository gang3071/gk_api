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
        // 使用 LIKE 匹配，支持不同语言的文本
        $this->execute("
            UPDATE `admin_menus`
            SET `name` = CASE
                WHEN `name` = '设备管理' THEN '玩家管理'
                WHEN `name` = '設備管理' THEN '玩家管理'
                WHEN `name` = 'Device Management' THEN 'Player Management'
                WHEN `name` = 'デバイス管理' THEN 'プレイヤー管理'
                ELSE `name`
            END,
            `updated_at` = NOW()
            WHERE `name` IN ('设备管理', '設備管理', 'Device Management', 'デバイス管理')
              AND `plugin` = 'webman'
              AND `type` = 4
              AND `pid` = 0
        ");

        // 更新子菜单名称：设备列表 -> 玩家列表
        $this->execute("
            UPDATE `admin_menus`
            SET `name` = CASE
                WHEN `name` = '设备列表' THEN '玩家列表'
                WHEN `name` = '設備列表' THEN '玩家列表'
                WHEN `name` = 'Device List' THEN 'Player List'
                WHEN `name` = 'デバイスリスト' THEN 'プレイヤーリスト'
                ELSE `name`
            END,
            `updated_at` = NOW()
            WHERE `name` IN ('设备列表', '設備列表', 'Device List', 'デバイスリスト')
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
            SET `name` = CASE
                WHEN `name` = '玩家管理' THEN '设备管理'
                WHEN `name` = 'Player Management' THEN 'Device Management'
                WHEN `name` = 'プレイヤー管理' THEN 'デバイス管理'
                ELSE `name`
            END,
            `updated_at` = NOW()
            WHERE `name` IN ('玩家管理', 'Player Management', 'プレイヤー管理')
              AND `plugin` = 'webman'
              AND `type` = 4
              AND `pid` = 0
        ");

        // 恢复子菜单名称：玩家列表 -> 设备列表
        $this->execute("
            UPDATE `admin_menus`
            SET `name` = CASE
                WHEN `name` = '玩家列表' THEN '设备列表'
                WHEN `name` = 'Player List' THEN 'Device List'
                WHEN `name` = 'プレイヤーリスト' THEN 'デバイスリスト'
                ELSE `name`
            END,
            `updated_at` = NOW()
            WHERE `name` IN ('玩家列表', 'Player List', 'プレイヤーリスト')
              AND `plugin` = 'webman'
              AND `type` = 4
              AND `url` = 'ex-admin/addons-webman-controller-StorePlayerController/index'
        ");
    }
}
