<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * slider 表新增 type 字段：1=图片 2=视频
 *
 * 存量数据按素材扩展名回填：mp4/webm/mov 记为视频，其余为图片。
 */
final class AddTypeToSlider extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("
            ALTER TABLE `slider`
            ADD COLUMN `type` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '类型 1=图片 2=视频' AFTER `picture_url`
        ");

        // 存量视频素材回填
        $this->execute("
            UPDATE `slider` SET `type` = 2
            WHERE `picture_url` LIKE '%.mp4'
               OR `picture_url` LIKE '%.webm'
               OR `picture_url` LIKE '%.mov'
        ");

        $row = $this->fetchRow("SELECT COUNT(*) AS c FROM `slider` WHERE `type` = 2");
        $this->output->writeln('✓ slider.type 字段添加完成，识别存量视频 ' . (int)($row['c'] ?? 0) . ' 条');
    }

    public function down(): void
    {
        $this->execute("ALTER TABLE `slider` DROP COLUMN `type`");
        $this->output->writeln('✓ slider.type 字段已回滚');
    }
}
