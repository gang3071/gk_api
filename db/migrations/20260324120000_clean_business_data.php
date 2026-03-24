<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 清理业务数据，保留基本配置
 *
 * 清理内容：
 * - 玩家数据
 * - 充值/提现记录
 * - 游戏记录
 * - 金流记录
 * - 交班记录
 * - 报表数据
 *
 * 保留内容：
 * - 系统配置
 * - 管理员账号（超级管理员）
 * - 菜单和权限
 * - 渠道配置
 * - 机台配置
 */
final class CleanBusinessData extends AbstractMigration
{
    /**
     * Migrate Up - 清理业务数据
     */
    public function up(): void
    {
        echo "开始清理业务数据...\n";

        // 禁用外键检查
        $this->execute("SET FOREIGN_KEY_CHECKS = 0");

        try {
            // ========== 1. 清理玩家相关数据 ==========
            echo "清理玩家数据...\n";
            $this->cleanPlayerData();

            // ========== 2. 清理订单和记录数据 ==========
            echo "清理订单和记录数据...\n";
            $this->cleanOrderData();

            // ========== 3. 清理报表和统计数据 ==========
            echo "清理报表数据...\n";
            $this->cleanReportData();

            // ========== 4. 清理日志数据 ==========
            echo "清理日志数据...\n";
            $this->cleanLogData();

            // ========== 5. 清理交班记录 ==========
            echo "清理交班记录...\n";
            $this->cleanShiftData();

            // ========== 6. 清理渠道数据 ==========
            echo "清理渠道数据...\n";
            $this->cleanChannelData();

            // ========== 7. 重置配置表的业务关联数据 ==========
            echo "重置配置表关联...\n";
            $this->resetConfigRelations();

            // ========== 8. 清理业务配置表 ==========
            echo "清理业务配置表...\n";
            $this->cleanBusinessConfigTables();

            // ========== 9. 清理额外日志数据 ==========
            echo "清理额外日志数据...\n";
            $this->cleanAdditionalLogs();

            // ========== 10. 清理其他业务记录 ==========
            echo "清理其他业务记录...\n";
            $this->cleanOtherBusinessRecords();

            echo "\n";
            echo "========================================\n";
            echo "业务数据清理完成！\n";
            echo "========================================\n";
            echo "保留的数据：\n";
            echo "  - 系统配置（system_setting等）\n";
            echo "  - 管理员、菜单、角色权限\n";
            echo "  - 线下渠道及其配置\n";
            echo "  - 游戏、机台、彩金配置\n";
            echo "  - 活动、公告等内容配置\n";
            echo "========================================\n";

        } finally {
            // 恢复外键检查
            $this->execute("SET FOREIGN_KEY_CHECKS = 1");
        }
    }

    /**
     * 清理玩家相关数据
     */
    private function cleanPlayerData(): void
    {
        $tables = [
            'player',                          // 玩家
            'player_extend',                   // 玩家扩展
            'player_platform_cash',            // 玩家钱包
            'player_bank',                     // 玩家银行卡
            'player_favorite_machine',         // 收藏机台
            'player_money_edit_log',          // 钱包编辑日志
            'player_edit_log',                // 玩家编辑日志
            'player_promoter',                // 推广员
            'national_promoter',              // 全民代理
            'national_profit_record',         // 全民代理分润记录
        ];

        foreach ($tables as $table) {
            if ($this->hasTable($table)) {
                $this->execute("TRUNCATE TABLE `{$table}`");
                echo "  ✓ 已清理: {$table}\n";
            } else {
                echo "  - 跳过(表不存在): {$table}\n";
            }
        }
    }

    /**
     * 清理订单和记录数据
     */
    private function cleanOrderData(): void
    {
        $tables = [
            'player_recharge_record',         // 充值记录
            'player_withdraw_record',         // 提现记录
            'player_delivery_record',         // 金流记录
            'player_present_record',          // 转点记录
            'player_wallet_transfer',         // 钱包转账
            'player_lottery_record',          // 彩金记录
            'player_activity_record',         // 活动记录
            'player_activity_phase_record',   // 活动阶段记录
            'player_reverse_water_detail',    // 反水明细
            'deposit_bonus_order',            // 充值满赠订单
            'deposit_bonus_bet_detail',       // 押码明细
        ];

        foreach ($tables as $table) {
            if ($this->hasTable($table)) {
                $this->execute("TRUNCATE TABLE `{$table}`");
                echo "  ✓ 已清理: {$table}\n";
            } else {
                echo "  - 跳过(表不存在): {$table}\n";
            }
        }
    }

    /**
     * 清理报表和统计数据
     */
    private function cleanReportData(): void
    {
        $tables = [
            'machine_report',                 // 机台报表
            'channel_profit_record',          // 渠道分润记录
            'channel_profit_settlement_record', // 渠道分润结算
            'promoter_profit_record',         // 推广员分润记录
            'promoter_profit_settlement_record', // 推广员分润结算
        ];

        foreach ($tables as $table) {
            if ($this->hasTable($table)) {
                $this->execute("TRUNCATE TABLE `{$table}`");
                echo "  ✓ 已清理: {$table}\n";
            } else {
                echo "  - 跳过(表不存在): {$table}\n";
            }
        }
    }

    /**
     * 清理日志数据
     */
    private function cleanLogData(): void
    {
        $tables = [
            'player_game_record',             // 游戏记录
            'play_game_record',               // 电子游戏记录
            'machine_keeping_log',            // 机台保留日志
            'machine_operation_log',          // 机台操作日志
            'machine_edit_log',               // 机台异动日志
            'machine_lottery_record',         // 机台彩金记录
            'machine_recording',              // 机台录像
        ];

        foreach ($tables as $table) {
            if ($this->hasTable($table)) {
                $this->execute("TRUNCATE TABLE `{$table}`");
                echo "  ✓ 已清理: {$table}\n";
            } else {
                echo "  - 跳过(表不存在): {$table}\n";
            }
        }
    }

    /**
     * 清理交班记录
     */
    private function cleanShiftData(): void
    {
        $tables = [
            'store_agent_shift_handover_record', // 交班记录
            'store_auto_shift_log',              // 自动交班日志
        ];

        foreach ($tables as $table) {
            if ($this->hasTable($table)) {
                $this->execute("TRUNCATE TABLE `{$table}`");
                echo "  ✓ 已清理: {$table}\n";
            } else {
                echo "  - 跳过(表不存在): {$table}\n";
            }
        }
    }

    /**
     * 清理渠道数据（保留线下渠道）
     */
    private function cleanChannelData(): void
    {
        if (!$this->hasTable('channel')) {
            echo "  - 跳过(表不存在): channel\n";
            return;
        }

        // 保留线下渠道（type=1 直营 且 is_offline=1）
        // Channel::TYPE_STORE = 1 (直营)
        // is_offline = 1 (线下站)
        $this->execute("
            DELETE FROM channel
            WHERE is_offline = 0
               OR type != 1
        ");
        echo "  ✓ 已清理: channel (保留线下直营站)\n";

        // 清理渠道相关的配置表
        $relatedTables = [
            'channel_recharge_setting',        // 渠道充值配置
            'channel_recharge_method',         // 渠道充值方式
            'channel_game_web',                // 渠道游戏配置
        ];

        foreach ($relatedTables as $table) {
            if ($this->hasTable($table)) {
                // 清理不属于线下渠道的配置
                $this->execute("
                    DELETE FROM `{$table}`
                    WHERE department_id NOT IN (
                        SELECT department_id FROM channel
                        WHERE is_offline = 1 AND type = 1
                    )
                ");
                echo "  ✓ 已清理: {$table} (保留线下渠道配置)\n";
            } else {
                echo "  - 跳过(表不存在): {$table}\n";
            }
        }

        // 清理已删除渠道的管理员（保留总站和线下渠道的管理员）
        if ($this->hasTable('admin_users')) {
            $this->execute("
                DELETE FROM admin_users
                WHERE type IN (2, 3, 4)
                  AND department_id NOT IN (
                      SELECT department_id FROM channel
                      WHERE is_offline = 1 AND type = 1
                  )
                  AND id > 1
            ");
            echo "  ✓ 已清理: admin_users (保留线下渠道管理员)\n";
        } else {
            echo "  - 跳过(表不存在): admin_users\n";
        }
    }

    /**
     * 重置配置表的业务关联数据
     */
    private function resetConfigRelations(): void
    {
        // 重置机台状态
        if ($this->hasTable('machine')) {
            $this->execute("
                UPDATE machine
                SET player_id = 0,
                    player_uuid = '',
                    player_name = '',
                    player_phone = '',
                    updated_at = NOW()
                WHERE player_id > 0
            ");
            echo "  ✓ 已重置: machine 的玩家绑定\n";
        } else {
            echo "  - 跳过(表不存在): machine\n";
        }

        // 重置彩金池金额
        if ($this->hasTable('lottery_pool')) {
            $this->execute("
                UPDATE lottery_pool
                SET amount = 0,
                    updated_at = NOW()
            ");
            echo "  ✓ 已重置: lottery_pool 金额\n";
        } else {
            echo "  - 跳过(表不存在): lottery_pool\n";
        }

        // 清理非超级管理员创建的管理员（可选）
        // 如果需要保留所有管理员，注释掉这段
        if ($this->hasTable('admin_users')) {
            // 只保留超级管理员（id=1）和类型为总站的管理员
            $this->execute("
                DELETE FROM admin_users
                WHERE id > 1
                  AND type NOT IN (1)
                  AND username NOT LIKE 'admin%'
            ");
            echo "  ✓ 已清理: 非必要管理员账号\n";
        } else {
            echo "  - 跳过(表不存在): admin_users (重置阶段)\n";
        }
    }

    /**
     * 清理业务配置表
     */
    private function cleanBusinessConfigTables(): void
    {
        $tables = [
            'store_setting',              // 店家配置
            'open_score_setting',         // 开分配置
            'store_auto_shift_config',    // 自动交班配置
        ];

        foreach ($tables as $table) {
            if ($this->hasTable($table)) {
                $this->execute("TRUNCATE TABLE `{$table}`");
                echo "  ✓ 已清理: {$table}\n";
            } else {
                echo "  - 跳过(表不存在): {$table}\n";
            }
        }
    }

    /**
     * 清理额外日志数据
     */
    private function cleanAdditionalLogs(): void
    {
        $tables = [
            'phone_sms_log',                // 短信日志
            'api_error_log',                // API错误日志
            'device_access_log',            // 设备访问日志
            'machine_gaming_log',           // 机台游戏日志
            'machine_kick_log',             // 机台踢人日志
            'player_login_record',          // 玩家登录记录
            'player_register_record',       // 玩家注册记录
            'player_enter_game_record',     // 玩家进入游戏记录
            'player_game_log',              // 玩家游戏日志
            'play_history',                 // 游戏历史
        ];

        foreach ($tables as $table) {
            if ($this->hasTable($table)) {
                $this->execute("TRUNCATE TABLE `{$table}`");
                echo "  ✓ 已清理: {$table}\n";
            } else {
                echo "  - 跳过(表不存在): {$table}\n";
            }
        }
    }

    /**
     * 清理其他业务记录
     */
    private function cleanOtherBusinessRecords(): void
    {
        $tables = [
            'agent_transfer_order',             // 代理转账订单
            'player_wash_record',               // 玩家洗分记录
            'player_gift_record',               // 玩家礼物记录
            'player_tag',                       // 玩家标签
            'player_bonus_task',                // 玩家奖金任务
            'player_money_edit_log_bonus',      // 玩家金额编辑日志奖金
            'promoter_profit_game_record',      // 推广员利润游戏记录
            'store_agent_profit_record',        // 店家代理利润记录
            'channel_transfer_record',          // 渠道转账记录
            'deposit_bonus_statistics',         // 充值奖金统计
            'player_allowed_game',              // 玩家允许游戏
            'player_disabled_game',             // 玩家禁用游戏
            'player_game_platform',             // 玩家游戏平台
        ];

        foreach ($tables as $table) {
            if ($this->hasTable($table)) {
                $this->execute("TRUNCATE TABLE `{$table}`");
                echo "  ✓ 已清理: {$table}\n";
            } else {
                echo "  - 跳过(表不存在): {$table}\n";
            }
        }
    }

    /**
     * Migrate Down - 回滚（不支持）
     */
    public function down(): void
    {
        echo "警告：此操作不支持回滚！\n";
        echo "数据清理是不可逆操作，请确保已备份数据库！\n";
    }

    /**
     * 检查表是否存在
     */
    private function hasTable(string $tableName): bool
    {
        $rows = $this->fetchAll("SHOW TABLES LIKE '{$tableName}'");
        return !empty($rows);
    }
}
