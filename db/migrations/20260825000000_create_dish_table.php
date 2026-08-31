<?php

use Phinx\Migration\AbstractMigration;

/**
 * 建立餐點表
 */
class CreateDishTable extends AbstractMigration
{
    public function up()
    {
        if ($this->hasTable('dish')) {
            return;
        }

        $table = $this->table('dish', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => '餐點表'
        ]);

        $table
            ->addColumn('id', 'integer', [
                'signed' => false,
                'identity' => true,
                'comment' => 'ID'
            ])
            ->addColumn('department_id', 'integer', [
                'signed' => false,
                'null' => false,
                'default' => 0,
                'comment' => '部門ID'
            ])
            ->addColumn('admin_user_id', 'integer', [
                'signed' => false,
                'null' => false,
                'default' => 0,
                'comment' => '門店ID'
            ])
            ->addColumn('category_id', 'integer', [
                'signed' => false,
                'null' => false,
                'default' => 0,
                'comment' => '類別ID'
            ])
            ->addColumn('title', 'string', [
                'limit' => 255,
                'null' => false,
                'comment' => '餐點名稱'
            ])
            ->addColumn('content', 'string', [
                'limit' => 255,
                'null' => true,
                'comment' => '餐點描述'
            ])
            ->addColumn('picture', 'string', [
                'limit' => 255,
                'null' => true,
                'comment' => '餐點圖片'
            ])
            ->addColumn('price', 'decimal', [
                'precision' => 16,
                'scale' => 2,
                'null' => false,
                'default' => '0.00',
                'comment' => '價格'
            ])
            ->addColumn('daily_limit', 'integer', [
                'signed' => false,
                'null' => false,
                'default' => 0,
                'comment' => '每人每天限量（0=不限量）'
            ])
            ->addColumn('status', 'tinyinteger', [
                'signed' => false,
                'null' => false,
                'default' => 1,
                'comment' => '狀態（1=啟用 0=停用）'
            ])
            ->addColumn('top', 'tinyinteger', [
                'signed' => false,
                'null' => false,
                'default' => 1,
                'comment' => '置頂（1=置頂 0=沒置頂）'
            ])
            ->addColumn('sort', 'integer', [
                'signed' => false,
                'null' => false,
                'default' => 0,
                'comment' => '排序'
            ])
            ->addColumn('remark', 'text', [
                'null' => true,
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
            ->addIndex(['department_id'], ['name' => 'idx_department_id'])
            ->addIndex(['admin_user_id'], ['name' => 'idx_admin_user_id'])
            ->addIndex(['category_id'], ['name' => 'idx_category_id'])
            ->create();
    }

    public function down()
    {
        $this->table('dish')->drop()->save();
    }
}
