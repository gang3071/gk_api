<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddTicketFieldsToShiftRecord extends AbstractMigration
{
    /**
     * Change Method.
     *
     * Write your reversible migrations using this method.
     *
     * More information on writing migrations is available here:
     * https://book.cakephp.org/phinx/0/en/migrations.html#the-change-method
     *
     * Remember to call "create()" or "update()" and NOT "save()" when working
     * with the Table class.
     */
    public function change(): void
    {
        $table = $this->table('store_agent_shift_handover_record');

        if (!$table->hasColumn('ticket_record_total_score')) {
            $table->addColumn('ticket_record_total_score', 'decimal', [
                'precision' => 12,
                'scale' => 2,
                'default' => 0,
                'null' => false,
                'comment' => '出票记录总金额',
                'after' => 'machine_bet_amount'
            ]);
        }

        if (!$table->hasColumn('ticket_redeem_backend_used_score')) {
            $table->addColumn('ticket_redeem_backend_used_score', 'decimal', [
                'precision' => 12,
                'scale' => 2,
                'default' => 0,
                'null' => false,
                'comment' => '核销记录后台使用金额',
                'after' => 'ticket_record_total_score'
            ]);
        }

        $table->save();
    }
}
