<?php

use Phinx\Migration\AbstractMigration;

/**
 * 插入店机设备收集开关设置
 */
class InsertDeviceCollectSetting extends AbstractMigration
{
    /**
     * 执行迁移
     */
    public function up()
    {
        $this->execute("
            INSERT INTO `system_setting`
            (`department_id`, `feature`, `num`, `content`, `date_start`, `date_end`, `status`, `created_at`, `updated_at`)
            VALUES
            (0, 'device_collect', 1, '', '00:00:00', '23:59:59', 1, NOW(), NOW())
        ");
    }

    /**
     * 回滚迁移
     */
    public function down()
    {
        $this->execute("
            DELETE FROM `system_setting` WHERE `feature` = 'device_collect'
        ");
    }
}
