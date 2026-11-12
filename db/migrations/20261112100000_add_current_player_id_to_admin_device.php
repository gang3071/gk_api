<?php

use Phinx\Migration\AbstractMigration;

/**
 * 为 admin_device 表添加 current_player_id 字段，记录当前登录该设备的玩家ID
 */
class AddCurrentPlayerIdToAdminDevice extends AbstractMigration
{
    public function up()
    {
        if ($this->hasTable('admin_device') && !$this->table('admin_device')->hasColumn('current_player_id')) {
            $this->execute("
                ALTER TABLE `admin_device`
                ADD COLUMN `current_player_id` INT(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '当前登录的玩家ID' AFTER `store_admin_id`,
                ADD KEY `idx_current_player_id` (`current_player_id`)
            ");
        }
    }

    public function down()
    {
        if ($this->hasTable('admin_device') && $this->table('admin_device')->hasColumn('current_player_id')) {
            $this->execute("ALTER TABLE `admin_device` DROP KEY `idx_current_player_id`, DROP COLUMN `current_player_id`");
        }
    }
}
