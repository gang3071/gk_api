<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 代理後台子帳號
 *
 * 1. admin_users 新增 visible_store_ids（子帳號可查看的店家ID清單，JSON）
 * 2. 新增「代理子帳號」內置角色（type=3、is_protected=1，不可刪除）
 * 3. 新增代理後台「子帳號管理」選單（掛在 agent_manage 底下）
 */
final class AddVisibleStoreIdsAndAgentSubRole extends AbstractMigration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // 1) admin_users.visible_store_ids
        $hasColumn = $this->fetchRow("SHOW COLUMNS FROM `admin_users` LIKE 'visible_store_ids'");
        if (!$hasColumn) {
            $this->table('admin_users')
                ->addColumn('visible_store_ids', 'json', [
                    'null' => true,
                    'after' => 'post',
                    'comment' => '代理子帳號可查看的店家ID清單(JSON)',
                ])
                ->update();
        }

        // 2) 內置角色：代理子帳號
        $role = $this->fetchRow("SELECT id FROM `admin_roles` WHERE `type` = 3 AND `name` = '代理子帳號' LIMIT 1");
        if (!$role) {
            $this->table('admin_roles')->insert([
                [
                    'name'           => '代理子帳號',
                    'desc'           => '代理後台子帳號（內置角色，不可刪除）',
                    'sort'           => 1,
                    'data_type'      => 2,
                    'type'           => 3,
                    'is_protected'   => 1,
                    'check_strictly' => 0,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ],
            ])->saveData();
        }

        // 3) 代理後台選單：子帳號管理
        $parent = $this->fetchRow("SELECT id FROM `admin_menus` WHERE `name` = 'agent_manage' AND `type` = 3 ORDER BY id DESC LIMIT 1");
        if ($parent) {
            $exists = $this->fetchRow("SELECT id FROM `admin_menus` WHERE `name` = 'agent_sub_account' AND `type` = 3 LIMIT 1");
            if (!$exists) {
                $this->table('admin_menus')->insert([
                    [
                        'name'       => 'agent_sub_account',
                        'icon'       => 'fas fa-user-friends',
                        'url'        => 'ex-admin/addons-webman-controller-AgentSubAdminController/index',
                        'plugin'     => '',
                        'pid'        => (int) $parent['id'],
                        'sort'       => 16,
                        'type'       => 3,
                        'status'     => 1,
                        'open'       => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                ])->saveData();
            }

            // 授權給代理超級管理員角色，讓總代理看得到此選單
            $menu = $this->fetchRow("SELECT id FROM `admin_menus` WHERE `name` = 'agent_sub_account' AND `type` = 3 LIMIT 1");
            $agentRole = $this->fetchRow("SELECT id FROM `admin_roles` WHERE `type` = 3 AND `name` = '代理超級管理員' LIMIT 1");
            if ($menu && $agentRole) {
                $granted = $this->fetchRow(
                    "SELECT id FROM `admin_role_menus` WHERE `role_id` = " . (int) $agentRole['id'] . " AND `menu_id` = " . (int) $menu['id'] . " LIMIT 1"
                );
                if (!$granted) {
                    $this->table('admin_role_menus')->insert([
                        ['role_id' => (int) $agentRole['id'], 'menu_id' => (int) $menu['id']],
                    ])->saveData();
                }
            }
        }
    }

    public function down(): void
    {
        $this->execute("DELETE FROM `admin_menus` WHERE `name` = 'agent_sub_account' AND `type` = 3");
        $this->execute("DELETE FROM `admin_roles` WHERE `type` = 3 AND `name` = '代理子帳號'");

        $hasColumn = $this->fetchRow("SHOW COLUMNS FROM `admin_users` LIKE 'visible_store_ids'");
        if ($hasColumn) {
            $this->table('admin_users')->removeColumn('visible_store_ids')->update();
        }
    }
}
