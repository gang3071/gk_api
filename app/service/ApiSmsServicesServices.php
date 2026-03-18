<?php
// 台湾手机号发送短信
namespace app\service;

use app\model\PhoneSmsLog;
use Exception;
use GPBMetadata\Google\Api\Log;
use support\Cache;
use WebmanTech\LaravelHttpClient\Facades\Http;

class ApiSmsServicesServices implements BaseSmsServices
{
    // 使⽤者帳號
    public int $expireTime = 300;

    public mixed $domain = '';

    public mixed $api = '';

    public mixed $authKey = '';

    public function __construct()
    {
        $this->domain = config('api-sms.domain');
        $this->api = config('api-sms.sm_send_api');
        $this->authKey = config('api-sms.auth_key');
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
        $env = 'pro';
        $code = ($env == 'pro' ? random_int(10000, 99999) : config('sms.default_code'));
        $key = setSmsKey($phone, $type);
        $uid = gen_uuid();
        //驗證通過
        if ($env == 'pro') {
            $result = $this->doCurl([
                'phone' => $phone,
                'syskey' => $this->authKey,
                'code' => $code,
            ]);
        } else {
            $result = $env;
        }
        $phoneSmsLog = new PhoneSmsLog();
        $phoneSmsLog->player_id = $playerId;
        $phoneSmsLog->code = $code;
        $phoneSmsLog->phone = PhoneSmsLog::COUNTRY_CODE_CH . $phone;
        $phoneSmsLog->uid = $uid;
        $phoneSmsLog->send_times = 1;
        $phoneSmsLog->type = $type;
        $phoneSmsLog->expire_time = date("Y-m-d H:i:s", time() + $this->expireTime);
        $phoneSmsLog->response = $result ? json_encode($result) : '';
        if ($env == 'pro') {
            if (isset($result['Code']) && $result['Code'] == 'OK') {
                Cache::set($key, $code, $this->expireTime);
                $phoneSmsLog->status = 1;
                $phoneSmsLog->uid = $result['data']['uid'] ?? '';
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

    /**
     * 执行请求
     * @param array $params 参数
     * @return mixed
     * @throws Exception
     */
    public function doCurl(array $params): mixed
    {
        $result = Http::timeout(10)
            ->withOptions([
                'version' => '1.1',
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                    'Accept' => 'application/json',
                ],
                'curl' => [
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_SSL_VERIFYPEER => true,
                ],
            ])->get($this->domain . $this->api . '?' . http_build_query($params));
        \support\Log::info('短信', [$result, $this->domain . $this->api . '?' . http_build_query($params)]);
        if ($result->ok()) {
            return $result->json();
        }
        throw new Exception(trans('phone_code_send_failed', [], 'message'));
    }
}
