<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 添加渠道后台店家分润报表菜单
 */
final class AddChannelStoreProfitReportMenu extends AbstractMigration
{
    /**
     * 菜单类型常量
     */
    private const TYPE_CHANNEL = 2; // 渠道后台

    /**
     * Migrate Up.
     */
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // 检查店家分润报表菜单是否已存在
        $existingMenu = $this->query(
            "SELECT id FROM admin_menus WHERE name = 'channel_store_profit_report' AND type = " . self::TYPE_CHANNEL . " LIMIT 1"
        )->fetch();

        if (!$existingMenu) {
            // 插入店家分润报表菜单
            $this->table('admin_menus')->insert([
                'name' => 'channel_store_profit_report',
                'icon' => 'DollarOutlined',
                'url' => 'ex-admin/addons-webman-controller-ChannelStoreProfitReportController/index',
                'plugin' => '',
                'pid' => 0,
                'sort' => 25,
                'status' => 1,
                'open' => 1,
                'type' => self::TYPE_CHANNEL,
                'created_at' => $now,
                'updated_at' => $now,
            ])->saveData();

        }
    }

    /**
     * Migrate Down.
     */
    public function down(): void
    {
        // 删除店家分润报表菜单
        $this->execute("
            DELETE FROM admin_menus
            WHERE name = 'channel_store_profit_report'
              AND type = " . self::TYPE_CHANNEL . "
        ");

        // 清理关联的角色菜单权限
        $this->execute("
            DELETE FROM admin_role_menus
            WHERE menu_id NOT IN (SELECT id FROM admin_menus)
        ");
    }
}
