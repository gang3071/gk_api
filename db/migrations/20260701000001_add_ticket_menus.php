<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 添加出票记录和核销记录菜单（总后台、渠道后台）
 * 节点权限通过 admin_node.php 和 channel_node.php 配置文件处理
 */
final class AddTicketMenus extends AbstractMigration
{
    private const TYPE_ADMIN = 1;   // 总后台
    private const TYPE_CHANNEL = 2; // 渠道后台

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // 一级菜单数据
        $menus = [
            // 总后台
            ['name' => 'ticket_record_list', 'icon' => 'FileTextOutlined', 'url' => 'ex-admin/addons-webman-controller-AdminTicketRecordController/index', 'type' => self::TYPE_ADMIN, 'sort' => 161],
            ['name' => 'ticket_redeem_list', 'icon' => 'CheckCircleOutlined', 'url' => 'ex-admin/addons-webman-controller-AdminTicketRedeemController/index', 'type' => self::TYPE_ADMIN, 'sort' => 162],
            // 渠道后台
            ['name' => 'ticket_record_list', 'icon' => 'FileTextOutlined', 'url' => 'ex-admin/addons-webman-controller-ChannelTicketRecordController/index', 'type' => self::TYPE_CHANNEL, 'sort' => 161],
            ['name' => 'ticket_redeem_list', 'icon' => 'CheckCircleOutlined', 'url' => 'ex-admin/addons-webman-controller-ChannelTicketRedeemController/index', 'type' => self::TYPE_CHANNEL, 'sort' => 162],
        ];

        foreach ($menus as $menu) {
            $existing = $this->query(
                "SELECT id FROM admin_menus WHERE name = '{$menu['name']}' AND type = {$menu['type']} LIMIT 1"
            )->fetch();

            if (!$existing) {
                $this->execute("
                    INSERT INTO admin_menus (name, icon, url, plugin, pid, sort, status, open, type, created_at, updated_at)
                    VALUES ('{$menu['name']}', '{$menu['icon']}', '{$menu['url']}', '', 0, {$menu['sort']}, 1, 0, {$menu['type']}, '{$now}', '{$now}')
                ");
            }
        }
    }

    public function down(): void
    {
        $this->execute("
            DELETE FROM admin_menus WHERE name IN (
                'ticket_record_list',
                'ticket_redeem_list'
            ) AND type IN (" . self::TYPE_ADMIN . ", " . self::TYPE_CHANNEL . ")
        ");
    }
}
