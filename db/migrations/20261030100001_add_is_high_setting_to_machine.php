<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * machine 表新增 is_high_setting 字段
 *
 * 用途：标记该机台是否为高设定机台。
 * 0 = 否，1 = 是。
 * 配合 vip_level.high_setting_machine_play_num（高设定机台可玩数量）使用。
 */
final class AddIsHighSettingToMachine extends AbstractMigration
{
    public function up(): void
    {
        $this->table('machine')
            ->addColumn('is_high_setting', 'integer', [
                'null'    => false,
                'default' => 0,
                'signed'  => false,
                'comment' => '是否高设定(0=否 1=是)',
                'after'   => 'min_vip_level_id',
            ])
            ->save();

        echo "✓ machine.is_high_setting 字段添加完成\n";
    }

    public function down(): void
    {
        $this->table('machine')
            ->removeColumn('is_high_setting')
            ->save();

        echo "✓ machine.is_high_setting 字段已回滚\n";
    }
}
