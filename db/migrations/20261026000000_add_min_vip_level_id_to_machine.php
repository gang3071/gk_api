<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * machine 表新增 min_vip_level_id 字段
 *
 * 用途：限制使用该机台的玩家最低 VIP 等级。
 * 0 = 不限制，>0 = 对应 vip_level.id 的最低等级要求。
 */
final class AddMinVipLevelIdToMachine extends AbstractMigration
{
    public function up(): void
    {
        $this->table('machine')
            ->addColumn('min_vip_level_id', 'integer', [
                'null'    => false,
                'default' => 0,
                'signed'  => false,
                'comment' => '最低VIP等级限制(0=不限制)',
                'after'   => 'is_special',
            ])
            ->save();

        echo "✓ machine.min_vip_level_id 字段添加完成\n";
    }

    public function down(): void
    {
        $this->table('machine')
            ->removeColumn('min_vip_level_id')
            ->save();

        echo "✓ machine.min_vip_level_id 字段已回滚\n";
    }
}
