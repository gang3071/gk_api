<?php

use Phinx\Migration\AbstractMigration;

/**
 * 添加代理后台摸奖券管理菜单
 *
 * 菜单结构：
 * - 摸奖券管理 (父级菜单)
 *   - 摸奖券活动 (活动列表)
 *   - 摸奖券列表 (券号列表)
 *   - 中奖记录 (中奖记录列表)
 */
class AddAgentLotteryTicketMenus extends AbstractMigration
{
    /**
     * 执行迁移
     */
    public function up()
    {
        // 1. 插入父级菜单：摸奖券管理
        // type = 3 表示代理菜单 (AdminDepartment::TYPE_AGENT)
        $this->execute("
            INSERT INTO `admin_menus` (`name`, `icon`, `url`, `plugin`, `pid`, `sort`, `status`, `open`, `type`, `created_at`, `updated_at`)
            VALUES ('agent_lottery_ticket_management', 'el-icon-present', '', 'webman', 0, 150, 1, 0, 3, NOW(), NOW())
        ");

        // 获取父级菜单ID
        $parentMenuId = $this->fetchRow("
            SELECT id FROM `admin_menus`
            WHERE `name` = 'agent_lottery_ticket_management' AND `plugin` = 'webman'
            ORDER BY id DESC LIMIT 1
        ")['id'];

        // 2. 插入子菜单：摸奖券活动
        $this->execute("
            INSERT INTO `admin_menus` (`name`, `icon`, `url`, `plugin`, `pid`, `sort`, `status`, `open`, `type`, `created_at`, `updated_at`)
            VALUES (
                'agent_lottery_ticket_activity_list',
                '',
                'ex-admin/addons-webman-controller-AgentLotteryTicketActivityController/index',
                'webman',
                {$parentMenuId},
                1,
                1,
                0,
                3,
                NOW(),
                NOW()
            )
        ");

        // 3. 插入子菜单：摸奖券列表
        $this->execute("
            INSERT INTO `admin_menus` (`name`, `icon`, `url`, `plugin`, `pid`, `sort`, `status`, `open`, `type`, `created_at`, `updated_at`)
            VALUES (
                'agent_lottery_ticket_list',
                '',
                'ex-admin/addons-webman-controller-AgentLotteryTicketController/index',
                'webman',
                {$parentMenuId},
                2,
                1,
                0,
                3,
                NOW(),
                NOW()
            )
        ");

        // 4. 插入子菜单：中奖记录
        $this->execute("
            INSERT INTO `admin_menus` (`name`, `icon`, `url`, `plugin`, `pid`, `sort`, `status`, `open`, `type`, `created_at`, `updated_at`)
            VALUES (
                'agent_lottery_ticket_win_record_list',
                '',
                'ex-admin/addons-webman-controller-AgentLotteryTicketRecordController/index',
                'webman',
                {$parentMenuId},
                3,
                1,
                0,
                3,
                NOW(),
                NOW()
            )
        ");
    }

    /**
     * 回滚迁移
     */
    public function down()
    {
        // 删除子菜单（按照插入的逆序删除）
        $this->execute("
            DELETE FROM `admin_menus`
            WHERE `name` = 'agent_lottery_ticket_win_record_list' AND `plugin` = 'webman'
        ");

        $this->execute("
            DELETE FROM `admin_menus`
            WHERE `name` = 'agent_lottery_ticket_list' AND `plugin` = 'webman'
        ");

        $this->execute("
            DELETE FROM `admin_menus`
            WHERE `name` = 'agent_lottery_ticket_activity_list' AND `plugin` = 'webman'
        ");

        // 删除父级菜单
        $this->execute("
            DELETE FROM `admin_menus`
            WHERE `name` = 'agent_lottery_ticket_management' AND `plugin` = 'webman'
        ");
    }
}
