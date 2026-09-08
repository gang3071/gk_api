<?php

use Phinx\Migration\AbstractMigration;

class CreatePurchaseScoreSettingTable extends AbstractMigration
{
    /**
     * 创建储值机购分配置表
     */
    public function change(): void
    {
        $table = $this->table('purchase_score_setting', [
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => '储值机购分配置表'
        ]);

        $table->addColumn('store_admin_id', 'integer', [
            'limit' => 11,
            'null' => false,
            'comment' => '店机id'
        ])
        ->addColumn('score_1', 'integer', [
            'limit' => 11,
            'null' => false,
            'default' => 0,
            'comment' => '购分选项1'
        ])
        ->addColumn('score_2', 'integer', [
            'limit' => 11,
            'null' => false,
            'default' => 0,
            'comment' => '购分选项2'
        ])
        ->addColumn('score_3', 'integer', [
            'limit' => 11,
            'null' => false,
            'default' => 0,
            'comment' => '购分选项3'
        ])
        ->addColumn('score_4', 'integer', [
            'limit' => 11,
            'null' => false,
            'default' => 0,
            'comment' => '购分选项4'
        ])
        ->addColumn('score_5', 'integer', [
            'limit' => 11,
            'null' => false,
            'default' => 0,
            'comment' => '购分选项5'
        ])
        ->addColumn('score_6', 'integer', [
            'limit' => 11,
            'null' => false,
            'default' => 0,
            'comment' => '购分选项6'
        ])
        ->addColumn('default_scores', 'integer', [
            'limit' => 11,
            'null' => false,
            'default' => 0,
            'comment' => '默认购分数'
        ])
        ->addColumn('created_at', 'timestamp', [
            'null' => true,
            'default' => null,
            'comment' => '创建时间'
        ])
        ->addColumn('updated_at', 'timestamp', [
            'null' => true,
            'default' => null,
            'comment' => '更新时间'
        ])
        ->addIndex(['store_admin_id'], ['unique' => true, 'name' => 'idx_store_admin_id'])
        ->create();
    }
}
