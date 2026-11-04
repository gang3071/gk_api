<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 新增機台控制日誌菜單（主站後台 → 日誌中心）
 */
final class AddMachineCtrlLogMenu extends AbstractMigration
{
    public function up(): void
    {
        $existing = $this->fetchRow(
            "SELECT id FROM `admin_menus`
             WHERE `name` = 'machine_ctrl_log' AND `type` = 1
             LIMIT 1"
        );

        if ($existing) {
            echo "- 菜單已存在，跳過: machine_ctrl_log\n";
            return;
        }

        $parent = $this->fetchRow(
            "SELECT id FROM `admin_menus`
             WHERE `name` = 'log_center' AND `type` = 1
             LIMIT 1"
        );

        $pid = $parent ? $parent['id'] : 0;

        $this->table('admin_menus')->insert([
            [
                'name'       => 'machine_ctrl_log',
                'icon'       => 'far fa-circle',
                'url'        => 'ex-admin/addons-webman-controller-MachineCtrlLogController/index',
                'plugin'     => '',
                'pid'        => $pid,
                'sort'       => 100,
                'status'     => 1,
                'open'       => 0,
                'type'       => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
        ])->saveData();

        echo "✓ 機台控制日誌菜單已新增（pid={$pid}）\n";
    }

    public function down(): void
    {
        $this->execute(
            "DELETE FROM `admin_menus` WHERE `name` = 'machine_ctrl_log' AND `type` = 1"
        );

        $this->execute(
            "DELETE FROM `admin_role_menus` WHERE `menu_id` NOT IN (SELECT `id` FROM `admin_menus`)"
        );

        echo "✓ 機台控制日誌菜單已回滾\n";
    }
}
