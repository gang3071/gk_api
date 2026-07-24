<?php

use Phinx\Migration\AbstractMigration;

class AddRebateSwitchSettings extends AbstractMigration
{
    /**
     * Change Method.
     *
     * Write your reversible migrations using this method.
     *
     * More information on writing migrations is available here:
     * https://book.cakephp.org/phinx/0/en/migrations.html#the-change-method
     *
     * Remember to call "create()" or "update()" and NOT "save()" when working
     * with the Table class.
     */
    public function change(): void
    {
        // 电子游戏反水开关
        $this->execute("
            INSERT INTO `system_setting` (`department_id`, `feature`, `num`, `content`, `date_start`, `date_end`, `status`, `created_at`, `updated_at`)
            SELECT
                d.id as department_id,
                'electronic_game_rebate' as feature,
                0 as num,
                '' as content,
                '' as date_start,
                '' as date_end,
                1 as status,
                NOW() as created_at,
                NOW() as updated_at
            FROM `admin_department` d
            WHERE NOT EXISTS (
                SELECT 1 FROM `system_setting` s
                WHERE s.department_id = d.id AND s.feature = 'electronic_game_rebate'
            )
        ");

        // 实体机台反水开关
        $this->execute("
            INSERT INTO `system_setting` (`department_id`, `feature`, `num`, `content`, `date_start`, `date_end`, `status`, `created_at`, `updated_at`)
            SELECT
                d.id as department_id,
                'machine_rebate' as feature,
                0 as num,
                '' as content,
                '' as date_start,
                '' as date_end,
                1 as status,
                NOW() as created_at,
                NOW() as updated_at
            FROM `admin_department` d
            WHERE NOT EXISTS (
                SELECT 1 FROM `system_setting` s
                WHERE s.department_id = d.id AND s.feature = 'machine_rebate'
            )
        ");
    }
}
