<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 将菜单中的玩家列表重命名为设备列表
 */
class RenamePlayerToDeviceInMenu extends AbstractMigration
{
    /**
     * 执行迁移
     */
    public function up()
    {
        // 更新子菜单名称：store_player_list -> store_device_list
        $this->execute("
            UPDATE `admin_menus`
            SET `name` = '设备列表', `updated_at` = NOW()
            WHERE `name` = '玩家列表' 
        ");
    }

    /**
     * 回滚迁移
     */
    public function down()
    {
        // 恢复子菜单名称：store_device_list -> store_player_list
        $this->execute("
            UPDATE `admin_menus`
            SET `name` = '玩家列表', `updated_at` = NOW()
            WHERE `name` = '设备列表' 
        ");
    }
}