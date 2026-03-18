<?php
// 台湾手机号发送短信
namespace app\service;

use app\model\Channel;
use app\model\PhoneSmsLog;
use Exception;
use Illuminate\Support\Str;
use support\Cache;
use WebmanTech\LaravelHttpClient\Facades\Http;

class TwSmsServicesServices implements BaseSmsServices
{
    // 使⽤者帳號
    private $username = '';
    // 使⽤者密碼
    private $password = '';
    // domain
    private $domain = '';
    // 过期时间
    public $expireTime = 300;

    public function __construct()
    {
        $this->domain = config('tw-sms.domain');
        $this->username = config('tw-sms.username');
        $this->password = config('tw-sms.password');
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
        $result = Http::timeout(10)->post($this->domain . $api . '?' . http_build_query($params));
        if ($result->ok()) {
            $arr = preg_split('/[;\r\n]+/s', $result->body());
            $data = [];
            foreach ($arr as $item) {
                $arr = explode('=', $item);
                if (!empty($arr) && isset($arr[0]) && isset($arr[1])) {
                    $data[$arr[0]] = $arr[1];
                }
            }
            return $data;
        }
        throw new Exception(trans('phone_code_send_failed', [], 'message'));
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
        $api = config('tw-sms.sm_send_api');
        $code = ($env == 'pro' ? random_int(10000, 99999) : config('sms.default_code'));
        $key = setSmsKey($phone, $type);
        $uid = gen_uuid();
        $name = Channel::query()->where('site_id',\request()->site_id)->value('sms_name')??'一级棒';
        $msg = Str::replaceFirst('{code}', $code, getContent($type, 'tw'));
        $msg = Str::replaceFirst('{name}', $name, $msg);
        //驗證通過
        if ($env == 'pro') {
            $result = $this->doCurl($api, [
                'username' => $this->username,
                'password' => $this->password,
                'dstaddr' => $phone,
                'destname' => $name,
                'dlvtime' => '',
                'vldtime' => $this->expireTime,
                'smbody' => $msg,
                'CharsetURL' => 'UTF-8',
            ]);
        } else {
            $result = $env;
        }
        $phoneSmsLog = new PhoneSmsLog();
        $phoneSmsLog->player_id = $playerId;
        $phoneSmsLog->code = $code;
        $phoneSmsLog->phone = PhoneSmsLog::COUNTRY_CODE_TW . $phone;
        $phoneSmsLog->uid = $uid;
        $phoneSmsLog->send_times = 1;
        $phoneSmsLog->type = $type;
        $phoneSmsLog->expire_time = date("Y-m-d H:i:s", time() + $this->expireTime);
        $phoneSmsLog->response = $result ? json_encode($result) : '';
        if ($env == 'pro') {
            if (isset($result['statuscode'])) {
                /* 0 預約傳送中1 已送達業者2 已送達業者4 已送達⼿機5 內容有錯誤6 ⾨號有錯誤7 簡訊已停⽤8 逾時無送達9 預約已取消*/
                switch ($result['statuscode']) {
                    case '0':
                    case '1':
                    case '2':
                    case '4':
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
