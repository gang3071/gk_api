<?php

use Phinx\Migration\AbstractMigration;

/**
 * 为所有渠道添加储值机版本号和下载链接配置
 * 用于管理储值机的版本号和客户端下载链接
 */
class AddTicketMachineVersionSetting extends AbstractMigration
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
        $skipCount = 0;
        $defaultContent = ''; // 默认内容为空
        $defaultStatus = 1; // 默认启用

        // 要创建的配置项
        $features = ['ticket_machine_version', 'ticket_machine_download_url'];

        foreach ($channels as $channel) {
            $departmentId = $channel['department_id'];
            $channelName = $channel['name'];

            foreach ($features as $feature) {
                // 检查该配置是否已存在
                $exists = $this->fetchRow("
                    SELECT COUNT(*) as count
                    FROM system_setting
                    WHERE department_id = {$departmentId}
                      AND feature = '{$feature}'
                ");

                if ($exists['count'] > 0) {
                    $this->output->writeln("   ⏭  渠道 {$departmentId} ({$channelName}) - {$feature} 已存在，跳过");
                    $skipCount++;
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

                $this->output->writeln("   ✅ 渠道 {$departmentId} ({$channelName}) - {$feature} 已创建");
                $createdCount++;
            }
        }

        $this->output->writeln('');
        $this->output->writeln('=== 迁移完成 ===');
        $this->output->writeln("   总渠道数: " . count($channels));
        $this->output->writeln("   配置项数: " . count($features));
        $this->output->writeln("   新创建配置: {$createdCount} 个");
        $this->output->writeln("   已存在跳过: {$skipCount} 个");
        $this->output->writeln('');
        $this->output->writeln('下一步:');
        $this->output->writeln('  1. 访问渠道后台 - 系统配置');
        $this->output->writeln('  2. 为各渠道设置储值机版本号和下载链接');
    }

    /**
     * 回滚迁移
     */
    public function down()
    {
        // 删除所有储值机版本号配置
        $this->execute("
            DELETE FROM system_setting
            WHERE feature = 'ticket_machine_version'
        ");

        // 删除所有储值机客户端下载链接配置
        $this->execute("
            DELETE FROM system_setting
            WHERE feature = 'ticket_machine_download_url'
        ");

        $this->output->writeln('   ✅ 已删除所有储值机版本号和下载链接配置');
    }
}
