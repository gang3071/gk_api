<?php

namespace app\service;

use app\model\Leaderboard;
use app\model\LeaderboardEntry;
use app\model\Notice;
use app\model\SystemSetting;
use app\model\Player;
use app\model\PlayerDeliveryRecord;
use app\model\PlayerMoneyEditLog;
use support\Log;
use support\Redis;

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

    /** 排行榜快取時間（秒）：屏蔽結算空窗期 */
    const CACHE_TTL = 120;

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
        $cacheKey = 'leaderboard:overview:' . $departmentId;

        // 结算空窗期保护：有缓存先用缓存
        $cached = Redis::get($cacheKey);
        if ($cached) {
            return json_decode($cached, true);
        }

        $pk = self::batchPeriodKeys([
            'store_weekly', 'golden_weekly', 'golden_monthly',
            'steel_ball_store_weekly', 'steel_ball_global_weekly', 'steel_ball_global_monthly',
        ]);

        $data = [
            'store_weekly'              => self::store('store_weekly', $departmentId, $pk['store_weekly'] ?? null),
            'store_monthly'             => [],
            'golden_weekly'             => self::golden('golden_weekly', $pk['golden_weekly'] ?? null),
            'golden_monthly'            => self::golden('golden_monthly', $pk['golden_monthly'] ?? null),
            'steel_ball_store_weekly'   => self::store('steel_ball_store_weekly', $departmentId, $pk['steel_ball_store_weekly'] ?? null),
            'steel_ball_global_weekly'  => self::golden('steel_ball_global_weekly', $pk['steel_ball_global_weekly'] ?? null),
            'steel_ball_global_monthly' => self::golden('steel_ball_global_monthly', $pk['steel_ball_global_monthly'] ?? null),
            'vip_ranking'               => self::vipRanking($departmentId),
        ];

        // 有实际数据才写缓存，空结果（结算空窗期）不缓存，以免覆盖旧缓存
        $hasData = !empty($data['store_weekly'])
            || !empty($data['golden_weekly'])
            || !empty($data['golden_monthly'])
            || !empty($data['steel_ball_store_weekly'])
            || !empty($data['steel_ball_global_weekly'])
            || !empty($data['steel_ball_global_monthly']);

        if ($hasData) {
            Redis::setex($cacheKey, self::CACHE_TTL, json_encode($data));
        }

        return $data;
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
    private static function store(string $code, int $departmentId, ?string $periodKey = null): array
    {
        $periodKey ??= self::latestPeriodKey($code);
        if (!$periodKey) {
            Log::warning('[Leaderboard] store: no period_key', ['code' => $code, 'department_id' => $departmentId]);
            return [];
        }

        $rows = LeaderboardEntry::query()
            ->join('leaderboard as l', 'l.id', '=', 'leaderboard_entry.leaderboard_id')
            ->leftJoin('player as p', 'p.id', '=', 'leaderboard_entry.player_id')
            ->leftJoin('admin_users as au', 'au.id', '=', 'leaderboard_entry.store_admin_id')
            ->where('l.code', $code)
            ->where('l.period_key', $periodKey)
            ->where('au.department_id', $departmentId)
            ->where('leaderboard_entry.rank', '<=', self::TOP)
            ->orderBy('leaderboard_entry.store_admin_id')
            ->orderBy('leaderboard_entry.rank')
            ->select([
                'leaderboard_entry.store_admin_id',
                'au.nickname as store_name',
                'leaderboard_entry.rank',
                'leaderboard_entry.player_id',
                'p.name as player_name',
                'leaderboard_entry.score',
            ])
            ->get();

        Log::info('[Leaderboard] store', [
            'code'          => $code,
            'period_key'    => $periodKey,
            'department_id' => $departmentId,
            'row_count'     => $rows->count(),
        ]);

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
                'rank'       => (int)$r->rank,
                'player_id'  => (int)$r->player_id,
                'name'       => (string)$r->player_name,
                'store_id'   => $sid,
                'store_name' => (string)($r->store_name ?? ''),
                'score'      => (string)$r->score,
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
     * 鋼珠各店週排行（分渠道）
     *
     * @param int $departmentId
     * @return array
     */
    public static function steelBallStoreWeekly(int $departmentId): array
    {
        return self::store('steel_ball_store_weekly', $departmentId);
    }

    /**
     * 鋼珠金樽週排行（全站）
     *
     * @return array
     */
    public static function steelBallGlobalWeekly(): array
    {
        return self::golden('steel_ball_global_weekly');
    }

    /**
     * 鋼珠金樽月排行（全站）
     *
     * @return array
     */
    public static function steelBallGlobalMonthly(): array
    {
        return self::golden('steel_ball_global_monthly');
    }

    /**
     * 鋼珠排行榜整頁資料（鋼珠各店週排行 + 鋼珠金樽週排行 + 鋼珠金樽月排行）
     *
     * 口徑同 overview()；計分來源：machine_lottery_record.lottery_win（機台大賞，鋼珠機台 type=2）
     *
     * @param int $departmentId 渠道/部門 id（來自 Site-Id）
     * @return array
     */
    public static function steelBallOverview(int $departmentId): array
    {
        $cacheKey = 'leaderboard:steel_ball_overview:' . $departmentId;

        $cached = Redis::get($cacheKey);
        if ($cached) {
            return json_decode($cached, true);
        }

        $pk = self::batchPeriodKeys([
            'steel_ball_store_weekly', 'steel_ball_global_weekly', 'steel_ball_global_monthly',
        ]);

        $data = [
            'store_weekly'   => self::store('steel_ball_store_weekly', $departmentId, $pk['steel_ball_store_weekly'] ?? null),
            'golden_weekly'  => self::golden('steel_ball_global_weekly', $pk['steel_ball_global_weekly'] ?? null),
            'golden_monthly' => self::golden('steel_ball_global_monthly', $pk['steel_ball_global_monthly'] ?? null),
        ];

        $hasData = !empty($data['store_weekly'])
            || !empty($data['golden_weekly'])
            || !empty($data['golden_monthly']);

        if ($hasData) {
            Redis::setex($cacheKey, self::CACHE_TTL, json_encode($data));
        }

        return $data;
    }

    /**
     * 金樽榜共用查詢（全站，不分渠道）
     *
     * @param string $code
     * @return array
     */
    private static function golden(string $code, ?string $periodKey = null): array
    {
        $periodKey ??= self::latestPeriodKey($code);
        if (!$periodKey) {
            Log::warning('[Leaderboard] golden: no period_key', ['code' => $code]);
            return [];
        }

        $rows = LeaderboardEntry::query()
            ->join('leaderboard as l', 'l.id', '=', 'leaderboard_entry.leaderboard_id')
            ->leftJoin('player as p', 'p.id', '=', 'leaderboard_entry.player_id')
            ->leftJoin('admin_users as au', 'au.id', '=', 'leaderboard_entry.store_admin_id')
            ->where('l.code', $code)
            ->where('l.period_key', $periodKey)
            ->where('leaderboard_entry.rank', '<=', self::TOP)
            ->orderBy('leaderboard_entry.rank')
            ->select([
                'leaderboard_entry.rank',
                'leaderboard_entry.player_id',
                'p.name as player_name',
                'leaderboard_entry.store_admin_id',
                'au.nickname as store_name',
                'leaderboard_entry.score',
                'leaderboard_entry.prize_amount',
            ])
            ->get();

        Log::info('[Leaderboard] golden', [
            'code'       => $code,
            'period_key' => $periodKey,
            'row_count'  => $rows->count(),
        ]);

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
        $board = Leaderboard::query()
            ->where('code', $code)
            ->orderBy('period_key', 'desc')
            ->first(['period_key', 'start_at', 'end_at']);

        if (!$board) {
            return [
                'period_key' => null,
                'start_at'   => null,
                'end_at'     => null,
                'list'       => [],
            ];
        }

        $periodKey = (string)$board->period_key;

        $rows = LeaderboardEntry::query()
            ->join('leaderboard as l', 'l.id', '=', 'leaderboard_entry.leaderboard_id')
            ->leftJoin('player as p', 'p.id', '=', 'leaderboard_entry.player_id')
            ->leftJoin('vip_level as vl', 'vl.id', '=', 'p.vip_level_id')
            ->leftJoin('admin_users as au', 'au.id', '=', 'leaderboard_entry.store_admin_id')
            ->where('l.code', $code)
            ->where('l.period_key', $periodKey)
            ->where('leaderboard_entry.rank', '<=', self::VIP_TOP)
            ->orderBy('leaderboard_entry.rank')
            ->select([
                'leaderboard_entry.rank',
                'leaderboard_entry.player_id',
                'p.name',
                'p.vip_level_id',
                'vl.name as vip_level_name',
                'leaderboard_entry.score',
                'leaderboard_entry.store_admin_id',
                'au.nickname as store_name',
            ])
            ->get();

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
            'start_at'   => (string)$board->start_at,
            'end_at'     => (string)$board->end_at,
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
        $decimals = (fmod($value, 1.0) < PHP_FLOAT_EPSILON) ? 0 : 2;

        return number_format($value, $decimals);
    }

    /**
     * 排行榜獎勵規則（對應前台獎勵說明）
     *
     * @return array
     */
    public static function rules(): array
    {
        $rows = SystemSetting::query()
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
                'name'              => (string)($content['name'] ?? $code),
                'threshold'         => $threshold,
                'threshold_text'    => self::thresholdText($threshold),
                'prizes'            => $content['prizes'] ?? [],
                'activity_rules'    => (string)($content['activity_rules'] ?? ''),
                'distribution_rules'=> (string)($content['distribution_rules'] ?? ''),
                'disclaimer'        => (string)($content['disclaimer'] ?? ''),
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

        // 預先收集所有 entry_id，批量查詢 LeaderboardEntry
        $entryIdMap = [];
        foreach ($notices as $notice) {
            $content = json_decode((string)$notice->content, true) ?: [];
            $entryId = (int)($content['entry_id'] ?? $notice->source_id ?? 0);
            if ($entryId > 0) {
                $entryIdMap[$notice->id] = $entryId;
            }
        }

        $entryIds = array_values(array_unique($entryIdMap));
        $entriesById = $entryIds
            ? LeaderboardEntry::query()
                ->whereIn('id', $entryIds)
                ->get(['id', 'prize_amount', 'grant_status'])
                ->keyBy('id')
            : collect();

        // 批量查 PlayerDeliveryRecord（以 LBRN{notice_id} 為 tradeno）
        $noticeIds = $notices->pluck('id')->all();
        $deliveryTradeNos = array_map(fn($id) => 'LBRN' . $id, $noticeIds);
        $claimedByDelivery = PlayerDeliveryRecord::query()
            ->whereIn('tradeno', $deliveryTradeNos)
            ->where('type', PlayerDeliveryRecord::TYPE_LEADERBOARD_REWARD)
            ->pluck('tradeno')
            ->flip()
            ->all();

        // 批量查 PlayerMoneyEditLog（LBRN{notice_id} + LBR{entry_id}）
        $legacyTradeNos = $deliveryTradeNos;
        foreach ($entryIdMap as $eid) {
            $legacyTradeNos[] = 'LBR' . $eid;
        }
        $claimedByLog = PlayerMoneyEditLog::query()
            ->whereIn('tradeno', array_unique($legacyTradeNos))
            ->withTrashed()
            ->pluck('tradeno')
            ->flip()
            ->all();

        // 需要補標的 notice_id（舊版已自動入帳）
        $noticeIdsToMark = [];

        $list = [];
        foreach ($notices as $notice) {
            $content  = json_decode((string)$notice->content, true) ?: [];
            $entryId  = $entryIdMap[$notice->id] ?? 0;
            $entry    = $entryId > 0 ? ($entriesById[$entryId] ?? null) : null;
            $amount   = $entry ? (float)$entry->prize_amount : (float)($content['amount'] ?? 0);
            $status   = (int)$notice->status;
            $claimed  = $status === 1;

            if ($entry && (int)$entry->grant_status === LeaderboardEntry::GRANT_STATUS_ISSUED) {
                $claimed = true;
                // 舊版已自動入帳的通知，累積後批量補標
                if ($status === 0) {
                    $noticeIdsToMark[] = (int)$notice->id;
                    $status = 1;
                }
            }

            if (!$claimed) {
                $deliveryKey = 'LBRN' . $notice->id;
                $claimed = isset($claimedByDelivery[$deliveryKey])
                    || isset($claimedByLog[$deliveryKey])
                    || ($entryId > 0 && isset($claimedByLog['LBR' . $entryId]));
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

        // 批量補標舊版已自動入帳通知
        if ($noticeIdsToMark) {
            try {
                Notice::query()
                    ->whereIn('id', $noticeIdsToMark)
                    ->where('status', 0)
                    ->update(['status' => 1, 'updated_at' => date('Y-m-d H:i:s')]);
            } catch (\Throwable $e) {
                Log::warning('[Leaderboard] myRewards 補標失敗', [
                    'notice_ids' => $noticeIdsToMark,
                    'error'      => $e->getMessage(),
                ]);
            }
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
        $originalGrantStatus = LeaderboardEntry::GRANT_STATUS_CLAIMABLE;
        if ($entryId > 0) {
            $entry = LeaderboardEntry::query()
                ->where('id', $entryId)
                ->where('player_id', $player->id)
                ->first();

            if ($entry) {
                $amount = (float)$entry->prize_amount;
                $originalGrantStatus = (int)$entry->grant_status;
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
            self::revertRewardClaim($notice->id, $entryId, $originalGrantStatus, $ts);
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
                self::revertRewardClaim($notice->id, $entryId, $originalGrantStatus, $ts);
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
     * @param int $originalGrantStatus 領取前的原始 grant_status，用於正確還原
     * @param string $ts
     * @return void
     */
    private static function revertRewardClaim(int $noticeId, int $entryId, int $originalGrantStatus, string $ts): void
    {
        Notice::query()->where('id', $noticeId)->update([
            'status'     => 0,
            'updated_at' => $ts,
        ]);

        if ($entryId > 0) {
            LeaderboardEntry::query()->where('id', $entryId)->update([
                'grant_status' => $originalGrantStatus,
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
        $key = Leaderboard::query()
            ->where('code', $code)
            ->orderBy('period_key', 'desc')
            ->value('period_key');

        return $key !== null ? (string)$key : null;
    }

    /**
     * 批量取多個榜單的最新期別（一次查詢）
     *
     * @param string[] $codes
     * @return array<string, string>  code => period_key
     */
    private static function batchPeriodKeys(array $codes): array
    {
        return Leaderboard::query()
            ->whereIn('code', $codes)
            ->groupBy('code')
            ->selectRaw('code, MAX(period_key) AS period_key')
            ->pluck('period_key', 'code')
            ->all();
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
