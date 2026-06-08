<?php

use Phinx\Migration\AbstractMigration;

/**
 * 移动VIP等级菜单：从主站后台移到渠道后台
 *
 * 操作说明：
 * 1. 删除主站后台（type=1）的VIP等级菜单
 * 2. 在渠道后台（type=2）创建VIP等级菜单
 */
class MoveVipLevelMenuToChannel extends AbstractMigration
{
    /**
     * 执行迁移
     */
    public function up()
    {
        // ============================================
        // Step 1: 删除主站后台的VIP等级菜单
        // ============================================

        $this->execute("
            DELETE FROM admin_menu
            WHERE type = 1
            AND (
                name = 'vip_level_manage'
                OR name = 'vip_level_list'
                OR name = 'vip_level_cashback'
                OR title LIKE '%VIP%等级%'
                OR title LIKE '%会员等级%'
                OR url LIKE '%vip-level%'
            )
        ");

        // ============================================
        // Step 2: 在渠道后台创建VIP等级菜单
        // ============================================

        // 2.1 查找渠道管理的父菜单ID（用于确定插入位置）
        $channelManageRow = $this->fetchRow("
            SELECT id FROM admin_menu
            WHERE type = 2
            AND (name = 'channel_manage' OR title = '渠道管理')
            LIMIT 1
        ");

        // 确定排序值（在渠道管理后面）
        $sortOrder = 100;
        if ($channelManageRow) {
            $channelManageSort = $this->fetchRow("
                SELECT sort FROM admin_menu WHERE id = {$channelManageRow['id']}
            ");
            $sortOrder = ($channelManageSort['sort'] ?? 100) + 5;
        }

        // 2.2 创建父菜单：VIP等级管理
        $this->execute("
            INSERT INTO admin_menu (
                name,
                pid,
                title,
                icon,
                url,
                type,
                sort,
                status,
                created_at,
                updated_at
            ) VALUES (
                'vip_level_manage',
                0,
                'VIP等级管理',
                'CrownOutlined',
                '',
                2,
                {$sortOrder},
                1,
                NOW(),
                NOW()
            )
        ");

        // 获取刚创建的父菜单ID
        $parentRow = $this->fetchRow("
            SELECT id FROM admin_menu
            WHERE type = 2 AND name = 'vip_level_manage'
            ORDER BY id DESC LIMIT 1
        ");

        if (!$parentRow) {
            throw new \Exception('创建VIP等级管理父菜单失败');
        }

        $parentId = $parentRow['id'];

        // 2.3 创建子菜单1：VIP等级列表
        $this->execute("
            INSERT INTO admin_menu (
                name,
                pid,
                title,
                icon,
                url,
                type,
                sort,
                status,
                created_at,
                updated_at
            ) VALUES (
                'vip_level_list',
                {$parentId},
                'VIP等级列表',
                '',
                'ex-admin/vip-level/index',
                2,
                1,
                1,
                NOW(),
                NOW()
            )
        ");

        // 2.4 创建子菜单2：VIP返水配置
        $this->execute("
            INSERT INTO admin_menu (
                name,
                pid,
                title,
                icon,
                url,
                type,
                sort,
                status,
                created_at,
                updated_at
            ) VALUES (
                'vip_level_cashback',
                {$parentId},
                'VIP返水配置',
                '',
                'ex-admin/vip-level/cashback',
                2,
                2,
                1,
                NOW(),
                NOW()
            )
        ");

        // ============================================
        // Step 3: 输出日志信息
        // ============================================

        echo "\n";
        echo "========================================\n";
        echo "VIP等级菜单迁移完成\n";
        echo "========================================\n";
        echo "✓ 已删除主站后台（type=1）的VIP等级菜单\n";
        echo "✓ 已在渠道后台（type=2）创建VIP等级菜单\n";
        echo "  - 父菜单ID: {$parentId}\n";
        echo "  - 排序值: {$sortOrder}\n";
        echo "\n";
        echo "后续操作：\n";
        echo "1. 登录渠道管理员账号\n";
        echo "2. 进入角色管理 → 编辑渠道管理员角色\n";
        echo "3. 勾选 VIP等级管理 相关权限\n";
        echo "4. 保存并刷新页面\n";
        echo "========================================\n";
        echo "\n";
    }

    /**
     * 回滚迁移
     */
    public function down()
    {
        // 回滚时删除渠道后台的VIP等级菜单
        $this->execute("
            DELETE FROM admin_menu
            WHERE type = 2
            AND (
                name = 'vip_level_manage'
                OR name = 'vip_level_list'
                OR name = 'vip_level_cashback'
            )
        ");

        echo "\n";
        echo "========================================\n";
        echo "VIP等级菜单迁移已回滚\n";
        echo "========================================\n";
        echo "✓ 已删除渠道后台（type=2）的VIP等级菜单\n";
        echo "\n";
        echo "注意：主站后台的VIP等级菜单需要手动恢复\n";
        echo "========================================\n";
        echo "\n";
    }
}
