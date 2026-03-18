<?php

namespace app\api\controller\v1;

use app\model\DepositBonusActivity;
use app\service\DepositBonusService;
use app\service\DepositBonusBetService;
use app\service\DepositBonusWithdrawService;
use app\service\DepositBonusQrcodeService;
use Respect\Validation\Validator as v;
use Respect\Validation\Exceptions\AllOfException;
use support\Request;
use support\Response;
use support\exception\BusinessException;
use Webman\RateLimiter\Annotation\RateLimiter;

/**
 * 玩家端充值满赠控制器
 */
class DepositBonusPlayerController
{
    protected $noNeedSign = ['verifyQrcode'];

    /**
     * @var DepositBonusService
     */
    protected $activityService;

    /**
     * @var DepositBonusBetService
     */
    protected $betService;

    /**
     * @var DepositBonusWithdrawService
     */
    protected $withdrawService;

    /**
     * @var DepositBonusQrcodeService
     */
    protected $qrcodeService;

    public function __construct()
    {
        $this->activityService = new DepositBonusService();
        $this->betService = new DepositBonusBetService();
        $this->withdrawService = new DepositBonusWithdrawService();
        $this->qrcodeService = new DepositBonusQrcodeService();
    }

    #[RateLimiter(limit: 20)]
    /**
     * 获取活动列表
     */
    public function getActivityList(Request $request): Response
    {
        try {
            $player = checkPlayer();
            $storeId = $player->department_id ?? 1;

            $activities = DepositBonusActivity::where('store_id', $storeId)
                ->where('status', DepositBonusActivity::STATUS_ENABLED)
                ->whereNull('deleted_at')
                ->where('start_time', '<=', time())
                ->where('end_time', '>=', time())
                ->with(['tiers' => function ($query) {
                    $query->where('status', 1)->orderBy('sort_order', 'asc');
                }])
                ->orderBy('created_at', 'desc')
                ->get();

            $result = [];
            foreach ($activities as $activity) {
                $tiers = [];
                foreach ($activity->tiers as $tier) {
                    $tiers[] = [
                        'tier_id' => $tier->id,
                        'deposit_amount' => $tier->deposit_amount,
                        'bonus_amount' => $tier->bonus_amount,
                        'bonus_ratio' => $tier->bonus_ratio,
                    ];
                }

                $result[] = [
                    'activity_id' => $activity->id,
                    'activity_name' => $activity->activity_name,
                    'description' => $activity->description,
                    'bet_multiple' => $activity->bet_multiple,
                    'valid_days' => $activity->valid_days,
                    'unlock_type' => $activity->unlock_type,
                    'limit_per_player' => $activity->limit_per_player,
                    'limit_period' => $activity->limit_period,
                    'start_time' => $activity->start_time,
                    'end_time' => $activity->end_time,
                    'tiers' => $tiers,
                    'can_participate' => $activity->checkPlayerLimit($player->id),
                ];
            }

            return jsonSuccessResponse('success', [
                'activities' => $result,
                'total' => count($result),
            ]);

        } catch (\Exception $e) {
            return jsonFailResponse('查询失败：' . $e->getMessage());
        }
    }

    #[RateLimiter(limit: 10)]
    /**
     * 核销二维码（玩家扫码）
     */
    public function verifyQrcode(Request $request): Response
    {
        try {
            $player = checkPlayer();
            $data = $request->post();

            // 验证参数
            $validator = v::key('qrcode_token', v::notEmpty()->setName('二维码令牌'));

            try {
                $validator->assert($data);
            } catch (AllOfException $e) {
                return jsonFailResponse(getValidationMessages($e));
            }

            // 核销二维码
            $order = $this->qrcodeService->verifyQrcode($data['qrcode_token'], $player->id);

            return jsonSuccessResponse('核销成功', [
                'order_id' => $order->id,
                'order_no' => $order->order_no,
                'bonus_amount' => $order->bonus_amount,
                'required_bet_amount' => $order->required_bet_amount,
                'expires_at' => $order->expires_at,
                'expires_at_formatted' => date('Y-m-d H:i:s', $order->expires_at),
                'valid_days' => ceil(($order->expires_at - time()) / 86400),
                'message' => "恭喜您获得 ¥{$order->bonus_amount} 赠送金额！",
                'tip' => "请在有效期内完成 ¥{$order->required_bet_amount} 押码量后即可提现",
            ]);

        } catch (BusinessException $e) {
            return jsonFailResponse($e->getMessage());
        } catch (\Exception $e) {
            return jsonFailResponse('核销失败：' . $e->getMessage());
        }
    }

    #[RateLimiter(limit: 20)]
    /**
     * 获取押码量进度
     */
    public function getBetProgress(Request $request): Response
    {
        try {
            $player = checkPlayer();

            $progress = $this->betService->getPlayerBetProgress($player->id);

            return jsonSuccessResponse('success', [
                'has_tasks' => !empty($progress),
                'task_count' => count($progress),
                'tasks' => $progress,
            ]);

        } catch (\Exception $e) {
            return jsonFailResponse('查询失败：' . $e->getMessage());
        }
    }

    #[RateLimiter(limit: 20)]
    /**
     * 获取押码量明细
     */
    public function getBetDetails(Request $request): Response
    {
        try {
            $player = checkPlayer();
            $data = $request->all();

            // 验证参数
            $validator = v::key('order_id', v::intVal()->setName('订单ID'));

            try {
                $validator->assert($data);
            } catch (AllOfException $e) {
                return jsonFailResponse(getValidationMessages($e));
            }

            $filters = [
                'page_size' => $data['page_size'] ?? 20,
            ];

            $details = $this->betService->getBetDetails($data['order_id'], $filters);

            return jsonSuccessResponse('success', $details);

        } catch (\Exception $e) {
            return jsonFailResponse('查询失败：' . $e->getMessage());
        }
    }

    #[RateLimiter(limit: 20)]
    /**
     * 我的活动订单
     */
    public function myOrders(Request $request): Response
    {
        try {
            $player = checkPlayer();
            $data = $request->all();

            $filters = [
                'status' => $data['status'] ?? null,
                'page_size' => $data['page_size'] ?? 20,
            ];

            $orders = $this->activityService->getPlayerOrders($player->id, $filters);

            return jsonSuccessResponse('success', $orders);

        } catch (\Exception $e) {
            return jsonFailResponse('查询失败：' . $e->getMessage());
        }
    }

    #[RateLimiter(limit: 30)]
    /**
     * 检查是否可以提现
     */
    public function checkWithdrawable(Request $request): Response
    {
        try {
            $player = checkPlayer();
            $data = $request->all();

            $withdrawAmount = $data['amount'] ?? 0;

            $check = $this->withdrawService->checkWithdrawable($player->id, $withdrawAmount);

            return jsonSuccessResponse('success', $check);

        } catch (\Exception $e) {
            return jsonFailResponse('查询失败：' . $e->getMessage());
        }
    }

    #[RateLimiter(limit: 30)]
    /**
     * 获取可提现余额信息
     */
    public function getWithdrawableBalance(Request $request): Response
    {
        try {
            $player = checkPlayer();

            $balance = $this->withdrawService->getWithdrawableBalance($player->id);

            return jsonSuccessResponse('success', $balance);

        } catch (\Exception $e) {
            return jsonFailResponse('查询失败：' . $e->getMessage());
        }
    }

    #[RateLimiter(limit: 20)]
    /**
     * 获取首页押码量卡片信息
     */
    public function getHomeBetCard(Request $request): Response
    {
        try {
            $player = checkPlayer();

            // 获取押码量进度
            $progress = $this->betService->getPlayerBetProgress($player->id);

            if (empty($progress)) {
                return jsonSuccessResponse('success', [
                    'show' => false,
                    'data' => null,
                ]);
            }

            // 取第一个任务显示
            $firstTask = $progress[0];

            return jsonSuccessResponse('success', [
                'show' => true,
                'data' => [
                    'title' => '押码量进度',
                    'activity_name' => $firstTask['activity_name'],
                    'current_bet_amount' => $firstTask['current_bet_amount'],
                    'required_bet_amount' => $firstTask['required_bet_amount'],
                    'bet_progress' => $firstTask['bet_progress'],
                    'remaining_days' => $firstTask['remaining_days'],
                    'tip' => "完成后可提现全部余额",
                ],
            ]);

        } catch (\Exception $e) {
            return jsonFailResponse('查询失败：' . $e->getMessage());
        }
    }
}