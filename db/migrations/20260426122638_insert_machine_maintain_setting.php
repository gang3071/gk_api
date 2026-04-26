<?php

use Phinx\Migration\AbstractMigration;

/**
 * 插入机器维护设置记录
 */
class InsertMachineMaintainSetting extends AbstractMigration
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
            (0, 'client_maintain', 1, '', '08:00:00', '09:03:00', 1, '2023-03-20 14:17:27', '2026-04-20 18:33:03')
        ");
    }

    /**
     * 回滚迁移
     */
    public function down()
    {
        $this->execute("
            DELETE FROM `system_setting` WHERE `feature` = 'client_maintain'
        ");
    }
}
