<?php

namespace app\service;

use app\model\LeaderboardEntry;
use app\model\Notice;
use app\model\Player;
use app\model\PlayerDeliveryRecord;
use app\model\PlayerMoneyEditLog;
use support\Db;
use support\Log;

/**
 * 排行榜服務（玩家端，唯讀）
 *
 * 資料來源：leaderboard、leaderboard_entry（由 gk_admin 排程結算寫入）
 * 店名：admin_users.nickname（type=4）
 *
 * 口徑：
 * - 各店週排行 / 月排行：分渠道（依 request department_id 過濾）
 * - VIP 總榜：全站，不分渠道；依 VIP 等級、同等級比分數
 * - 金樽遊戲量週排行 / 月排行：全站，不分渠道
 */
class LeaderboardService
{
    /** 各榜取前 N 名 */
    const TOP = 10;

    /** VIP 總榜：取前 N 名 */
    const VIP_TOP = 10;

    /** VIP 排行榜開關（目前不做，保留程式） */
    const VIP_ENABLED = false;

    /** VIP 總榜代碼（由 gk_admin 結算寫入） */
    const VIP_CODE_WEEKLY = 'vip_weekly';
    const VIP_CODE_MONTHLY = 'vip_monthly';

    /**
     * 排行榜整頁資料
     *
     * @param int $departmentId 渠道/部門 id（來自 Site-Id）
     * @return array
     */
    public static function overview(int $departmentId): array
    {
        return [
            'store_weekly'   => self::storeWeekly($departmentId),
            'store_monthly'  => self::storeMonthly($departmentId),
            'golden_weekly'  => self::goldenWeekly(),
            'golden_monthly' => self::goldenMonthly(),
            'vip_ranking'    => self::vipRanking($departmentId),
        ];
    }

    /**
     * 各店週排行（分渠道）
     *
     * @param int $departmentId
     * @return array
     */
    public static function storeWeekly(int $departmentId): array
    {
        return self::store('store_weekly', $departmentId);
    }

    /**
     * 各店月排行（分渠道）
     *
     * @param int $departmentId
     * @return array
     */
    public static function storeMonthly(int $departmentId): array
    {
        return self::store('store_monthly', $departmentId);
    }

    /**
     * 各店榜共用查詢（分渠道，每店各自排名）
     *
     * @param string $code
     * @param int $departmentId
     * @return array
     */
    private static function store(string $code, int $departmentId): array
    {
        $periodKey = self::latestPeriodKey($code);
        if (!$periodKey) {
            return [];
        }

        $sql = "SELECT e.store_admin_id, au.nickname AS store_name,
                       e.`rank`, e.player_id, p.name AS player_name, e.score
                FROM leaderboard_entry e
                JOIN leaderboard l ON l.id = e.leaderboard_id
                JOIN player p ON p.id = e.player_id
                LEFT JOIN admin_users au ON au.id = e.store_admin_id
                WHERE l.code = ? AND l.period_key = ?
                  AND p.department_id = ? AND e.`rank` <= ?
                ORDER BY e.store_admin_id ASC, e.`rank` ASC";

        $rows = Db::select($sql, [$code, $periodKey, $departmentId, self::TOP]);

        $stores = [];
        foreach ($rows as $r) {
            $sid = (int)$r->store_admin_id;
            if (!isset($stores[$sid])) {
                $stores[$sid] = [
                    'store_id'   => $sid,
                    'store_name' => (string)($r->store_name ?? ''),
                    'list'       => [],
                ];
            }
            $stores[$sid]['list'][] = [
                'rank'      => (int)$r->rank,
                'player_id' => (int)$r->player_id,
                'name'      => (string)$r->player_name,
                'score'     => (string)$r->score,
            ];
        }

        return array_values($stores);
    }

    /**
     * 金樽遊戲量週排行（全站）
     *
     * @return array
     */
    public static function goldenWeekly(): array
    {
        return self::golden('golden_weekly');
    }

    /**
     * 金樽遊戲量月排行（全站）
     *
     * @return array
     */
    public static function goldenMonthly(): array
    {
        return self::golden('golden_monthly');
    }

    /**
     * 金樽榜共用查詢（全站，不分渠道）
     *
     * @param string $code
     * @return array
     */
    private static function golden(string $code): array
    {
        $periodKey = self::latestPeriodKey($code);
        if (!$periodKey) {
            return [];
        }

        $sql = "SELECT e.`rank`, e.player_id, p.name AS player_name,
                       e.store_admin_id, au.nickname AS store_name, e.score, e.prize_amount
                FROM leaderboard_entry e
                JOIN leaderboard l ON l.id = e.leaderboard_id
                JOIN player p ON p.id = e.player_id
                LEFT JOIN admin_users au ON au.id = e.store_admin_id
                WHERE l.code = ? AND l.period_key = ? AND e.`rank` <= ?
                ORDER BY e.`rank` ASC";

        $rows = Db::select($sql, [$code, $periodKey, self::TOP]);

        $list = [];
        foreach ($rows as $r) {
            $list[] = [
                'rank'       => (int)$r->rank,
                'player_id'  => (int)$r->player_id,
                'name'       => (string)$r->player_name,
                'store_id'   => (int)$r->store_admin_id,
                'store_name' => (string)($r->store_name ?? ''),
                'score'      => (string)$r->score,
                'prize'      => (string)$r->prize_amount,
            ];
        }

        return $list;
    }

    /**
     * VIP 總榜（週榜 + 月榜）
     *
     * 名次由 gk_admin 結算寫入 leaderboard_entry，API 只讀快照。
     * 排序：VIP 等級 DESC，同等級比分數 DESC。
     *
     * @param int $departmentId
     * @return array ['weekly' => [...], 'monthly' => [...]]
     */
    public static function vipRanking(int $departmentId): array
    {
        if (!self::VIP_ENABLED) {
            return [
                'weekly'  => [
                    'period_key' => null,
                    'start_at'   => null,
                    'end_at'     => null,
                    'list'       => [],
                ],
                'monthly' => [
                    'period_key' => null,
                    'start_at'   => null,
                    'end_at'     => null,
                    'list'       => [],
                ],
            ];
        }

        return [
            'weekly'  => self::vipBoard(self::VIP_CODE_WEEKLY),
            'monthly' => self::vipBoard(self::VIP_CODE_MONTHLY),
        ];
    }

    /**
     * VIP 單一週期榜單（讀取結算快照）
     *
     * @param string $code
     * @return array
     */
    private static function vipBoard(string $code): array
    {
        $periodKey = self::latestPeriodKey($code);
        if ($periodKey === null) {
            return [
                'period_key' => null,
                'start_at'   => null,
                'end_at'     => null,
                'list'       => [],
            ];
        }

        $board = Db::table('leaderboard')
            ->where('code', $code)
            ->where('period_key', $periodKey)
            ->first(['start_at', 'end_at']);

        $sql = "SELECT e.`rank`, e.player_id, p.name, p.vip_level_id,
                       vl.name AS vip_level_name, e.score,
                       e.store_admin_id, au.nickname AS store_name
                FROM leaderboard_entry e
                JOIN leaderboard l ON l.id = e.leaderboard_id
                JOIN player p ON p.id = e.player_id
                LEFT JOIN vip_level vl ON vl.id = p.vip_level_id
                LEFT JOIN admin_users au ON au.id = e.store_admin_id
                WHERE l.code = ? AND l.period_key = ? AND e.`rank` <= ?
                ORDER BY e.`rank` ASC";

        $rows = Db::select($sql, [$code, $periodKey, self::VIP_TOP]);

        $list = [];
        foreach ($rows as $r) {
            $score = (string)$r->score;
            $list[] = [
                'rank'           => (int)$r->rank,
                'player_id'      => (int)$r->player_id,
                'name'           => (string)$r->name,
                'vip_level'      => (int)$r->vip_level_id,
                'vip_level_name' => (string)($r->vip_level_name ?? ''),
                'store_id'       => (int)$r->store_admin_id,
                'store_name'     => (string)($r->store_name ?? ''),
                'score'          => $score,
                'score_text'     => self::scoreText($score),
            ];
        }

        return [
            'period_key' => $periodKey,
            'start_at'   => $board ? (string)$board->start_at : null,
            'end_at'     => $board ? (string)$board->end_at : null,
            'list'       => $list,
        ];
    }

    /**
     * 分數顯示文字（整數不帶小數，其餘保留兩位）
     *
     * @param string $score
     * @return string
     */
    private static function scoreText(string $score): string
    {
        $value = (float)$score;
        $decimals = (floor($value) == $value) ? 0 : 2;

        return number_format($value, $decimals);
    }

    /**
     * 排行榜獎勵規則（對應前台獎勵說明）
     *
     * @return array
     */
    public static function rules(): array
    {
        $rows = Db::table('system_setting')
            ->where('feature', 'like', 'leaderboard_%')
            ->where('department_id', 0)
            ->where('status', 1)
            ->get(['feature', 'content']);

        $result = [];
        foreach ($rows as $row) {
            $content = json_decode($row->content, true);
            if (!is_array($content)) {
                continue;
            }
            $code = (string)($content['code'] ?? $row->feature);
            $threshold = (float)($content['threshold'] ?? 0);

            $result[$code] = [
                'name'           => (string)($content['name'] ?? $code),
                'threshold'      => $threshold,
                'threshold_text' => self::thresholdText($threshold),
                'prizes'         => $content['prizes'] ?? [],
            ];
        }

        return $result;
    }

    /**
     * 我的排行榜獎勵（待領取＋已領取）
     *
     * @param Player $player
     * @return array
     */
    public static function myRewards(Player $player): array
    {
        $notices = Notice::query()
            ->where('player_id', $player->id)
            ->where('type', Notice::TYPE_LEADERBOARD_REWARD)
            ->where('receiver', Notice::RECEIVER_PLAYER)
            ->whereNull('deleted_at')
            ->orderBy('id', 'desc')
            ->limit(50)
            ->get();

        $list = [];
        foreach ($notices as $notice) {
            $content = json_decode((string)$notice->content, true) ?: [];
            $entryId = (int)($content['entry_id'] ?? $notice->source_id ?? 0);
            $amount = (float)($content['amount'] ?? 0);
            $entry = null;

            if ($entryId > 0) {
                $entry = LeaderboardEntry::query()
                    ->where('id', $entryId)
                    ->first(['prize_amount', 'grant_status']);

                if ($entry) {
                    $amount = (float)$entry->prize_amount;
                }
            }

            $status = (int)$notice->status;
            $claimed = $status === 1;

            if ($entry && (int)$entry->grant_status === LeaderboardEntry::GRANT_STATUS_ISSUED) {
                $claimed = true;

                // 舊版已自動入帳的通知，補標成已領取
                if ($status === 0) {
                    Notice::query()->where('id', $notice->id)->update([
                        'status'     => 1,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                    $status = 1;
                }
            }

            if (!$claimed) {
                $tradeNos = ['LBRN' . $notice->id];
                if ($entryId > 0) {
                    $tradeNos[] = 'LBR' . $entryId;
                }

                $claimed = PlayerDeliveryRecord::query()
                    ->where('tradeno', 'LBRN' . $notice->id)
                    ->where('type', PlayerDeliveryRecord::TYPE_LEADERBOARD_REWARD)
                    ->exists();

                if (!$claimed) {
                    $claimed = PlayerMoneyEditLog::query()
                        ->whereIn('tradeno', $tradeNos)
                        ->withTrashed()
                        ->exists();
                }
            }

            $list[] = [
                'notice_id'        => (int)$notice->id,
                'status'           => $status,
                'claimable'        => !$claimed,
                'amount'           => $amount,
                'rank'             => (int)($content['rank'] ?? 0),
                'leaderboard_name' => (string)($content['leaderboard_name'] ?? ''),
                'text'             => (string)($content['text'] ?? $notice->content),
                'created_at'       => (string)$notice->created_at,
            ];
        }

        return $list;
    }

    /**
     * 領取排行榜獎勵
     *
     * 由玩家點擊領取時呼叫；通知 status=0 代表尚未領取。
     *
     * @param Player $player
     * @param int $noticeId
     * @return array
     */
    public static function claimReward(Player $player, int $noticeId): array
    {
        $notice = Notice::query()
            ->where('id', $noticeId)
            ->where('player_id', $player->id)
            ->where('type', Notice::TYPE_LEADERBOARD_REWARD)
            ->where('receiver', Notice::RECEIVER_PLAYER)
            ->whereNull('deleted_at')
            ->first();

        if (!$notice) {
            throw new \RuntimeException('排行榜獎勵不存在');
        }

        $content = json_decode((string)$notice->content, true) ?: [];
        $entryId = (int)($content['entry_id'] ?? $notice->source_id ?? 0);
        $amount = (float)($content['amount'] ?? 0);

        $entry = null;
        if ($entryId > 0) {
            $entry = LeaderboardEntry::query()
                ->where('id', $entryId)
                ->where('player_id', $player->id)
                ->first();

            if ($entry) {
                $amount = (float)$entry->prize_amount;
            }
        }

        if ($amount <= 0) {
            throw new \RuntimeException('排行榜獎勵金額錯誤');
        }

        $tradeNo = 'LBRN' . $notice->id;
        $ts = date('Y-m-d H:i:s');

        $existingLog = PlayerDeliveryRecord::query()
            ->where('tradeno', $tradeNo)
            ->where('type', PlayerDeliveryRecord::TYPE_LEADERBOARD_REWARD)
            ->exists();

        $legacyTradeNos = [$tradeNo];
        if ($entryId > 0) {
            $legacyTradeNos[] = 'LBR' . $entryId;
        }

        $legacyLog = PlayerMoneyEditLog::query()
            ->whereIn('tradeno', $legacyTradeNos)
            ->withTrashed()
            ->exists();

        if ($existingLog || $legacyLog) {
            self::markRewardClaimed($notice->id, $entryId, $ts);
            return [
                'notice_id'       => (int)$notice->id,
                'amount'          => $amount,
                'balance'         => WalletService::getBalance($player->id),
                'already_claimed' => true,
            ];
        }

        $claimed = Notice::query()
            ->where('id', $notice->id)
            ->where('status', 0)
            ->update([
                'status'     => 1,
                'updated_at' => $ts,
            ]);

        if ($claimed === 0) {
            return [
                'notice_id'       => (int)$notice->id,
                'amount'          => $amount,
                'balance'         => WalletService::getBalance($player->id),
                'already_claimed' => true,
            ];
        }

        if ($entry) {
            $entryClaimed = LeaderboardEntry::query()
                ->where('id', $entry->id)
                ->whereIn('grant_status', [
                    LeaderboardEntry::GRANT_STATUS_UNISSUED,
                    LeaderboardEntry::GRANT_STATUS_CLAIMABLE,
                ])
                ->update([
                    'grant_status' => LeaderboardEntry::GRANT_STATUS_ISSUED,
                    'granted_at'   => $ts,
                    'updated_at'   => $ts,
                ]);

            if ($entryClaimed === 0) {
                Notice::query()->where('id', $notice->id)->update([
                    'status'     => 1,
                    'updated_at' => $ts,
                ]);

                return [
                    'notice_id'       => (int)$notice->id,
                    'amount'          => $amount,
                    'balance'         => WalletService::getBalance($player->id),
                    'already_claimed' => true,
                ];
            }
        }

        try {
            $before = WalletService::getBalance($player->id);
            $result = WalletService::atomicIncrement($player->id, $amount);
            $after = (float)($result['balance'] ?? bcadd((string)$before, (string)$amount, 2));
        } catch (\Throwable $e) {
            self::revertRewardClaim($notice->id, $entryId, $ts);
            throw $e;
        }

        try {
            $delivery = new PlayerDeliveryRecord();
            $delivery->player_id = $player->id;
            $delivery->department_id = $player->department_id;
            $delivery->type = PlayerDeliveryRecord::TYPE_LEADERBOARD_REWARD;
            $delivery->source = 'leaderboard_reward';
            $delivery->target = $entryId > 0 ? 'leaderboard_entry' : 'notice';
            $delivery->target_id = $entryId > 0 ? $entryId : $notice->id;
            $delivery->amount = $amount;
            $delivery->amount_before = $before;
            $delivery->amount_after = $after;
            $delivery->tradeno = $tradeNo;
            $delivery->user_id = (int)($content['admin_id'] ?? 0);
            $delivery->user_name = (string)($content['admin_name'] ?? '');
            $delivery->remark = (string)($content['text'] ?? sprintf(
                '%s 第%d名',
                $content['leaderboard_name'] ?? '',
                (int)($content['rank'] ?? 0)
            ));
            $delivery->created_at = $ts;
            $delivery->updated_at = $ts;
            $delivery->save();
        } catch (\Throwable $e) {
            $rolledBack = false;
            try {
                $rollbackResult = WalletService::atomicDecrement($player->id, $amount);
                $rolledBack = (bool)($rollbackResult['ok'] ?? false);
            } catch (\Throwable $rollbackError) {
                Log::error('[排行榜領取] 退款失敗，需人工核對', [
                    'notice_id' => $notice->id,
                    'player_id' => $player->id,
                    'amount'    => $amount,
                    'error'     => $rollbackError->getMessage(),
                ]);
            }

            if ($rolledBack) {
                self::revertRewardClaim($notice->id, $entryId, $ts);
            }

            throw $e;
        }

        return [
            'notice_id'       => (int)$notice->id,
            'amount'          => $amount,
            'balance'         => $after,
            'already_claimed' => false,
        ];
    }

    /**
     * 補標記領取狀態
     *
     * @param int $noticeId
     * @param int $entryId
     * @param string $ts
     * @return void
     */
    private static function markRewardClaimed(int $noticeId, int $entryId, string $ts): void
    {
        Notice::query()->where('id', $noticeId)->update([
            'status'     => 1,
            'updated_at' => $ts,
        ]);

        if ($entryId > 0) {
            LeaderboardEntry::query()->where('id', $entryId)->update([
                'grant_status' => LeaderboardEntry::GRANT_STATUS_ISSUED,
                'granted_at'   => $ts,
                'updated_at'   => $ts,
            ]);
        }
    }

    /**
     * 還原領取狀態（錢包或帳務失敗時）
     *
     * @param int $noticeId
     * @param int $entryId
     * @param string $ts
     * @return void
     */
    private static function revertRewardClaim(int $noticeId, int $entryId, string $ts): void
    {
        Notice::query()->where('id', $noticeId)->update([
            'status'     => 0,
            'updated_at' => $ts,
        ]);

        if ($entryId > 0) {
            LeaderboardEntry::query()->where('id', $entryId)->update([
                'grant_status' => LeaderboardEntry::GRANT_STATUS_CLAIMABLE,
                'granted_at'   => null,
                'updated_at'   => $ts,
            ]);
        }
    }

    // ========================================
    // 內部方法
    // ========================================

    /**
     * 取某榜最新期別（含進行中當期；定案與否都可顯示）
     *
     * @param string $code
     * @return string|null
     */
    private static function latestPeriodKey(string $code): ?string
    {
        $key = Db::table('leaderboard')
            ->where('code', $code)
            ->orderBy('period_key', 'desc')
            ->value('period_key');

        return $key !== null ? (string)$key : null;
    }

    /**
     * 門檻文字（例：1000000 => 需達100萬分以上）
     *
     * @param float $threshold
     * @return string|null
     */
    private static function thresholdText(float $threshold): ?string
    {
        if ($threshold <= 0) {
            return null;
        }

        $wan = $threshold / 10000;
        $num = (floor($wan) == $wan) ? number_format($wan) : (string)$wan;

        return "需達{$num}萬分以上";
    }
}
