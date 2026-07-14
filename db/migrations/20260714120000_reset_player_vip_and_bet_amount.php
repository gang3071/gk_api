<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 重置玩家VIP等级和打码量
 *
 * 1. 清零所有玩家的累计打码量 (total_bet_amount)
 * 2. 重置玩家VIP周期记录表 (player_vip_period)
 * 3. 重置所有玩家的VIP等级为7 (vip_level_id)
 * 4. 将所有 play_game_record 记录设置为已结算
 */
final class ResetPlayerVipAndBetAmount extends AbstractMigration
{
    /**
     * Migrate Up.
     */
    public function up(): void
    {
        $this->output->writeln('=========================================');
        $this->output->writeln('开始重置玩家VIP等级和打码量...');
        $this->output->writeln('=========================================');

        // 1. 清零所有玩家的累计打码量
        $this->output->writeln('');
        $this->output->writeln('步骤1: 清零玩家累计打码量');
        $this->output->writeln('  - 表: player');
        $this->output->writeln('  - 字段: total_bet_amount = 0');
        $affectedRows = $this->execute('UPDATE player SET total_bet_amount = 0');
        $this->output->writeln("  ✓ 已更新 {$affectedRows} 条玩家记录");

        // 2. 清空玩家VIP周期记录表
        $this->output->writeln('');
        $this->output->writeln('步骤2: 清空玩家VIP周期记录表');
        $this->output->writeln('  - 表: player_vip_period');
        $this->output->writeln('  - 操作: TRUNCATE TABLE 清空所有数据');
        $this->execute('TRUNCATE TABLE player_vip_period');
        $this->output->writeln("  ✓ 已清空 player_vip_period 表");

        // 3. 重置所有玩家的VIP等级为7
        $this->output->writeln('');
        $this->output->writeln('步骤3: 重置所有玩家的VIP等级');
        $this->output->writeln('  - 表: player');
        $this->output->writeln('  - 字段: vip_level_id = 7');
        $affectedRows = $this->execute('UPDATE player SET vip_level_id = 7');
        $this->output->writeln("  ✓ 已更新 {$affectedRows} 条玩家记录的VIP等级为7");

        // 4. 清零玩家扩展表的返水金额
        $this->output->writeln('');
        $this->output->writeln('步骤4: 清零玩家扩展表的返水金额');
        $this->output->writeln('  - 表: player_extend');
        $this->output->writeln('  - 字段: pending_cashback_amount = 0, total_cashback_amount = 0');
        $affectedRows = $this->execute('UPDATE player_extend SET pending_cashback_amount = 0, total_cashback_amount = 0');
        $this->output->writeln("  ✓ 已更新 {$affectedRows} 条玩家扩展记录");

        // 5. 将所有 play_game_record 记录的 vip_level_id 设置为7
        $this->output->writeln('');
        $this->output->writeln('步骤5: 重置所有游戏记录的VIP等级ID');
        $this->output->writeln('  - 表: play_game_record');
        $this->output->writeln('  - 字段: vip_level_id = 7');
        $affectedRows = $this->execute('UPDATE play_game_record SET vip_level_id = 7');
        $this->output->writeln("  ✓ 已更新 {$affectedRows} 条游戏记录的 vip_level_id 为7");

        $this->output->writeln('');
        $this->output->writeln('=========================================');
        $this->output->writeln('重置完成！');
        $this->output->writeln('=========================================');
        $this->output->writeln('');
        $this->output->writeln('执行的操作汇总:');
        $this->output->writeln('  1. player.total_bet_amount → 0 (清零累计打码量)');
        $this->output->writeln('  2. player_vip_period TRUNCATE (清空VIP周期记录)');
        $this->output->writeln('  3. player.vip_level_id → 7 (重置VIP等级)');
        $this->output->writeln('  4. player_extend 返水金额清零');
        $this->output->writeln('     - pending_cashback_amount → 0');
        $this->output->writeln('     - total_cashback_amount → 0');
        $this->output->writeln('  5. play_game_record.vip_level_id → 7 (重置游戏记录VIP等级)');
        $this->output->writeln('=========================================');
    }

    /**
     * Migrate Down.
     */
    public function down(): void
    {
        $this->output->writeln('警告: 此迁移不可逆操作！');
        $this->output->writeln('原始数据已无法恢复。');
    }
}
