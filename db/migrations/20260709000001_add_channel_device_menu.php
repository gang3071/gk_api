<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 添加渠道后台设备列表菜单
 */
final class AddChannelDeviceMenu extends AbstractMigration
{
    private const TYPE_CHANNEL = 2; // 渠道后台

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $existing = $this->query(
            "SELECT id FROM admin_menus WHERE name = 'channel_device_list' AND type = " . self::TYPE_CHANNEL . " LIMIT 1"
        )->fetch();

        if (!$existing) {
            $this->execute("
                INSERT INTO admin_menus (name, icon, url, plugin, pid, sort, status, open, type, created_at, updated_at)
                VALUES ('channel_device_list', 'LaptopOutlined', 'ex-admin/addons-webman-controller-ChannelDeviceController/index', 'webman', 0, 55, 1, 0, " . self::TYPE_CHANNEL . ", '{$now}', '{$now}')
            ");
        }
    }

    public function down(): void
    {
        $this->execute("
            DELETE FROM admin_menus
            WHERE name = 'channel_device_list' AND type = " . self::TYPE_CHANNEL . "
        ");
    }
}
