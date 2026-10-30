<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * vip_level 表新增高设定机台可玩数量字段
 *
 * 用途：限制该 VIP 等级玩家可玩的高设定机台数量。
 * 0 = 不可玩，>0 = 可玩数量。
 */
final class AddHighSettingMachinePlayNumToVipLevel extends AbstractMigration
{
    public function up(): void
    {
        $this->table('vip_level')
            ->addColumn('high_setting_machine_play_num', 'integer', [
                'null'    => false,
                'default' => 0,
                'signed'  => false,
                'comment' => '高设定机台可玩数量(0=不可玩)',
                'after'   => 'daily_login_bonus',
            ])
            ->save();

        echo "✓ vip_level.high_setting_machine_play_num 字段添加完成\n";
    }

    public function down(): void
    {
        $this->table('vip_level')
            ->removeColumn('high_setting_machine_play_num')
            ->save();

        echo "✓ vip_level.high_setting_machine_play_num 字段已回滚\n";
    }
}
