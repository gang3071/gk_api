<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 添加代理后台店家分润报表菜单
 */
final class AddAgentStoreProfitReportMenu extends AbstractMigration
{
    /**
     * 菜单类型常量
     */
    private const TYPE_AGENT = 3; // 代理后台

    /**
     * Migrate Up.
     */
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // 检查店家分润报表菜单是否已存在
        $existingMenu = $this->query(
            "SELECT id FROM admin_menus WHERE name = 'store_profit_report' AND type = " . self::TYPE_AGENT . " LIMIT 1"
        )->fetch();

        if (!$existingMenu) {
            // 插入店家分润报表菜单
            $this->table('admin_menus')->insert([
                'name' => 'store_profit_report',
                'icon' => 'DollarOutlined',
                'url' => 'ex-admin/addons-webman-controller-AgentStoreProfitReportController/index',
                'plugin' => '',
                'pid' => 0,
                'sort' => 20,
                'status' => 1,
                'open' => 1,
                'type' => self::TYPE_AGENT,
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
            WHERE name = 'store_profit_report'
              AND type = " . self::TYPE_AGENT . "
        ");

        // 清理关联的角色菜单权限
        $this->execute("
            DELETE FROM admin_role_menus
            WHERE menu_id NOT IN (SELECT id FROM admin_menus)
        ");
    }
}
