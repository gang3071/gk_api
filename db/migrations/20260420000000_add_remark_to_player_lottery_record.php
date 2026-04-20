<?php

use Phinx\Migration\AbstractMigration;

/**
 * 为player_lottery_record表添加备注字段
 * 用于手动发放彩金时记录备注信息
 */
class AddRemarkToPlayerLotteryRecord extends AbstractMigration
{
    /**
     * 执行迁移
     */
    public function up()
    {
        // 检查字段是否已存在，避免重复添加
        $sql = "SELECT COUNT(*) as count FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'player_lottery_record'
                AND COLUMN_NAME = 'remark'";

        $result = $this->fetchRow($sql);

        if ($result['count'] == 0) {
            // 添加备注字段
            $this->execute("
                ALTER TABLE `player_lottery_record`
                ADD COLUMN `remark` varchar(500) DEFAULT '' COMMENT '备注' AFTER `status`
            ");
        }
    }

    /**
     * 回滚迁移
     */
    public function down()
    {
        // 检查字段是否存在
        $sql = "SELECT COUNT(*) as count FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'player_lottery_record'
                AND COLUMN_NAME = 'remark'";

        $result = $this->fetchRow($sql);

        if ($result['count'] > 0) {
            // 删除备注字段
            $this->execute("
                ALTER TABLE `player_lottery_record`
                DROP COLUMN `remark`
            ");
        }
    }
}
