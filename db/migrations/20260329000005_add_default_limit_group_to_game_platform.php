<?php

use Phinx\Migration\AbstractMigration;

/**
 * 为game_platform表添加默认限红组字段
 */
class AddDefaultLimitGroupToGamePlatform extends AbstractMigration
{
    /**
     * 执行迁移
     */
    public function up()
    {
        $this->execute("
            ALTER TABLE `game_platform`
            ADD COLUMN `default_limit_group_id` BIGINT UNSIGNED NULL DEFAULT NULL COMMENT '默认限红组ID' AFTER `status`,
            ADD KEY `idx_default_limit_group` (`default_limit_group_id`);
        ");
    }

    /**
     * 回滚迁移
     */
    public function down()
    {
        $this->execute("
            ALTER TABLE `game_platform`
            DROP INDEX `idx_default_limit_group`,
            DROP COLUMN `default_limit_group_id`;
        ");
    }
}
