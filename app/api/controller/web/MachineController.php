<?php

namespace app\api\controller\web;

use app\exception\PlayerCheckException;
use app\model\AdminUser;
use app\model\ChannelMachine;
use app\model\GameType;
use app\model\Machine;
use app\model\Player;
use app\service\machine\MachineClient;
use app\service\machine\MachineServices;
use app\service\WalletService;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Respect\Validation\Exceptions\AllOfException;
use Respect\Validation\Validator;
use support\Log;
use support\Request;
use support\Response;
use Webman\RateLimiter\Annotation\RateLimiter;

class MachineController
{
    use \support\IdempotentTrait;

    /** 單次轉分上限（點） */
    private const MAX_TRANSFER_POINTS = 50000;

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
     * 掃碼 / 查詢單機
     * @param Request $request
     * @param string $code 掃碼結果（machine.code）
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public function machineByCode(Request $request, string $code): Response
    {
        $player = checkPlayer();

        // 依機台編號查詢，限該玩家渠道部門下關聯的機台
        $machine = Machine::query()
            ->with(['machineLabel', 'machineCategory'])
            ->where('code', $code)
            ->where('status', 1)
            ->whereHas('machineLabel', function (Builder $query) {
                $query->where('status', 1);
            })
            ->whereHas('channelMachines', function (Builder $query) use ($player) {
                $query->where('department_id', $player->department_id);
            })
            ->first();
        if (!$machine) {
            return apiFailResponse(trans('machine_not_found', [], 'message'));
        }

        // 玩家已占用機台數（bindable 配額判斷）
        $occupiedCount = Machine::query()
            ->where('gaming_user_id', $player->id)
            ->count();
        $machinePlayNum = $player->machine_play_num > 0 ? $player->machine_play_num : 1;

        $venueStatus = self::aggregateVenueStatus($machine);

        // occupiedBy：規格對應 machine_session.member_id，目前無此表，暫回 null
        $occupiedBy = null;

        return apiSuccessResponse('ok', [
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
            'occupiedBy' => $occupiedBy,
        ]);
    }

    /**
     * 綁定機台
     * @param Request $request
     * @param string $code 機台編號
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public function bind(Request $request, string $code): Response
    {
        $player = checkPlayer();

        // 依機台編號查詢，限該玩家渠道部門下關聯的機台
        /** @var Machine $machine */
        $machine = Machine::query()
            ->with(['machineLabel', 'machineCategory'])
            ->where('code', $code)
            ->where('status', 1)
            ->whereHas('machineLabel', function (Builder $query) {
                $query->where('status', 1);
            })
            ->whereHas('channelMachines', function (Builder $query) use ($player) {
                $query->where('department_id', $player->department_id);
            })
            ->first();
        if (!$machine) {
            return apiFailResponse(trans('machine_not_found', [], 'message'));
        }

        // 已被其他玩家占用
        if ($machine->gaming_user_id != 0 && $machine->gaming_user_id != $player->id) {
            return apiFailResponse(trans('machine_occupied', [], 'message'));
        }
        // 非 idle（保留 / 使用中）視為被占用
        if ($machine->gaming_user_id == 0 && ($machine->gaming == 1 || $machine->keeping == 1 || $machine->is_use == 1)) {
            return apiFailResponse(trans('machine_occupied', [], 'message'));
        }

        // 已由自己綁定，直接回成功（冪等）
        if ($machine->gaming_user_id == $player->id && $machine->gaming == 1) {
            return $this->bindSuccessResponse($machine, $player);
        }

        // 配額檢查：未達 machinePlayNum 配額
        $occupiedCount = Machine::query()
            ->where('gaming_user_id', $player->id)
            ->count();
        $machinePlayNum = $player->machine_play_num > 0 ? $player->machine_play_num : 1;
        if ($occupiedCount >= $machinePlayNum) {
            return apiFailResponse(trans('quota_exceeded', [], 'message'));
        }

        // 綁定：佔位並標記使用中
        $machine->gaming_user_id = $player->id;
        $machine->gaming = 1;
        $machine->save();

        return $this->bindSuccessResponse($machine, $player);
    }

    /**
     * 綁定成功回應
     * @param Machine $machine
     * @param Player $player
     * @return Response
     */
    private function bindSuccessResponse(Machine $machine, Player $player): Response
    {
        $venueStatus = self::aggregateVenueStatus($machine);

        return apiSuccessResponse('ok', [
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
            'bindable' => false,
            'occupiedBy' => [
                'id' => $player->id,
                'nickname' => $player->name,
            ],
            'boundAt' => (int)(strtotime($machine->updated_at) * 1000),
        ]);
    }

    /**
     * 機台上分 / 下分
     * @param Request $request
     * @param string $code 機台編號
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    #[RateLimiter(limit: 5)]
    public function transfer(Request $request, string $code): Response
    {
        $player = checkPlayer();
        $data = $request->all();

        // ---------------------------------------- 冪等性處理 ----------------------------------------
        $requestId = $request->header('X-Request-Id', '') ?: ($data['request_id'] ?? null);
        $operation = 'machine-transfer:' . $player->id;

        $idempotentResponse = $this->checkIdempotent($requestId, $operation, $player->id);
        if ($idempotentResponse !== null) {
            return $idempotentResponse;
        }
        if (! $this->reserveIdempotent($requestId, $operation, $player->id)) {
            $response = $this->checkIdempotent($requestId, $operation, $player->id);
            return $response ?? apiFailResponse(trans('request_processing', [], 'message'));
        }

        // 業務失敗時釋放冪等佔位並回失敗響應
        $fail = function (string $message, string $code = 'ERROR') use ($requestId): Response {
            $this->releaseIdempotent($requestId);
            return apiFailResponse($message, [], $code);
        };

        try {
            // ---------------------------------------- 參數檢查 ----------------------------------------
            $direction = $data['direction'] ?? '';
            $amount = $data['amount'] ?? null;
            if (! in_array($direction, ['up', 'down'], true)) {
                return $fail(trans('transfer_amount_invalid', [], 'message'), 'INVALID_TRANSFER_AMOUNT');
            }
            if ($amount === null || ! is_numeric($amount) || (float)$amount != (int)$amount || (int)$amount <= 0) {
                return $fail(trans('transfer_amount_invalid', [], 'message'), 'INVALID_TRANSFER_AMOUNT');
            }
            $amount = (int)$amount;

            // ---------------------------------------- 機台檢查 ----------------------------------------
            // 依機台編號查詢，限該玩家渠道部門下關聯的機台
            $machine = Machine::query()
                ->with(['machineLabel', 'machineCategory'])
                ->where('code', $code)
                ->where('status', 1)
                ->whereHas('machineLabel', function (Builder $query) {
                    $query->where('status', 1);
                })
                ->whereHas('channelMachines', function (Builder $query) use ($player) {
                    $query->where('department_id', $player->department_id);
                })
                ->first();
            if (! $machine) {
                return $fail(trans('machine_not_found', [], 'message'));
            }
            // 機台維護中不可轉分
            if ($machine->maintaining == 1) {
                return $fail(trans('machine_maintaining', [], 'message'));
            }
            // 全局維護中不可轉分
            if (machineMaintaining()) {
                return $fail(trans('machine_maintaining', [], 'message'));
            }
            // 機台必須已綁定且為本人
            if ($machine->gaming_user_id == 0) {
                return $fail(trans('machine_no_gaming', [], 'message'));
            }
            if ($machine->gaming_user_id != $player->id) {
                return $fail(trans('machine_occupied', [], 'message'));
            }

            // ---------------------------------------- 機台即時狀態 ----------------------------------------
            $services = MachineServices::createServices($machine, locale() ?? 'zh_CN');
            // 機台鎖定不可轉分
            if ($services->has_lock) {
                return $fail(trans('machine_has_lock', [], 'message'));
            }
            // 開獎中不可轉分
            if ($services->reward_status == 1) {
                return $fail(trans('machine_reward_drawing', ['{code}' => $machine->code], 'message'), 'MACHINE_DRAWING');
            }
            // 斯洛機自動中檢查：
            // 上分：斯洛機自動中一律禁止；下分：僅雙美機（CONTROL_TYPE_MEI）自動中禁止
            if ($machine->machine_type == GameType::TYPE_SLOT && $services->auto == 1) {
                if ($direction == 'up' || $machine->control_type == Machine::CONTROL_TYPE_MEI) {
                    return $fail(trans('slot_machine_must_stop_auto', [], 'message'));
                }
            }

            $oddsX = (float)$machine->odds_x;
            $oddsY = (float)$machine->odds_y;
            $ratio = $oddsY / $oddsX;
            $creditBefore = (int)$services->point;

            // ---------------------------------------- 執行轉分 ----------------------------------------
            if ($direction == 'up') {
                $response = $this->transferUp($player, $machine, $amount, $ratio, $creditBefore, $fail);
            } else {
                $response = $this->transferDown($player, $machine, $amount, $ratio, $creditBefore, $fail);
            }

            // 成功才保存冪等性記錄
            $body = json_decode($response->rawBody(), true);
            if (isset($body['code']) && $body['code'] === 0) {
                $this->saveIdempotent($requestId, $response, $operation, $player->id);
            }
            return $response;
        } catch (Exception $e) {
            // 業務失敗，釋放冪等佔位
            $this->releaseIdempotent($requestId);
            return apiFailResponse($e->getMessage() ?: trans('system_error', [], 'message'));
        }
    }

    /**
     * 執行上分（點 → 機台分數）
     * @param Player $player
     * @param Machine $machine
     * @param int $amount 上分點數
     * @param float $ratio 兌換比例 odds_y / odds_x
     * @param int $creditBefore 上分前機台分數
     * @param callable $fail 失敗響應處理（釋放冪等佔位）
     * @return Response
     */
    private function transferUp(Player $player, Machine $machine, int $amount, float $ratio, int $creditBefore, callable $fail): Response
    {
        // 錢包餘額檢查（上分 amount 單位：點）
        $walletBalance = WalletService::getBalance($player->id);
        if ($amount > $walletBalance) {
            return $fail(trans('insufficient_balance', [], 'message'), 'WALLET_INSUFFICIENT');
        }
        // 單次轉分上限（點）
        if ($amount > self::MAX_TRANSFER_POINTS) {
            return $fail(trans('transfer_limit_exceeded', ['{limit}' => self::MAX_TRANSFER_POINTS], 'message'), 'TRANSFER_LIMIT_EXCEEDED');
        }

        // 上分：交由 gk_work 統一處理（金額單位與 v1 上分一致，gk_work 依比例換算分數）
        $lang = locale() ?? 'zh_TW';
        $lang = Str::replace('_', '-', $lang);
        $client = new MachineClient(null, 30);
        $result = $client->openMachine($machine->id, $player->id, $amount, 0, null, $lang);
        if (! $result['success']) {
            throw new Exception($result['message'] ?? trans('machine_open_point_failed', [], 'message'));
        }

        // 上分後機台分數（與 gk_work 開分換算一致：floor(點 × ratio)）
        $creditDelta = (int)floor($amount * $ratio);
        $credit = $creditBefore + $creditDelta;

        return apiSuccessResponse('ok', [
            'walletBalance' => self::formatAmount(WalletService::getBalance($player->id, 1, true)),
            'credit' => $credit,
            'points' => $amount,
            'creditDelta' => $creditDelta,
            'remainderCredit' => 0,
        ]);
    }

    /**
     * 執行下分（機台分數 → 點，全機台洗分）
     * @param Player $player
     * @param Machine $machine
     * @param int $amount 下分點數（須等於機台目前分數，gk_work 只支援全機台洗分）
     * @param float $ratio 兌換比例 odds_y / odds_x
     * @param int $creditBefore 下分前機台分數
     * @param callable $fail 失敗響應處理（釋放冪等佔位）
     * @return Response
     */
    private function transferDown(Player $player, Machine $machine, int $amount, float $ratio, int $creditBefore, callable $fail): Response
    {
        // 下分 amount 須為 ratio 整數倍（分 → 點換算須為整數）
        if (abs(fmod($amount, $ratio)) > 0.000001) {
            return $fail(trans('transfer_amount_invalid', [], 'message'), 'INVALID_TRANSFER_AMOUNT');
        }
        // 全機台洗分：amount 必須等於機台目前分數
        if ($amount != $creditBefore) {
            return $fail(trans('credit_insufficient', [], 'message'), 'CREDIT_INSUFFICIENT');
        }
        // 換算後點數（分 → 點）
        $points = (int)floor($amount / $ratio);
        // 單次轉分上限（點）
        if ($points > self::MAX_TRANSFER_POINTS) {
            return $fail(trans('transfer_limit_exceeded', ['{limit}' => self::MAX_TRANSFER_POINTS], 'message'), 'TRANSFER_LIMIT_EXCEEDED');
        }

        // 下分：交由 gk_work 統一處理（不棄台，保留綁定與遊戲記錄）
        $lang = locale() ?? 'zh_TW';
        $lang = Str::replace('_', '-', $lang);
        $client = new MachineClient(null, 30);
        $result = $client->washMachine($machine->id, $player->id, 'down', false, 0, $lang);
        if (! $result['success']) {
            throw new Exception($result['message'] ?? trans('machine_wash_command_failed', [], 'message'));
        }

        return apiSuccessResponse('ok', [
            'walletBalance' => self::formatAmount(WalletService::getBalance($player->id, 1, true)),
            'credit' => 0,
            'points' => $points,
            'creditDelta' => $amount,
            'remainderCredit' => 0,
        ]);
    }

    /**
     * 格式化金額顯示（整數不顯示小數位）
     * @param float $amount
     * @return float|int
     */
    private static function formatAmount(float $amount): float|int
    {
        // 判斷是否為整數
        if (floor($amount) == $amount) {
            // 整數：返回整數類型
            return (int)$amount;
        }
        // 小數：保留兩位小數
        return round($amount, 2);
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
     * 機台登出（單台)
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public function machinesLogout(Request $request): Response
    {
        $player = checkPlayer();

        if (empty($player->channel->status_machine) || empty($player->status_machine)) {
            return apiFailResponse(trans('platform_no_permission', [], 'message'));
        }

        // ---------------------------------------- 參數檢查 ----------------------------------------
        $data = $request->all();
        $validator = Validator::key('machine_id', Validator::intVal()->notEmpty()->setName(trans('machine_id', [], 'message')));

        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return apiFailResponse(getValidationMessages($e));
        }

        // ---------------------------------------- 冪等性處理 ----------------------------------------
        $requestId = $data['request_id'] ?? null;
        $machineId = $data['machine_id'] ?? 0;
        $operation = 'machines-logout:' . $player->id . ':' . $machineId;
        $checkIdempotent = $this->checkIdempotent($requestId, $operation, $player->id);

        if ($checkIdempotent) {
            return $checkIdempotent;
        }

        if (! $this->reserveIdempotent($requestId, $operation, $player->id)) {
            $response = $this->checkIdempotent($requestId, $operation, $player->id);
            return $response ?? apiFailResponse(trans('request_processing', [], 'message'));
        }

        // ---------------------------------------- 機台離線 ----------------------------------------
        $machine = Machine::query()
            ->where('id', $data['machine_id'])
            ->where('gaming_user_id', $player->id)
            ->first();

        if (! $machine) {
            $response = apiFailResponse(trans('machine_not_found', [], 'message'));
            $this->saveIdempotent($requestId, $response, $operation, $player->id);

            return $response;
        }

        $action = 'leave';
        $language = locale() ?? 'zh_TW';
        $language = Str::replace('_', '-', $language);

        try {
            $services = MachineServices::createServices($machine, $language);

            // 機台鎖定
            if ($services->has_lock == 1) {
                $this->releaseIdempotent($requestId);
                return apiFailResponse(trans('machine_has_lock', [], 'message'));
            }

            // 機台開獎中
            if ($services->reward_status == 1) {
                $this->releaseIdempotent($requestId);
                return apiFailResponse(trans('machine_reward_drawing', ['{code}' => $machine->code], 'message'));
            }

            Log::channel('machine_operations')
                ->info('[MachineWashV2] 开始洗分', [
                    'player_id' => $player->id,
                    'machine_id' => $machine->id,
                    'action' => $action,
                    'lang' => $language
                ]);

            // 调用 gk_work 完整业务逻辑
            // 超时30秒，因为要处理完整的数据库事务
            $client = new MachineClient(null, 30);

            $result = $client->washMachine(
                $machine->id, $player->id, $action, true, 0, $language
            );

            if (! $result['success']) {
                $this->releaseIdempotent($requestId);
                return apiFailResponse($result['message'] ?? trans('machine_wash_command_failed', [], 'message'));
            }

            Log::channel('machine_operations')->info('[MachineWashV2] 洗分成功', [
                'player_id' => $player->id,
                'machine_id' => $machine->id,
                'has_lottery' => $result['data']['has_lottery'] ?? false,
            ]);
        } catch (Exception $e) {
            Log::error($e->getMessage());
            $this->releaseIdempotent($requestId);

            return apiFailResponse($e->getMessage());
        }

        $response = apiSuccessResponse(trans('machine_logout', [], 'message'));
        $this->saveIdempotent($requestId, $response, $operation, $player->id);

        return $response;
    }

    /**
     * 機台登出（全部)
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public function machinesLogoutAll(Request $request): Response
    {
        $player = checkPlayer();

        if (empty($player->channel->status_machine) || empty($player->status_machine)) {
            return apiFailResponse(trans('platform_no_permission', [], 'message'));
        }

        // ---------------------------------------- 冪等性處理 ----------------------------------------
        $requestId = $request->input('request_id') ?? null;
        $operation = 'machines-logout-all:' . $player->id;
        $checkIdempotent = $this->checkIdempotent($requestId, $operation, $player->id);

        if ($checkIdempotent) {
            return $checkIdempotent;
        }

        if (! $this->reserveIdempotent($requestId, $operation, $player->id)) {
            $response = $this->checkIdempotent($requestId, $operation, $player->id);
            return $response ?? apiFailResponse(trans('request_processing', [], 'message'));
        }

        // ---------------------------------------- 機台逐台離線 ----------------------------------------
        $machine = Machine::query()
            ->where('gaming_user_id', $player->id)
            ->orderBy('sort')
            ->orderBy('id', 'desc')
            ->get();

        if ($machine->isEmpty()) {
            $response = apiFailResponse(trans('machine_no_gaming', [], 'message'));
            $this->saveIdempotent($requestId, $response, $operation, $player->id);

            return $response;
        }

        $action = 'leave';
        $language = locale() ?? 'zh_TW';
        $language = Str::replace('_', '-', $language);
        $message = '';

        foreach ($machine as $value) {
            try {
                $services = MachineServices::createServices($value, $language);

                // 機台鎖定
                if ($services->has_lock == 1) {
                    $message = trans('machine_has_lock', [], 'message');
                    continue;
                }

                // 機台開獎中
                if ($services->reward_status == 1) {
                    $message = trans('machine_reward_drawing', ['{code}' => $value->code], 'message');
                    continue;
                }

                Log::channel('machine_operations')
                    ->info('[MachineWashV2] 开始洗分', [
                        'player_id' => $player->id,
                        'machine_id' => $value->id,
                        'action' => $action,
                        'lang' => $language
                    ]);

                // 调用 gk_work 完整业务逻辑
                // 超时30秒，因为要处理完整的数据库事务
                $client = new MachineClient(null, 30);

                $result = $client->washMachine(
                    $value->id, $player->id, $action, true, 0, $language
                );

                if (! $result['success']) {
                    $message = $result['message'] ?? trans('machine_wash_command_failed', [], 'message');
                    continue;
                }

                Log::channel('machine_operations')->info('[MachineWashV2] 洗分成功', [
                    'player_id' => $player->id,
                    'machine_id' => $value->id,
                    'has_lottery' => $result['data']['has_lottery'] ?? false,
                ]);
            } catch (Exception $e) {
                Log::error($e->getMessage());
                $this->releaseIdempotent($requestId);

                return apiFailResponse($e->getMessage());
            }
        }

        if (! empty($message)) {
            $this->releaseIdempotent($requestId);
            return apiFailResponse($message);
        }

        $response = apiSuccessResponse(trans('machine_logout_all', [], 'message'));
        $this->saveIdempotent($requestId, $response, $operation, $player->id);

        return $response;
    }
}
