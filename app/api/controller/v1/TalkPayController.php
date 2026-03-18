<?php

namespace app\api\controller\v1;

use app\model\PlayerRechargeRecord;
use support\Log;
use support\Request;
use support\Response;
use Webman\RateLimiter\Annotation\RateLimiter;

class TalkPayController
{
    /** 排除验签 */
    protected $noNeedSign = ['talkPayNotify'];

    protected $whiteIp = ['47.242.235.9', '210.16.104.14', '210.16.104.13', '8.217.109.12', '8.217.185.166'];
    
    #[RateLimiter(limit: 5)]
    /**
     * Q-talk支付回调
     * @param Request $request
     * @return Response
     */
    public function talkPayNotify(Request $request): Response
    {
        $ip = request()->getRealIp();
        Log::error('支付回调-回调数据', [$request->post()]);
        if (!$this->checkIp($ip)) {
            Log::error('支付回调-ip验证不通过-' . $ip);
            return response('fail', 400);
        }
        $data = $request->post();
        Log::error('支付回调-回调数据', [$data]);
        if (empty($data['oauthChargeId']) || empty($data['oauthAppChargeId']) || empty($data['status']) || empty($data['amount'])) {
            return response('fail', 400);
        }
        /** @var PlayerRechargeRecord $recharge */
        $recharge = PlayerRechargeRecord::where([
            ['status', PlayerRechargeRecord::STATUS_RECHARGING],
            ['talk_tradeno', $data['oauthChargeId']],
            ['tradeno', $data['oauthAppChargeId']],
            ['money', $data['amount'] / 100] // Q-talk充值金额需要除100
        ])->first();
        if (empty($recharge)) {
            Log::error('支付回调-充值订单不存在');
            return response('fail', 400);
        }
        if ($data['status'] == 1) {
            // 充值成功
            if (!talkPaySuccess($recharge)) {
                Log::error('支付回调-充值订单状态已改');
                return response('fail', 400);
            }
        } else {
            // 充值失败
            $recharge->status = PlayerRechargeRecord::STATUS_RECHARGED_FAIL;
            $recharge->finish_time = date('Y-m-d H:i:s');
            $recharge->save();
        }

        return response('success');
    }

    /**
     * 验证服务器白名单
     * @param string $ip
     * @return bool
     */
    private function checkIp(string $ip): bool
    {
        if (empty($ip) || !in_array($ip, $this->whiteIp)) {
            return false;
        }

        return true;
    }
}
