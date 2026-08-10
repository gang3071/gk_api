<?php

use Phinx\Migration\AbstractMigration;

class CreateWashPointSettingTable extends AbstractMigration
{
    /**
     * 创建洗分配置表
     */
    public function change(): void
    {
        $table = $this->table('wash_point_setting', [
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => '洗分配置表'
        ]);

        $table->addColumn('admin_user_id', 'integer', [
            'limit' => 11,
            'null' => false,
            'comment' => '店家AdminUser ID'
        ])
        ->addColumn('wash_1', 'decimal', [
            'precision' => 10,
            'scale' => 2,
            'null' => false,
            'default' => 0.00,
            'comment' => '洗分选项1'
        ])
        ->addColumn('wash_2', 'decimal', [
            'precision' => 10,
            'scale' => 2,
            'null' => false,
            'default' => 0.00,
            'comment' => '洗分选项2'
        ])
        ->addColumn('wash_3', 'decimal', [
            'precision' => 10,
            'scale' => 2,
            'null' => false,
            'default' => 0.00,
            'comment' => '洗分选项3'
        ])
        ->addColumn('wash_4', 'decimal', [
            'precision' => 10,
            'scale' => 2,
            'null' => false,
            'default' => 0.00,
            'comment' => '洗分选项4'
        ])
        ->addColumn('wash_5', 'decimal', [
            'precision' => 10,
            'scale' => 2,
            'null' => false,
            'default' => 0.00,
            'comment' => '洗分选项5'
        ])
        ->addColumn('wash_6', 'decimal', [
            'precision' => 10,
            'scale' => 2,
            'null' => false,
            'default' => 0.00,
            'comment' => '洗分选项6'
        ])
        ->addColumn('default_wash_point', 'decimal', [
            'precision' => 10,
            'scale' => 2,
            'null' => false,
            'default' => 100.00,
            'comment' => '默认洗分基数'
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
        ->addIndex(['admin_user_id'], ['unique' => true, 'name' => 'idx_admin_user_id'])
        ->create();
    }
}
