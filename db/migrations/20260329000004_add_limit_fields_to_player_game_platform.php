<?php

use Phinx\Migration\AbstractMigration;

/**
 * 为player_game_platform表添加限红相关字段
 */
class AddLimitFieldsToPlayerGamePlatform extends AbstractMigration
{
    /**
     * 执行迁移
     */
    public function up()
    {
        $this->execute("
            ALTER TABLE `player_game_platform`
            ADD COLUMN `limit_group_id` BIGINT UNSIGNED NULL DEFAULT NULL COMMENT '当前使用的限红组ID（通过店家继承）' AFTER `web_id`,
            ADD COLUMN `limit_config_data` JSON NULL DEFAULT NULL COMMENT '限红配置快照（缓存用）' AFTER `limit_group_id`,
            ADD KEY `idx_limit_group` (`limit_group_id`);
        ");
    }

    /**
     * 回滚迁移
     */
    public function down()
    {
        $this->execute("
            ALTER TABLE `player_game_platform`
            DROP INDEX `idx_limit_group`,
            DROP COLUMN `limit_group_id`,
            DROP COLUMN `limit_config_data`;
        ");
    }
}
