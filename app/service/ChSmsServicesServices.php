<?php
// 台湾手机号发送短信
namespace app\service;

use app\model\Channel;
use app\model\PhoneSmsLog;
use Exception;
use Illuminate\Support\Str;
use support\Cache;
use WebmanTech\LaravelHttpClient\Facades\Http;

class ChSmsServicesServices implements BaseSmsServices
{
    // 使⽤者帳號
    public $expireTime = 300;
    // 使⽤者密碼
    private $username = '';
    // domain
    private $password = '';
    // 过期时间
    private $domain = '';

    public function __construct()
    {
        $this->domain = config('ch-sms.domain');
        $this->username = config('ch-sms.username');
        $this->password = config('ch-sms.password');
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
        $env = config('app.env');
        $api = config('ch-sms.sm_send_api');
        $code = ($env == 'pro' ? random_int(10000, 99999) : config('sms.default_code'));
        $name = Channel::query()->where('site_id',\request()->site_id)->value('sms_name')??'一级棒';
        $key = setSmsKey($phone, $type);
        $msg = Str::replaceFirst('{code}', $code, getContent($type, 'ch'));
        $msg = Str::replaceFirst('{name}', $name, $msg);
        //驗證通過
        if ($env == 'pro') {
            $result = $this->doCurl($api, [
                'account' => $this->username,
                'pswd' => $this->password,
                'mobile' => $phone,
                'msg' => $msg,
                'needstatus' => 'true',
            ]);
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
            if (isset($result['code']) && $result['code'] == '0') {
                Cache::set($key, $code, $this->expireTime);
                $phoneSmsLog->status = 1;
                $phoneSmsLog->uid = $result['msgId'];
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
     * @param string $api 接口
     * @param array $params 参数
     * @return mixed
     * @throws Exception
     */
    public function doCurl(string $api, array $params)
    {
        $result = Http::timeout(10)->get($this->domain . $api . '?' . http_build_query($params));
        if ($result->ok()) {
            $result = $result->body();
            $lines = explode("\n", $result);
            $firstLine = trim($lines[0]);
            $resultData = explode(',', $firstLine);
            if (count($lines) >= 2) {
                $secondLine = trim($lines[1]);
                return [
                    'code' => $resultData[1],
                    'msgId' => $secondLine,
                ];
            } else {
                return [
                    'code' => $resultData[1],
                    'msgId' => '',
                ];
            }
        }
        throw new Exception(trans('phone_code_send_failed', [], 'message'));
    }
}
