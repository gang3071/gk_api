<?php

namespace app\api\controller\web;

use app\exception\PlayerCheckException;
use app\model\Channel;
use app\model\GamePlatform;
use app\model\GameType;
use app\model\Machine;
use app\model\PlayerDeliveryRecord;
use app\model\PlayGameRecord;
use app\model\PlayerMoneyEditLog;
use app\model\PlayerReverseWaterDetail;
use app\model\VipLevel;
use app\service\machine\MachineClient;
use app\service\machine\MachineServices;
use app\service\WalletService;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Str;
use Respect\Validation\Exceptions\AllOfException;
use Respect\Validation\Validator;
use support\Cache;
use support\Db;
use support\Log;
use support\Request;
use support\Response;

class PlayerController
{
    use \support\IdempotentTrait;

    /**
     * 個人資料
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public function profile(): Response
    {
        // ---------------------------------------- 基本資料 ----------------------------------------
        $player = checkPlayer();
        $storeName = '';

        if (! empty($player->storeAdmin)) {
            $storeName = $player->storeAdmin->nickname ?? $player->storeAdmin->username ?? '';
        }

        // ---------------------------------------- 打分相關 ----------------------------------------
        // 获取电子游戏打码量（排除真人视讯/体育平台，用于VIP升级统计）
        $todayStart = Carbon::today()->startOfDay()->toDateTimeString();
        $todayEnd = Carbon::today()->endOfDay()->toDateTimeString();
        $yesterdayStart = Carbon::yesterday()->startOfDay()->toDateTimeString();
        $yesterdayEnd = Carbon::yesterday()->endOfDay()->toDateTimeString();

        $todayScore = PlayGameRecord::query()
            ->where('player_id', $player->id)
            ->where('created_at', '>=', $todayStart)
            ->where('created_at', '<=', $todayEnd)
            ->whereNotIn('platform_id', self::getExcludedPlatformIds())
            ->sum('bet');

        $yesterdayScore = PlayGameRecord::query()
            ->where('player_id', $player->id)
            ->where('created_at', '>=', $yesterdayStart)
            ->where('created_at', '<=', $yesterdayEnd)
            ->whereNotIn('platform_id', self::getExcludedPlatformIds())
            ->sum('bet');
        // ---------------------------------------- VIP 相關 ----------------------------------------
        $nextLevel = '';
        $vipLevel = $player->vipLevel()->first();
        $currentPeriod = $player->currentVipPeriod()->first();
        $currentBetAmount = $currentPeriod ? $currentPeriod->period_bet_amount : 0;

        // 如果玩家没有VIP等级，获取所属渠道的最低VIP等级作为默认展示
        if (empty($vipLevel)) {
            $vipLevel = VipLevel::query()
                ->where('department_id', $player->department_id)
                ->orderBy('sort', 'asc')
                ->first();
        }

        $nextLevel = VipLevel::query()
            ->where('department_id', $player->department_id)
            ->where('sort', '>', $vipLevel->sort)
            ->orderBy('sort', 'asc')
            ->first();
        // ---------------------------------------- 機台相關 ----------------------------------------
        $machine = Machine::query()
            ->whereHas('machineCategory', function ($query) {
                $query->whereHas('gameType', function ($query) {
                    $query->where('status', 1);
                })->where('status', 1);
            })
            ->where('status', 1)
            ->where('maintaining', 0)
            ->where('gaming_user_id', $player->id)
            ->count();

        return apiSuccessResponse('success', [
            'player' => [
                'id' => $player->id,
                'uid' => $player->uuid,
                'nickname' => $player->name,
                'avatar' => $player->avatar,
                'storeName' => $storeName,
                'todayScore' => self::formatAmount($todayScore),
                'yesterdayScore' => self::formatAmount($yesterdayScore),
                'createdAt' => $player->created_at
            ],
            'vip' => [
                'vipName' => $vipLevel->name,
                'vipLevel' => $vipLevel->sort,
                'nextVipLevel' => empty($nextLevel) ? '' : $nextLevel->name,
                'currentBetAmount' => $currentBetAmount,
                'upgradeBetAmount' => $vipLevel->upgrade_bet_amount
            ],
            'machine' => $machine
        ]);
    }

    /**
     * 錢包點數
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public function wallet(): Response
    {
        $player = checkPlayer();
        $walletBalance = WalletService::getBalance($player->id);

        return apiSuccessResponse('success', [
            'walletBalance' => self::formatAmount($walletBalance)
        ]);
    }

    /**
     * 返水資訊
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public function rebate(): Response
    {
        $player = checkPlayer();
        $vipLevel = $player->vipLevel()->first();

        // 如果玩家没有VIP等级，获取所属渠道的最低VIP等级作为默认展示
        if (empty($vipLevel)) {
            $vipLevel = VipLevel::query()
                ->where('department_id', $player->department_id)
                ->orderBy('sort', 'asc')
                ->first();
        }

        $baseWager = $player->player_extend->pending_cashback_amount ?? 0;
        $claimUnit = $vipLevel->min_claim_amount ?? 0;
        $claimable = 0;

        // 未设置最低领取额则不可领取，否则取最低额的整数倍
        if ($claimUnit > 0) {
            $claimable = floor($baseWager / $claimUnit) * $claimUnit;
        }

        return apiSuccessResponse('success', [
            'claimUnit' => $claimUnit,
            'baseWager' => $baseWager,
            'claimable' => self::formatAmount($claimable)
        ]);
    }

    /**
     * 領取返水
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public function rebateClaim(Request $request): Response
    {
        $player = checkPlayer();
        // ---------------------------------------- 參數檢查 ----------------------------------------
        $data = $request->all();
        $validator = Validator::key('amount', Validator::intVal()->setName(trans('amount', [], 'message')));

        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return apiFailResponse(getValidationMessages($e));
        }

        // ---------------------------------------- 冪等性處理 ----------------------------------------
        $requestId = $data['request_id'] ?? null;
        $idempotentResponse = $this->checkIdempotent($requestId, 'rebate-claim:' . $player->id, $player->id);

        if ($idempotentResponse !== null) {
            return $idempotentResponse;
        }

        if (! $this->reserveIdempotent($requestId, 'rebate-claim:' . $player->id, $player->id)) {
            $response = $this->checkIdempotent($requestId, 'rebate-claim:' . $player->id, $player->id);
            return $response ?? apiFailResponse(trans('request_processing', [], 'message'));
        }

        // ---------------------------------------- 流程檢查 ----------------------------------------
        $pendingAmount = $player->player_extend->pending_cashback_amount ?? 0;

        if ($pendingAmount <= 0) {
            $response = apiSuccessResponse('success');
            $this->saveIdempotent($requestId, $response, 'rebate-claim:' . $player->id, $player->id);

            return $response;
        }

        $vipLevel = $player->vipLevel()->first();
        $minAmount = $vipLevel->min_claim_amount ?? 0;

        if ($minAmount <= 0) {
            $this->releaseIdempotent($requestId);
            return apiFailResponse(trans('reverse_water_min_not_set', [], 'message'));
        }

        $claimableAmount = floor($pendingAmount / $minAmount) * $minAmount;

        if ($claimableAmount <= 0) {
            $this->releaseIdempotent($requestId);
            return apiFailResponse(trans('reverse_water_insufficient', [], 'message'));
        }

        if ($data['amount'] != $claimableAmount) {
            $this->releaseIdempotent($requestId);
            return apiFailResponse(trans('reverse_water_different', [], 'message'));
        }

        Db::beginTransaction();

        try {
            // ---------------------------------------- 更新玩家钱包 ----------------------------------------
            $beforeGameAmount = WalletService::getBalance($player->id);
            $incrementResult = WalletService::atomicIncrement($player->id, $claimableAmount);
            $afterGameAmount = $incrementResult['balance'];
            // ---------------------------------------- 寫入賬變記錄 ----------------------------------------
            $playerMoneyEditLog = new PlayerMoneyEditLog;
            $playerMoneyEditLog->player_id = $player->id;
            $playerMoneyEditLog->department_id = $player->department_id;
            $playerMoneyEditLog->type = PlayerMoneyEditLog::TYPE_INCREASE;
            $playerMoneyEditLog->action = PlayerMoneyEditLog::REVERSE_WATER_POOL;
            $playerMoneyEditLog->tradeno = date('YmdHis') . rand(10000, 99999);
            $playerMoneyEditLog->currency = $player->currency;
            $playerMoneyEditLog->money = $claimableAmount;
            $playerMoneyEditLog->inmoney = $claimableAmount;
            $playerMoneyEditLog->remark = '电子游戏反水领取';
            $playerMoneyEditLog->user_id = 0;
            $playerMoneyEditLog->user_name = trans('system_automatic', [], 'message');
            $playerMoneyEditLog->save();
            // ---------------------------------------- 寫入金流明細 ----------------------------------------
            $playerDeliveryRecord = new PlayerDeliveryRecord;
            $playerDeliveryRecord->player_id = $player->id;
            $playerDeliveryRecord->department_id = $player->department_id;
            $playerDeliveryRecord->target = $playerMoneyEditLog->getTable();
            $playerDeliveryRecord->target_id = $playerMoneyEditLog->id;
            $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_REVERSE_WATER_POOL;
            $playerDeliveryRecord->source = 'reverse_water_pool';
            $playerDeliveryRecord->amount = $claimableAmount;
            $playerDeliveryRecord->amount_before = $incrementResult['old'] ?? $beforeGameAmount;
            $playerDeliveryRecord->amount_after = $afterGameAmount;
            $playerDeliveryRecord->tradeno = $playerMoneyEditLog->tradeno;
            $playerDeliveryRecord->remark = '电子游戏反水领取';
            $playerDeliveryRecord->save();
            // ---------------------------------------- 更新返水明細 ----------------------------------------
            // 按明细逐条累加更新状态，只将已领取金额对应的明细标记为已领取
            $details = PlayerReverseWaterDetail::query()
                ->where('player_id', $player->id)
                ->where('status', PlayerReverseWaterDetail::STATUS_UNRECEIVED)
                ->where('is_settled', 1)
                ->where('switch', 1)
                ->orderBy('id')
                ->get();

            $accumulated = 0;

            foreach ($details as $detail) {
                if ($accumulated >= $claimableAmount) {
                    break;
                }

                $accumulated += $detail->reverse_water;
                $detail->update([
                    'status' => PlayerReverseWaterDetail::STATUS_RECEIVED,
                    'receive_time' => Carbon::now()
                ]);
            }

            // 扣减 player_extend 待领取反水金额
            $player->player_extend()->decrement('pending_cashback_amount', $claimableAmount);

            DB::commit();

            $response = apiSuccessResponse('success', [
                'walletBalance' => $afterGameAmount,
                'remaining' => self::formatAmount($pendingAmount - $claimableAmount)
            ]);

            // 保存幂等性记录（覆盖占位）
            if (! empty($requestId)) {
                $this->saveIdempotent($requestId, $response, 'rebate-claim:' . $player->id, $player->id);
            }

            return $response;
        } catch (Exception) {
            DB::rollBack();
            $this->releaseIdempotent($requestId);

            return apiFailResponse(trans('system_error', [], 'message'));
        }
    }

    /**
     * 我的機台清單
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public function machines(): Response
    {
        $player = checkPlayer();

        if (empty($player->channel->status_machine) || empty($player->status_machine)) {
            return jsonFailResponse(trans('platform_no_permission', [], 'message'));
        }

        $machine = Machine::query()
            ->whereHas('machineCategory', function ($query) {
                $query->whereHas('gameType', function ($query) {
                    $query->where('status', 1);
                })->where('status', 1);
            })
            ->where('status', 1)
            ->where('maintaining', 0)
            ->where('gaming_user_id', $player->id)
            ->orderBy('sort')
            ->orderBy('id', 'desc')
            ->get();
        // ---------------------------------------- 批量檢查機台在線狀態 ----------------------------------------
        $onlineMap = [];
        $machineIds = $machine->pluck('id')->toArray();

        try {
            $client = new MachineClient();
            $checkOnline = $client->batchCheckOnline($machineIds);

            if ($checkOnline['success'] && isset($checkOnline['data'])) {
                $onlineMap = $checkOnline['data'];
            }
        } catch (Exception $e) {
            Log::error('Batch check machine online failed', ['error' => $e->getMessage()]);
        }

        // ---------------------------------------- 組裝資料 ----------------------------------------
        $list = [];
        $language = locale() ?? 'zh_TW';
        $language = Str::replace('_', '-', $language);
        $channel = Channel::query()->where('department_id', $player->department_id)->first();

        foreach ($machine as $key => $value) {
            $services = MachineServices::createServices($value, $language);

            // 初始化机台信息数组
            $machineInfo = [];
            $machineInfo['id'] = $value->id;
            $machineInfo['code'] = $value->code;
            $machineInfo['picture_url'] = $value->picture_url;
            $machineInfo['type'] = $value->type;
            $machineInfo['odds_x'] = $value->odds_x;
            $machineInfo['odds_y'] = $value->odds_y;

            if ($value->type == GameType::TYPE_STEEL_BALL) {
                $machineInfo['odds_x'] = $value->machineCategory->name;
                $machineInfo['odds_y'] = '';
            }

            $machineInfo['category_name'] = $value->machineCategory->name;
            $machineInfo['turn_used_point'] = rtrim(rtrim(number_format($value->machineCategory->turn_used_point, 2, '.', ''), '0'), '.');

            $machineInfo['name'] = $value->machineLabel->name ?? '';
            $machineInfo['courtyard'] = $value->machineLabel->courtyard ?? '';
            $machineInfo['correct_rate'] = $value->machineLabel->correct_rate ?? '';

            $machineInfo['reward_status'] = $services->reward_status;
            $machineInfo['bet'] = $services->bet;
            $machineInfo['last_play_time'] = $services->last_play_time;
            $machineInfo['keeping'] = $services->keeping;
            $machineInfo['keep_seconds'] = $services->keep_seconds;

            $playRouteNum = Cache::get('machine_play_route_num', 1);

            switch ($channel->machine_media_line) {
                case 1:
                case 2:
                    $machineInfo['play_route'] = 0;
                    break;

                case 3:
                    $machineInfo['play_route'] = $playRouteNum % 2;
                    Cache::set('machine_play_route_num', $playRouteNum + 1, 24 * 60 * 60);
                    break;
            }

            $machineInfo['online_status'] = $onlineMap[$value->id] ?? 'offline';
            $list[] = $machineInfo;
        }

        return apiSuccessResponse('success', [
            'list' => $list
        ]);
    }

    /**
     * 機台登出（釋放全部)
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public function machinesLogoutAll(Request $request): Response
    {
        $player = checkPlayer();

        $machine = Machine::query()
            ->where('gaming_user_id', $player->id)
            ->orderBy('sort')
            ->orderBy('id', 'desc')
            ->get();

        if ($machine->isEmpty()) {
            return apiFailResponse(trans('machine_no_gaming', [], 'message'));
        }

        // ---------------------------------------- 冪等性處理 ----------------------------------------
        $requestId = $request->input('request_id') ?? null;
        $idempotentResponse = $this->checkIdempotent($requestId, 'machines-logout-all:' . $player->id, $player->id);

        if ($idempotentResponse !== null) {
            return $idempotentResponse;
        }

        if (! $this->reserveIdempotent($requestId, 'machines-logout-all:' . $player->id, $player->id)) {
            $response = $this->checkIdempotent($requestId, 'machines-logout-all:' . $player->id, $player->id);
            return $response ?? apiFailResponse(trans('request_processing', [], 'message'));
        }

        // ---------------------------------------- 機台逐台離線 ----------------------------------------
        $action = 'leave';
        $language = locale() ?? 'zh_TW';
        $language = Str::replace('_', '-', $language);
        $message = '';

        foreach ($machine as $key => $value) {
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
                $this->releaseIdempotent($requestId);
                throw $e;
            }
        }

        if (! empty($message)) {
            $this->releaseIdempotent($requestId);
            return apiFailResponse($message);
        }

        $response = apiSuccessResponse(trans('machine_logout_all', [], 'message'));

        // 保存幂等性记录（覆盖占位）
        if (! empty($requestId)) {
            $this->saveIdempotent($requestId, $response, 'machines-logout-all:' . $player->id, $player->id);
        }

        return $response;
    }

    /**
     * 格式化金额显示（整数不显示小数位）
     * @param float $amount
     * @return float|int
     */
    private function formatAmount(float $amount): float|int
    {
        // 判断是否为整数
        if (floor($amount) == $amount) {
            // 整数：返回整数类型
            return (int)$amount;
        } else {
            // 小数：保留两位小数
            return round($amount, 2);
        }
    }

    /**
     * 获取排除的平台ID列表（真人视讯和体育平台）
     * 这些平台不参与VIP等级升级打码量统计
     * 使用配置文件统一管理平台过滤规则
     *
     * @return array 平台ID数组
     */
    private function getExcludedPlatformIds(): array
    {
        // 从配置文件读取排除的平台代码
        $excludedCodes = config('platform_filter.excluded_platforms', [
            // 默认值（防止配置文件不存在）
            'WM', 'DG', 'SA', 'RSGLIVE', 'MT', 'O8', 'TNINE',
            'KY', 'KYS', 'OB', 'SPS', 'SPS_DY'
        ]);

        // 根据平台代码查询平台ID
        return GamePlatform::query()
            ->whereIn('code', $excludedCodes)
            ->pluck('id')
            ->toArray();
    }
}
