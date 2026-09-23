<?php

namespace app\service;

use app\model\Player;
use app\model\PlayerBetStatistics;
use app\model\PlayGameRecord;
use app\model\TicketRecord;
use Carbon\Carbon;

class VoucherService
{
    /**
     * 获取玩家今日可领取的体验券/福利券数量
     *
     * @param Player $player
     * @return array ['experience' => int, 'welfare' => int]
     */
    public static function getClaimableCounts(Player $player): array
    {
        $voucherConfig = config('voucher');

        // 检查活动有效期
        $activityEndTime = $voucherConfig['activity']['end_time'] ?? '';
        $forceEnable = $voucherConfig['activity']['force_enable'] ?? false;
        if (!$forceEnable && $activityEndTime && date('Y-m-d H:i:s') > $activityEndTime) {
            return ['experience' => 0, 'welfare' => 0];
        }

        // 时间区间：以每天08:00:00作为分界点
        $now = Carbon::now();
        $today8am = Carbon::today()->setTime(8, 0, 0);
        $yesterday8am = Carbon::yesterday()->setTime(8, 0, 0);
        $tomorrow8am = Carbon::tomorrow()->setTime(8, 0, 0);

        $isAfter8am = $now->gte($today8am);

        if ($isAfter8am) {
            $todayStart = $today8am->toDateTimeString();
            $todayEnd = $tomorrow8am->toDateTimeString();
            $yesterdayStart = $yesterday8am->toDateTimeString();
            $yesterdayEnd = $today8am->toDateTimeString();
        } else {
            $todayStart = $yesterday8am->toDateTimeString();
            $todayEnd = $today8am->toDateTimeString();
            $yesterdayStart = Carbon::parse('-2 days')->setTime(8, 0, 0)->toDateTimeString();
            $yesterdayEnd = $yesterday8am->toDateTimeString();
        }

        $todayStatDate = $isAfter8am ? date('Y-m-d') : date('Y-m-d', strtotime('-1 day'));
        $yesterdayStatDate = $isAfter8am ? date('Y-m-d', strtotime('-1 day')) : date('Y-m-d', strtotime('-2 days'));

        $todayBetAmount = self::getPlayerBetAmount($player->id, $todayStart, $todayEnd, $todayStatDate);
        $yesterdayBetAmount = self::getPlayerBetAmount($player->id, $yesterdayStart, $yesterdayEnd, $yesterdayStatDate);

        $claimableExperience = 0;
        $claimableWelfare = 0;

        // ---- 体验券可领数量 ----
        $expConfig = $voucherConfig['experience'] ?? [];
        if (!empty($expConfig['enabled'])) {
            $dailyLimit = $expConfig['daily_limit'] ?? 1;
            $totalLimit = $expConfig['total_limit'] ?? 6;
            $registerAfter = $expConfig['register_after'] ?? '2026-01-01 00:00:00';

            $claimedToday = TicketRecord::query()
                ->where('player_id', $player->id)
                ->where('ticket_type', TicketRecord::TYPE_EXPERIENCE)
                ->where('created_at', '>=', $todayStart)
                ->where('created_at', '<', $todayEnd)
                ->whereNull('deleted_at')
                ->count();

            $claimedTotal = TicketRecord::query()
                ->where('player_id', $player->id)
                ->where('ticket_type', TicketRecord::TYPE_EXPERIENCE)
                ->whereNull('deleted_at')
                ->count();

            $isNewUser = $player->created_at >= $registerAfter;
            $isDailyLimitReached = $claimedToday >= $dailyLimit;
            $isTotalLimitReached = $claimedTotal >= $totalLimit;

            $storeAdmin = \app\model\AdminUser::query()->find($player->store_admin_id);
            $experienceBetCheckEnabled = $storeAdmin?->experience_bet_check_enabled ?? false;

            $betCheckPassed = true;
            if ($experienceBetCheckEnabled && $claimedTotal > 0) {
                $betCheckPassed = $yesterdayBetAmount >= 10000;
            }

            if ($isNewUser && !$isDailyLimitReached && !$isTotalLimitReached && $betCheckPassed) {
                $claimableExperience = $dailyLimit - $claimedToday;
            }
        }

        // ---- 福利券可领数量 ----
        $welfareConfig = $voucherConfig['welfare'] ?? [];
        $todayWelfareConfig = $voucherConfig['today_welfare'] ?? [];

        $claimedWelfareRecords = [];
        if (!empty($welfareConfig['enabled']) || !empty($todayWelfareConfig['enabled'])) {
            $claimedWelfareRecords = TicketRecord::query()
                ->where('player_id', $player->id)
                ->where('ticket_type', TicketRecord::TYPE_WELFARE)
                ->where('created_at', '>=', $todayStart)
                ->where('created_at', '<', $todayEnd)
                ->whereNull('deleted_at')
                ->select('score', 'extra_data')
                ->get()
                ->toArray();
        }

        // 今日福利券规则
        if (!empty($todayWelfareConfig['enabled']) && !empty($todayWelfareConfig['rules'])) {
            foreach ($todayWelfareConfig['rules'] as $rule) {
                if ($todayBetAmount < $rule['bet_amount']) {
                    continue;
                }
                $isClaimed = false;
                foreach ($claimedWelfareRecords as $record) {
                    $extraData = $record['extra_data'] ?? null;
                    if (is_string($extraData)) {
                        $extraData = json_decode($extraData, true);
                    }
                    if (bccomp((string)($record['score'] ?? 0), (string)$rule['score'], 2) === 0
                        && is_array($extraData)
                        && ($extraData['rule_type'] ?? '') === 'today') {
                        $isClaimed = true;
                        break;
                    }
                }
                if (!$isClaimed) {
                    $claimableWelfare++;
                }
            }
        }

        // 昨日福利券规则（仅最高档位可领）
        if (!empty($welfareConfig['enabled']) && !empty($welfareConfig['rules'])) {
            $maxQualifiedScore = 0;
            foreach ($welfareConfig['rules'] as $rule) {
                if ($yesterdayBetAmount >= $rule['bet_amount']) {
                    $maxQualifiedScore = max($maxQualifiedScore, $rule['score']);
                }
            }

            foreach ($welfareConfig['rules'] as $rule) {
                if ($yesterdayBetAmount < $rule['bet_amount']) {
                    continue;
                }
                $isMaxTier = $rule['score'] === $maxQualifiedScore && $maxQualifiedScore > 0;
                if (!$isMaxTier) {
                    continue;
                }

                $isClaimed = false;
                foreach ($claimedWelfareRecords as $record) {
                    $extraData = $record['extra_data'] ?? null;
                    if (is_string($extraData)) {
                        $extraData = json_decode($extraData, true);
                    }
                    if (bccomp((string)($record['score'] ?? 0), (string)$rule['score'], 2) === 0
                        && is_array($extraData)
                        && ($extraData['rule_type'] ?? '') === 'yesterday') {
                        $isClaimed = true;
                        break;
                    }
                }
                if (!$isClaimed) {
                    $claimableWelfare++;
                }
            }
        }

        return [
            'experience' => $claimableExperience,
            'welfare' => $claimableWelfare,
        ];
    }

    /**
     * 查询玩家打码量（优先统计表，降级实时查询）
     */
    private static function getPlayerBetAmount(int $playerId, string $startDate, string $endDate, string $statDate): float
    {
        $statData = PlayerBetStatistics::where('player_id', $playerId)
            ->where('stat_type', 'game')
            ->where('dimension', 'daily')
            ->where('stat_date', $statDate)
            ->first();

        if ($statData) {
            $gameBetAmount = floatval($statData->bet_amount);
        } else {
            $gameBetAmount = (float) PlayGameRecord::query()
                ->where('player_id', $playerId)
                ->where('created_at', '>=', $startDate)
                ->where('created_at', '<', $endDate)
                ->sum('bet');
        }

        // 实体机台打码量（从 player_game_log 表的 chip_amount 字段汇总）
        $machineBetAmount = (float) \app\model\PlayerGameLog::query()
            ->where('player_id', $playerId)
            ->where('created_at', '>=', $startDate)
            ->where('created_at', '<', $endDate)
            ->sum('chip_amount');

        return (float) bcadd((string)$gameBetAmount, (string)$machineBetAmount, 2);
    }
}
