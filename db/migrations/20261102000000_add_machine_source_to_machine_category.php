<?php

use Phinx\Migration\AbstractMigration;

/**
 * 在 machine_category 表添加 machine_source 字段
 *
 * 用于标记机台类别属于线上机台（有直播）还是线下机台（无直播）
 * 1 = 线上机台（有直播）
 * 2 = 线下机台（无直播）
 */
class AddMachineSourceToMachineCategory extends AbstractMigration
{
    public function change()
    {
        $table = $this->table('machine_category');

        if (!$table->hasColumn('machine_source')) {
            $table->addColumn('machine_source', 'integer', [
                'null' => false,
                'limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_TINY,
                'signed' => false,
                'default' => 1,
                'comment' => '机台来源 1=线上机台（有直播） 2=线下机台（无直播）',
                'after' => 'sort',
            ]);
            $table->update();
        }
    }

    public function down()
    {
        $table = $this->table('machine_category');
        if ($table->hasColumn('machine_source')) {
            $table->removeColumn('machine_source');
            $table->update();
        }
    }
}
