<?php

use Phinx\Migration\AbstractMigration;

/**
 * 添加餐點訂單明細報表菜單
 */
final class AddDishOrderReportItem extends AbstractMigration
{
    public function up(): void
    {
        // ---------------------------------------- 總站 ----------------------------------------
        $parentMenu = $this->query("SELECT id FROM `admin_menus` WHERE `name` = 'dish_manage' AND `type` = 1 ORDER BY id DESC LIMIT 1")->fetch();

        $this->execute("
            INSERT INTO `admin_menus` (`name`, `icon`, `url`, `plugin`, `pid`, `sort`, `type`, `status`, `open`, `created_at`, `updated_at`)
            VALUES ('dish_order_report_item', 'far fa-circle', 'ex-admin/addons-webman-controller-DishOrderController/reportItem', '', {$parentMenu['id']}, 4, 1, 1, 0, NOW(), NOW())
        ");
        // ---------------------------------------- 渠道 ----------------------------------------
        $parentMenu = $this->query("SELECT id FROM `admin_menus` WHERE `name` = 'dish_manage' AND `type` = 2 ORDER BY id DESC LIMIT 1")->fetch();

        $this->execute("
            INSERT INTO `admin_menus` (`name`, `icon`, `url`, `plugin`, `pid`, `sort`, `type`, `status`, `open`, `created_at`, `updated_at`)
            VALUES ('dish_order_report_item', 'far fa-circle', 'ex-admin/addons-webman-controller-ChannelDishOrderController/reportItem', '', {$parentMenu['id']}, 4, 2, 1, 0, NOW(), NOW())
        ");
        // ---------------------------------------- 門店 ----------------------------------------
        $parentMenu = $this->query("SELECT id FROM `admin_menus` WHERE `name` = 'dish_manage' AND `type` = 4 ORDER BY id DESC LIMIT 1")->fetch();

        $this->execute("
            INSERT INTO `admin_menus` (`name`, `icon`, `url`, `plugin`, `pid`, `sort`, `type`, `status`, `open`, `created_at`, `updated_at`)
            VALUES ('dish_order_report_item', 'far fa-circle', 'ex-admin/addons-webman-controller-StoreDishOrderController/reportItem', '', {$parentMenu['id']}, 4, 4, 1, 0, NOW(), NOW())
        ");
    }

    public function down(): void
    {
        $this->execute("DELETE FROM `admin_menus` WHERE `name` = 'dish_order_report_item'");
    }
}
