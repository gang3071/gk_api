<?php

namespace app\api\controller\v1;

use app\service\LeaderboardService;
use support\Request;
use support\Response;

/**
 * 排行榜（玩家端）
 *
 * 全部免登入；渠道由 Site-Id 對應的 department_id 決定。
 */
class LeaderboardController
{
    /**
     * 排行榜整頁（各店週排行 + 金樽週排行 + 金樽月排行 + VIP 排行）
     *
     * @param Request $request
     * @return Response
     */
    public function overview(Request $request): Response
    {
        $departmentId = (int)$request->department_id;

        return jsonSuccessResponse('success', LeaderboardService::overview($departmentId));
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

        return jsonSuccessResponse('success', [
            'list' => LeaderboardService::storeWeekly($departmentId),
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
     * VIP 排行（分渠道）
     *
     * @param Request $request
     * @return Response
     */
    public function vipRanking(Request $request): Response
    {
        $departmentId = (int)$request->department_id;

        return jsonSuccessResponse('success', [
            'list' => LeaderboardService::vipRanking($departmentId),
        ]);
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
}
