<?php

namespace app\api\controller\v1;

use app\exception\PlayerCheckException;
use app\model\ActivityContent;
use app\model\GameLottery;
use app\model\GameType;
use app\model\Lottery;
use app\model\Notice;
use app\model\PlayerActivityPhaseRecord;
use app\model\PlayerDeliveryRecord;
use app\model\PlayerLotteryRecord;
use app\model\PlayerPlatformCash;
use app\model\PlayerReverseWaterDetail;
use app\service\GameLotteryServices;
use app\service\LotteryServices;
use Carbon\Carbon;
use Respect\Validation\Exceptions\AllOfException;
use Respect\Validation\Validator as v;
use support\Db;
use support\Request;
use support\Response;
use think\Exception;
use Webman\RateLimiter\Annotation\RateLimiter;

class LotteryController
{
    /** 排除  */
    protected $noNeedSign = [];

    #[RateLimiter(limit: 5)]
    /**
     * 彩金列表（新版：支持独立彩池）
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public function lotteryList(Request $request): Response
    {
        checkPlayer();
        $data = $request->all();
        $validator = v::key('type',
            v::in([GameType::TYPE_SLOT, GameType::TYPE_STEEL_BALL, GameType::TYPE_GAME])->notEmpty()->setName(trans('game_type', [],
                'message')));

        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        $lotteryList = [];
        if ($data['type'] == GameType::TYPE_SLOT || $data['type'] == GameType::TYPE_STEEL_BALL) {
            // 获取彩金列表（新版：每个lottery有独立的amount）
            $lotteryList = Lottery::query()
                ->where('game_type', $data['type'])
                ->whereNull('deleted_at')
                ->where('status', 1)
                ->select([
                    'id',
                    'name',
                    'rate',
                    'lottery_type',
                    'condition',
                    'max_amount',
                    'lottery_times',
                    'amount',  // 新增：独立彩池金额
                    'pool_ratio',  // 新增：入池比值
                    'burst_status',  // 新增：爆彩状态
                    'burst_duration',  // 新增：爆彩时长
                ])
                ->orderBy('sort', 'desc')
                ->get();
            // 获取Redis中的实时金额累积
            try {
                $redis = \support\Redis::connection()->client();
                foreach ($lotteryList as &$lottery) {
                    $redisKey = LotteryServices::REDIS_KEY_LOTTERY_AMOUNT . $lottery->id;
                    $redisAmount = $redis->get($redisKey);

                    // 如果 Redis 中有累积金额，加到数据库金额上
                    if ($redisAmount !== false && $redisAmount > 0) {
                        $lottery->amount = bcadd($lottery->amount, $redisAmount, 4);
                    }
                }
            } catch (\Exception $e) {
                // Redis 读取失败时，降级使用数据库金额
                \support\Log::warning('从 Redis 获取彩金实时金额失败', ['error' => $e->getMessage()]);
            }
        }
        if ($data['type'] == GameType::TYPE_GAME) {
            $lotteryList = GameLottery::query()
                ->whereNull('deleted_at')
                ->where('status', 1)
                ->select([
                    'id',
                    'name',
                    'rate',
                    'lottery_type',
                    'max_amount',
                    'lottery_times',
                    'amount',  // 新增：独立彩池金额
                    'pool_ratio',  // 新增：入池比值
                    'burst_status',  // 新增：爆彩状态
                    'burst_duration',  // 新增：爆彩时长
                ])
                ->orderBy('sort', 'desc')
                ->get();
            // 性能优化：从 Redis 获取实时累积金额（如果存在）
            try {
                $redis = \support\Redis::connection()->client();
                foreach ($lotteryList as &$item) {
                    $redisKey = GameLotteryServices::REDIS_KEY_LOTTERY_AMOUNT . $item['id'];
                    $redisAmount = $redis->get($redisKey);

                    // 如果 Redis 中有累积金额，加到数据库金额上
                    if ($redisAmount !== false && $redisAmount > 0) {
                        $item['amount'] = bcadd($item['amount'], $redisAmount, 4);
                    }
                }
            } catch (\Exception $e) {
                // Redis 读取失败时，降级使用数据库金额
                \support\Log::error('从 Redis 获取彩金实时金额失败', ['error' => $e->getMessage()]);
            }
        }

        // 新客户端应该使用lottery_list中的amount字段
        return jsonSuccessResponse('success', [
            'lottery_list' => $lotteryList
        ]);
    }

    #[RateLimiter(limit: 5)]
    /**
     * 彩金领取列表
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public function lotteryRecordList(Request $request): Response
    {
        checkPlayer();
        $data = $request->all();
        $validator = v::key('type',
            v::in([GameType::TYPE_SLOT, GameType::TYPE_STEEL_BALL, GameType::TYPE_GAME])->notEmpty()->setName(trans('game_type', [],
                'message')))
            ->key('id', v::intVal()->setName(trans('id', [], 'message')), false)
            ->key('page', v::intVal()->setName(trans('page', [], 'message')), false)
            ->key('size', v::intVal()->setName(trans('size', [], 'message')), false);

        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        $recordList = PlayerLotteryRecord::query()
            ->when($data['type'] == GameType::TYPE_SLOT || $data['type'] == GameType::TYPE_STEEL_BALL, function ($query) use ($data) {
                $query->where('game_type', $data['type']);
            })
            ->when($data['type'] == GameType::TYPE_GAME, function ($query) use ($data) {
                $query->where('source', PlayerLotteryRecord::SOURCE_GAME);
            })
            ->where('status', PlayerLotteryRecord::STATUS_COMPLETE)
            ->when(!empty($data['id']), function ($query) use ($data) {
                $query->where('lottery_id', $data['id']);
            })
            ->select([
                'id',
                'player_name',
                'lottery_name',
                'amount',
                'machine_code',
                'machine_name',
                'created_at',
                'uuid',
                'machine_id'
            ])
            ->orderBy('lottery_type', 'asc')
            ->orderBy('id', 'desc')
            ->forPage($data['page'] ?? 1, $data['size'] ?? 20)
            ->get();
        $list = [];
        /** @var PlayerLotteryRecord $item */
        foreach ($recordList as $item) {
            $list[] = [
                'id' => $item->id,
                'player_name' => $item->player_name,
                'lottery_name' => $item->lottery_name,
                'amount' => $item->amount,
                'created_at' => date('Y-m-d H:i:s', strtotime($item->created_at)),
                'uuid' => $item->uuid,
            ];
        }
        return jsonSuccessResponse('success', [
            'lottery_list' => Lottery::query()
                ->where('game_type', $data['type'])
                ->whereNull('deleted_at')
                ->where('status', 1)
                ->select(['id', 'name'])
                ->orderBy('sort', 'desc')
                ->get(),
            'lottery_record_list' => $list
        ]);
    }

    #[RateLimiter(limit: 5)]
    /**
     * 领取彩金
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public function receiveLottery(Request $request): Response
    {
        $player = checkPlayer();
        $data = $request->all();
        $validator = v::key('id', v::intVal()->setName(trans('player_lottery_record_id', [], 'message')));

        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }

        /** @var PlayerLotteryRecord $playerLotteryRecord */
        $playerLotteryRecord = PlayerLotteryRecord::where('player_id', $player->id)->where('id', $data['id'])->first();
        if (empty($playerLotteryRecord)) {
            return jsonFailResponse(trans('player_lottery_record_not_found', [], 'message'));
        }
        if ($playerLotteryRecord->status == PlayerLotteryRecord::STATUS_REJECT) {
            return jsonFailResponse(trans('player_lottery_record_reject', [], 'message'));
        }
        if ($playerLotteryRecord->status == PlayerLotteryRecord::STATUS_UNREVIEWED) {
            return jsonFailResponse(trans('player_activity_phase_record_unreviewed', [], 'message'));
        }
        if ($playerLotteryRecord->status == PlayerLotteryRecord::STATUS_COMPLETE) {
            return jsonFailResponse(trans('player_activity_phase_record_complete', [], 'message'));
        }

        DB::beginTransaction();
        try {
            if ($playerLotteryRecord->source == PlayerLotteryRecord::SOURCE_MACHINE) {
                /** @var lottery $lottery */
                $lottery = Lottery::query()->find($playerLotteryRecord->lottery_id);
            }
            if ($playerLotteryRecord->source == PlayerLotteryRecord::SOURCE_GAME) {
                /** @var GameLottery $lottery */
                $lottery = \app\model\GameLottery::query()->find($playerLotteryRecord->lottery_id);
            }
            if (empty($lottery)) {
                throw new Exception(trans('player_lottery_record_not_found', [], 'message'));
            }
            // 玩家钱包加款（使用 Lua 原子操作，Redis 作为唯一实时标准）
            $beforeGameAmount = \app\service\WalletService::getBalance($player->id, 1);
            $afterGameAmount = \app\service\WalletService::add($player->id, $playerLotteryRecord->amount, 1);

            // 寫入金流明細
            $playerDeliveryRecord = new PlayerDeliveryRecord;
            $playerDeliveryRecord->player_id = $player->id;
            $playerDeliveryRecord->department_id = $player->department_id;
            $playerDeliveryRecord->target = $playerLotteryRecord->getTable();
            $playerDeliveryRecord->target_id = $playerLotteryRecord->id;
            $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_LOTTERY;
            $playerDeliveryRecord->source = 'lottery';
            $playerDeliveryRecord->amount = $playerLotteryRecord->amount;
            $playerDeliveryRecord->amount_before = $beforeGameAmount;
            $playerDeliveryRecord->amount_after = $afterGameAmount;
            $playerDeliveryRecord->tradeno = '';
            $playerDeliveryRecord->remark = '';
            $playerDeliveryRecord->save();
            $playerLotteryRecord->status = PlayerLotteryRecord::STATUS_COMPLETE;
            $playerLotteryRecord->save();

            $lottery->last_player_id = $player->id;
            $lottery->last_player_name = $player->name;
            $lottery->last_award_amount = $playerLotteryRecord->amount;
            $lottery->lottery_times = $lottery->lottery_times +1;
            $lottery->save();
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return jsonFailResponse(trans('system_error', [], 'message'));
        }

        return jsonSuccessResponse('success');
    }

    #[RateLimiter(limit: 5)]
    /**
     * 一键领取彩金
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public function receiveAllLottery(): Response
    {
        $player = checkPlayer();

        $playerLotteryRecordList = PlayerLotteryRecord::query()
            ->where('player_id', $player->id)
            ->where('status', PlayerLotteryRecord::STATUS_PASS)
            ->get();
        $playerActivityPhaseRecordList = PlayerActivityPhaseRecord::query()
            ->where('player_id', $player->id)
            ->where('status', PlayerActivityPhaseRecord::STATUS_UNRECEIVED)
            ->get();
        $playerReverseWater = PlayerReverseWaterDetail::query()
            ->where('player_id',$player->id)
            ->where('status',PlayerReverseWaterDetail::STATUS_UNRECEIVED)
            ->where('is_settled', 1)
            ->where('switch', 1)
            ->get();

        if (empty($playerLotteryRecordList->toArray()) && empty($playerActivityPhaseRecordList->toArray()) && empty($playerReverseWater->toArray())) {
            return jsonFailResponse(trans('player_lottery_record_not_found', [], 'message'));
        }

        $now = Carbon::now();
        DB::beginTransaction();
        try {
            // 玩家钱包扣减
            /** @var PlayerPlatformCash $machineWallet */
            $machineWallet = PlayerPlatformCash::query()->where('platform_id',
                PlayerPlatformCash::PLATFORM_SELF)->where('player_id', $player->id)->lockForUpdate()->first();
            /** @var PlayerLotteryRecord $playerLotteryRecord */
            foreach ($playerLotteryRecordList as $playerLotteryRecord) {
                if ($playerLotteryRecord->source == PlayerLotteryRecord::SOURCE_MACHINE) {
                    /** @var lottery $lottery */
                    $lottery = Lottery::query()->find($playerLotteryRecord->lottery_id);
                }
                if ($playerLotteryRecord->source == PlayerLotteryRecord::SOURCE_GAME) {
                    /** @var GameLottery $lottery */
                    $lottery = \app\model\GameLottery::query()->find($playerLotteryRecord->lottery_id);
                }
                if (empty($lottery)) {
                    continue;
                }
                // 更新玩家钱包（使用 Lua 原子操作，Redis 作为唯一实时标准）
                $beforeGameAmount = \app\service\WalletService::getBalance($player->id, 1);
                $afterGameAmount = \app\service\WalletService::add($player->id, $playerLotteryRecord->amount, 1);

                // 寫入金流明細
                $playerDeliveryRecord = new PlayerDeliveryRecord;
                $playerDeliveryRecord->player_id = $player->id;
                $playerDeliveryRecord->department_id = $player->department_id;
                $playerDeliveryRecord->target = $playerLotteryRecord->getTable();
                $playerDeliveryRecord->target_id = $playerLotteryRecord->id;
                $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_LOTTERY;
                $playerDeliveryRecord->source = 'lottery';
                $playerDeliveryRecord->amount = $playerLotteryRecord->amount;
                $playerDeliveryRecord->amount_before = $beforeGameAmount;
                $playerDeliveryRecord->amount_after = $afterGameAmount;
                $playerDeliveryRecord->tradeno = '';
                $playerDeliveryRecord->remark = '';
                $playerDeliveryRecord->save();
                $playerLotteryRecord->status = PlayerLotteryRecord::STATUS_COMPLETE;
                $playerLotteryRecord->save();
                $lottery->last_player_id = $player->id;
                $lottery->last_player_name = $player->name;
                $lottery->last_award_amount = $playerLotteryRecord->amount;
                $lottery->lottery_times = $lottery->lottery_times +1;
                $lottery->save();
            }

            /** @var PlayerLotteryRecord $playerActivityPhaseRecord */
            foreach ($playerActivityPhaseRecordList as $playerActivityPhaseRecord) {
                $playerActivityPhaseRecord->status = PlayerActivityPhaseRecord::STATUS_RECEIVED;
                $playerActivityPhaseRecord->save();
                /** @var ActivityContent $activityContent */
                $activityContent = $playerActivityPhaseRecord->activity->activity_content()
                    ->where('lang', $playerActivityPhaseRecord->player->channel->lang)
                    ->first();
                $content = '活動獎勵待稽核，玩家' . (empty($playerActivityPhaseRecord->player->name) ? $playerActivityPhaseRecord->player->name : $playerActivityPhaseRecord->player->phone);
                $content .= ', 在機台: ' . $playerActivityPhaseRecord->machine->code;
                $content .= ' 達成活動: ' . ($activityContent->name ? $activityContent->name : '') . '的獎勵要求';
                $content .= ' 獎勵遊戲點: ' . $playerActivityPhaseRecord->bonus . '.';
                $notice = new Notice();
                $notice->department_id = $playerActivityPhaseRecord->player->department_id;
                $notice->player_id = $playerActivityPhaseRecord->player_id;
                $notice->source_id = $playerActivityPhaseRecord->id;
                $notice->type = Notice::TYPE_EXAMINE_ACTIVITY;
                $notice->receiver = Notice::RECEIVER_ADMIN;
                $notice->is_private = 0;
                $notice->title = '活動獎勵待稽核';
                $notice->content = $content;
                $notice->save();

                // 发送总站领取消息
                sendSocketMessage('private-admin_group-admin-1', [
                    'msg_type' => 'player_examine_activity_bonus',
                    'id' => $playerActivityPhaseRecord->id,
                    'player_id' => $player->id,
                ]);
                // 发送子站领取消息
                sendSocketMessage('private-admin_group-channel-' . $player->department_id, [
                    'msg_type' => 'player_examine_activity_bonus',
                    'id' => $playerActivityPhaseRecord->id,
                    'player_id' => $player->id,
                ]);
            }

            // 处理电子游戏反水一键领取

            /** @var PlayerReverseWaterDetail $detail */
            foreach($playerReverseWater as $detail){
                // 更新玩家钱包（使用 Lua 原子操作，Redis 作为唯一实时标准）
                $beforeGameAmount = \app\service\WalletService::getBalance($player->id, 1);
                $afterGameAmount = \app\service\WalletService::add($player->id, $detail->reverse_water, 1);

                // 寫入金流明細
                $playerDeliveryRecord = new PlayerDeliveryRecord;
                $playerDeliveryRecord->player_id = $player->id;
                $playerDeliveryRecord->department_id = $player->department_id;
                $playerDeliveryRecord->target = $detail->getTable();
                $playerDeliveryRecord->target_id = $detail->id;
                $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_REVERSE_WATER;
                $playerDeliveryRecord->source = 'reverse_water';
                $playerDeliveryRecord->amount = $detail->reverse_water;
                $playerDeliveryRecord->amount_before = $beforeGameAmount;
                $playerDeliveryRecord->amount_after = $afterGameAmount;
                $playerDeliveryRecord->tradeno = '';
                $playerDeliveryRecord->remark = '';
                $playerDeliveryRecord->save();
                
                $detail->status = PlayerReverseWaterDetail::STATUS_RECEIVED;
                $detail->receive_time = $now;
                $detail->save();
            }
            
            $machineWallet->save();
            DB::commit();
        } catch (\Exception) {
            DB::rollBack();
            return jsonFailResponse(trans('system_error', [], 'message'));
        }

        return jsonSuccessResponse('success');
    }
}
