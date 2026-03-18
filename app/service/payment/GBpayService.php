<?php

namespace app\service\payment;

use app\model\Channel;
use app\model\ChannelRechargeMethod;
use app\model\ChannelRechargeSetting;
use app\model\Player;
use app\exception\PaymentException;
use Exception;
use support\Cache;
use support\Log;
use WebmanTech\LaravelHttpClient\Facades\Http;

class GBpayService
{
    public string $method = 'POST';
    
    public string $successCode = '0';
    
    public array $failCode = [
        '0' => '成功',
        '1' => '参数不足或参数不正确',
        '2' => '订单号错误',
        '3' => '其他错误',
        '4' => '用户未绑定',
        '5' => '呼叫端 IP 地址不在白名单内',
        '6' => '系统错误',
        '7' => '交易失败',
        '8' => '请求已过期',
        '9' => '请求时间来自未来',
        '10' => '钱包余额不足',
        '11' => '用户未授权',
        '12' => '用户已授权',
        '13' => '已达发送次数上限',
        '14' => '已达验证次数上限',
        '997' => 'Token 取得失败',
        '998' => '系统维护',
        '999' => 'checksum 验证失败',
        '10117' => '发送 SMS 简讯失败',
        '10121' => '发送简讯 OTP 间隔时间未满',
        '10787' => '会员手机号码未设置',
        '10433' => '钱包余额不足',
        '10119' => '查詢簡訊 OTP 失敗',
        '10310' => '购宝交易密码错误',
    ];
    public ?Player $player;
    public ?Channel $channel;
    public string $secret;
    public string $token;
    public string $api_domain;
    public ?\Monolog\Logger $log = null;
    private array $api = [
        'getAccessToken' => '/Auth/getAccessToken', // 取得通行令牌
        'fastBind' => '/Auth/FastBind', // 取得快速用户绑定链接
        'fastTrans' => '/Auth/FastTrans', // 取得快速充值连结
        'tranInfo' => '/Auth/TranInfo', // 取得交易订单信息
        'withdraw' => '/Auth/Withdraw', // 用户提款
        'withdrawInfo' => '/Auth/WithdrawInfo', // 取得用户提款信息
        'balance' => '/Auth/Balance', // 取得钱包余额
        'unBind' => '/Auth/Unbind', // 解除用户绑定
        'memberWalletInfo' => '/Auth/MemberWalletInfo', // 取得购宝帐户信息
        'checkBinding' => '/Auth/CheckBinding', // 检查用户绑定
        'verifyUser' => '/Auth/VerifyUser', // 用户授权/解除授权
        'verifyCode' => '/Auth/VerifyCode', // 用户授权/解除授权-验证
        'fastDeposi' => '/Auth/FastDeposi', // 免扫码充值
        'checkVerify' => '/Auth/CheckVerify', // 检查用户授權狀態
        'fastDeposit' => '/Auth/FastDeposit', // 快速充值
    ];
    private array $lang = [
        'zh-CN' => 'zh-cn',
        'zh-TW' => 'zh-tw',
        'jp' => 'jp',
        'en' => 'en',
    ];
    
    /**
     * @param Player|null $player
     * @throws Exception
     */
    public function __construct(Player $player = null)
    {
        $this->channel = Channel::query()->where('department_id', $player->department_id)->first();
        /** @var ChannelRechargeSetting $setting */
        $setting = ChannelRechargeSetting::query()->where('department_id', $player->department_id)->where('type',
            ChannelRechargeMethod::TYPE_GB)->first();
        if (empty($setting) || empty($setting->gb_token) || empty($setting->gb_secret)) {
            throw new PaymentException(trans('gb_payment_not_setting', [], 'message'));
        }
        $this->api_domain = config('payment.GB.api_domain');
        if (empty($this->api_domain)) {
            throw new PaymentException(trans('gb_payment_not_setting', [], 'message'));
        }
        $this->player = $player;
        $this->secret = $setting->gb_secret;
        $this->token = $setting->gb_token;
        $this->log = Log::channel('gb_pay_server');
    }
    
    /**
     * @return array|mixed
     * @throws PaymentException
     */
    private function getAccessToken(): mixed
    {
        $key = 'gb_token';
        $token = Cache::get($key);
        if (!empty($token)) {
            return $token;
        }
        $params = [
            'token' => $this->token,
            'time' => time(),
        ];
        try {
            $res = $this->doCurl($this->createUrl('getAccessToken'), $params, false);
        } catch (PaymentException $e) {
            throw new PaymentException($e->getMessage());
        }
        Cache::set($key, $res['token'], 60 * 3);
        
        return $res['token'] ?? '';
    }
    
    /**
     * 组装请求
     * @param string $url
     * @param array $params
     * @param bool $auth
     * @return array|mixed
     * @throws PaymentException
     * @throws Exception
     */
    private function doCurl(string $url, array $params = [], bool $auth = true): mixed
    {
        $headers = [];
        $params['checksum'] = $this->createChecksum($params);
        if ($auth) {
            $headers['Auth'] = $this->getAccessToken();
        }
        $response = Http::timeout(7)
            ->withHeaders($headers)
            ->asForm()
            ->post($url, $params);
        $this->log->info('doCurl -> 请求结果', [$url, $response]);
        if (!$response->ok()) {
            throw new PaymentException(trans('system_busy', [], 'message'));
        }
        
        $data = $response->json();
        if ($data['errorCode'] != $this->successCode) {
            if ($data['errorCode'] == '998') {
                throw new PaymentException(trans('gb_maintenance',
                    ['{startAt}' => $data['startAt'], '{endAt}' => $data['endAt']], 'message'));
            }
            if ($data['errorCode'] == '10119') {
                throw new Exception(trans('gb_phone_code_invalid', [], 'message'), '10119');
            }
            if ($data['errorCode'] == '10787') {
                throw new Exception(trans('gb_phone_not_setting', [], 'message'), '10787');
            }
            throw new PaymentException($this->failCode[$data['errorCode']]);
        }
        return $data;
    }
    
    /**
     * @param array $param
     * @return string
     */
    private function createChecksum(array $param): string
    {
        ksort($param);
        $filteredParam = array_filter($param, function ($value) {
            return !is_null($value);
        });
        $checksum = implode('', $filteredParam);
        return hash('sha256', $checksum . $this->secret);
    }
    
    /**
     * 生成请求url
     * @param $method
     * @return string
     */
    private function createUrl($method): string
    {
        return $this->api_domain . $this->api[$method];
    }
    
    /**
     * 生成绑定链接
     * @return array|mixed
     * @throws PaymentException
     */
    public function fastBind(): mixed
    {
        $params = [
            'mid' => $this->player->id,
            'callback' => config('app.api_domain') . 'callback-fast-bind',
            'redirect_url' => 'Y',
        ];
        $this->log->info('fastBind -> 请求数据', [$params]);
        try {
            $res = $this->doCurl($this->createUrl('fastBind'), $params);
        } catch (PaymentException $e) {
            throw new PaymentException($e->getMessage());
        }
        
        return $res;
    }
    
    /**
     * 取得交易订单信息
     * @return array|mixed
     * @throws PaymentException
     */
    public function tranInfo($order_no): mixed
    {
        $params = [
            'order_no' => $order_no,
        ];
        try {
            $res = $this->doCurl($this->createUrl('tranInfo'), $params);
        } catch (PaymentException $e) {
            throw new PaymentException($e->getMessage());
        }
        
        return $res;
    }
    
    /**
     * 用户提款
     * @return array|mixed
     * @throws PaymentException
     */
    public function withdraw($order_no, $amount): mixed
    {
        $params = [
            'mid' => $this->player->id,
            'order_no' => $order_no,
            'amount' => $amount,
            'callback' => config('app.api_domain') . 'callback-withdraw',
        ];
        try {
            $res = $this->doCurl($this->createUrl('withdraw'), $params);
        } catch (PaymentException $e) {
            throw new PaymentException($e->getMessage());
        }
        
        return $res;
    }
    
    /**
     * 查看贵司钱包余额
     * 测试环境：https://{TEST_API_HOST}/Auth/Balance
     * @return array|mixed
     * @throws PaymentException
     */
    public function balance(): mixed
    {
        try {
            $res = $this->doCurl($this->createUrl('balance'));
        } catch (PaymentException $e) {
            throw new PaymentException($e->getMessage());
        }
        
        return $res;
    }
    
    /**
     * 解除用户绑定
     * @return array|mixed
     * @throws PaymentException
     */
    public function unBind(): mixed
    {
        $params = [
            'mid' => $this->player->id,
        ];
        try {
            $res = $this->doCurl($this->createUrl('unBind'), $params);
        } catch (PaymentException $e) {
            throw new PaymentException($e->getMessage());
        }
        
        return $res;
    }
    
    /**
     * 取得购宝帐户信息
     * @return array|mixed
     * @throws PaymentException
     */
    public function memberWalletInfo(): mixed
    {
        $params = [
            'mid' => $this->player->id,
        ];
        try {
            $res = $this->doCurl($this->createUrl('memberWalletInfo'), $params);
        } catch (PaymentException $e) {
            throw new PaymentException($e->getMessage());
        }
        
        return $res;
    }
    
    /**
     * 检查用户绑定
     * @return array|mixed
     * @throws PaymentException
     */
    public function checkBinding(): mixed
    {
        $params = [
            'mid' => $this->player->id,
        ];
        try {
            $res = $this->doCurl($this->createUrl('checkBinding'), $params);
        } catch (PaymentException $e) {
            $this->log->info('fastBind -> 请求数据', [$params, $e]);
            throw new PaymentException($e->getMessage());
        }
        
        return $res;
    }
    
    /**
     * 透过 mid 、授权类型、OTP 验证码，来验证用户收到的验证码
     * 用户授权/解除授权-验证
     * @return array|mixed
     * @throws PaymentException
     */
    public function verifyCode($type, $code): mixed
    {
        $params = [
            'mid' => $this->player->id,
            'type' => $type,
            'code' => $code,
        ];
        try {
            $res = $this->doCurl($this->createUrl('verifyCode'), $params);
        } catch (PaymentException $e) {
            throw new PaymentException($e->getMessage());
        }
        
        return $res;
    }
    
    /**
     * 透过 mid 、授权类型、OTP 验证码，来验证用户收到的验证码
     * 发起授权/解除授权
     * @return array|mixed
     * @throws PaymentException
     */
    public function verifyUser($type): mixed
    {
        $params = [
            'mid' => $this->player->id,
            'type' => $type,
        ];
        try {
            $res = $this->doCurl($this->createUrl('verifyUser'), $params);
        } catch (PaymentException $e) {
            throw new PaymentException($e->getMessage());
        }
        
        return $res;
    }
    
    /**
     * 免扫码充值
     * @return array|mixed
     * @throws PaymentException
     */
    public function fastDeposit($order_no, $amount, $trans_pwd, $remark = ''): mixed
    {
        $params = [
            'order_no' => $order_no,
            'amount' => $amount,
            'mid' => $this->player->id,
            'trans_pwd' => $trans_pwd,
            'remark' => $remark,
        ];
        try {
            $res = $this->doCurl($this->createUrl('fastDeposit'), $params);
        } catch (PaymentException $e) {
            throw new PaymentException($e->getMessage());
        }
        
        return $res;
    }
    
    /**
     * 检查用户授權狀態
     * 测试环境：https://{TEST_API_HOST}/Auth/CheckVerify
     * @return array|mixed
     * @throws PaymentException
     */
    public function checkVerify(): mixed
    {
        $params = [
            'mid' => $this->player->id,
        ];
        try {
            $res = $this->doCurl($this->createUrl('checkVerify'), $params);
        } catch (PaymentException $e) {
            throw new PaymentException($e->getMessage());
        }
        
        return $res;
    }
}
