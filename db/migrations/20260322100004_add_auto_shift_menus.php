<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 添加自动交班菜单
 */
final class AddAutoShiftMenus extends AbstractMigration
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

        // 检查父级菜单是否已存在
        $existingParent = $this->query(
            "SELECT id FROM admin_menus WHERE name = 'auto_shift_management' AND deleted_at IS NULL LIMIT 1"
        )->fetch();

        if ($existingParent) {
            $parentId = $existingParent['id'];
        } else {
            // 插入父级菜单
            $this->execute("
                INSERT INTO admin_menus (name, title, icon, url, plugin, pid, sort, status, open, type, created_at, updated_at)
                VALUES ('auto_shift_management', '自动交班', 'ClockCircleOutlined', '', '', 0, 150, 1, 1, " . self::TYPE_STORE . ", '{$now}', '{$now}')
            ");

            $lastId = $this->query("SELECT LAST_INSERT_ID() as id")->fetch();
            $parentId = $lastId['id'];
        }

        // 准备子菜单数据
        $childMenus = [
            [
                'name' => 'auto_shift_config',
                'title' => '交班配置',
                'icon' => 'SettingOutlined',
                'url' => 'ex-admin/addons-webman-controller-ChannelAutoShiftController/config',
                'sort' => 1,
            ],
            [
                'name' => 'auto_shift_logs',
                'title' => '执行日志',
                'icon' => 'FileTextOutlined',
                'url' => 'ex-admin/addons-webman-controller-ChannelAutoShiftController/logs',
                'sort' => 2,
            ],
        ];

        // 插入子菜单
        $insertData = [];
        foreach ($childMenus as $child) {
            // 检查子菜单是否已存在
            $existingChild = $this->query(
                "SELECT id FROM admin_menus WHERE name = '{$child['name']}' AND deleted_at IS NULL LIMIT 1"
            )->fetch();

            if (!$existingChild) {
                $insertData[] = [
                    'name' => $child['name'],
                    'title' => $child['title'],
                    'icon' => $child['icon'],
                    'url' => $child['url'],
                    'plugin' => '',
                    'pid' => $parentId,
                    'sort' => $child['sort'],
                    'status' => 1,
                    'open' => 1,
                    'type' => self::TYPE_STORE,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if (!empty($insertData)) {
            $this->table('admin_menus')->insert($insertData)->saveData();
        }
    }

    /**
     * Migrate Down.
     */
    public function down(): void
    {
        $this->execute("
            DELETE FROM admin_menus
            WHERE name IN ('auto_shift_config', 'auto_shift_logs')
              AND type = " . self::TYPE_STORE . "
        ");

        $this->execute("
            DELETE FROM admin_menus
            WHERE name = 'auto_shift_management'
              AND type = " . self::TYPE_STORE . "
        ");

        $this->execute("
            DELETE FROM admin_role_menus
            WHERE menu_id NOT IN (SELECT id FROM admin_menus)
        ");
    }
}
