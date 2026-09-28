<?php

use Phinx\Migration\AbstractMigration;

class AddDeviceFieldsToTicketRecord extends AbstractMigration
{
    /**
     * 为 qr_ticket_record 表添加设备记录字段
     *
     * 新增字段：
     * - issue_device_id: 出票设备ID（购票/领券/拆合票/洗分出票时的设备）
     * - redeem_device_id: 核销设备ID（扫码核销使用票据时的设备）
     */
    public function change(): void
    {
        $table = $this->table('qr_ticket_record');

        // 出票设备：记录票据创建时的设备
        $table->addColumn('issue_device_id', 'integer', [
            'signed' => false,
            'default' => 0,
            'comment' => '出票设备ID',
            'after' => 'machine_id',
        ])
        // 核销设备：记录票据扫码核销使用时的设备
        ->addColumn('redeem_device_id', 'integer', [
            'signed' => false,
            'default' => 0,
            'comment' => '核销设备ID',
            'after' => 'scanned_by',
        ])
        // 索引
        ->addIndex(['issue_device_id'], [
            'name' => 'idx_issue_device_id',
        ])
        ->addIndex(['redeem_device_id'], [
            'name' => 'idx_redeem_device_id',
        ])
        ->save();
    }
}
