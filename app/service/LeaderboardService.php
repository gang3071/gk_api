<?php

namespace app\service;

use support\Db;

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
