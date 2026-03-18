<?php
// 台湾手机号发送短信
namespace app\service;

use app\model\PhoneSmsLog;
use Exception;
use Overtrue\EasySms\EasySms;
use Overtrue\EasySms\Exceptions\InvalidArgumentException;
use Overtrue\EasySms\Exceptions\NoGatewayAvailableException;
use Overtrue\EasySms\Strategies\OrderStrategy;
use support\Cache;

class VipSmsServicesServices implements BaseSmsServices
{
    // 使⽤者帳號
    public $expireTime = 300;

    public $config = [];

    public function __construct()
    {
        $this->config = [
            // HTTP 请求的超时时间（秒）
            'timeout' => 5.0,
            // 默认发送配置
            'default' => [
                // 网关调用策略，默认：顺序调用
                'strategy' => OrderStrategy::class,
                // 默认可用的发送网关
                'gateways' => [
                   'aliyun',
                ],
            ],
            // 可用的网关配置
            'gateways' => [
                'aliyun' => [
                    'access_key_id' => config('vip-sms.username'),
                    'access_key_secret' => config('vip-sms.password'),
                    'sign_name' => config('vip-sms.sign_name'),
                ],
            ],
        ];
    }

    /**
     * 日本供应商短信发送
     * @param $phone
     * @param $type
     * @param int $playerId
     * @param string $name
     * @return bool
     * @throws Exception
     */
    public function send($phone, $type, int $playerId = 0, string $name = ''): bool
    {
        $easySms = new EasySms($this->config);
        $env = config('app.env');
        $code = ($env == 'pro' ? random_int(10000, 99999) : config('sms.default_code'));
        $key = setSmsKey($phone, $type);
        $template = config('vip-sms.department_id')[\request()->department_id];
        //驗證通過
        if ($env == 'pro') {
            try {
                $result = $easySms->send($phone, [
                    'template' => $template,
                    'data' => [
                        'code' => $code
                    ],
                ]);
            } catch (InvalidArgumentException|NoGatewayAvailableException $e) {
                throw new Exception(trans('phone_code_send_failed', [], 'message'));
            }
        } else {
            $result = $env;
        }


        $phoneSmsLog = new PhoneSmsLog();
        $phoneSmsLog->player_id = $playerId;
        $phoneSmsLog->code = $code;
        $phoneSmsLog->phone = PhoneSmsLog::COUNTRY_CODE_CH . $phone;
        $phoneSmsLog->send_times = 1;
        $phoneSmsLog->type = $type;
        $phoneSmsLog->expire_time = date("Y-m-d H:i:s", time() + $this->expireTime);
        $phoneSmsLog->response = $result ? json_encode($result) : '';
        if ($env == 'pro') {
            if (isset($result)&& $result['aliyun']['result']['Code'] == 'OK') {
                Cache::set($key, $code, $this->expireTime);
                $phoneSmsLog->status = 1;
                $phoneSmsLog->save();
                return true;
            }
        } else {
            Cache::set($key, $code, $this->expireTime);
            $phoneSmsLog->status = 1;
            $phoneSmsLog->save();
            return true;
        }
        $phoneSmsLog->status = 0;
        $phoneSmsLog->save();

        throw new Exception(trans('phone_code_send_failed', [], 'message'));
    }
}
