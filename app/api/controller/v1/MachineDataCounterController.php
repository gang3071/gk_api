<?php

namespace app\api\controller\v1;

use app\service\machine\DataCounterService;
use support\Request;
use support\Response;

/**
 * 虚拟打赏灯 API 控制器
 */
class MachineDataCounterController
{
    /**
     * 获取虚拟打赏灯数据
     *
     * GET /api/machine/{machineId}/data-counter
     *
     * @param Request $request
     * @param int $machineId
     * @return Response
     */
    public function getData(Request $request, int $machineId): Response
    {
        try {
            $data = DataCounterService::getData($machineId);

            return json([
                'code' => 200,
                'message' => 'success',
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 获取开奖历史列表
     *
     * GET /api/machine/{machineId}/lottery-history
     *
     * @param Request $request
     * @param int $machineId
     * @return Response
     */
    public function getLotteryHistory(Request $request, int $machineId): Response
    {
        try {
            $page = $request->get('page', 1);
            $pageSize = $request->get('page_size', 30);

            $history = \support\Cache::get("machine:{$machineId}:lottery_history", []);

            $total = count($history);
            $start = ($page - 1) * $pageSize;
            $data = array_slice($history, $start, $pageSize);

            return json([
                'code' => 200,
                'message' => 'success',
                'data' => [
                    'list' => $data,
                    'total' => $total,
                    'page' => $page,
                    'page_size' => $pageSize,
                ],
            ]);
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 获取历史统计（今日/昨日/前日）
     *
     * GET /api/machine/{machineId}/stats-history
     *
     * @param Request $request
     * @param int $machineId
     * @return Response
     */
    public function getStatsHistory(Request $request, int $machineId): Response
    {
        try {
            $data = DataCounterService::getData($machineId);

            return json([
                'code' => 200,
                'message' => 'success',
                'data' => [
                    'today' => $data['today'],
                    'yesterday' => $data['yesterday'],
                    'day_before' => $data['day_before'],
                ],
            ]);
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 获取当前游戏状态
     *
     * GET /api/machine/{machineId}/current-status
     *
     * @param Request $request
     * @param int $machineId
     * @return Response
     */
    public function getCurrentStatus(Request $request, int $machineId): Response
    {
        try {
            $data = DataCounterService::getData($machineId);

            return json([
                'code' => 200,
                'message' => 'success',
                'data' => [
                    'current_turn' => $data['current_turn'],
                    'reward_status' => $data['reward_status'],
                    'bb_status' => $data['bb_status'],
                    'rb_status' => $data['rb_status'],
                ],
            ]);
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}