<?php

use Phinx\Migration\AbstractMigration;

/**
 * 更新代理后台摸奖券中奖记录菜单名称
 * 将 agent_lottery_ticket_win_record_list 更新为 agent_lottery_ticket_record_list
 */
class UpdateAgentLotteryTicketMenuName extends AbstractMigration
{
    /**
     * 执行迁移
     */
    public function up()
    {
        // 更新菜单名称和URL
        $this->execute("
            UPDATE `admin_menus`
            SET
                `name` = 'agent_lottery_ticket_record_list',
                `url` = 'ex-admin/addons-webman-controller-AgentLotteryTicketRecordController/index',
                `updated_at` = NOW()
            WHERE
                `name` = 'agent_lottery_ticket_win_record_list'
                AND `plugin` = 'webman'
        ");
    }

    /**
     * 回滚迁移
     */
    public function down()
    {
        // 恢复旧的菜单名称
        $this->execute("
            UPDATE `admin_menus`
            SET
                `name` = 'agent_lottery_ticket_win_record_list',
                `url` = 'ex-admin/addons-webman-controller-AgentLotteryTicketWinRecordController/index',
                `updated_at` = NOW()
            WHERE
                `name` = 'agent_lottery_ticket_record_list'
                AND `plugin` = 'webman'
        ");
    }
}
