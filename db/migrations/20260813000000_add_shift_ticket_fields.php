<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 交班记录新增入票、核销字段
 *
 * 新增字段：
 * - incoming_ticket_amount: 入票金额（原开票 + TicketRecord后台核销）
 * - redeem_amount: 核销金额（后台核销数据）
 */
class AddShiftTicketFields extends AbstractMigration
{
    /**
     * Change Method.
     */
    public function change(): void
    {
        // 1. 修改 store_shift_device_detail 表（设备明细）
        $this->table('store_shift_device_detail')
            ->addColumn('incoming_ticket_amount', 'decimal', [
                'default' => 0,
                'precision' => 15,
                'scale' => 2,
                'comment' => '入票金额(原开票+后台核销)',
                'after' => 'ticket_open_score_amount',
            ])
            ->addColumn('redeem_amount', 'decimal', [
                'default' => 0,
                'precision' => 15,
                'scale' => 2,
                'comment' => '核销金额(后台核销)',
                'after' => 'incoming_ticket_amount',
            ])
            ->save();

        // 2. 修改 store_agent_shift_handover_record 表（交班记录主表）
        $this->table('store_agent_shift_handover_record')
            ->addColumn('incoming_ticket_amount', 'decimal', [
                'default' => 0,
                'precision' => 15,
                'scale' => 2,
                'comment' => '入票金额汇总(原开票+后台核销)',
                'after' => 'ticket_open_score_amount',
            ])
            ->addColumn('redeem_amount', 'decimal', [
                'default' => 0,
                'precision' => 15,
                'scale' => 2,
                'comment' => '核销金额汇总(后台核销)',
                'after' => 'incoming_ticket_amount',
            ])
            ->save();
    }
}
