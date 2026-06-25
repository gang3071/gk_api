<?php

use Phinx\Migration\AbstractMigration;

class AddTicketFields extends AbstractMigration
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
        $table = $this->table('qr_ticket_record');

        // 添加加密内容字段
        $table->addColumn('encrypted_content', 'string', [
            'limit' => 500,
            'null' => true,
            'default' => null,
            'comment' => '加密内容(用于生成二维码)',
            'after' => 'qr_code_no',
        ]);

        // 添加扫码时间字段
        $table->addColumn('scanned_at', 'datetime', [
            'null' => true,
            'default' => null,
            'comment' => '扫码时间',
            'after' => 'scan_status',
        ]);

        // 添加扫码人字段
        $table->addColumn('scanned_by', 'string', [
            'limit' => 100,
            'null' => true,
            'default' => null,
            'comment' => '扫码人',
            'after' => 'scanned_at',
        ]);

        $table->save();
    }
}
