<?php

namespace app\api\controller\web;

use app\exception\PlayerCheckException;
use app\model\AdminUser;
use app\model\ChannelMachine;
use app\model\GameType;
use app\model\Machine;
use app\model\Player;
use app\model\SystemSetting;
use app\service\machine\MachineClient;
use app\service\machine\MachineServices;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use support\Log;
use support\Request;
use support\Response;
use Webman\RateLimiter\Annotation\RateLimiter;

/**
 * Request 包装类，用于注入额外参数
 */
class RequestWrapper extends Request
{
    private array $injectedData = [];

    public function __construct(Request $originalRequest)
    {
        // 复制原始请求的所有属性
        foreach (get_object_vars($originalRequest) as $key => $value) {
            $this->$key = $value;
        }
    }

    public function injectData(array $data): void
    {
        $this->injectedData = array_merge($this->injectedData, $data);
    }

    public function post($name = null, $default = null)
    {
        $postData = parent::post();
        if (!is_array($postData)) {
            $postData = [];
        }
        $mergedData = array_merge($postData, $this->injectedData);

        if ($name === null) {
            return $mergedData;
        }
        return $mergedData[$name] ?? $default;
    }

    public function all()
    {
        return $this->get() + $this->post();
    }
}

class MachineController
{
    use \support\IdempotentTrait;

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
        $status = (string)$request->get('status', ''); // 机台状态筛选

        // 門店 = 店家管理員（AdminUser.type=4）
        $store = AdminUser::query()->where('id', $storeId)->where('type', AdminUser::TYPE_STORE)->whereNull('deleted_at')->first();
        if (!$store) {
            return apiFailResponse(trans('store_not_found', [], 'message'));
        }
        // 門店必須屬於玩家所在渠道，避免跨渠道撈取
        if ($store->department_id != $player->department_id) {
            return apiFailResponse(trans('store_not_found', [], 'message'));
        }

        // 该门店绑定的线下机台（通过 channel_machine.store_admin_id 过滤）
        $machineIds = ChannelMachine::query()->where('store_admin_id', $storeId)  // ✅ 只获取绑定到当前店家的机台
            ->whereHas('machine', function ($query) {
                $query->where('machine_source', Machine::MACHINE_SOURCE_OFFLINE);
            })->pluck('machine_id');
        if ($machineIds->isEmpty()) {
            return apiSuccessResponse('ok', ['list' => [], 'page' => $page, 'pageSize' => $pageSize, 'total' => 0,]);
        }

        $query = Machine::query()->with(['machineLabel', 'machineCategory'])->whereIn('id', $machineIds)->where('status', 1)->where('machine_source', Machine::MACHINE_SOURCE_OFFLINE)->whereHas('machineLabel', function ($query) {
                $query->where('status', 1);
            })->orderBy('sort', 'desc')->orderBy('id', 'desc');

        // 玩家已占用機台數（bindable 配額判斷）
        $occupiedCount = Machine::query()->where('gaming_user_id', $player->id)->where('machine_source', Machine::MACHINE_SOURCE_OFFLINE)->count();
        $machinePlayNum = $player->machine_play_num > 0 ? $player->machine_play_num : 1;

        // ---------------------------------------- 批量檢查機台在線狀態 ----------------------------------------
        $machines = $query->get();
        $machineIds = $machines->pluck('id')->toArray();
        $onlineStatusMap = [];

        try {
            $client = new MachineClient();
            $result = $client->batchCheckOnline($machineIds);
            if ($result['success'] && isset($result['data'])) {
                $onlineStatusMap = $result['data'];
            }
        } catch (Exception $e) {
            Log::error('Batch check machine online failed', ['error' => $e->getMessage()]);
        }

        // ---------------------------------------- 組裝機台清單 ----------------------------------------
        $lang = locale() ?? 'zh_TW';
        $lang = Str::replace('_', '-', $lang);
        $list = [];

        /** @var Machine $machine */
        foreach ($machines as $machine) {
            $venueStatus = self::aggregateVenueStatus($machine);
            if (!empty($status) && $venueStatus != $status) {
                continue;
            }

            // 獲取機台即時狀態
            $machineServices = MachineServices::createServices($machine, $lang);
            $onlineStatus = $onlineStatusMap[$machine->id] ?? 'offline';

            // 當前轉數（斯洛機需要除以3向上取整）
            $nowTurn = $machineServices->now_turn;
            if ($machine->type == GameType::TYPE_SLOT) {
                $nowTurn = $nowTurn > 0 ? intval(ceil($nowTurn / 3)) : 0;
            }

            $list[] = ['id' => $machine->id, 'code' => $machine->code, 'name' => $machine->machineLabel->name, 'type' => $machine->type, 'pictureUrl' => $machine->picture_url, 'point' => $machine->machineLabel->point ?? 0, 'turn' => $machine->machineLabel->turn ?? 0, 'score' => $machine->machineLabel->score ?? 0, 'courtyard' => $machine->machineLabel->courtyard ?? '', 'correct_rate' => $machine->machineLabel->correct_rate ?? '', 'odds_x' => $machine->odds_x, 'odds_y' => $machine->odds_y, 'venueStatus' => $venueStatus, 'bindable' => $venueStatus === 'idle' && $occupiedCount < $machinePlayNum, // ✅ 新增：機台即時狀態
                'maintaining' => $machine->maintaining, 'gamingUserId' => $machine->gaming_user_id, 'keeping' => $machineServices->keeping, 'gaming' => $machineServices->gaming, 'isUse' => $machine->is_use, 'rewardStatus' => $machineServices->reward_status, 'nowTurn' => $nowTurn ? intval($nowTurn) : 0, 'keepSeconds' => $machineServices->keep_seconds, 'onlineStatus' => $onlineStatus,];
        }

        $total = count($list);
        $list = array_slice($list, ($page - 1) * $pageSize, $pageSize);

        return apiSuccessResponse('ok', ['list' => $list, 'page' => $page, 'pageSize' => $pageSize, 'total' => $total,]);
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

    /**
     * 查詢單機（支持通过 ID 或 Code 查询）
     * @param Request $request
     * @param string $identifier 機台 ID 或 Code（自动识别）
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public function getMachine(Request $request): Response
    {
        $player = checkPlayer();

        // 从查询参数获取 id 或 code
        $machineId = $request->input('id') ? (int)$request->input('id') : null;     // 机台ID（整数）
        $machineCode = $request->input('code') ? (string)$request->input('code') : null; // 机台编号（字符串）

        if (!$machineId && !$machineCode) {
            return apiFailResponse(trans('invalid_params', [], 'message'));
        }

        // 依機台ID或Code查詢，限該玩家所属店家的机台
        /** @var Machine $machine */
        $query = Machine::query()->with(['machineLabel', 'machineCategory'])->where('status', 1)->where('machine_source', Machine::MACHINE_SOURCE_OFFLINE)->whereHas('machineLabel', function (Builder $query) {
                $query->where('status', 1);
            })->whereHas('channelMachines', function (Builder $query) use ($player) {
                // ✅ 只查询绑定到玩家所属店家的机台
                $query->where('store_admin_id', $player->store_admin_id);
            });

        // 优先使用 id，其次使用 code
        if ($machineId) {
            $query->where('id', $machineId);
        } elseif ($machineCode) {
            $query->where('code', $machineCode);
        }

        $machine = $query->first();
        if (!$machine) {
            return apiFailResponse(trans('machine_not_found', [], 'message'));
        }

        // 玩家已占用機台數（bindable 配額判斷）
        $occupiedCount = Machine::query()->where('gaming_user_id', $player->id)->where('machine_source', Machine::MACHINE_SOURCE_OFFLINE)->count();
        $machinePlayNum = $player->machine_play_num > 0 ? $player->machine_play_num : 1;

        // ---------------------------------------- 獲取機台即時狀態 ----------------------------------------
        $lang = locale() ?? 'zh_TW';
        $lang = Str::replace('_', '-', $lang);
        $machineServices = MachineServices::createServices($machine, $lang);

        // 檢查機台在線狀態
        $onlineStatus = 'offline';
        try {
            $client = new MachineClient();
            $result = $client->batchCheckOnline([$machine->id]);
            if ($result['success'] && isset($result['data'][$machine->id])) {
                $onlineStatus = $result['data'][$machine->id];
            }
        } catch (Exception $e) {
            Log::error('Check machine online failed', ['error' => $e->getMessage()]);
        }

        // 當前轉數（斯洛機需要除以3向上取整）
        $nowTurn = $machineServices->now_turn;
        if ($machine->type == GameType::TYPE_SLOT) {
            $nowTurn = $nowTurn > 0 ? intval(ceil($nowTurn / 3)) : 0;
        }

        $venueStatus = self::aggregateVenueStatus($machine);

        // 占用玩家信息
        $occupiedBy = null;
        if ($machine->gaming_user_id > 0) {
            $occupiedPlayer = Player::query()->find($machine->gaming_user_id);
            if ($occupiedPlayer) {
                $occupiedBy = ['id' => $occupiedPlayer->id, 'nickname' => $occupiedPlayer->name, 'avatar' => $occupiedPlayer->avatar ?? '',];
            }
        }

        return apiSuccessResponse('ok', ['id' => $machine->id, 'code' => $machine->code, 'name' => $machine->machineLabel->name ?? $machine->name, 'type' => $machine->type, 'pictureUrl' => $machine->picture_url, 'point' => $machine->machineLabel->point ?? 0, 'turn' => $machine->machineLabel->turn ?? 0, 'score' => $machine->machineLabel->score ?? 0, 'courtyard' => $machine->machineLabel->courtyard ?? '', 'correct_rate' => $machine->machineLabel->correct_rate ?? $machine->correct_rate ?? '', 'odds_x' => $machine->odds_x, 'odds_y' => $machine->odds_y, 'venueStatus' => $venueStatus, 'bindable' => $venueStatus === 'idle' && $occupiedCount < $machinePlayNum, 'occupiedBy' => $occupiedBy, // ✅ 新增：機台即時狀態
            'maintaining' => $machine->maintaining, 'gamingUserId' => $machine->gaming_user_id, 'keeping' => $machineServices->keeping, 'gaming' => $machineServices->gaming, 'isUse' => $machine->is_use, 'rewardStatus' => $machineServices->reward_status, 'nowTurn' => $nowTurn ? intval($nowTurn) : 0, 'bet' => $machineServices->bet ?? 0, 'keepSeconds' => $machineServices->keep_seconds, 'onlineStatus' => $onlineStatus, 'lastPlayTime' => $machineServices->last_play_time ?? null,]);
    }

    /**
     * 綁定機台
     * @param Request $request
     * @param string $machineId
     * @return Response
     * @throws PlayerCheckException
     */
    public function bind(Request $request, string $machineId): Response
    {
        $player = checkPlayer();

        // 依機台ID查詢，限該玩家所属店家的机台
        /** @var Machine $machine */
        $machine = Machine::query()->with(['machineLabel', 'machineCategory'])->where('id', $machineId)->where('status', 1)->where('machine_source', Machine::MACHINE_SOURCE_OFFLINE)->whereHas('machineLabel', function (Builder $query) {
                $query->where('status', 1);
            })->whereHas('channelMachines', function (Builder $query) use ($player) {
                // ✅ 只查询绑定到玩家所属店家的机台
                $query->where('store_admin_id', $player->store_admin_id);
            })->first();
        if (!$machine) {
            return apiFailResponse(trans('machine_not_found', [], 'message'));
        }

        // ---------------------------------------- 機台狀態檢查 ----------------------------------------
        // 檢查維護狀態
        if ($machine->maintaining == 1) {
            return apiFailResponse(trans('machine_maintaining', [], 'message'));
        }

        // 檢查全局維護
        if (machineMaintaining()) {
            return apiFailResponse(trans('machine_maintaining', [], 'message'));
        }

        // 獲取機台即時狀態（Redis）
        $lang = locale() ?? 'zh_TW';
        $lang = Str::replace('_', '-', $lang);
        $machineServices = MachineServices::createServices($machine, $lang);

        // 檢查機台鎖定狀態
        if ($machineServices->has_lock == 1) {
            return apiFailResponse(trans('machine_has_lock', [], 'message'));
        }

        // 檢查開獎狀態
        if ($machineServices->reward_status == 1) {
            return apiFailResponse(trans('machine_reward_drawing', ['{code}' => $machine->code], 'message'));
        }

        // 檢查在線狀態
        $onlineStatus = 'offline';
        try {
            $client = new MachineClient();
            $result = $client->batchCheckOnline([$machine->id]);
            if ($result['success'] && isset($result['data'][$machine->id])) {
                $onlineStatus = $result['data'][$machine->id];
            }
        } catch (Exception $e) {
            Log::error('Check machine online failed', ['error' => $e->getMessage()]);
        }

        if ($onlineStatus !== 'online') {
            return apiFailResponse(trans('machine_has_offline', ['{code}' => $machine->code], 'message'));
        }

        // ---------------------------------------- 占用狀態檢查 ----------------------------------------
        // 已被其他玩家占用（數據庫狀態）
        if ($machine->gaming_user_id != 0 && $machine->gaming_user_id != $player->id) {
            return apiFailResponse(trans('machine_occupied', [], 'message'));
        }

        // Redis 實時狀態檢查（保留 / 使用中）
        if ($machineServices->keeping == 1 || $machineServices->gaming == 1) {
            // 如果 Redis 顯示占用但數據庫顯示空閒，可能是狀態不一致
            if ($machine->gaming_user_id == 0) {
                return apiFailResponse(trans('machine_occupied', [], 'message'));
            }
            // 如果是其他玩家占用
            if ($machine->gaming_user_id != $player->id) {
                return apiFailResponse(trans('machine_occupied', [], 'message'));
            }
        }

        // 數據庫狀態檢查（保留 / 使用中）
        if ($machine->gaming_user_id == 0 && ($machine->gaming == 1 || $machine->keeping == 1 || $machine->is_use == 1)) {
            return apiFailResponse(trans('machine_occupied', [], 'message'));
        }

        // ---------------------------------------- 冪等性處理 ----------------------------------------
        // 已由自己綁定，直接回成功（冪等）
        if ($machine->gaming_user_id == $player->id && $machine->gaming == 1) {
            return $this->bindSuccessResponse($machine, $player);
        }

        // ---------------------------------------- 配額檢查 ----------------------------------------
        $occupiedCount = Machine::query()->where('gaming_user_id', $player->id)->where('machine_source', Machine::MACHINE_SOURCE_OFFLINE)->count();
        $machinePlayNum = $player->machine_play_num > 0 ? $player->machine_play_num : 1;
        if ($occupiedCount >= $machinePlayNum) {
            return apiFailResponse(trans('quota_exceeded', [], 'message'));
        }

        // ---------------------------------------- 執行綁定 ----------------------------------------
        // 更新數據庫狀態
        $machine->gaming_user_id = $player->id;
        $machine->gaming = 1;
        $machine->last_game_at = date('Y-m-d H:i:s');
        $machine->save();

        // ✅ 同步更新 Redis 狀態（通過 MachineServices）
        try {
            try {
                /** @var SystemSetting $keepingGiftSetting */
                $keepingGiftSetting = SystemSetting::query()->where('feature', 'gift_keeping_minutes')->where('status', 1)->first();

                if (!empty($keepingGiftSetting) && $keepingGiftSetting->num > 0) {
                    $giftKeepSeconds = bcmul($keepingGiftSetting->num, 60);  // 分钟转秒
                    $machineServices->keep_seconds = $giftKeepSeconds;
                }
                $machineServices->gaming = 1;
                $machineServices->last_play_time = time();
                $machineServices->gaming_user_id = $player->id;
            } catch (\Exception $e) {
                Log::error('[machineOpenAnyFree] 赠送保留时间失败', ['player_id' => $player->id, 'machine_id' => $machine->id, 'error' => $e->getMessage(),]);
            }
        } catch (Exception $e) {
            // Redis 更新失敗不影響綁定，記錄日誌
            Log::error('[MachineBinding] Failed to update Redis status', ['machine_id' => $machine->id, 'player_id' => $player->id, 'error' => $e->getMessage(),]);
        }

        return $this->bindSuccessResponse($machine, $player);
    }

    /**
     * 綁定成功回應
     * @param Machine $machine
     * @param Player $player
     * @return Response
     * @throws Exception
     */
    private function bindSuccessResponse(Machine $machine, Player $player): Response
    {
        // 獲取機台即時狀態
        $lang = locale() ?? 'zh_TW';
        $lang = Str::replace('_', '-', $lang);
        $machineServices = MachineServices::createServices($machine, $lang);

        // 檢查在線狀態
        $onlineStatus = 'offline';
        try {
            $client = new MachineClient();
            $result = $client->batchCheckOnline([$machine->id]);
            if ($result['success'] && isset($result['data'][$machine->id])) {
                $onlineStatus = $result['data'][$machine->id];
            }
        } catch (Exception $e) {
            Log::error('Check machine online failed', ['error' => $e->getMessage()]);
        }

        // 當前轉數（斯洛機需要除以3向上取整）
        $nowTurn = $machineServices->now_turn;
        if ($machine->type == GameType::TYPE_SLOT) {
            $nowTurn = $nowTurn > 0 ? intval(ceil($nowTurn / 3)) : 0;
        }

        $venueStatus = self::aggregateVenueStatus($machine);

        return apiSuccessResponse('ok', ['id' => $machine->id, 'code' => $machine->code, 'name' => $machine->machineLabel->name ?? $machine->name, 'type' => $machine->type, 'pictureUrl' => $machine->picture_url, 'point' => $machine->machineLabel->point ?? 0, 'turn' => $machine->machineLabel->turn ?? 0, 'score' => $machine->machineLabel->score ?? 0, 'courtyard' => $machine->machineLabel->courtyard ?? '', 'correct_rate' => $machine->machineLabel->correct_rate ?? $machine->correct_rate ?? '', 'odds_x' => $machine->odds_x, 'odds_y' => $machine->odds_y, 'venueStatus' => $venueStatus, 'bindable' => false, 'occupiedBy' => ['id' => $player->id, 'nickname' => $player->name, 'avatar' => $player->avatar ?? '',], 'boundAt' => (int)(strtotime($machine->updated_at) * 1000), // ✅ 新增：機台即時狀態
            'maintaining' => $machine->maintaining, 'gamingUserId' => $machine->gaming_user_id, 'keeping' => $machineServices->keeping, 'gaming' => $machineServices->gaming, 'isUse' => $machine->is_use, 'rewardStatus' => $machineServices->reward_status, 'nowTurn' => $nowTurn ? intval($nowTurn) : 0, 'bet' => $machineServices->bet ?? 0, 'keepSeconds' => $machineServices->keep_seconds, 'onlineStatus' => $onlineStatus, 'lastPlayTime' => $machineServices->last_play_time ?? null,]);
    }

    #[RateLimiter(limit: 20)]
    /**
     * 機台操作（統一入口：直接调用 v1 的 jackPotAction 和 slotAction）
     *
     * @param Request $request
     * @param string $machineId
     * @return Response
     */ public function machineOperation(Request $request, string $machineId): Response
    {
        try {
            $player = checkPlayer();
            $action = (string)$request->input('action', '');

            // ---------------------------------------- 參數驗證 ----------------------------------------
            if (empty($action)) {
                return apiFailResponse(trans('action_required', [], 'message'), [], 'INVALID_PARAMS');
            }

            // ---------------------------------------- 機台檢查 ----------------------------------------
            /** @var Machine $machine */
            $machine = Machine::query()->where('id', $machineId)->where('status', 1)->where('machine_source', Machine::MACHINE_SOURCE_OFFLINE)->whereHas('machineLabel', function (Builder $query) {
                    $query->where('status', 1);
                })->whereHas('channelMachines', function (Builder $query) use ($player) {
                    $query->where('store_admin_id', $player->store_admin_id);
                })->first();

            if (!$machine) {
                return apiFailResponse(trans('machine_not_found', [], 'message'));
            }

            // ---------------------------------------- 构造包含 machine_id 的新请求 ----------------------------------------
            // 使用 RequestWrapper 注入 machine_id 参数
            $wrappedRequest = new RequestWrapper($request);
            $wrappedRequest->injectData(['machine_id' => $machine->id]);

            // ---------------------------------------- 根據機台類型調用 v1 的方法 ----------------------------------------
            $v1Controller = new \app\api\controller\v1\MachineController();

            if ($machine->type == GameType::TYPE_STEEL_BALL) {
                return $v1Controller->jackPotAction($wrappedRequest);
            } elseif ($machine->type == GameType::TYPE_SLOT) {
                return $v1Controller->slotAction($wrappedRequest);
            } else {
                return apiFailResponse(trans('machine_type_not_supported', [], 'message'));
            }
        } catch (Exception $e) {
            Log::error('[MachineOperation] Error', ['machine_id' => $machineId, 'action' => $action ?? '', 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString(),]);
            return apiFailResponse($e->getMessage() ?: trans('system_error', [], 'message'));
        }
    }
}
