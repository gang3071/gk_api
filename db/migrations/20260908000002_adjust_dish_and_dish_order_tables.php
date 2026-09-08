<?php

use Phinx\Migration\AbstractMigration;

/**
 * 調整餐點/類別/訂單表結構
 * - dish_category 移除 content、picture、department_id
 * - dish_category 狀態註解調整、排序預設改 0
 * - dish 狀態註解調整、排序預設改 0
 * - dish_order 新增 admin_user_id（門店ID）欄位
 */
class AdjustDishAndDishOrderTables extends AbstractMigration
{
    public function up()
    {
        // ===== dish_category：移除不需欄位與索引，並調整狀態/排序 =====
        $category = $this->table('dish_category');
        if ($category->hasIndexByName('idx_department_id')) {
            $category->removeIndexByName('idx_department_id')->save();
        }
        if ($category->hasColumn('department_id')) {
            $category->removeColumn('department_id')->save();
        }
        if ($category->hasColumn('content')) {
            $category->removeColumn('content')->save();
        }
        if ($category->hasColumn('picture')) {
            $category->removeColumn('picture')->save();
        }

        // ===== dish：調整狀態與排序預設值 =====
        $dish = $this->table('dish');
        $dish->changeColumn('status', 'tinyinteger', [
            'signed' => false,
            'null' => false,
            'default' => 1,
            'comment' => '狀態（1=啟用 0=停用）'
        ])->changeColumn('sort', 'integer', [
                'signed' => false,
                'null' => false,
                'default' => 0,
                'comment' => '排序'
            ])->save();

        // ===== dish_order：新增門店ID欄位 =====
        $order = $this->table('dish_order');
        if (!$order->hasColumn('admin_user_id')) {
            $order->addColumn('admin_user_id', 'integer', [
                'signed' => false,
                'null' => false,
                'default' => 0,
                'after' => 'department_id',
                'comment' => '門店ID'
            ])->addIndex(['admin_user_id'], ['name' => 'idx_admin_user_id'])
                ->save();
        }
    }

    public function down()
    {
        // ===== dish_order：移除門店ID欄位 =====
        $order = $this->table('dish_order');
        if ($order->hasColumn('admin_user_id')) {
            $order->removeIndexByName('idx_admin_user_id')->removeColumn('admin_user_id')->save();
        }

        // ===== dish：還原狀態與排序預設值 =====
        $dish = $this->table('dish');
        $dish->changeColumn('status', 'tinyinteger', [
            'signed' => false,
            'null' => false,
            'default' => 1,
            'comment' => '狀態（1=啟用 2=停用 3=售完）'
        ])->changeColumn('sort', 'integer', [
                'signed' => false,
                'null' => false,
                'default' => 100,
                'comment' => '排序'
            ])->save();

        // ===== dish_category：還原欄位 =====
        $category = $this->table('dish_category');
        if (!$category->hasColumn('department_id')) {
            $category->addColumn('department_id', 'integer', [
                'signed' => false,
                'null' => false,
                'default' => 0,
                'comment' => '渠道ID'
            ])->addIndex(['department_id'], ['name' => 'idx_department_id'])
                ->save();
        }
        if (!$category->hasColumn('content')) {
            $category->addColumn('content', 'string', [
                'limit' => 255,
                'null' => true,
                'comment' => '類別描述'
            ])->save();
        }
        if (!$category->hasColumn('picture')) {
            $category->addColumn('picture', 'string', [
                'limit' => 255,
                'null' => true,
                'comment' => '類別圖片'
            ])->save();
        }
    }
}