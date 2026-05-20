<?php

use Phinx\Migration\AbstractMigration;

/**
 * 添加广告位字段到轮播图表
 */
class AddAdPositionToSlider extends AbstractMigration
{
    /**
     * 执行迁移
     */
    public function up()
    {
        $this->execute("
            ALTER TABLE `slider`
            ADD COLUMN `ad_position` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '广告位 1-电子游戏大厅 2-实体大厅 3-待机页面' AFTER `sort`;
        ");

        $this->execute("
            ALTER TABLE `slider`
            ADD INDEX `idx_ad_position` (`ad_position`);
        ");
    }

    /**
     * 回滚迁移
     */
    public function down()
    {
        $this->execute("
            ALTER TABLE `slider`
            DROP INDEX `idx_ad_position`;
        ");

        $this->execute("
            ALTER TABLE `slider`
            DROP COLUMN `ad_position`;
        ");
    }
}
