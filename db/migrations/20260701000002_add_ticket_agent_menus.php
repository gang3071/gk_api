<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 添加出票记录和核销记录菜单（代理后台）
 * 节点权限通过 agent_node.php 配置文件处理
 */
final class AddTicketAgentMenus extends AbstractMigration
{
    private const TYPE_AGENT = 3; // 代理后台

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // 一级菜单数据
        $menus = [
            ['name' => 'ticket_record_list', 'icon' => 'FileTextOutlined', 'url' => 'ex-admin/addons-webman-controller-AgentTicketRecordController/index', 'type' => self::TYPE_AGENT, 'sort' => 161],
            ['name' => 'ticket_redeem_list', 'icon' => 'CheckCircleOutlined', 'url' => 'ex-admin/addons-webman-controller-AgentTicketRedeemController/index', 'type' => self::TYPE_AGENT, 'sort' => 162],
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
            ) AND type = " . self::TYPE_AGENT . "
        ");
    }
}
