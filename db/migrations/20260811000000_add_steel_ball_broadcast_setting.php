<?php

use Phinx\Migration\AbstractMigration;

/**
 * 为所有渠道添加钢珠下珠数报喜配置
 * 当玩家在钢珠机台下珠数超过配置阈值时全频道广播该消息
 */
class AddSteelBallBroadcastSetting extends AbstractMigration
{
    /**
     * 执行迁移
     */
    public function up()
    {
        // 只获取渠道表中的 department_id
        $channels = $this->fetchAll("
            SELECT department_id, name
            FROM channel
            WHERE deleted_at IS NULL
            ORDER BY department_id
        ");

        if (empty($channels)) {
            $this->output->writeln('   ⚠️  未找到任何渠道');
            return;
        }

        $this->output->writeln('   ℹ️  找到 ' . count($channels) . ' 个渠道');

        $createdCount = 0;
        $existsCount = 0;
        $defaultThreshold = 500; // 默认阈值 500 珠
        $defaultStatus = 0; // 默认禁用

        foreach ($channels as $channel) {
            $departmentId = $channel['department_id'];
            $channelName = $channel['name'];

            // 检查是否已存在配置
            $exists = $this->fetchRow("
                SELECT COUNT(*) as count
                FROM system_setting
                WHERE department_id = {$departmentId}
                  AND feature = 'steel_ball_broadcast_threshold'
            ");

            if ($exists['count'] > 0) {
                $this->output->writeln("   ⏭  渠道 {$departmentId} ({$channelName}) - 配置已存在，跳过");
                $existsCount++;
                continue;
            }

            // 创建配置记录
            $this->execute("
                INSERT INTO system_setting (
                    department_id,
                    feature,
                    num,
                    content,
                    date_start,
                    date_end,
                    status,
                    created_at,
                    updated_at
                ) VALUES (
                    {$departmentId},
                    'steel_ball_broadcast_threshold',
                    {$defaultThreshold},
                    '',
                    NULL,
                    NULL,
                    {$defaultStatus},
                    NOW(),
                    NOW()
                )
            ");

            $this->output->writeln("   ✅ 渠道 {$departmentId} ({$channelName}) - 已创建（阈值: {$defaultThreshold} 珠，状态: 禁用）");
            $createdCount++;
        }

        $this->output->writeln('');
        $this->output->writeln('=== 迁移完成 ===');
        $this->output->writeln("   总渠道数: " . count($channels));
        $this->output->writeln("   新创建配置: {$createdCount} 个");
        $this->output->writeln("   已存在配置: {$existsCount} 个");
        $this->output->writeln('');
        $this->output->writeln('下一步:');
        $this->output->writeln('  1. 访问后台系统设置页面');
        $this->output->writeln('  2. 找到【钢珠下珠数报喜阈值】配置项');
        $this->output->writeln('  3. 为需要的渠道启用并设置阈值');
    }

    /**
     * 回滚迁移
     */
    public function down()
    {
        // 删除所有钢珠下珠数报喜配置
        $this->execute("
            DELETE FROM system_setting
            WHERE feature = 'steel_ball_broadcast_threshold'
        ");

        $this->output->writeln('   ✅ 已删除所有钢珠下珠数报喜配置');
    }
}
