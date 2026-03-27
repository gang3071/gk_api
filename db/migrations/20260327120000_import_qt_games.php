<?php

use Phinx\Migration\AbstractMigration;

/**
 * 导入QT电子游戏列表
 */
class ImportQtGames extends AbstractMigration
{
    /**
     * 执行迁移
     */
    public function up()
    {
        // 查找QT平台ID
        $platform = $this->fetchRow("SELECT id FROM `game_platform` WHERE `code` = 'QT' LIMIT 1");

        if (!$platform) {
            $this->output->writeln('<error>未找到QT平台，请先运行 20260327100000_add_qt_game_platform.php 迁移</error>');
            return;
        }

        $platformId = $platform['id'];
        $this->output->writeln("<info>找到QT平台，ID: {$platformId}</info>");

        // 插入QT游戏数据
        $this->execute("
            INSERT INTO `game_extend` (
                `game_id`,
                `platform_id`,
                `cate_id`,
                `name`,
                `code`,
                `logo`,
                `status`,
                `org_data`,
                `created_at`,
                `updated_at`
            ) VALUES
            ('qt_game', {$platformId}, 2, 'QT电子游戏', 'qt_game', '', 1, '{}', NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                `name` = VALUES(`name`),
                `logo` = VALUES(`logo`),
                `status` = VALUES(`status`),
                `updated_at` = NOW()
        ");

        $this->output->writeln("<info>成功导入QT电子游戏</info>");
    }

    /**
     * 回滚迁移
     */
    public function down()
    {
        // 查找QT平台ID
        $platform = $this->fetchRow("SELECT id FROM `game_platform` WHERE `code` = 'QT' LIMIT 1");

        if ($platform) {
            $platformId = $platform['id'];

            // 删除QT平台的所有游戏
            $this->execute("
                DELETE FROM `game_extend` WHERE `platform_id` = {$platformId}
            ");

            $this->output->writeln("<info>已删除QT平台的所有游戏</info>");
        }
    }
}


