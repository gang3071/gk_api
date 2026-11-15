<?php

namespace app\support;

use support\Log;

class WalletLogger
{
    const OP_DEDUCT = 'deduct';
    const OP_ADD = 'add';
    const OP_ATOMIC_INCREMENT = 'atomic_increment';
    const OP_ATOMIC_DECREMENT = 'atomic_decrement';
    const OP_ATOMIC_WASH = 'atomic_wash';

    const REASON_MACHINE_OPEN = 'machine_open';
    const REASON_MACHINE_WASH = 'machine_wash';
    const REASON_MACHINE_REFUND = 'machine_refund';
    const REASON_LOTTERY = 'lottery';
    const REASON_GAME_WIN = 'game_win';
    const REASON_GAME_BET = 'game_bet';
    const REASON_GAME_TRANSFER = 'game_transfer';
    const REASON_GAME_REFUND = 'game_refund';
    const REASON_DEPOSIT_BONUS = 'deposit_bonus';
    const REASON_SETTLE = 'settle';
    const REASON_REFUND = 'refund';
    const REASON_ADMIN_ADJUST = 'admin_adjust';
    const REASON_UNKNOWN = 'unknown';

    private function __construct() {}

    public static function log(
        int    $playerId,
        string $operationType,
        string $reason,
        float  $amount,
        float  $oldBalance,
        float  $newBalance,
        bool   $success,
        array  $extra = []
    ): void {
        $context = [
            'player_id'      => $playerId,
            'operation_type' => $operationType,
            'reason'         => $reason ?: self::REASON_UNKNOWN,
            'amount'         => round($amount, 2),
            'old_balance'    => round($oldBalance, 2),
            'new_balance'    => round($newBalance, 2),
            'success'        => $success ? 1 : 0,
            'error_code'     => (string)($extra['error_code'] ?? ''),
            'machine_id'     => (int)($extra['machine_id'] ?? 0),
            'platform'       => (string)($extra['platform'] ?? ''),
        ];

        static $knownKeys = ['error_code', 'machine_id', 'platform'];
        $custom = array_diff_key($extra, array_flip($knownKeys));
        $context['extra_data'] = empty($custom) ? '' : (json_encode($custom, JSON_UNESCAPED_UNICODE) ?: '');

        if (!$success) {
            Log::channel('wallet_ops')->warning($operationType, $context);
        } else {
            Log::channel('wallet_ops')->info($operationType, $context);
        }
    }
}
