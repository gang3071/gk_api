<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 更新店机后台设备列表菜单的PID为4
 */
class UpdateStorePlayerMenuPid extends AbstractMigration
{
    /**
     * 执行迁移
     */
    public function up()
    {
        // 更新 store_player 菜单的 pid 为 4
        $this->execute("
            UPDATE `admin_menus`
            SET `pid` = 4, `updated_at` = NOW()
            WHERE `name` = 'store_player' AND `plugin` = 'webman'
        ");

        // 更新 store_player_list 菜单的 pid 为 4
        $this->execute("
            UPDATE `admin_menus`
            SET `pid` = 4, `updated_at` = NOW()
            WHERE `name` = 'store_player_list' AND `plugin` = 'webman'
        ");
    }

    /**
     * 回滚迁移
     */
    public function down()
    {
        // 获取 store_player 菜单的ID
        $parentMenuId = $this->fetchRow("SELECT id FROM `admin_menus` WHERE `name` = 'store_player' AND `plugin` = 'webman' LIMIT 1")['id'];

        // 恢复 store_player 菜单的 pid 为 0（顶级菜单）
        $this->execute("
            UPDATE `admin_menus`
            SET `pid` = 0, `updated_at` = NOW()
            WHERE `name` = 'store_player' AND `plugin` = 'webman'
        ");

        // 恢复 store_player_list 菜单的 pid 为其父级菜单ID
        $this->execute("
            UPDATE `admin_menus`
            SET `pid` = {$parentMenuId}, `updated_at` = NOW()
            WHERE `name` = 'store_player_list' AND `plugin` = 'webman'
        ");
    }
}
