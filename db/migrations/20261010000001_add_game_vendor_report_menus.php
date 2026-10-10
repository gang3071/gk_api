<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 添加厂商数据报表菜单（四个后台）
 *
 * 菜单结构：
 * - 主站  → 报表中心 (report_center)
 * - 渠道  → 电子游戏 (computer_game)
 * - 代理  → 电子游戏管理 (agent_game_management)
 * - 门店  → 电子游戏管理 (store_game_manage)
 */
final class AddGameVendorReportMenus extends AbstractMigration
{
    private const TYPE_MAIN    = 1;
    private const TYPE_CHANNEL = 2;
    private const TYPE_AGENT   = 3;
    private const TYPE_STORE   = 4;

    public function up(): void
    {
        $menus = [
            [
                'name'        => 'game_vendor_report',
                'icon'        => 'BarChartOutlined',
                'url'         => 'ex-admin/addons-webman-controller-AdminGameVendorReportController/index',
                'type'        => self::TYPE_MAIN,
                'parent_name' => 'report_center',
                'sort'        => 100,
            ],
            [
                'name'        => 'game_vendor_report',
                'icon'        => 'BarChartOutlined',
                'url'         => 'ex-admin/addons-webman-controller-ChannelGameVendorReportController/index',
                'type'        => self::TYPE_CHANNEL,
                'parent_name' => 'computer_game',
                'sort'        => 100,
            ],
            [
                'name'        => 'game_vendor_report',
                'icon'        => 'BarChartOutlined',
                'url'         => 'ex-admin/addons-webman-controller-AgentGameVendorReportController/index',
                'type'        => self::TYPE_AGENT,
                'parent_name' => 'agent_game_management',
                'sort'        => 100,
            ],
            [
                'name'        => 'game_vendor_report',
                'icon'        => 'BarChartOutlined',
                'url'         => 'ex-admin/addons-webman-controller-StoreGameVendorReportController/index',
                'type'        => self::TYPE_STORE,
                'parent_name' => 'store_game_manage',
                'sort'        => 100,
            ],
        ];

        $now        = date('Y-m-d H:i:s');
        $insertData = [];

        foreach ($menus as $menu) {
            $existing = $this->fetchRow(
                "SELECT id FROM `admin_menus`
                 WHERE `name` = 'game_vendor_report' AND `type` = {$menu['type']}
                 LIMIT 1"
            );

            if ($existing) {
                echo "- 菜单已存在，跳过: game_vendor_report (type={$menu['type']})\n";
                continue;
            }

            $parent = $this->fetchRow(
                "SELECT id FROM `admin_menus`
                 WHERE `name` = '{$menu['parent_name']}' AND `type` = {$menu['type']}
                 LIMIT 1"
            );

            $pid = $parent ? $parent['id'] : 0;

            $insertData[] = [
                'name'       => $menu['name'],
                'icon'       => $menu['icon'],
                'url'        => $menu['url'],
                'plugin'     => '',
                'pid'        => $pid,
                'sort'       => $menu['sort'],
                'status'     => 1,
                'open'       => 1,
                'type'       => $menu['type'],
                'created_at' => $now,
                'updated_at' => $now,
            ];

            echo "✓ 准备插入菜单: game_vendor_report (type={$menu['type']}, pid={$pid})\n";
        }

        if (!empty($insertData)) {
            $this->table('admin_menus')->insert($insertData)->saveData();
            echo "✓ 厂商数据报表菜单插入完成\n";
        }
    }

    public function down(): void
    {
        $this->execute("
            DELETE FROM `admin_menus`
            WHERE `name` = 'game_vendor_report'
              AND `type` IN (" . self::TYPE_MAIN . ", " . self::TYPE_CHANNEL . ", " . self::TYPE_AGENT . ", " . self::TYPE_STORE . ")
        ");

        $this->execute("
            DELETE FROM `admin_role_menus`
            WHERE `menu_id` NOT IN (SELECT `id` FROM `admin_menus`)
        ");

        echo "✓ 厂商数据报表菜单已回滚\n";
    }
}
