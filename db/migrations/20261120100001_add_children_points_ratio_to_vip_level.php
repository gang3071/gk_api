<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * vip_level 表新增推广积分比例字段
 *
 * 用途：玩家按此比例从下级玩家的积分抽成（推广积分），
 * 分成口径与推广红利比例（children_cashback_ratio）一致：100 = 100%，0.1 = 0.1%。
 */
final class AddChildrenPointsRatioToVipLevel extends AbstractMigration
{
    public function up(): void
    {
        $this->table('vip_level')
            ->addColumn('children_points_ratio', 'decimal', [
                'precision' => 10,
                'scale'     => 2,
                'null'      => false,
                'default'   => '0.00',
                'comment'   => '推廣積分比例 (%)',
                'after'     => 'children_cashback_ratio',
            ])
            ->save();

        echo "✓ vip_level.children_points_ratio 字段添加完成\n";
    }

    public function down(): void
    {
        $this->table('vip_level')
            ->removeColumn('children_points_ratio')
            ->save();

        echo "✓ vip_level.children_points_ratio 字段已回滚\n";
    }
}
