<?php

namespace app\api\controller\web;

use app\exception\PlayerCheckException;
use app\model\GameLottery;
use app\model\GameType;
use app\model\Lottery;
use app\model\PlayerLotteryRecord;
use app\service\GameLotteryServices;
use app\service\LotteryServices;
use Exception;
use Respect\Validation\Exceptions\AllOfException;
use Respect\Validation\Validator;
use support\Log;
use support\Redis;
use support\Request;
use support\Response;
use Webman\RateLimiter\Annotation\RateLimiter;

class LotteryController
{
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
        $list = [];
        // ---------------------------------------- 參數驗證 ----------------------------------------
        $data = $request->all();
        $validator = Validator::key('type', Validator::in([GameType::TYPE_SLOT, GameType::TYPE_STEEL_BALL, GameType::TYPE_GAME])
            ->notEmpty()
            ->setName(trans('game_type', [], 'message')));

        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return apiFailResponse(getValidationMessages($e));
        }

        // ---------------------------------------- 鋼珠 & 斯洛 ----------------------------------------
        if (in_array($data['type'], [GameType::TYPE_SLOT, GameType::TYPE_STEEL_BALL])) {
            $list = Lottery::query()
                ->select([
                    'id', 'name', 'lottery_type', 'condition', 'lottery_times', 'max_amount', 'amount',
                    'pool_ratio', 'burst_status', 'burst_duration', 'rate'
                ])
                ->where('status', 1)
                ->where('game_type', $data['type'])
                ->whereNull('deleted_at')
                ->orderBy('sort', 'desc')
                ->get();

            // 从 Redis 获取实时累积金额（如果存在）
            try {
                $redis = Redis::connection()->client();

                foreach ($list as &$value) {
                    $redisKey = LotteryServices::REDIS_KEY_LOTTERY_AMOUNT . $value->id;
                    $redisAmount = $redis->get($redisKey);

                    // 如果 Redis 中有累积金额，加到数据库金额上
                    if ($redisAmount !== false && $redisAmount > 0) {
                        $value->amount = bcadd($value->amount, $redisAmount, 4);
                    }
                }
            } catch (Exception $e) {
                Log::error('从 Redis 获取彩金实时金额失败', ['error' => $e->getMessage()]);
            }
        }

        // ---------------------------------------- 電子遊戲 ----------------------------------------
        if ($data['type'] == GameType::TYPE_GAME) {
            $list = GameLottery::query()
                ->select([
                    'id', 'name', 'rate', 'lottery_type', 'lottery_times', 'amount', 'max_amount',
                    'burst_status', 'burst_duration', 'pool_ratio'
                ])
                ->where('status', 1)
                ->whereNull('deleted_at')
                ->orderBy('sort', 'desc')
                ->get();

            // 从 Redis 获取实时累积金额（如果存在）
            try {
                $redis = Redis::connection()->client();

                foreach ($list as &$value) {
                    $redisKey = GameLotteryServices::REDIS_KEY_LOTTERY_AMOUNT . $value->id;
                    $redisAmount = $redis->get($redisKey);

                    // 如果 Redis 中有累积金额，加到数据库金额上
                    if ($redisAmount !== false && $redisAmount > 0) {
                        $value->amount = bcadd($value->amount, $redisAmount, 4);
                    }
                }
            } catch (Exception $e) {
                Log::error('从 Redis 获取彩金实时金额失败', ['error' => $e->getMessage()]);
            }
        }

        return apiSuccessResponse('success', [
            'list' => $list
        ]);
    }

    #[RateLimiter(limit: 5)]
    /**
     * 彩金中獎記錄
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public function lotteryRecordList(Request $request): Response
    {
        checkPlayer();
        $data = $request->all();
        $validator = Validator::key('type',
            Validator::in([GameType::TYPE_SLOT, GameType::TYPE_STEEL_BALL, GameType::TYPE_GAME])->notEmpty()->setName(trans('game_type', [], 'message')))
            ->key('id', Validator::intVal()->setName(trans('id', [], 'message')), false)
            ->key('page', Validator::intVal()->setName(trans('page', [], 'message')), false)
            ->key('size', Validator::intVal()->setName(trans('size', [], 'message')), false);

        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return apiFailResponse(getValidationMessages($e));
        }

        $recordList = PlayerLotteryRecord::query()
            ->with(['player:id,store_admin_id', 'player.storeAdmin:id,nickname'])
            ->when($data['type'] == GameType::TYPE_SLOT || $data['type'] == GameType::TYPE_STEEL_BALL, function ($query) use ($data) {
                $query->where('game_type', $data['type']);
            })
            ->when($data['type'] == GameType::TYPE_GAME, function ($query) use ($data) {
                $query->whereIn('source', [PlayerLotteryRecord::SOURCE_GAME, PlayerLotteryRecord::SOURCE_MANUAL]);
            })
            ->where('status', PlayerLotteryRecord::STATUS_COMPLETE)
            ->when(!empty($data['id']), function ($query) use ($data) {
                $query->where('lottery_id', $data['id']);
            })
            ->select([
                'id',
                'player_id',
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
            $storeName = $item->player?->storeAdmin?->nickname ?? '';
            $playerNameWithStore = $item->player_name . ($storeName ? '/' . $storeName : '');

            $list[] = [
                'id' => $item->id,
                'player_name' => $playerNameWithStore,
                'lottery_name' => $item->lottery_name,
                'amount' => $item->amount,
                'created_at' => date('Y-m-d H:i:s', strtotime($item->created_at)),
                'uuid' => $item->uuid,
            ];
        }

        if ($data['type'] == GameType::TYPE_GAME) {
            $lotteryNameList = GameLottery::query()
                ->whereNull('deleted_at')
                ->where('status', 1)
                ->select(['id', 'name'])
                ->orderBy('sort', 'desc')
                ->get();
        } else {
            $lotteryNameList = Lottery::query()
                ->where('game_type', $data['type'])
                ->whereNull('deleted_at')
                ->where('status', 1)
                ->select(['id', 'name'])
                ->orderBy('sort', 'desc')
                ->get();
        }

        return apiSuccessResponse('success', [
            'lottery_list' => $lotteryNameList,
            'lottery_record_list' => $list
        ]);
    }
}
