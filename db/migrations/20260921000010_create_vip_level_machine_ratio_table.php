<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 创建VIP等级机台比例表
 */
final class CreateVipLevelMachineRatioTable extends AbstractMigration
{
    /**
     * Change Method.
     */
    public function change(): void
    {
        $table = $this->table('vip_level_machine_ratio', [
            'id' => true,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'VIP等级机台比例配置',
        ]);

        $table
            ->addColumn('vip_level_id', 'integer', [
                'null' => false,
                'comment' => 'VIP等级ID',
            ])
            ->addColumn('machine_type', 'integer', [
                'null' => false,
                'default' => 0,
                'comment' => '机台类型（1=斯洛，2=钢珠）',
            ])
            ->addColumn('ratio', 'decimal', [
                'null' => false,
                'precision' => 10,
                'scale' => 2,
                'default' => '0.00',
                'comment' => '机台比例（100=100%，0.1=0.1%）',
            ])
            ->addColumn('status', 'integer', [
                'null' => false,
                'default' => 1,
                'comment' => '状态（0=禁用，1=启用）',
            ])
            ->addColumn('created_at', 'datetime', [
                'null' => true,
                'comment' => '创建时间',
            ])
            ->addColumn('updated_at', 'datetime', [
                'null' => true,
                'comment' => '更新时间',
            ])
            ->addIndex(['vip_level_id', 'machine_type'], ['unique' => true, 'name' => 'uk_level_machine_type'])
            ->addIndex(['vip_level_id'], ['name' => 'idx_vip_level_id'])
            ->create();
    }
}
