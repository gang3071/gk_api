<?php

use Phinx\Migration\AbstractMigration;

class AddVoucherEnabledToAdminUsers extends AbstractMigration
{
    public function change()
    {
        $table = $this->table($this->getTable('admin_users'));
        $table->addColumn('experience_voucher_enabled', 'boolean', [
            'signed' => false,
            'null' => false,
            'default' => 1,
            'after' => 'experience_bet_check_enabled',
            'comment' => '体验券开关（0=关闭，1=开启）',
        ])
        ->addColumn('welfare_voucher_enabled', 'boolean', [
            'signed' => false,
            'null' => false,
            'default' => 1,
            'after' => 'experience_voucher_enabled',
            'comment' => '福利券开关（0=关闭，1=开启）',
        ])
        ->save();
    }
}
