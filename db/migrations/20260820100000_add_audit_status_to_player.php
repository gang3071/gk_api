<?php

use Phinx\Migration\AbstractMigration;

class AddAuditStatusToPlayer extends AbstractMigration
{
    public function change()
    {
        $table = $this->table('player');
        $table->addColumn('audit_status', 'integer', [
            'signed' => false,
            'null' => false,
            'default' => 0,
            'after' => 'real_name',
            'comment' => '审核状态 0=未审核 1=已通过 2=未通过',
        ])
        ->save();
    }
}
