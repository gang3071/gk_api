<?php

use Phinx\Migration\AbstractMigration;

class CreateTicketRecordTable extends AbstractMigration
{
    /**
     * Change Method.
     *
     * Write your reversible migrations using this method.
     */
    public function change()
    {
        $table = $this->table('qr_ticket_record', [
            'id'          => true,
            'primary_key' => ['id'],
            'engine'      => 'InnoDB',
            'collation'   => 'utf8mb4_unicode_ci',
            'comment'     => '出票记录表',
            'signed'      => false,
        ]);

        $table->addColumn('order_id', 'string', [
            'limit'   => 64,
            'default' => '',
            'comment' => '订单号',
        ])
        ->addColumn('department_id', 'integer', [
            'signed'  => false,
            'default' => 0,
            'comment' => '部门ID',
        ])
        ->addColumn('store_admin_id', 'integer', [
            'signed'  => false,
            'default' => 0,
            'comment' => '店家管理员ID',
        ])
        ->addColumn('store_name', 'string', [
            'limit'   => 50,
            'default' => '',
            'comment' => '店名',
        ])
        ->addColumn('machine_no', 'integer', [
            'signed'  => false,
            'default' => 0,
            'comment' => '台号',
        ])
        ->addColumn('machine_id', 'integer', [
            'signed'  => false,
            'default' => 0,
            'comment' => '机台ID',
        ])
        ->addColumn('player_id', 'integer', [
            'signed'  => false,
            'default' => 0,
            'comment' => '玩家ID',
        ])
        ->addColumn('player_name', 'string', [
            'limit'   => 50,
            'default' => '',
            'comment' => '玩家名称',
        ])
        ->addColumn('score', 'decimal', [
            'precision' => 12,
            'scale'     => 2,
            'default'   => 0.00,
            'comment'   => '分数/金额',
        ])
        ->addColumn('qr_code', 'text', [
            'null'    => true,
            'comment' => '二维码信息',
        ])
        ->addColumn('qr_code_no', 'string', [
            'limit'   => 100,
            'default' => '',
            'comment' => '二维码编号',
        ])
        ->addColumn('ticket_type', 'integer', [
            'signed'  => false,
            'default' => 0,
            'comment' => '票据类型: 1=开分 2=洗分',
        ])
        ->addColumn('status', 'integer', [
            'signed'  => false,
            'default' => 1,
            'comment' => '状态: 0=禁用 1=正常 2=已打印 3=已使用',
        ])
        ->addColumn('print_count', 'integer', [
            'signed'  => false,
            'default' => 0,
            'comment' => '打印次数',
        ])
        ->addColumn('last_print_time', 'datetime', [
            'null'    => true,
            'comment' => '最后打印时间',
        ])
        ->addColumn('extra_data', 'json', [
            'null'    => true,
            'comment' => '扩展数据',
        ])
        ->addColumn('remark', 'string', [
            'limit'   => 255,
            'default' => '',
            'comment' => '备注',
        ])
        ->addColumn('created_at', 'datetime', [
            'default' => 'CURRENT_TIMESTAMP',
            'comment' => '创建时间',
        ])
        ->addColumn('updated_at', 'datetime', [
            'default' => 'CURRENT_TIMESTAMP',
            'update'  => 'CURRENT_TIMESTAMP',
            'comment' => '更新时间',
        ])
        ->addColumn('deleted_at', 'datetime', [
            'null'    => true,
            'comment' => '删除时间',
        ])
        ->addIndex(['order_id'])
        ->addIndex(['department_id'])
        ->addIndex(['store_admin_id'])
        ->addIndex(['machine_id'])
        ->addIndex(['player_id'])
        ->addIndex(['ticket_type'])
        ->addIndex(['status'])
        ->addIndex(['created_at'])
        ->addIndex(['qr_code_no'])
        ->save();
    }
}
