<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 交班记录导出优化 - 新增字段
 *
 * 新增字段：
 * - open_score_amount: 开分金额 (source=artificial_recharge)
 * - ticket_open_score_amount: 开票金额 (source=ticket_open_score)
 * - channel_withdrawal_amount: 洗分金额 (source=channel_withdrawal)
 * - ticket_redeem_amount: 洗票金额 (source=ticket_redeem)
 * - ticket_unredeemed_amount: 洗票未核销金额
 * - experience_coupon_amount: 体验券金额
 * - welfare_coupon_amount: 福利券金额
 */
class AddShiftDetailNewFields extends AbstractMigration
{
    /**
     * Change Method.
     */
    public function change(): void
    {
        // 1. 修改 store_shift_device_detail 表（设备明细）
        $this->table('store_shift_device_detail')
            ->addColumn('open_score_amount', 'decimal', [
                'default' => 0,
                'precision' => 15,
                'scale' => 2,
                'comment' => '开分金额(source=artificial_recharge)',
                'after' => 'recharge_amount',
            ])
            ->addColumn('ticket_open_score_amount', 'decimal', [
                'default' => 0,
                'precision' => 15,
                'scale' => 2,
                'comment' => '开票金额(source=ticket_open_score)',
                'after' => 'open_score_amount',
            ])
            ->addColumn('channel_withdrawal_amount', 'decimal', [
                'default' => 0,
                'precision' => 15,
                'scale' => 2,
                'comment' => '洗分金额(source=channel_withdrawal)',
                'after' => 'withdrawal_amount',
            ])
            ->addColumn('ticket_redeem_amount', 'decimal', [
                'default' => 0,
                'precision' => 15,
                'scale' => 2,
                'comment' => '洗票金额(source=ticket_redeem)',
                'after' => 'channel_withdrawal_amount',
            ])
            ->addColumn('ticket_unredeemed_amount', 'decimal', [
                'default' => 0,
                'precision' => 15,
                'scale' => 2,
                'comment' => '洗票未核销金额',
                'after' => 'ticket_redeem_amount',
            ])
            ->addColumn('experience_coupon_amount', 'decimal', [
                'default' => 0,
                'precision' => 15,
                'scale' => 2,
                'comment' => '体验券金额',
                'after' => 'ticket_unredeemed_amount',
            ])
            ->addColumn('welfare_coupon_amount', 'decimal', [
                'default' => 0,
                'precision' => 15,
                'scale' => 2,
                'comment' => '福利券金额',
                'after' => 'experience_coupon_amount',
            ])
            ->save();

        // 2. 修改 store_agent_shift_handover_record 表（交班记录主表）
        // 主表没有 recharge_amount/withdrawal_amount，放在 total_out 之后
        $this->table('store_agent_shift_handover_record')
            ->addColumn('open_score_amount', 'decimal', [
                'default' => 0,
                'precision' => 15,
                'scale' => 2,
                'comment' => '开分金额汇总',
                'after' => 'total_out',
            ])
            ->addColumn('ticket_open_score_amount', 'decimal', [
                'default' => 0,
                'precision' => 15,
                'scale' => 2,
                'comment' => '开票金额汇总',
                'after' => 'open_score_amount',
            ])
            ->addColumn('channel_withdrawal_amount', 'decimal', [
                'default' => 0,
                'precision' => 15,
                'scale' => 2,
                'comment' => '洗分金额汇总',
                'after' => 'ticket_open_score_amount',
            ])
            ->addColumn('ticket_redeem_amount', 'decimal', [
                'default' => 0,
                'precision' => 15,
                'scale' => 2,
                'comment' => '洗票金额汇总',
                'after' => 'channel_withdrawal_amount',
            ])
            ->addColumn('ticket_unredeemed_amount', 'decimal', [
                'default' => 0,
                'precision' => 15,
                'scale' => 2,
                'comment' => '洗票未核销汇总',
                'after' => 'ticket_redeem_amount',
            ])
            ->addColumn('experience_coupon_amount', 'decimal', [
                'default' => 0,
                'precision' => 15,
                'scale' => 2,
                'comment' => '体验券汇总',
                'after' => 'ticket_unredeemed_amount',
            ])
            ->addColumn('welfare_coupon_amount', 'decimal', [
                'default' => 0,
                'precision' => 15,
                'scale' => 2,
                'comment' => '福利券汇总',
                'after' => 'experience_coupon_amount',
            ])
            ->save();
    }
}
