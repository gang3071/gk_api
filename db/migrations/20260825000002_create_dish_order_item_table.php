<?php

use Phinx\Migration\AbstractMigration;

/**
 * 建立點餐訂單明細表
 */
class CreateDishOrderItemTable extends AbstractMigration
{
    public function up()
    {
        if ($this->hasTable('dish_order_item')) {
            return;
        }

        $table = $this->table('dish_order_item', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => '點餐訂單明細表'
        ]);

        $table
            ->addColumn('id', 'biginteger', [
                'signed' => false,
                'identity' => true,
                'comment' => 'ID'
            ])
            ->addColumn('order_id', 'biginteger', [
                'signed' => false,
                'null' => false,
                'comment' => '訂單ID'
            ])
            ->addColumn('dish_id', 'biginteger', [
                'signed' => false,
                'null' => false,
                'comment' => '餐點ID'
            ])
            ->addColumn('dish_title', 'string', [
                'limit' => 255,
                'null' => false,
                'comment' => '餐點名稱（下單時快照）'
            ])
            ->addColumn('dish_picture', 'string', [
                'limit' => 255,
                'null' => true,
                'default' => '',
                'comment' => '餐點圖片（下單時快照）'
            ])
            ->addColumn('quantity', 'integer', [
                'signed' => false,
                'null' => false,
                'default' => 1,
                'comment' => '數量'
            ])
            ->addColumn('price', 'decimal', [
                'precision' => 16,
                'scale' => 2,
                'null' => false,
                'default' => '0.00',
                'comment' => '單價（下單時快照）'
            ])
            ->addColumn('subtotal', 'decimal', [
                'precision' => 16,
                'scale' => 2,
                'null' => false,
                'default' => '0.00',
                'comment' => '小計（price × quantity）'
            ])
            ->addColumn('remark', 'string', [
                'limit' => 255,
                'null' => true,
                'default' => '',
                'comment' => '備註'
            ])
            ->addColumn('created_at', 'timestamp', [
                'null' => true,
                'comment' => '建立時間'
            ])
            ->addColumn('updated_at', 'timestamp', [
                'null' => true,
                'comment' => '更新時間'
            ])
            ->addIndex(['order_id'], ['name' => 'idx_order_id'])
            ->addIndex(['dish_id'], ['name' => 'idx_dish_id'])
            ->create();
    }

    public function down()
    {
        $this->table('dish_order_item')->drop()->save();
    }
}
