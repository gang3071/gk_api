<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 添加出票记录菜单
 */
final class AddTicketRecordMenus extends AbstractMigration
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
            "SELECT id FROM admin_menus WHERE name = 'ticket_management' LIMIT 1"
        )->fetch();

        if ($existingParent) {
            $parentId = $existingParent['id'];
        } else {
            // 插入父级菜单
            $this->execute("
                INSERT INTO admin_menus (name, icon, url, plugin, pid, sort, status, open, type, created_at, updated_at)
                VALUES ('ticket_management', 'QrcodeOutlined', '', '', 0, 160, 1, 1, " . self::TYPE_STORE . ", '{$now}', '{$now}')
            ");

            $lastId = $this->query("SELECT LAST_INSERT_ID() as id")->fetch();
            $parentId = $lastId['id'];
        }

        // 准备子菜单数据
        $childMenus = [
            [
                'name' => 'ticket_record_list',
                'icon' => 'FileTextOutlined',
                'url' => 'ex-admin/addons-webman-controller-StoreTicketRecordController/index',
                'sort' => 1,
            ],
        ];

        // 插入子菜单
        $insertData = [];
        foreach ($childMenus as $child) {
            // 检查子菜单是否已存在
            $existingChild = $this->query(
                "SELECT id FROM admin_menus WHERE name = '{$child['name']}' LIMIT 1"
            )->fetch();

            if (!$existingChild) {
                $insertData[] = [
                    'name' => $child['name'],
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
            WHERE name IN ('ticket_record_list')
              AND type = " . self::TYPE_STORE . "
        ");

        $this->execute("
            DELETE FROM admin_menus
            WHERE name = 'ticket_management'
              AND type = " . self::TYPE_STORE . "
        ");

        $this->execute("
            DELETE FROM admin_role_menus
            WHERE menu_id NOT IN (SELECT id FROM admin_menus)
        ");
    }
}
