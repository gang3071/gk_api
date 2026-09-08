<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 更新餐點系統菜單
 */
final class UpdateDishMenus extends AbstractMigration
{
    public function up(): void
    {
        // 渠道
        $this->execute("UPDATE `admin_menus` SET `pid` = 33, `sort` = 100, `updated_at` = NOW() WHERE `name` = 'dish_manage' AND `type` = 2");
        // 代理
        $this->execute("UPDATE `admin_menus` SET `pid` = 190, `sort` = 100, `updated_at` = NOW() WHERE `name` = 'dish_manage' AND `type` = 3");
        // 門店
        $this->execute("UPDATE `admin_menus` SET `pid` = 191, `sort` = 100, `updated_at` = NOW() WHERE `name` = 'dish_manage' AND `type` = 4");
    }

    public function down(): void
    {
        // 渠道
        $this->execute("UPDATE `admin_menus` SET `pid` = 1, `updated_at` = NOW() WHERE `name` = 'dish_manage' AND `type` = 2");
        // 代理
        $this->execute("UPDATE `admin_menus` SET `pid` = 1, `updated_at` = NOW() WHERE `name` = 'dish_manage' AND `type` = 3");
        // 門店
        $this->execute("UPDATE `admin_menus` SET `pid` = 1, `updated_at` = NOW() WHERE `name` = 'dish_manage' AND `type` = 4");
    }
}
