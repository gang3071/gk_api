<?php

declare(strict_types=1);

namespace app\api\controller\web;

use app\exception\PlayerCheckException;
use app\model\TicketRecord;
use app\service\WalletService;
use app\service\WalletUnlockService;
use support\Request;
use support\Response;

class TicketController
{
    /**
     * 查询指定券是否可使用
     *
     * 根据 order_id 查询券信息，并检查当前玩家的使用前置条件。
     * 前置条件全部满足时 can_use=true；否则 can_use=false 并附带 reason。
     *
     * @throws PlayerCheckException
     */
    public function voucherStatus(Request $request): Response
    {
        $player  = checkPlayer();
        $orderId = $request->get('order_id', '');

        if (empty($orderId)) {
            return jsonFailResponse(trans('ticket_order_id_empty', [], 'message'));
        }

        /** @var TicketRecord|null $ticket */
        $ticket = TicketRecord::query()
            ->where('order_id', $orderId)
            ->whereIn('ticket_type', [TicketRecord::TYPE_WELFARE, TicketRecord::TYPE_EXPERIENCE])
            ->whereNull('deleted_at')
            ->first();

        if (!$ticket) {
            return jsonFailResponse(trans('ticket_not_found', [], 'message'));
        }

        // 已使用/已失效的券直接返回不可用
        if ((int)$ticket->status !== TicketRecord::STATUS_NORMAL) {
            return jsonSuccessResponse('success', [
                'can_use' => false,
                'reason'  => trans('ticket_already_used', [], 'message'),
                'voucher' => self::formatTicket($ticket),
            ]);
        }

        // 检查各项前置条件，按优先级排列
        $canUse = true;
        $reason = '';

        // 1. 活动是否在有效期
        $activityEndTime = (string) config('welfare_ticket.activity_end_time', '');
        if (!empty($activityEndTime) && time() > strtotime($activityEndTime)) {
            $canUse = false;
            $reason = trans('welfare_activity_expired', [], 'message');
        }

        // 2. 券是否已过期
        if ($canUse && $ticket->isExpired()) {
            $canUse = false;
            $reason = trans('ticket_expired', [], 'message');
        }

        // 3. 余额是否满足使用条件（钱包 + 机台分数 < open_score_limit）
        if ($canUse) {
            $walletBalance  = WalletService::getBalance($player->id);
            $machineScores  = WalletUnlockService::calculateAllMachineScores($player->id);
            $totalBalance   = (float) bcadd((string)$walletBalance, (string)$machineScores, 2);
            $openScoreLimit = (float) config('welfare_ticket.open_score_limit', 100);

            if ($totalBalance >= $openScoreLimit) {
                $canUse = false;
                $reason = trans('ticket_wallet_balance_too_high', ['{limit}' => $openScoreLimit], 'message');
            }
        }

        // 4. 钱包是否仍处于锁定状态
        if ($canUse && WalletService::isWalletLocked($player->id)) {
            $canUse = false;
            $reason = trans('wallet_locked', [], 'message');
        }

        return jsonSuccessResponse('success', [
            'can_use' => $canUse,
            'reason'  => $reason,
            'voucher' => self::formatTicket($ticket),
        ]);
    }

    private static function formatTicket(TicketRecord $ticket): array
    {
        $expireHours = (int) config('welfare_ticket.expire_hours', 24);

        return [
            'id'               => $ticket->id,
            'order_id'         => $ticket->order_id,
            'score'            => (float) $ticket->score,
            'ticket_type'      => $ticket->ticket_type,
            'ticket_type_name' => $ticket->ticket_type_name,
            'status'           => $ticket->status,
            'status_name'      => $ticket->status_name,
            'is_expired'       => $ticket->isExpired(),
            'created_at'       => $ticket->created_at?->toDateTimeString(),
            'expires_at'       => $ticket->created_at?->addHours($expireHours)->toDateTimeString(),
        ];
    }
}
