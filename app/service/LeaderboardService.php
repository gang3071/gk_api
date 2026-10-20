<?php

namespace app\service;

use app\model\GamePlatform;
use Carbon\Carbon;
use support\Cache;
use support\Db;

/**
 * 排行榜服務（玩家端，唯讀）
 *
 * 資料來源：leaderboard、leaderboard_entry（由 gk_admin 排程結算寫入）
 * 店名：admin_users.nickname（type=4）
 *
 * 口徑：
 * - 各店週排行 / VIP 排行：分渠道（依 request department_id 過濾）
 * - 金樽遊戲量週排行 / 月排行：全站，不分渠道
 */
class LeaderboardService
{
    /** 各榜取前 N 名 */
    const TOP = 10;

    /** VIP 排行：最低 VIP 等級（對應 vip_level.sort） */
    const VIP_MIN_LEVEL = 5;

    /** VIP 排行：每店取前 N */
    const VIP_TOP = 10;

    /** VIP 排行：快取秒數 */
    const VIP_CACHE_TTL = 30;

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
     * VIP 排行（分渠道，每店前 N，VIP5 以上）
     *
     * 週榜：本週一 08:00 ~ 下週一 08:00
     * 月榜：本月 1 號 08:00 ~ 下月 1 號 08:00
     * 分數：play_game_record 電子遊戲已結算下注額合計（與門市榜、金樽榜同口徑）
     *
     * @param int $departmentId
     * @return array ['weekly' => [...], 'monthly' => [...]]
     */
    public static function vipRanking(int $departmentId): array
    {
        return [
            'weekly'  => self::vipBoard('weekly', $departmentId),
            'monthly' => self::vipBoard('monthly', $departmentId),
        ];
    }

    /**
     * VIP 單一週期榜單（含期別資訊）
     *
     * @param string $type weekly / monthly
     * @param int $departmentId
     * @return array
     */
    private static function vipBoard(string $type, int $departmentId): array
    {
        [$start, $end, $periodKey] = self::vipPeriod($type);
        $cacheKey = "leaderboard_vip_{$type}_{$departmentId}_{$periodKey}";

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $board = [
            'period_key' => $periodKey,
            'start_at'   => $start->format('Y-m-d H:i:s'),
            'end_at'     => $end->format('Y-m-d H:i:s'),
            'list'       => self::vipQuery($departmentId, $start, $end),
        ];

        Cache::set($cacheKey, $board, self::VIP_CACHE_TTL);

        return $board;
    }

    /**
     * VIP 榜單期間（08:00 制）
     *
     * @param string $type weekly / monthly
     * @return array [Carbon $start, Carbon $end, string $periodKey]
     */
    private static function vipPeriod(string $type): array
    {
        $now = Carbon::now('Asia/Shanghai');

        if ($type === 'weekly') {
            $start = $now->copy()->startOfWeek(Carbon::MONDAY)->setTime(8, 0, 0);
            if ($now->lt($start)) {
                $start->subWeek();
            }
            $end = $start->copy()->addWeek();
            $periodKey = $start->format('Y-m-d');
        } else {
            $start = $now->copy()->startOfMonth()->setTime(8, 0, 0);
            if ($now->lt($start)) {
                $start->subMonthNoOverflow();
            }
            $end = $start->copy()->addMonthNoOverflow();
            $periodKey = $start->format('Y-m');
        }

        return [$start, $end, $periodKey];
    }

    /**
     * VIP 榜單查詢（依分數排名，每店前 N）
     *
     * @param int $departmentId
     * @param Carbon $start
     * @param Carbon $end
     * @return array
     */
    private static function vipQuery(int $departmentId, Carbon $start, Carbon $end): array
    {
        $platformIds = self::electronicPlatformIds();
        if (empty($platformIds)) {
            return [];
        }

        $in = implode(',', array_fill(0, count($platformIds), '?'));

        $sql = "SELECT t.store_admin_id, au.nickname AS store_name,
                       t.player_id, t.name, t.vip_level_id, t.vip_level_name, t.score, t.rn
                FROM (
                    SELECT p.store_admin_id, p.id AS player_id, p.name, p.vip_level_id,
                           vl.name AS vip_level_name, SUM(r.bet) AS score,
                           ROW_NUMBER() OVER (PARTITION BY p.store_admin_id
                                              ORDER BY SUM(r.bet) DESC, p.id ASC) AS rn
                    FROM play_game_record r
                    JOIN player p ON p.id = r.player_id
                    JOIN vip_level vl ON vl.id = p.vip_level_id
                    WHERE r.created_at >= ? AND r.created_at < ?
                      AND r.settlement_status = 1 AND r.type = 1 AND r.bet > 0
                      AND r.platform_id IN ({$in})
                      AND r.department_id = ?
                      AND p.department_id = ?
                      AND vl.sort >= ?
                      AND p.status = 1 AND p.deleted_at IS NULL AND p.store_admin_id > 0
                    GROUP BY p.id
                ) t
                LEFT JOIN admin_users au ON au.id = t.store_admin_id
                WHERE t.rn <= ?
                ORDER BY t.store_admin_id ASC, t.rn ASC";

        $bindings = array_merge(
            [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')],
            $platformIds,
            [$departmentId, $departmentId, self::VIP_MIN_LEVEL, self::VIP_TOP]
        );

        $rows = Db::select($sql, $bindings);

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
            $score = (string)$r->score;
            $stores[$sid]['list'][] = [
                'rank'           => (int)$r->rn,
                'player_id'      => (int)$r->player_id,
                'name'           => (string)$r->name,
                'vip_level'      => (int)$r->vip_level_id,
                'vip_level_name' => (string)($r->vip_level_name ?? ''),
                'score'          => $score,
                'score_text'     => self::scoreText($score),
            ];
        }

        return array_values($stores);
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
     * 電子遊戲平台 ID 清單（來源：platform_filter.included_platforms）
     *
     * @return array
     */
    private static function electronicPlatformIds(): array
    {
        $codes = config('platform_filter.included_platforms', []);
        if (empty($codes)) {
            return [];
        }

        return GamePlatform::query()
            ->whereIn('code', $codes)
            ->pluck('id')
            ->map(function ($v) {
                return (int)$v;
            })
            ->all();
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
