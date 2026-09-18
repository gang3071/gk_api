<?php

use Phinx\Migration\AbstractMigration;

/**
 * 更新餐點系統菜單
 */
final class UpdateDishMenusForRole extends AbstractMigration
{
    public function up(): void
    {
        // ---------------------------------------- 渠道 ----------------------------------------
        $this->execute("
            UPDATE `admin_menus` SET `url` = 'ex-admin/addons-webman-controller-ChannelDishCategoryController/index', `updated_at` = NOW()
            WHERE `name` = 'dish_category' AND `type` = 2
        ");
        $this->execute("
            UPDATE `admin_menus` SET `url` = 'ex-admin/addons-webman-controller-ChannelDishController/index', `updated_at` = NOW()
            WHERE `name` = 'dish' AND `type` = 2
        ");
        $this->execute("
            UPDATE `admin_menus` SET `url` = 'ex-admin/addons-webman-controller-ChannelDishOrderController/index', `updated_at` = NOW()
            WHERE `name` = 'dish_order' AND `type` = 2
        ");
        // ---------------------------------------- 門店 ----------------------------------------
        $this->execute("
            UPDATE `admin_menus` SET `url` = 'ex-admin/addons-webman-controller-StoreDishController/index', `updated_at` = NOW()
            WHERE `name` = 'dish' AND `type` = 4
        ");
        $this->execute("
            UPDATE `admin_menus` SET `url` = 'ex-admin/addons-webman-controller-StoreDishOrderController/index', `updated_at` = NOW()
            WHERE `name` = 'dish_order' AND `type` = 4
        ");
        // ---------------------------------------- 代理 ----------------------------------------
        $this->execute("DELETE FROM `admin_menus` WHERE `name` = 'dish_manage' AND `type` = 3");
        $this->execute("DELETE FROM `admin_menus` WHERE `name` = 'dish_order' AND `type` = 3");
    }

    public function down(): void
    {
        // ---------------------------------------- 渠道 ----------------------------------------
        $this->execute("
            UPDATE `admin_menus` SET `url` = 'ex-admin/addons-webman-controller-DishCategoryController/index', `updated_at` = NOW()
            WHERE `name` = 'dish_category' AND `type` = 2
        ");
        $this->execute("
            UPDATE `admin_menus` SET `url` = 'ex-admin/addons-webman-controller-DishController/index', `updated_at` = NOW()
            WHERE `name` = 'dish' AND `type` = 2
        ");
        $this->execute("
            UPDATE `admin_menus` SET `url` = 'ex-admin/addons-webman-controller-DishOrderController/index', `updated_at` = NOW()
            WHERE `name` = 'dish_order' AND `type` = 2
        ");
        // ---------------------------------------- 門店 ----------------------------------------
        $this->execute("
            UPDATE `admin_menus` SET `url` = 'ex-admin/addons-webman-controller-DishController/index', `updated_at` = NOW()
            WHERE `name` = 'dish' AND `type` = 4
        ");
        $this->execute("
            UPDATE `admin_menus` SET `url` = 'ex-admin/addons-webman-controller-DishOrderController/index', `updated_at` = NOW()
            WHERE `name` = 'dish_order' AND `type` = 4
        ");
    }
}
