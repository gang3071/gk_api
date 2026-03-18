<?php

namespace app\api\controller\v1;

use app\model\ChannelRechargeMethod;
use app\model\PlayerBank;
use app\exception\PaymentException;
use app\exception\PlayerCheckException;
use app\service\payment\GBpayService;
use Exception;
use Respect\Validation\Exceptions\AllOfException;
use Respect\Validation\Validator as v;
use support\Request;
use support\Response;
use Webman\RateLimiter\Annotation\RateLimiter;

class PaymentController
{
    /** 排除验签 */
    protected $noNeedSign = [];
    
    #[RateLimiter(limit: 5)]
    /**
     * 检查用户绑定情况
     * @return Response
     * @throws Exception
     */
    public function checkBinding(): Response
    {
        $player = checkPlayer();
        try {
            $res = (new GBpayService($player))->checkBinding();
        } catch (Exception $e) {
            return jsonFailResponse($e->getMessage());
        }
        return jsonSuccessResponse('success', ['is_bound' => $res['isBound']]);
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 玩家快速绑定购宝钱包
     * @return Response
     * @throws Exception
     */
    public function fastBind(): Response
    {
        $player = checkPlayer();
        try {
            $res = (new GBpayService($player))->fastBind();
        } catch (Exception) {
            return jsonFailResponse(trans('machine_action_fail', [], 'message'));
        }
        return jsonSuccessResponse('success', ['url' => $res['msg']]);
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 玩家快速解绑定购宝钱包
     * @return Response
     * @throws Exception
     */
    public function unBind(): Response
    {
        $player = checkPlayer();
        try {
            (new GBpayService($player))->unBind();
        } catch (Exception) {
            return jsonFailResponse(trans('machine_action_fail', [], 'message'));
        }
        return jsonSuccessResponse('success');
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 检查用户授权情况
     * @return Response
     * @throws Exception
     */
    public function checkVerify(): Response
    {
        $player = checkPlayer();
        try {
            $res = (new GBpayService($player))->checkVerify();
        } catch (Exception) {
            return jsonFailResponse(trans('machine_action_fail', [], 'message'));
        }
        
        return jsonSuccessResponse('success', [
            'is_verify' => $res['isVerify'],
            'player_bank' => PlayerBank::query()->where('player_id', $player->id)->where('type',
                ChannelRechargeMethod::TYPE_GB)->first()
        ]);
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 发起授权
     * @return Response
     * @throws Exception
     */
    public function verifyUser(): Response
    {
        $player = checkPlayer();
        try {
            (new GBpayService($player))->verifyUser('Y');
        } catch (Exception $e) {
            if ($e->getCode() == '10787') {
                return jsonFailResponse(trans('gb_phone_not_setting', [], 'message'));
            }
            return jsonFailResponse(trans('machine_action_fail', [], 'message'));
        }
        
        return jsonSuccessResponse('success', []);
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 用户授权/解除授权-验证
     * @param Request $request
     * @return Response
     * @throws PaymentException
     * @throws PlayerCheckException
     * @throws \think\Exception
     */
    public function verifyCode(Request $request): Response
    {
        $player = checkPlayer();
        $data = $request->all();
        $validator = v::key('code', v::stringType()->notEmpty()->setName(trans('phone_code', [], 'message')));
        
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        try {
            (new GBpayService($player))->verifyCode('Y', $data['code']);
        } catch (Exception $e) {
            if ($e->getCode() == '10119') {
                return jsonFailResponse(trans('gb_phone_code_invalid', [], 'message'));
            }
            return jsonFailResponse(trans('machine_action_fail', [], 'message'));
        }
        
        return jsonSuccessResponse('success');
    }
}
