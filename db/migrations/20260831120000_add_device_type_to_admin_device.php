<?php

use Phinx\Migration\AbstractMigration;

class AddDeviceTypeToAdminDevice extends AbstractMigration
{
    /**
     * 为 admin_device 表添加设备类型字段
     *
     * 设备类型：
     * - 1 = 游戏机
     * - 2 = 储值机
     */
    public function change(): void
    {
        $table = $this->table('admin_device');

        $table->addColumn('device_type', 'integer', [
            'limit' => 1,
            'signed' => false,
            'null' => false,
            'default' => 1,
            'comment' => '设备类型: 1=游戏机, 2=储值机',
            'after' => 'device_model',
        ])
        ->addIndex(['device_type'], [
            'name' => 'idx_device_type',
        ])
        ->save();
    }
}
