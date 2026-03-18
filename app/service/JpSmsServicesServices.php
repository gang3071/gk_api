<?php
// 日本手机号发送短信
namespace app\service;

use app\model\Channel;
use app\model\PhoneSmsLog;
use Exception;
use Illuminate\Support\Str;
use support\Cache;
use WebmanTech\LaravelHttpClient\Facades\Http;

class JpSmsServicesServices implements BaseSmsServices
{
    // 应用key
    private $appKey = '';
    // 应用代码
    private $appcode = '';
    // 应用秘钥
    private $appSecret = '';
    // domain
    private $domain = '';
    // 过期时间
    public $expireTime = 120;

    public function __construct()
    {
        $this->domain = config('jp-sms.domain');
        $this->appKey = config('jp-sms.app_key');
        $this->appcode = config('jp-sms.appcode');
        $this->appSecret = config('jp-sms.app_secret');
    }

    /**
     * 执行请求
     * @param string $api 接口
     * @param array $params 参数
     * @return mixed
     */
    public function doCurl(string $api, array $params)
    {
        $result = Http::timeout(10)->get($this->domain . $api, $params);

        return json_decode($result, true);
    }

    /**
     * 日本供应商短信发送
     * @param $phone
     * @param $type
     * @param int $playerId
     * @return bool
     * @throws Exception
     */
    public function send($phone, $type, int $playerId = 0): bool
    {
        $env = config('app.env');
        $api = config('jp-sms.batchSend');
        $code = ($env == 'pro' ? random_int(10000, 99999) : config('sms.default_code'));
        $key = setSmsKey($phone, $type);
        $uid = gen_uuid();
        $name = Channel::query()->where('site_id',\request()->site_id)->value('sms_name')??'一级棒';
        $msg = Str::replaceFirst('{code}', $code, getContent($type, 'jp'));
        $msg = Str::replaceFirst('{name}', $name, $msg);
        //驗證通過
        if ($env == 'pro') {
            $result = $this->doCurl($api, [
                'appKey' => $this->appKey,
                'appcode' => $this->appcode,
                'appSecret' => $this->appSecret,
                'uid' => $uid,
                'phone' => PhoneSmsLog::COUNTRY_CODE_JP . $phone,
                'msg' => $msg
            ]);
        } else {
            $result = $env;
        }
        $phoneSmsLog = new PhoneSmsLog();
        $phoneSmsLog->player_id = $playerId;
        $phoneSmsLog->code = $code;
        $phoneSmsLog->phone = PhoneSmsLog::COUNTRY_CODE_JP . $phone;
        $phoneSmsLog->uid = $uid;
        $phoneSmsLog->send_times = 1;
        $phoneSmsLog->type = $type;
        $phoneSmsLog->expire_time = date("Y-m-d H:i:s", time() + $this->expireTime);
        $phoneSmsLog->response = $result ? json_encode($result) : '';
        if ($env == 'pro') {
            if (isset($result) && $result['code'] == '00000') {
                if (isset($result['result']) && $result['result'][0]['status'] == '00000') {
                    Cache::set($key, $code, $this->expireTime);
                    $phoneSmsLog->status = 1;
                    $phoneSmsLog->save();
                    return true;
                }
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
