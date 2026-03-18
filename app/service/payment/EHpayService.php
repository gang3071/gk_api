<?php

namespace app\service\payment;

use app\model\Player;
use app\exception\PaymentException;
use Exception;
use support\Log;
use WebmanTech\LaravelHttpClient\Facades\Http;

class EHpayService
{
    public string $method = 'POST';
    
    public array $successCode = [200, 201];
    
    public array $failCode = [
        '1' => '余额不⾜ ',
        '2' => '提现（下发）功能未开启',
        '3' => '提交参数有误',
        '4' => '查⽆商户 ',
        '5' => '签名错误',
        '6' => '请求冲突，请重新发起 ',
        '7' => '查⽆交易',
        '8' => '订单号重复',
        '9' => '代付功能未开启',
        '10' => '⾦额低于下限，请提⾼⾦额',
        '11' => '⾦额⾼于上限，请降低⾦额',
        '12' => '通道代码有误',
        '13' => '通道维护中',
        '14' => '交易功能未开启',
        '15' => '⾦额有误',
        '16' => '同 IP 多笔未⽀付订单暂时锁定，请稍候再试',
        '17' => '⾦额有误',
    ];
    public string $api_domain;
    public string $secret;
    public string $merchant_id;
    public string $deposit_notify_url;
    public string $withdraws_notify_url;
    public ?\Monolog\Logger $log = null;
    
    /**
     * @param Player|null $player
     * @throws Exception
     */
    public function __construct(Player $player = null)
    {
        $this->api_domain = config('payment.EH.api_domain');
        $this->secret = config('payment.EH.secret_key');
        $this->merchant_id = config('payment.EH.merchant_id');
        $this->deposit_notify_url = config('payment.EH.deposit_notify_url');
        $this->withdraws_notify_url = config('payment.EH.withdraws_notify_url');
        $this->log = Log::channel('eh_pay_server');
    }
    
    /**
     * 组装请求
     * @param string $url
     * @param array $params
     * @return array|mixed
     * @throws PaymentException
     * @throws Exception
     */
    private function doCurl(string $url, array $params = []): mixed
    {
        $response = Http::timeout(10)
            ->asForm()
            ->post($url, $params);
        $this->log->info('doCurl -> 请求结果', [$url, $response]);
        if (!$response->ok()) {
            throw new Exception(trans('system_busy', [], 'message'));
        }
        $data = $response->json();
        if (!in_array($data['http_status_code'], $this->successCode)) {
            throw new PaymentException($this->failCode[$data['error_code']]);
        }
        return $data['data'];
    }
    
    /**
     * 创建签名
     * @param array $params
     * @return string
     */
    private function createSign(array $params): string
    {
        ksort($params);
        return md5(urldecode(http_build_query($params, '', '&')) . '&secret_key='.$this->secret);
    }

    /**
     * 验证签名
     * @param array $params
     * @return string
     */
    public function verifySign(array $params): string
    {
        unset($params['sign']);
        ksort($params);
        return md5(urldecode(http_build_query($params, '', '&')) . '&secret_key='.$this->secret);
    }
    
    /**
     * 生成请求url
     * @param $method
     * @return string
     */
    private function createUrl($method): string
    {
        return $this->api_domain . $method;
    }

    /**
     * 入⾦下单接⼝
     * @return array|mixed
     * @throws PaymentException
     */
    public function deposit($order_no, $amount): mixed
    {
        if ($amount < 500){
            $channel = 'ALIPAY_SAC';
        } elseif ($amount >= 500 & $amount <= 1000){
            $channel = 'QR_ALIPAY';
        } else {
            $channel = 'ALIPAY_BAC';
        }
        $params = [
            'channel_code' => $channel,
            'username' => $this->merchant_id,
            'amount' => $amount,
            'order_number' => $order_no,
            'notify_url' => config('app.api_domain').$this->deposit_notify_url,
            'client_ip' => request()->getRealIp(),
        ];
        $params['sign'] = $this->createSign($params);
        try {
            $res = $this->doCurl($this->createUrl('api/v1/third-party/create-transactions'), $params);
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
    public function withdraw($order_no, $amount, $holderName, $number, $bankName): mixed
    {
        $params = [
            'username' => $this->merchant_id,
            'amount' => $amount,
            'order_number' => $order_no,
            'notify_url' => config('app.api_domain').$this->withdraws_notify_url,
            'bank_card_holder_name' => $holderName,
            'bank_card_number' => $number,
            'bank_name' => $bankName,
        ];
        $params['sign'] = $this->createSign($params);
        try {
            $res = $this->doCurl($this->createUrl('api/v1/third-party/agency-withdraws'), $params);
        } catch (PaymentException $e) {
            throw new PaymentException($e->getMessage());
        }

        return $res;
    }
}
