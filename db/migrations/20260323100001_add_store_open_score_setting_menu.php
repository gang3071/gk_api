<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 添加店家开分配置菜单
 */
final class AddStoreOpenScoreSettingMenu extends AbstractMigration
{
    /**
     * 菜单类型常量
     */
    private const TYPE_STORE = 4; // 店家后台

    /**
     * Migrate Up.
     */
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // 检查菜单是否已存在
        $existing = $this->query(
            "SELECT id FROM admin_menus WHERE name = 'store_open_score_setting' LIMIT 1"
        )->fetch();

        if (!$existing) {
            // 插入店家开分配置菜单
            $this->execute("
                INSERT INTO admin_menus (name, icon, url, plugin, pid, sort, status, open, type, created_at, updated_at)
                VALUES ('store_open_score_setting', 'DollarOutlined', 'ex-admin/addons-webman-controller-ChannelOpenScoreSettingController/index', '', 0, 165, 1, 1, " . self::TYPE_STORE . ", '{$now}', '{$now}')
            ");
        }
    }

    /**
     * Migrate Down.
     */
    public function down(): void
    {
        $this->execute("
            DELETE FROM admin_menus
            WHERE name = 'store_open_score_setting'
              AND type = " . self::TYPE_STORE . "
        ");

        $this->execute("
            DELETE FROM admin_role_menus
            WHERE menu_id NOT IN (SELECT id FROM admin_menus)
        ");
    }
}
