<?php

use Phinx\Migration\AbstractMigration;

/**
 * 建立餐點類別表
 */
class CreateDishCategoryTable extends AbstractMigration
{
    public function up()
    {
        if ($this->hasTable('dish_category')) {
            return;
        }

        $table = $this->table('dish_category', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => '餐點類別表'
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
            ->addColumn('title', 'string', [
                'limit' => 255,
                'null' => false,
                'comment' => '類別名稱'
            ])
            ->addColumn('content', 'string', [
                'limit' => 255,
                'null' => true,
                'comment' => '類別描述'
            ])
            ->addColumn('picture', 'string', [
                'limit' => 255,
                'null' => true,
                'comment' => '類別圖片'
            ])
            ->addColumn('status', 'tinyinteger', [
                'signed' => false,
                'null' => false,
                'default' => 1,
                'comment' => '狀態（1=啟用 2=停用）'
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
                'default' => 100,
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
            ->create();
    }

    public function down()
    {
        $this->table('dish_category')->drop()->save();
    }
}
