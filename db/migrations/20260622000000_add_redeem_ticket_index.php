<?php

use Phinx\Migration\AbstractMigration;

class AddRedeemTicketIndex extends AbstractMigration
{
    /**
     * Change Method.
     *
     * Write your reversible migrations using this method.
     */
    public function change()
    {
        $table = $this->table('qr_ticket_record');

        $table->addIndex(['ticket_type', 'store_admin_id', 'created_at'], [
            'name'   => 'idx_ticket_type_store_created',
            'unique' => false,
        ])
        ->save();
    }
}
