<?php

namespace app\api\controller\v1;

use app\model\Player;
use app\service\LeaderboardService;
use support\Request;
use support\Response;
use Tinywan\Jwt\JwtToken;

/**
 * 排行榜（玩家端）
 *
 * 全部免登入；渠道由 Site-Id 對應的 department_id 決定。
 * 門市榜可依登入玩家的 store_admin_id 過濾；未登入可用 store_id 參數。
 */
class LeaderboardController
{
    /**
     * 排行榜整頁（各店週排行 + 金樽週排行 + 金樽月排行 + VIP 排行）
     *
     * 已登入：依玩家門店過濾各店週/月排行
     * 未登入：有帶 store_id 則依該門店過濾，否則回全部
     *
     * @param Request $request
     * @return Response
     */
    public function overview(Request $request): Response
    {
        $departmentId = (int)$request->department_id;
        $storeId = self::resolveStoreId($request);

        $data = LeaderboardService::overview($departmentId);

        if ($storeId > 0) {
            $data['store_weekly'] = self::filterStore($data['store_weekly'], $storeId);
            $data['store_monthly'] = self::filterStore($data['store_monthly'], $storeId);
        }

        return jsonSuccessResponse('success', $data);
    }

    /**
     * 各店週排行（分渠道）
     *
     * @param Request $request
     * @return Response
     */
    public function storeWeekly(Request $request): Response
    {
        $departmentId = (int)$request->department_id;
        $storeId = self::resolveStoreId($request);

        $list = LeaderboardService::storeWeekly($departmentId);
        if ($storeId > 0) {
            $list = self::filterStore($list, $storeId);
        }

        return jsonSuccessResponse('success', [
            'list' => $list,
        ]);
    }

    /**
     * 各店月排行（分渠道）
     *
     * @param Request $request
     * @return Response
     */
    public function storeMonthly(Request $request): Response
    {
        $departmentId = (int)$request->department_id;
        $storeId = self::resolveStoreId($request);

        $list = LeaderboardService::storeMonthly($departmentId);
        if ($storeId > 0) {
            $list = self::filterStore($list, $storeId);
        }

        return jsonSuccessResponse('success', [
            'list' => $list,
        ]);
    }

    /**
     * 金樽遊戲量週排行（全站）
     *
     * @return Response
     */
    public function goldenWeekly(): Response
    {
        return jsonSuccessResponse('success', [
            'list' => LeaderboardService::goldenWeekly(),
        ]);
    }

    /**
     * 金樽遊戲量月排行（全站）
     *
     * @return Response
     */
    public function goldenMonthly(): Response
    {
        return jsonSuccessResponse('success', [
            'list' => LeaderboardService::goldenMonthly(),
        ]);
    }

    /**
     * VIP 排行（總榜，週榜 + 月榜）
     *
     * @param Request $request
     * @return Response
     */
    public function vipRanking(Request $request): Response
    {
        $departmentId = (int)$request->department_id;

        return jsonSuccessResponse('success', LeaderboardService::vipRanking($departmentId));
    }

    /**
     * 排行榜獎勵規則（門檻、名次贈分）
     *
     * @return Response
     */
    public function rules(): Response
    {
        return jsonSuccessResponse('success', LeaderboardService::rules());
    }

    /**
     * 決定要過濾的門店 ID
     *
     * 已登入優先使用玩家門店；未登入或取不到玩家時，改用 store_id 參數。
     * 都取不到回 0（代表回全部）。
     *
     * @param Request $request
     * @return int
     */
    private static function resolveStoreId(Request $request): int
    {
        $storeId = (int)($request->all()['store_id'] ?? 0);

        try {
            $playerId = (int)JwtToken::getCurrentId();
            if ($playerId > 0) {
                $playerStoreId = (int)(Player::query()->where('id', $playerId)->value('store_admin_id') ?? 0);
                if ($playerStoreId > 0) {
                    return $playerStoreId;
                }
            }
        } catch (\Throwable $e) {
            // 未登入或 token 無效，維持使用 store_id 參數
        }

        return $storeId;
    }

    /**
     * 過濾出指定門店
     *
     * @param array $stores
     * @param int $storeId
     * @return array
     */
    private static function filterStore(array $stores, int $storeId): array
    {
        return array_values(array_filter($stores, function ($store) use ($storeId) {
            return (int)($store['store_id'] ?? 0) === $storeId;
        }));
    }
}
