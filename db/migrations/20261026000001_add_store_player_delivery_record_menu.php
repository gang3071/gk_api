<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 店家後台新增玩家账变记录菜单
 */
final class AddStorePlayerDeliveryRecordMenu extends AbstractMigration
{
    public function up(): void
    {
        $parentMenu = $this->query(
            "SELECT id FROM `admin_menus` WHERE `name` = 'store_player' AND `type` = 4 ORDER BY id DESC LIMIT 1"
        )->fetch();

        if (!$parentMenu) {
            return;
        }

        $exists = $this->query(
            "SELECT id FROM `admin_menus` WHERE `name` = 'store_player_delivery_record' AND `type` = 4 LIMIT 1"
        )->fetch();

        if ($exists) {
            return;
        }

        $this->execute("
            INSERT INTO `admin_menus` (`name`, `icon`, `url`, `plugin`, `pid`, `sort`, `type`, `status`, `open`, `created_at`, `updated_at`)
            VALUES ('store_player_delivery_record', 'far fa-circle', 'ex-admin/addons-webman-controller-StorePlayerDeliveryRecordController/index', '', {$parentMenu['id']}, 5, 4, 1, 0, NOW(), NOW())
        ");
    }

    public function down(): void
    {
        $this->execute(
            "DELETE FROM `admin_menus` WHERE `name` = 'store_player_delivery_record' AND `type` = 4"
        );
    }
}
