<?php

use Phinx\Migration\AbstractMigration;

/**
 * 为交班记录表添加活动奖励和摸奖券奖励字段
 *
 * 涉及两张表:
 * 1. store_agent_shift_handover_record - 交班记录主表
 * 2. store_shift_device_detail - 交班设备明细表
 */
class AddActivityFieldsToShiftHandoverRecord extends AbstractMigration
{
    public function change()
    {
        // ========================================
        // 1. 修改交班记录主表
        // ========================================
        $shiftTable = $this->table('store_agent_shift_handover_record');

        if (!$shiftTable->exists()) {
            $this->output->writeln('<error>表 store_agent_shift_handover_record 不存在</error>');
            return;
        }

        // 添加活动奖励金额字段
        if (!$shiftTable->hasColumn('activity_bonus_amount')) {
            $shiftTable->addColumn('activity_bonus_amount', 'decimal', [
                'precision' => 10,
                'scale' => 2,
                'default' => '0.00',
                'null' => false,
                'comment' => '活动奖励金额（TYPE_ACTIVITY_BONUS=10）',
                'after' => 'lottery_amount'
            ]);
        }

        // 添加摸奖券中奖奖励金额字段
        if (!$shiftTable->hasColumn('lottery_ticket_reward_amount')) {
            $shiftTable->addColumn('lottery_ticket_reward_amount', 'decimal', [
                'precision' => 10,
                'scale' => 2,
                'default' => '0.00',
                'null' => false,
                'comment' => '摸奖券中奖奖励金额（TYPE_LOTTERY_TICKET_REWARD=33）',
                'after' => 'activity_bonus_amount'
            ]);
        }

        $shiftTable->update();
        $this->output->writeln('<info>✓ 交班记录主表字段添加完成</info>');

        // ========================================
        // 2. 修改交班设备明细表
        // ========================================
        $detailTable = $this->table('store_shift_device_detail');

        if (!$detailTable->exists()) {
            $this->output->writeln('<error>表 store_shift_device_detail 不存在</error>');
            return;
        }

        // 添加活动奖励金额字段
        if (!$detailTable->hasColumn('activity_bonus_amount')) {
            $detailTable->addColumn('activity_bonus_amount', 'decimal', [
                'precision' => 10,
                'scale' => 2,
                'default' => '0.00',
                'null' => false,
                'comment' => '活动奖励金额（TYPE_ACTIVITY_BONUS=10）',
                'after' => 'lottery_amount'
            ]);
        }

        // 添加摸奖券中奖奖励金额字段
        if (!$detailTable->hasColumn('lottery_ticket_reward_amount')) {
            $detailTable->addColumn('lottery_ticket_reward_amount', 'decimal', [
                'precision' => 10,
                'scale' => 2,
                'default' => '0.00',
                'null' => false,
                'comment' => '摸奖券中奖奖励金额（TYPE_LOTTERY_TICKET_REWARD=33）',
                'after' => 'activity_bonus_amount'
            ]);
        }

        $detailTable->update();
        $this->output->writeln('<info>✓ 设备明细表字段添加完成</info>');

        // ========================================
        // 提示信息
        // ========================================
        $this->output->writeln('');
        $this->output->writeln('<comment>========================================</comment>');
        $this->output->writeln('<comment>后续需要修改的文件:</comment>');
        $this->output->writeln('<comment>1. StoreAgentShiftHandoverRecord.php - 添加 @property 注释</comment>');
        $this->output->writeln('<comment>2. StoreShiftDeviceDetail.php - 添加 @property 注释</comment>');
        $this->output->writeln('<comment>3. ChannelIndexController.php - 手动交班统计逻辑</comment>');
        $this->output->writeln('<comment>4. StoreShiftHandoverRecordController.php - 显示列</comment>');
        $this->output->writeln('<comment>5. ShiftReportExporter.php - 导出器</comment>');
        $this->output->writeln('<comment>6. 翻译文件 (8个) - 字段翻译</comment>');
        $this->output->writeln('<comment>========================================</comment>');
    }
}
