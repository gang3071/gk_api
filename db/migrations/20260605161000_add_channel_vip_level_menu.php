<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 添加渠道后台 VIP 等级管理菜单
 */
final class AddChannelVipLevelMenu extends AbstractMigration
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

        // 检查渠道后台 VIP 等级菜单是否已存在
        $existingMenu = $this->query(
            "SELECT id FROM admin_menus WHERE name = 'channel_vip_level' AND type = " . self::TYPE_CHANNEL . " LIMIT 1"
        )->fetch();

        if (!$existingMenu) {
            // 查找渠道后台的父级菜单（VIP管理）
            $parentMenu = $this->query(
                "SELECT id FROM admin_menus WHERE name = 'vip' AND type = " . self::TYPE_CHANNEL . " LIMIT 1"
            )->fetch();

            $pid = $parentMenu ? (int)$parentMenu['id'] : 0;

            // 插入渠道后台 VIP 等级管理菜单
            $this->table('admin_menus')->insert([
                'name' => 'channel_vip_level',
                'icon' => 'el-icon-trophy',
                'url' => 'ex-admin/addons-webman-controller-ChannelVipLevelController/index',
                'plugin' => '',
                'pid' => $pid,
                'sort' => 10,
                'status' => 1,
                'open' => 0,
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
        // 删除渠道后台 VIP 等级菜单
        $this->execute("
            DELETE FROM admin_menus
            WHERE name = 'channel_vip_level'
              AND type = " . self::TYPE_CHANNEL . "
        ");

        // 清理关联的角色菜单权限
        $this->execute("
            DELETE FROM admin_role_menus
            WHERE menu_id NOT IN (SELECT id FROM admin_menus)
        ");
    }
}
