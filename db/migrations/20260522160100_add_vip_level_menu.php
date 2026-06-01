<?php

use Phinx\Migration\AbstractMigration;

/**
 * 添加 VIP 等级管理菜单（总后台）
 */
class AddVipLevelMenu extends AbstractMigration
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

        // 检查 VIP 等级菜单是否已存在
        $exists = $this->fetchRow("
            SELECT `id` FROM `admin_menus`
            WHERE `name` = 'vip_level'
            AND `plugin` = 'webman'
            AND `type` = 1
            LIMIT 1
        ");

        if ($exists) {
            return; // 已存在，跳过
        }

        // 插入 VIP 等级管理菜单（总后台，顶级主菜单）
        // type = 1 表示总后台菜单
        // pid = 0 表示顶级主菜单
        $this->execute("
            INSERT INTO `admin_menus` (`name`, `icon`, `url`, `plugin`, `pid`, `sort`, `status`, `open`, `type`, `created_at`, `updated_at`)
            VALUES ('vip_level', 'el-icon-trophy', 'ex-admin/addons-webman-controller-VipLevelController/index', 'webman', 0, 106, 1, 0, 1, NOW(), NOW())
        ");
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

        // 删除 VIP 等级菜单
        $this->execute("
            DELETE FROM `admin_menus`
            WHERE `name` = 'vip_level'
            AND `plugin` = 'webman'
            AND `type` = 1
        ");
    }
}
