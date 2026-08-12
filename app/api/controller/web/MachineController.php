<?php

namespace app\api\controller\web;

use app\exception\PlayerCheckException;
use app\model\AdminUser;
use app\model\ChannelMachine;
use app\model\Machine;
use Exception;
use support\Request;
use support\Response;

class MachineController
{
    /**
     * 門店機台列表
     * @param Request $request
     * @param string $storeId 門店 ID（店家 AdminUser.type=4）
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public function storeMachines(Request $request, string $storeId): Response
    {
        $player = checkPlayer();
        $page = (int)$request->get('page', 1);
        $pageSize = (int)$request->get('pageSize', 10);
        $status = $request->get('status', '');

        if ($page < 1) {
            $page = 1;
        }
        if ($pageSize < 1) {
            $pageSize = 10;
        }

        // 門店 = 店家管理員（AdminUser.type=4）
        $store = AdminUser::query()
            ->where('id', $storeId)
            ->where('type', AdminUser::TYPE_STORE)
            ->whereNull('deleted_at')
            ->first();
        if (!$store) {
            return apiFailResponse(trans('store_not_found', [], 'message'));
        }
        // 門店必須屬於玩家所在渠道，避免跨渠道撈取
        if ($store->department_id != $player->department_id) {
            return apiFailResponse(trans('store_not_found', [], 'message'));
        }

        // 該門店部門下關聯的機台
        $machineIds = ChannelMachine::query()
            ->where('department_id', $store->department_id)
            ->pluck('machine_id');
        if ($machineIds->isEmpty()) {
            return apiSuccessResponse('ok', [
                'list' => [],
                'page' => $page,
                'pageSize' => $pageSize,
                'total' => 0,
            ]);
        }

        $query = Machine::query()
            ->with(['machineLabel', 'machineCategory'])
            ->whereIn('id', $machineIds)
            ->where('status', 1)
            ->whereHas('machineLabel', function ($query) {
                $query->where('status', 1);
            })
            ->orderBy('sort', 'desc')
            ->orderBy('id', 'desc');

        // 玩家已占用機台數（bindable 配額判斷）
        $occupiedCount = Machine::query()
            ->where('gaming_user_id', $player->id)
            ->count();
        $machinePlayNum = $player->machine_play_num > 0 ? $player->machine_play_num : 1;

        $list = [];
        foreach ($query->get() as $machine) {
            $venueStatus = self::aggregateVenueStatus($machine);
            if (!empty($status) && $venueStatus != $status) {
                continue;
            }
            $list[] = [
                'id' => $machine->id,
                'code' => $machine->code,
                'name' => $machine->name,
                'pictureUrl' => $machine->picture_url,
                'point' => $machine->machineLabel->point ?? 0,
                'turn' => $machine->machineLabel->turn ?? 0,
                'score' => $machine->machineLabel->score ?? 0,
                'courtyard' => $machine->machineLabel->courtyard ?? '',
                'correct_rate' => $machine->correct_rate,
                'odds_x' => $machine->odds_x,
                'odds_y' => $machine->odds_y,
                'venueStatus' => $venueStatus,
                'bindable' => $venueStatus === 'idle' && $occupiedCount < $machinePlayNum,
            ];
        }

        $total = count($list);
        $list = array_slice($list, ($page - 1) * $pageSize, $pageSize);

        return apiSuccessResponse('ok', [
            'list' => $list,
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $total,
        ]);
    }

    /**
     * 聚合機台現場狀態
     * @param Machine $machine
     * @return string idle / in-use / maintenance
     */
    private static function aggregateVenueStatus(Machine $machine): string
    {
        if ($machine->maintaining == 1) {
            return 'maintenance';
        }
        if ($machine->gaming == 1 || $machine->keeping == 1 || $machine->is_use == 1) {
            return 'in-use';
        }
        return 'idle';
    }
}
