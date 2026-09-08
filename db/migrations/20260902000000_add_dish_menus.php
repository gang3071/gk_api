<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 添加餐點系統菜單
 */
final class AddDishMenus extends AbstractMigration
{
    public function up(): void
    {
        $menus = [
            ['name' => 'dish_category', 'icon' => 'far fa-circle', 'url' => 'ex-admin/addons-webman-controller-DishCategoryController/index', 'sort' => 1],
            ['name' => 'dish', 'icon' => 'far fa-circle', 'url' => 'ex-admin/addons-webman-controller-DishController/index', 'sort' => 2],
            ['name' => 'dish_order', 'icon' => 'far fa-circle', 'url' => 'ex-admin/addons-webman-controller-DishOrderController/index', 'sort' => 3]
        ];
        // ---------------------------------------- 總站 ----------------------------------------
        $this->execute("
            INSERT INTO `admin_menus` (`name`, `icon`, `url`, `plugin`, `pid`, `sort`, `type`, `status`, `open`, `created_at`, `updated_at`)
            VALUES ('dish_manage', 'fas fa-coffee', '', '', 1, 100, 1, 1, 0, NOW(), NOW())
        ");

        $parentMenu = $this->query("SELECT id FROM `admin_menus` WHERE `name` = 'dish_manage' AND `type` = 1 ORDER BY id DESC LIMIT 1")->fetch();

        foreach ($menus as $value) {
            $this->execute("
                INSERT `admin_menus` (`name`, `icon`, `url`, `plugin`, `pid`, `sort`, `type`, `status`, `open`, `created_at`, `updated_at`)
                VALUES ('{$value['name']}', '{$value['icon']}', '{$value['url']}', '', {$parentMenu['id']}, {$value['sort']}, 1, 1, 0, NOW(), NOW())
            ");
        }

        // ---------------------------------------- 渠道 ----------------------------------------
        $this->execute("
            INSERT INTO `admin_menus` (`name`, `icon`, `url`, `plugin`, `pid`, `sort`, `type`, `status`, `open`, `created_at`, `updated_at`)
            VALUES ('dish_manage', 'fas fa-coffee', '', '', 1, 101, 2, 1, 0, NOW(), NOW())
        ");

        $parentMenu = $this->query("SELECT id FROM `admin_menus` WHERE `name` = 'dish_manage' AND `type` = 2 ORDER BY id DESC LIMIT 1")->fetch();

        foreach ($menus as $value) {
            $this->execute("
                INSERT `admin_menus` (`name`, `icon`, `url`, `plugin`, `pid`, `sort`, `type`, `status`, `open`, `created_at`, `updated_at`)
                VALUES ('{$value['name']}', '{$value['icon']}', '{$value['url']}', '', {$parentMenu['id']}, {$value['sort']}, 2, 1, 0, NOW(), NOW())
            ");
        }

        // ---------------------------------------- 代理 ----------------------------------------
        $this->execute("
            INSERT INTO `admin_menus` (`name`, `icon`, `url`, `plugin`, `pid`, `sort`, `type`, `status`, `open`, `created_at`, `updated_at`)
            VALUES ('dish_manage', 'fas fa-coffee', '', '', 1, 102, 3, 1, 0, NOW(), NOW())
        ");

        $parentMenu = $this->query("SELECT id FROM `admin_menus` WHERE `name` = 'dish_manage' AND `type` = 3 ORDER BY id DESC LIMIT 1")->fetch();

        foreach ($menus as $value) {
            if ($value['sort'] == 3) {
                $this->execute("
                    INSERT `admin_menus` (`name`, `icon`, `url`, `plugin`, `pid`, `sort`, `type`, `status`, `open`, `created_at`, `updated_at`)
                    VALUES ('{$value['name']}', '{$value['icon']}', '{$value['url']}', '', {$parentMenu['id']}, {$value['sort']}, 3, 1, 0, NOW(), NOW())
                ");
            }
        }

        // ---------------------------------------- 門店 ----------------------------------------
        $this->execute("
            INSERT INTO `admin_menus` (`name`, `icon`, `url`, `plugin`, `pid`, `sort`, `type`, `status`, `open`, `created_at`, `updated_at`)
            VALUES ('dish_manage', 'fas fa-coffee', '', '', 1, 103, 4, 1, 0, NOW(), NOW())
        ");

        $parentMenu = $this->query("SELECT id FROM `admin_menus` WHERE `name` = 'dish_manage' AND `type` = 4 ORDER BY id DESC LIMIT 1")->fetch();

        foreach ($menus as $value) {
            if ($value['sort'] != 1) {
                $this->execute("
                    INSERT `admin_menus` (`name`, `icon`, `url`, `plugin`, `pid`, `sort`, `type`, `status`, `open`, `created_at`, `updated_at`)
                    VALUES ('{$value['name']}', '{$value['icon']}', '{$value['url']}', '', {$parentMenu['id']}, {$value['sort']}, 4, 1, 0, NOW(), NOW())
                ");
            }
        }
    }

    public function down(): void
    {
        $this->execute("DELETE FROM `admin_menus` WHERE `name` IN ('dish_category', 'dish', 'dish_order')");
    }
}
