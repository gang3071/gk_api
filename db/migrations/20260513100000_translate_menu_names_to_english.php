<?php

use Phinx\Migration\AbstractMigration;

/**
 * 将菜单名称从中文转换为英文key，支持多语言翻译
 */
class TranslateMenuNamesToEnglish extends AbstractMigration
{
    /**
     * 执行迁移
     */
    public function up()
    {
        $this->output->writeln('=== 开始迁移菜单名称到英文key ===');

        $updates = [
            12 => ['old' => '插件管理', 'new' => 'plugin_manage'],
            115 => ['old' => '游戏列表', 'new' => 'game_list'],
            116 => ['old' => '机器标签', 'new' => 'machine_label'],
            118 => ['old' => '短信日志', 'new' => 'sms_log'],
            119 => ['old' => '玩家报表', 'new' => 'player_report'],
            122 => ['old' => '全民代理管理', 'new' => 'national_promoter_manage'],
            123 => ['old' => '用户等级设置', 'new' => 'user_level_setting'],
            124 => ['old' => '邀请人数奖励', 'new' => 'invite_reward'],
            126 => ['old' => '玩家报表', 'new' => 'player_report'],
            127 => ['old' => '全民代理管理', 'new' => 'national_promoter_manage'],
            128 => ['old' => '用户等级设置', 'new' => 'user_level_setting'],
            129 => ['old' => '邀请人数奖励', 'new' => 'invite_reward'],
            131 => ['old' => '银行列表', 'new' => 'bank_list'],
            132 => ['old' => '银行列表', 'new' => 'bank_list'],
            138 => ['old' => '全民代理报表', 'new' => 'national_promoter_report'],
            139 => ['old' => '全民代理报表', 'new' => 'national_promoter_report'],
            140 => ['old' => '分润明细', 'new' => 'profit_detail'],
            144 => ['old' => '反水管理', 'new' => 'reverse_water_manage'],
            145 => ['old' => '反水活动', 'new' => 'reverse_water_activity'],
            146 => ['old' => '反水奖励记录', 'new' => 'reverse_water_reward_record'],
            147 => ['old' => '腾讯云配置', 'new' => 'tencent_cloud_config'],
            148 => ['old' => '反水管理', 'new' => 'reverse_water_manage'],
            149 => ['old' => '反水活动', 'new' => 'reverse_water_activity'],
            150 => ['old' => '反水奖励记录', 'new' => 'reverse_water_reward_record'],
            152 => ['old' => '分润报表', 'new' => 'profit_report'],
            155 => ['old' => '管理员操作', 'new' => 'admin_operation'],
            156 => ['old' => '币商提现记录', 'new' => 'coin_withdraw_record'],
            157 => ['old' => '币商提现记录', 'new' => 'coin_withdraw_record'],
            158 => ['old' => '机台大赏报表', 'new' => 'machine_reward_report'],
            159 => ['old' => '管理员操作日志', 'new' => 'admin_operation_log'],
            170 => ['old' => '分润明细', 'new' => 'profit_detail'],
            172 => ['old' => '分润结算', 'new' => 'profit_settlement'],
            173 => ['old' => '分润管理', 'new' => 'profit_manage'],
            174 => ['old' => '分润明细', 'new' => 'profit_detail'],
            176 => ['old' => '线下代理', 'new' => 'offline_agent'],
            182 => ['old' => '线下代理管理', 'new' => 'offline_agent_manage'],
            183 => ['old' => '线下代理列表', 'new' => 'offline_agent_list'],
            184 => ['old' => '结算记录', 'new' => 'settlement_record'],
            185 => ['old' => '电子游戏彩金', 'new' => 'game_lottery'],
            188 => ['old' => '游戏玩家', 'new' => 'game_player'],
            190 => ['old' => '代理后台', 'new' => 'agent_manage'],
            191 => ['old' => '店机后台', 'new' => 'store_manage'],
            192 => ['old' => '代理中心', 'new' => 'agent_center'],
            194 => ['old' => '店家列表', 'new' => 'store_list'],
            195 => ['old' => '設備清單', 'new' => 'device_list_tw'],
            199 => ['old' => '店机中心', 'new' => 'store_center'],
            203 => ['old' => '店机管理', 'new' => 'store_machine_manage'],
            204 => ['old' => '彩金领取', 'new' => 'lottery_receive'],
            228 => ['old' => '财务管理', 'new' => 'financial_management'],
            244 => ['old' => '设备管理', 'new' => 'device_manage'],
            245 => ['old' => '设备列表', 'new' => 'device_list'],
            251 => ['old' => '交班记录', 'new' => 'shift_handover_record'],
            253 => ['old' => '限红管理', 'new' => 'limit_management'],
            254 => ['old' => '限红组管理', 'new' => 'limit_group_management'],
            255 => ['old' => '平台配置', 'new' => 'platform_config'],
            256 => ['old' => '限红管理', 'new' => 'limit_management'],
        ];

        $updatedCount = 0;
        $skippedCount = 0;

        foreach ($updates as $id => $data) {
            // 检查当前名称
            $current = $this->fetchRow("SELECT name FROM admin_menus WHERE id = {$id}");

            if (!$current) {
                $this->output->writeln("   ⏭  ID {$id}: 菜单不存在，跳过");
                $skippedCount++;
                continue;
            }

            if ($current['name'] === $data['new']) {
                $this->output->writeln("   ⏭  ID {$id}: 已经是英文key ({$data['new']})，跳过");
                $skippedCount++;
                continue;
            }

            // 更新菜单名称
            $this->execute("UPDATE admin_menus SET name = '{$data['new']}' WHERE id = {$id}");
            $this->output->writeln("   ✅ ID {$id}: '{$current['name']}' => '{$data['new']}'");
            $updatedCount++;
        }

        $this->output->writeln('');
        $this->output->writeln('=== 迁移完成 ===');
        $this->output->writeln("   总菜单数: " . count($updates));
        $this->output->writeln("   已更新: {$updatedCount} 个");
        $this->output->writeln("   跳过: {$skippedCount} 个");
        $this->output->writeln('');
        $this->output->writeln('下一步:');
        $this->output->writeln('  1. 确保 gk_admin 中已添加对应的翻译文件');
        $this->output->writeln('  2. 清除后管缓存');
        $this->output->writeln('  3. 验证菜单显示是否正确');
    }

    /**
     * 回滚迁移
     */
    public function down()
    {
        $this->output->writeln('⚠️  警告: 此迁移不支持回滚');
        $this->output->writeln('   原因: 中文菜单名称已被英文key替换，无法恢复原始中文');
    }
}
