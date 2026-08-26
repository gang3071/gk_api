<?php

use Phinx\Migration\AbstractMigration;

class AddExperienceBetCheckEnabledToAdminUsers extends AbstractMigration
{
    public function change()
    {
        $table = $this->table('admin_users');
        $table->addColumn('experience_bet_check_enabled', 'boolean', [
            'signed' => false,
            'null' => false,
            'default' => 0,
            'after' => 'status',
            'comment' => '体验券打码判定开关（0=关闭，1=开启）',
        ])
        ->save();
    }
}
