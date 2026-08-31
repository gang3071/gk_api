<?php

use Phinx\Migration\AbstractMigration;

/**
 * 建立點餐訂單表
 */
class CreateDishOrderTable extends AbstractMigration
{
    public function up()
    {
        if ($this->hasTable('dish_order')) {
            return;
        }

        $table = $this->table('dish_order', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => '點餐訂單表'
        ]);

        $table
            ->addColumn('id', 'biginteger', [
                'signed' => false,
                'identity' => true,
                'comment' => 'ID'
            ])
            ->addColumn('order_no', 'string', [
                'limit' => 32,
                'null' => false,
                'comment' => '訂單編號'
            ])
            ->addColumn('player_id', 'biginteger', [
                'signed' => false,
                'null' => false,
                'comment' => '玩家ID'
            ])
            ->addColumn('department_id', 'integer', [
                'signed' => false,
                'null' => false,
                'default' => 0,
                'comment' => '部門ID'
            ])
            ->addColumn('total_amount', 'decimal', [
                'precision' => 16,
                'scale' => 2,
                'null' => false,
                'default' => '0.00',
                'comment' => '訂單總金額(積分)'
            ])
            ->addColumn('status', 'tinyinteger', [
                'signed' => false,
                'null' => false,
                'default' => 0,
                'comment' => '狀態（0=待確認 1=已確認 2=製作中 3=已完成 4=已取消）'
            ])
            ->addColumn('remark', 'string', [
                'limit' => 255,
                'null' => true,
                'default' => '',
                'comment' => '訂單備註'
            ])
            ->addColumn('created_at', 'timestamp', [
                'null' => true,
                'comment' => '建立時間'
            ])
            ->addColumn('updated_at', 'timestamp', [
                'null' => true,
                'comment' => '更新時間'
            ])
            ->addIndex(['order_no'], ['unique' => true, 'name' => 'uk_order_no'])
            ->addIndex(['player_id'], ['name' => 'idx_player_id'])
            ->addIndex(['department_id'], ['name' => 'idx_department_id'])
            ->addIndex(['status'], ['name' => 'idx_status'])
            ->addIndex(['created_at'], ['name' => 'idx_created_at'])
            ->create();
    }

    public function down()
    {
        $this->table('dish_order')->drop()->save();
    }
}
