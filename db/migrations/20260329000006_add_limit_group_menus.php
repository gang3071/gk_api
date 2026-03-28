<?php

use Phinx\Migration\AbstractMigration;

/**
 * 添加限红组功能菜单权限
 */
class AddLimitGroupMenus extends AbstractMigration
{
    /**
     * 执行迁移
     */
    public function up()
    {
        // ============================================
        // 总后台菜单（type = 1）
        // ============================================

        // 1. 插入限红管理父级菜单
        $this->execute("
            INSERT INTO `admin_menus` (`name`, `icon`, `url`, `plugin`, `pid`, `sort`, `status`, `open`, `type`, `created_at`, `updated_at`)
            VALUES ('limit_group', 'el-icon-s-operation', '', 'webman', 0, 95, 1, 0, 1, NOW(), NOW())
        ");

        // 获取限红管理父级菜单ID
        $parentMenuId = $this->fetchRow("SELECT id FROM `admin_menus` WHERE `name` = 'limit_group' AND `plugin` = 'webman' AND `type` = 1 ORDER BY id DESC LIMIT 1")['id'];

        // 2. 插入限红组管理子菜单
        $this->execute("
            INSERT INTO `admin_menus` (`name`, `icon`, `url`, `plugin`, `pid`, `sort`, `status`, `open`, `type`, `created_at`, `updated_at`)
            VALUES ('limit_group_list', '', 'ex-admin/addons-webman-controller-PlatformLimitGroupController/index', 'webman', {$parentMenuId}, 1, 1, 0, 1, NOW(), NOW())
        ");

        // 3. 插入限红组平台配置子菜单
        $this->execute("
            INSERT INTO `admin_menus` (`name`, `icon`, `url`, `plugin`, `pid`, `sort`, `status`, `open`, `type`, `created_at`, `updated_at`)
            VALUES ('limit_group_config', '', 'ex-admin/addons-webman-controller-PlatformLimitGroupConfigController/index', 'webman', {$parentMenuId}, 2, 1, 0, 1, NOW(), NOW())
        ");

        // ============================================
        // 渠道后台菜单（type = 2）
        // ============================================

        // 4. 插入渠道限红管理父级菜单
        $this->execute("
            INSERT INTO `admin_menus` (`name`, `icon`, `url`, `plugin`, `pid`, `sort`, `status`, `open`, `type`, `created_at`, `updated_at`)
            VALUES ('channel_limit_group', 'el-icon-s-operation', '', 'webman', 0, 95, 1, 0, 2, NOW(), NOW())
        ");

        // 获取渠道限红管理父级菜单ID
        $channelParentMenuId = $this->fetchRow("SELECT id FROM `admin_menus` WHERE `name` = 'channel_limit_group' AND `plugin` = 'webman' AND `type` = 2 ORDER BY id DESC LIMIT 1")['id'];

        // 5. 插入店家限红分配子菜单
        $this->execute("
            INSERT INTO `admin_menus` (`name`, `icon`, `url`, `plugin`, `pid`, `sort`, `status`, `open`, `type`, `created_at`, `updated_at`)
            VALUES ('channel_admin_limit_group', '', 'ex-admin/addons-webman-controller-ChannelAdminUserLimitGroupController/index', 'webman', {$channelParentMenuId}, 1, 1, 0, 2, NOW(), NOW())
        ");
    }

    /**
     * 回滚迁移
     */
    public function down()
    {
        // 删除渠道后台菜单
        $this->execute("
            DELETE FROM `admin_menus` WHERE `name` = 'channel_admin_limit_group' AND `plugin` = 'webman' AND `type` = 2
        ");

        $this->execute("
            DELETE FROM `admin_menus` WHERE `name` = 'channel_limit_group' AND `plugin` = 'webman' AND `type` = 2
        ");

        // 删除总后台菜单
        $this->execute("
            DELETE FROM `admin_menus` WHERE `name` = 'limit_group_config' AND `plugin` = 'webman' AND `type` = 1
        ");

        $this->execute("
            DELETE FROM `admin_menus` WHERE `name` = 'limit_group_list' AND `plugin` = 'webman' AND `type` = 1
        ");

        $this->execute("
            DELETE FROM `admin_menus` WHERE `name` = 'limit_group' AND `plugin` = 'webman' AND `type` = 1
        ");
    }
}
