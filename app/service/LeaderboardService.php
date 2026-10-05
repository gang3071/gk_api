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
 * - 各店週排行 / VIP 排行：分渠道（依 request department_id 過濾）
 * - 金樽遊戲量週排行 / 月排行：全站，不分渠道
 */
class LeaderboardService
{
    /** 各榜取前 N 名 */
    const TOP = 8;

    /** VIP 排行：最低 VIP 等級 */
    const VIP_MIN_LEVEL = 5;

    /** VIP 排行：每店取前 N */
    const VIP_TOP = 5;

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
        $periodKey = self::latestPeriodKey('store_weekly');
        if (!$periodKey) {
            return [];
        }

        $sql = "SELECT e.store_admin_id, au.nickname AS store_name,
                       e.`rank`, e.player_id, p.name AS player_name, e.score
                FROM leaderboard_entry e
                JOIN leaderboard l ON l.id = e.leaderboard_id
                JOIN player p ON p.id = e.player_id
                LEFT JOIN admin_users au ON au.id = e.store_admin_id
                WHERE l.code = 'store_weekly' AND l.period_key = ?
                  AND p.department_id = ? AND e.`rank` <= ?
                ORDER BY e.store_admin_id ASC, e.`rank` ASC";

        $rows = Db::select($sql, [$periodKey, $departmentId, self::TOP]);

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
     * @param int $departmentId
     * @return array
     */
    public static function vipRanking(int $departmentId): array
    {
        $sql = "SELECT t.store_admin_id, au.nickname AS store_name,
                       t.player_id, t.name, t.vip_level_id, t.rn
                FROM (
                    SELECT p.store_admin_id, p.id AS player_id, p.name, p.vip_level_id,
                           ROW_NUMBER() OVER (PARTITION BY p.store_admin_id
                                              ORDER BY p.vip_level_id DESC, p.id ASC) AS rn
                    FROM player p
                    WHERE p.department_id = ?
                      AND p.vip_level_id >= ?
                      AND p.status = 1
                      AND p.deleted_at IS NULL
                      AND p.store_admin_id > 0
                ) t
                LEFT JOIN admin_users au ON au.id = t.store_admin_id
                WHERE t.rn <= ?
                ORDER BY t.store_admin_id ASC, t.rn ASC";

        $rows = Db::select($sql, [$departmentId, self::VIP_MIN_LEVEL, self::VIP_TOP]);

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
                'rank'      => (int)$r->rn,
                'player_id' => (int)$r->player_id,
                'name'      => (string)$r->name,
                'vip_level' => (int)$r->vip_level_id,
            ];
        }

        return array_values($stores);
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
     * 取某榜最新已結算期別
     *
     * @param string $code
     * @return string|null
     */
    private static function latestPeriodKey(string $code): ?string
    {
        $key = Db::table('leaderboard')
            ->where('code', $code)
            ->whereNotNull('settle_at')
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
