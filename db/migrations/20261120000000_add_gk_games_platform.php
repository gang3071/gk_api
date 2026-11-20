<?php

use Phinx\Migration\AbstractMigration;

/**
 * 添加 GK 遊戲平台
 */
final class AddGkGamesPlatform extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("
            INSERT INTO `game_platform` (`code`, `name`, `ratio`, `cate_id`, `display_mode`, `has_lobby`, `status`, `maintenance_status`, `sort`, `created_at`, `updated_at`, `picture`)
            VALUES ('GK', 'GK', '0.00', '[2]', '3', '0', '1', '0', '15', NOW(), NOW(), '{\"zh-CN\":{\"picture\":\"\"},\"zh-TW\":{\"picture\":\"\"},\"en\":{\"picture\":\"\"},\"jp\":{\"picture\":\"\"}}')
        ");
    }

    public function down(): void
    {
        $this->execute("DELETE FROM `game_platform` WHERE `code` = 'GK'");
    }
}
