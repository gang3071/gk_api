<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 添加核销记录菜单
 */
final class AddRedeemRecordMenus extends AbstractMigration
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

        // 获取出票管理父级菜单ID
        $existingParent = $this->query(
            "SELECT id FROM admin_menus WHERE name = 'ticket_management' LIMIT 1"
        )->fetch();

        if (!$existingParent) {
            // 如果父级菜单不存在，跳过
            return;
        }

        $parentId = $existingParent['id'];

        // 准备子菜单数据
        $childMenus = [
            [
                'name' => 'ticket_redeem_list',
                'icon' => 'FileTextOutlined',
                'url' => 'ex-admin/addons-webman-controller-StoreTicketRedeemController/index',
                'sort' => 2,
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
            WHERE name IN ('ticket_redeem_list')
              AND type = " . self::TYPE_STORE . "
        ");

        $this->execute("
            DELETE FROM admin_role_menus
            WHERE menu_id NOT IN (SELECT id FROM admin_menus)
        ");
    }
}
