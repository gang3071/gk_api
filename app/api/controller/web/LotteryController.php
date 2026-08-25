<?php

namespace app\api\controller\web;

use app\exception\PlayerCheckException;
use app\model\GameLottery;
use app\model\GameType;
use app\model\Lottery;
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
}
