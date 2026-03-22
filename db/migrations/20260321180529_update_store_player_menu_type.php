<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 更新店机后台设备列表菜单的type为4
 */
class UpdateStorePlayerMenuType extends AbstractMigration
{
    /**
     * 执行迁移
     */
    public function up()
    {
        // 更新 store_player 菜单的 type 为 4（店家菜单）
        $this->execute("
            UPDATE `admin_menus`
            SET `type` = 4, `updated_at` = NOW()
            WHERE `name` = 'store_player' AND `plugin` = 'webman'
        ");

        // 更新 store_player_list 菜单的 type 为 4（店家菜单）
        $this->execute("
            UPDATE `admin_menus`
            SET `type` = 4, `updated_at` = NOW()
            WHERE `name` = 'store_player_list' AND `plugin` = 'webman'
        ");
    }

    /**
     * 回滚迁移
     */
    public function down()
    {
        // 恢复 store_player 菜单的 type 为 1（默认类型）
        $this->execute("
            UPDATE `admin_menus`
            SET `type` = 1, `updated_at` = NOW()
            WHERE `name` = 'store_player' AND `plugin` = 'webman'
        ");

        // 恢复 store_player_list 菜单的 type 为 1（默认类型）
        $this->execute("
            UPDATE `admin_menus`
            SET `type` = 1, `updated_at` = NOW()
            WHERE `name` = 'store_player_list' AND `plugin` = 'webman'
        ");
    }
}
