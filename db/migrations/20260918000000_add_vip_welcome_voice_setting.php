<?php

use Phinx\Migration\AbstractMigration;

/**
 * 为总配置和所有渠道添加 VIP 欢迎语音配置
 * 支持 VIP 8/9/10 三个等级独立设置欢迎语音文字及合成音频
 */
class AddVipWelcomeVoiceSetting extends AbstractMigration
{
    /**
     * 执行迁移
     */
    public function up()
    {
        $feature = 'vip_welcome_voice';
        $defaultContent = '{}';
        $defaultStatus = 1;
        $createdCount = 0;
        $skipCount = 0;

        // 需要创建配置的 department_id 列表：0 为总配置 + 所有渠道
        $departmentIds = [['department_id' => 0, 'name' => '总配置']];

        $channels = $this->fetchAll("
            SELECT department_id, name
            FROM channel
            WHERE deleted_at IS NULL
            ORDER BY department_id
        ");

        foreach ($channels as $channel) {
            $departmentIds[] = $channel;
        }

        $this->output->writeln('   ℹ️  共 ' . count($departmentIds) . ' 个配置范围（总配置 + ' . count($channels) . ' 个渠道）');

        foreach ($departmentIds as $item) {
            $departmentId = (int)$item['department_id'];
            $name = $item['name'];

            $exists = $this->fetchRow("
                SELECT COUNT(*) as count
                FROM system_setting
                WHERE department_id = {$departmentId}
                  AND feature = '{$feature}'
            ");

            if ($exists['count'] > 0) {
                $this->output->writeln("   ⏭  {$name}（department_id={$departmentId}）- {$feature} 已存在，跳过");
                $skipCount++;
                continue;
            }

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
                    '{$feature}',
                    0,
                    '{$defaultContent}',
                    NULL,
                    NULL,
                    {$defaultStatus},
                    NOW(),
                    NOW()
                )
            ");

            $this->output->writeln("   ✅ {$name}（department_id={$departmentId}）- {$feature} 已创建");
            $createdCount++;
        }

        $this->output->writeln('');
        $this->output->writeln('=== 迁移完成 ===');
        $this->output->writeln("   新创建: {$createdCount} 个");
        $this->output->writeln("   已跳过: {$skipCount} 个");
        $this->output->writeln('');
        $this->output->writeln('下一步:');
        $this->output->writeln('  1. 访问总后台 - 系统配置');
        $this->output->writeln('  2. 找到「VIP欢迎语音」配置行，点击配置标签');
        $this->output->writeln('  3. 为 VIP 8 / 9 / 10 分别输入欢迎语音文字并保存');
    }

    /**
     * 回滚迁移
     */
    public function down()
    {
        $this->execute("
            DELETE FROM system_setting
            WHERE feature = 'vip_welcome_voice'
        ");

        $this->output->writeln('   ✅ 已删除所有 vip_welcome_voice 配置');
    }
}
