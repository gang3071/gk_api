<?php

use Phinx\Migration\AbstractMigration;

/**
 * 账变记录表添加设备ID字段
 *
 * 记录储值机等设备（admin_device.id）产生的流水归属，
 * 交班报表导出时按设备拆分「储值机储值」金额（与出票记录的 issue_device_id 对应）。
 */
class AddDeviceIdToPlayerDeliveryRecord extends AbstractMigration
{
    /**
     * Change Method.
     */
    public function change(): void
    {
        $table = $this->table('player_delivery_record');

        // 检查字段是否已存在，避免重复添加
        if (!$table->hasColumn('device_id')) {
            $table->addColumn('device_id', 'integer', [
                'signed' => false,
                'null' => false,
                'default' => 0,
                'comment' => '设备ID（admin_device.id，如储值机），0=未记录',
            ])
            ->addIndex(['device_id'], [
                'name' => 'idx_device_id',
            ]);
        }

        $table->save();
    }
}
