<?php

use Phinx\Migration\AbstractMigration;

/**
 * 修改交班记录表，添加柜台开票字段
 */
class AddCounterTicketAmountToHandoverRecord extends AbstractMigration
{
    /**
     * Change Method.
     */
    public function change()
    {
        $table = $this->table('store_agent_shift_handover_record');

        // 检查字段是否已存在，避免重复添加
        if (!$table->hasColumn('counter_ticket_amount')) {
            $table->addColumn('counter_ticket_amount', 'decimal', [
                'null' => false,
                'precision' => 12,
                'scale' => 2,
                'default' => '0.00',
                'comment' => '柜台开票金额',
                'after' => 'welfare_coupon_amount',
            ]);
        }

        // 检查字段是否已存在，避免重复添加
        if (!$table->hasColumn('counter_redeem_amount')) {
            $table->addColumn('counter_redeem_amount', 'decimal', [
                'null' => false,
                'precision' => 12,
                'scale' => 2,
                'default' => '0.00',
                'comment' => '柜台核销金额（开分类型票后台核销）',
                'after' => 'counter_ticket_amount',
            ]);
        }

        $table->update();
    }

    /**
     * Migrate Up.
     */
    public function up()
    {
        parent::up();

        // 更新已有记录的 counter_ticket_amount 为 0（如果为 NULL）
        $this->execute("
            UPDATE `store_agent_shift_handover_record`
            SET `counter_ticket_amount` = 0.00
            WHERE `counter_ticket_amount` IS NULL
        ");
    }
}
