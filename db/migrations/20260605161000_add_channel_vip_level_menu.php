<?php

use Phinx\Migration\AbstractMigration;

/**
 * 添加渠道后台 VIP 等级管理菜单和节点权限
 */
class AddChannelVipLevelMenu extends AbstractMigration
{
    /**
     * 执行迁移
     */
    public function up()
    {
        // 检查 admin_menus 表是否存在
        if (!$this->hasTable('admin_menus')) {
            return;
        }

        // 检查渠道后台 VIP 等级菜单是否已存在
        $exists = $this->fetchRow("
            SELECT `id` FROM `admin_menus`
            WHERE `name` = 'channel_vip_level'
            AND `plugin` = 'webman'
            AND `type` = 2
            LIMIT 1
        ");

        if ($exists) {
            return; // 已存在，跳过
        }

        // 查找渠道后台的父级菜单（VIP管理）
        $parentMenu = $this->fetchRow("
            SELECT `id` FROM `admin_menus`
            WHERE `name` = 'vip'
            AND `plugin` = 'webman'
            AND `type` = 2
            LIMIT 1
        ");

        $pid = $parentMenu ? $parentMenu['id'] : 0;

        // 插入渠道后台 VIP 等级管理菜单
        // type = 2 表示渠道后台菜单
        $this->execute("
            INSERT INTO `admin_menus` (`name`, `icon`, `url`, `plugin`, `pid`, `sort`, `status`, `open`, `type`, `created_at`, `updated_at`)
            VALUES ('channel_vip_level', 'el-icon-trophy', 'ex-admin/addons-webman-controller-ChannelVipLevelController/index', 'webman', {$pid}, 10, 1, 0, 2, NOW(), NOW())
        ");

        // 获取新插入的菜单ID
        $menuId = $this->fetchRow("SELECT LAST_INSERT_ID() as id")['id'];

        // 插入节点权限（CRUD操作）
        $nodes = [
            ['name' => '列表', 'node' => 'index', 'sort' => 1],
            ['name' => '新增', 'node' => 'save', 'sort' => 2],
            ['name' => '编辑', 'node' => 'update', 'sort' => 3],
            ['name' => '删除', 'node' => 'delete', 'sort' => 4],
            ['name' => '反水比例', 'node' => 'cashback', 'sort' => 5],
        ];

        foreach ($nodes as $node) {
            $this->execute("
                INSERT INTO `admin_menus` (`name`, `icon`, `url`, `plugin`, `pid`, `sort`, `status`, `open`, `type`, `created_at`, `updated_at`)
                VALUES ('{$node['name']}', '', '{$node['node']}', 'webman', {$menuId}, {$node['sort']}, 1, 0, 2, NOW(), NOW())
            ");
        }
    }

    /**
     * 回滚迁移
     */
    public function down()
    {
        // 检查 admin_menus 表是否存在
        if (!$this->hasTable('admin_menus')) {
            return;
        }

        // 查找渠道后台 VIP 等级菜单
        $menu = $this->fetchRow("
            SELECT `id` FROM `admin_menus`
            WHERE `name` = 'channel_vip_level'
            AND `plugin` = 'webman'
            AND `type` = 2
            LIMIT 1
        ");

        if ($menu) {
            // 删除子节点（权限）
            $this->execute("
                DELETE FROM `admin_menus`
                WHERE `pid` = {$menu['id']}
                AND `type` = 2
            ");

            // 删除主菜单
            $this->execute("
                DELETE FROM `admin_menus`
                WHERE `id` = {$menu['id']}
                AND `type` = 2
            ");
        }
    }
}
