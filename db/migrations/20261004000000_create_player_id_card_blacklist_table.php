<?php

use Phinx\Migration\AbstractMigration;

class CreatePlayerIdCardBlacklistTable extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table($this->getTable('player_id_card_blacklist'));
        $table->addColumn('id_number', 'string', ['limit' => 100, 'comment' => '身份证号'])
            ->addColumn('player_id', 'integer', ['null' => true, 'comment' => '玩家ID'])
            ->addColumn('player_name', 'string', ['limit' => 100, 'null' => true, 'comment' => '玩家名称'])
            ->addColumn('admin_id', 'integer', ['default' => 0, 'comment' => '操作管理员ID'])
            ->addColumn('admin_name', 'string', ['limit' => 100, 'null' => true, 'comment' => '操作管理员名称'])
            ->addColumn('remark', 'string', ['limit' => 500, 'null' => true, 'comment' => '备注'])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['id_number'])
            ->create();
    }
}
