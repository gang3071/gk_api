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
     * 查询玩家福利券/体验券信息及当前是否可使用
     *
     * 返回玩家持有的有效券列表，以及使用前置条件的检查结果。
     * 前置条件全部满足时 can_use=true；否则 can_use=false 并附带 reason。
     *
     * @throws PlayerCheckException
     */
    public function voucherStatus(Request $request): Response
    {
        $player = checkPlayer();

        $walletBalance   = WalletService::getBalance($player->id);
        $machineScores   = WalletUnlockService::calculateAllMachineScores($player->id);
        $totalBalance    = (float) bcadd((string)$walletBalance, (string)$machineScores, 2);
        $openScoreLimit  = (float) config('welfare_ticket.open_score_limit', 100);
        $walletLocked    = WalletService::isWalletLocked($player->id);

        // 检查各项前置条件，按优先级排列
        $canUse = true;
        $reason = '';

        // 1. 活动是否在有效期
        $activityEndTime = (string) config('welfare_ticket.activity_end_time', '');
        if (!empty($activityEndTime) && time() > strtotime($activityEndTime)) {
            $canUse = false;
            $reason = trans('welfare_activity_expired', [], 'message');
        }

        // 2. 钱包余额是否满足使用条件（总余额 < open_score_limit）
        if ($canUse && $totalBalance >= $openScoreLimit) {
            $canUse = false;
            $reason = trans('ticket_wallet_balance_too_high', ['{limit}' => $openScoreLimit], 'message');
        }

        // 3. 钱包是否仍处于锁定状态（余额高于阈值且未解锁）
        if ($canUse && $walletLocked) {
            $canUse = false;
            $reason = trans('wallet_locked', [], 'message');
        }

        // 查询玩家持有的有效福利券/体验券（未使用且未过期）
        $tickets = TicketRecord::query()
            ->where('player_id', $player->id)
            ->whereIn('ticket_type', [TicketRecord::TYPE_WELFARE, TicketRecord::TYPE_EXPERIENCE])
            ->where('status', TicketRecord::STATUS_NORMAL)
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'desc')
            ->get();

        // 过滤掉已过期的券，并组装返回数据
        $availableVouchers = [];
        $hasValidVoucher   = false;
        $expireHours       = (int) config('welfare_ticket.expire_hours', 24);

        foreach ($tickets as $ticket) {
            $expired = $ticket->isExpired();
            if (!$expired) {
                $hasValidVoucher = true;
            }
            $availableVouchers[] = [
                'id'               => $ticket->id,
                'order_id'         => $ticket->order_id,
                'score'            => (float) $ticket->score,
                'ticket_type'      => $ticket->ticket_type,
                'ticket_type_name' => $ticket->ticket_type_name,
                'is_expired'       => $expired,
                'created_at'       => $ticket->created_at?->toDateTimeString(),
                'expires_at'       => $ticket->created_at?->addHours($expireHours)->toDateTimeString(),
            ];
        }

        // 4. 是否持有有效券
        if ($canUse && !$hasValidVoucher) {
            $canUse = false;
            $reason = trans('voucher_no_valid_ticket', [], 'message');
        }

        return jsonSuccessResponse('success', [
            'can_use'         => $canUse,
            'reason'          => $reason,
            'wallet_locked'   => $walletLocked,
            'wallet_balance'  => $walletBalance,
            'machine_scores'  => $machineScores,
            'total_balance'   => $totalBalance,
            'open_score_limit'=> $openScoreLimit,
            'vouchers'        => $availableVouchers,
        ]);
    }
}
