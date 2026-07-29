<?php

use Phinx\Migration\AbstractMigration;

/**
 * 添加语音播报URL字段到设备表
 *
 * 功能：为设备表添加 voice_url 字段，用于存储 Google TTS 生成的语音播报文件URL
 * 播报内容："{设备名称}呼叫服务"
 * 语音：台湾女声（cmn-TW-Wavenet-A）
 *
 * @date 2026-07-29
 */
class AddVoiceUrlToAdminDevice extends AbstractMigration
{
    /**
     * 获取表名（带前缀）
     */
    private function getTable(): string
    {
        return 'admin_device';
    }

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        $table = $this->table($this->getTable());

        // 检查字段是否已存在
        if (!$table->hasColumn('voice_url')) {
            $table->addColumn('voice_url', 'string', [
                'limit' => 255,
                'null' => true,
                'comment' => '语音播报文件URL',
                'after' => 'device_model'
            ])->update();

            $this->output->writeln('<info>✓ 已添加 voice_url 字段到 admin_device 表</info>');
        } else {
            $this->output->writeln('<comment>! voice_url 字段已存在，跳过添加</comment>');
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        $table = $this->table($this->getTable());

        // 检查字段是否存在
        if ($table->hasColumn('voice_url')) {
            $table->removeColumn('voice_url')->update();
            $this->output->writeln('<info>✓ 已删除 admin_device 表的 voice_url 字段</info>');
        } else {
            $this->output->writeln('<comment>! voice_url 字段不存在，跳过删除</comment>');
        }
    }
}