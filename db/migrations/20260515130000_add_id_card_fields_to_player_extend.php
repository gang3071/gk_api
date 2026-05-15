<?php

use Phinx\Migration\AbstractMigration;

/**
 * 为player_extend表添加身份证图片和个人照片字段
 * 用于玩家详情页展示身份证正反面照和个人照片
 */
class AddIdCardFieldsToPlayerExtend extends AbstractMigration
{
    /**
     * 执行迁移
     */
    public function up()
    {
        // 检查字段是否已存在，避免重复添加
        $sql = "SELECT COUNT(*) as count FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'player_extend'
                AND COLUMN_NAME = 'id_card_front'";

        $result = $this->fetchRow($sql);

        if ($result['count'] == 0) {
            $this->execute("
                ALTER TABLE `player_extend`
                ADD COLUMN `id_card_front` varchar(500) DEFAULT NULL COMMENT '身份证正面照URL' AFTER `id_number`,
                ADD COLUMN `id_card_back` varchar(500) DEFAULT NULL COMMENT '身份证反面照URL' AFTER `id_card_front`,
                ADD COLUMN `personal_photo` varchar(500) DEFAULT NULL COMMENT '个人照片URL' AFTER `id_card_back`
            ");
        }
    }

    /**
     * 回滚迁移
     */
    public function down()
    {
        $sql = "SELECT COUNT(*) as count FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'player_extend'
                AND COLUMN_NAME = 'id_card_front'";

        $result = $this->fetchRow($sql);

        if ($result['count'] > 0) {
            $this->execute("
                ALTER TABLE `player_extend`
                DROP COLUMN `personal_photo`,
                DROP COLUMN `id_card_back`,
                DROP COLUMN `id_card_front`
            ");
        }
    }
}
