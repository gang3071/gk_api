<?php

namespace app\service;

use app\model\Machine;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Response;
use support\Log;
use think\Exception;

class SlotService
{

    public $method = 'POST';

    private $machine;

    private $domain = '';

    private $ip = '';

    private $api_path = 'api/shuangmei/slot/v1';

    private $machine_port = '8000';

    public static $action = [
        'open_any_point',
        'plc_open_times',
        'wash_zero',
        'start_game',
        'stop_1',
        'stop_2',
        'stop_3',
        'start_auto',
        'out_1_pulse',
        'combine_status',
        'pressure',
        'score',
        'wash_remainder',
        'reward_switch',
        'check_wash_status',
        'move_on',
        'stake_point',
        'open_1',
        'open_5',
        'open_10',
        'stop_auto',
        'move_off',
        'seven_display',
        'grant_approval',
        'bb_status',
        'rb_status',
        'check_lottery',
        'check_wash_key_status',
    ];

    public function __construct(Machine $machine)
    {
        $this->machine = $machine;
        $this->domain = $machine->domain;
        $this->ip = $machine->ip;
    }

    /**
     * 返回操作
     * @return string[]
     */
    public static function getAction(): array
    {
        return self::$action;
    }

    /**
     * 拼装请求地址
     * @param $feature
     * @return string
     */
    protected function setUrl($feature): string
    {
        return $this->domain . '/' . $this->api_path . '/' . $feature . '/' . $this->ip . '/' . $this->machine_port;
    }

    /**
     * 机台操作
     * @param string $action 功能
     * @param array $param 参数
     * @param int $isSystem 是否系统
     * @return PromiseInterface|Response
     * @throws Exception
     */
    public function machineAction(string $action, array $param = [], int $isSystem = 0)
    {
        /** TODO 新版工控调整完毕这边将删除 */
        if ($this->machine->domain == '59.120.86.58' || $this->machine->domain == '210.59.240.147') {
            throw new Exception('该机台作为,新版工控测试机台使用中');
        }
        switch ($action) {
            case 'check_wash_status': // 檢查可否洗分
            case 'move_on': // 移分ON
            case 'stake_point': // 壓分
            case 'start_game': // 開始
            case 'pressure': // 取得壓分
            case 'score': // 取得得分
            case 'stop_1': //停1
            case 'stop_2': //停2
            case 'stop_3': //停3
            case 'open_1': //開分1次
            case 'open_5': //開分5次
            case 'open_10': //開分10次
            case 'stop_auto': //停止自動
            case 'move_off': //移分OFF
            case 'wash_zero': //洗分清零
            case 'wash_remainder': //洗分餘數
            case 'start_auto': //啟動自動
            case 'reward_switch': //大賞燈切換
            case 'out_1_pulse': //看表
            case 'seven_display':  //取餘分
            case 'grant_approval': //檢查開贈核准
            case 'bb_status': //取得大獎狀態
            case 'rb_status': //取得小獎狀態
            case 'combine_status': //機台分數狀態
            case 'check_lottery': //確認開獎連莊
                $result = doCurl($this->setUrl($action), $this->machine->gaming_user_id, $this->machine->id);
                break;
            case 'check_wash_key_status': //檢查洗分KEY狀態 //auto自動狀態 //move_point移分狀態 //small_reward小獎狀態 //big_reward大獎狀態
                return doCurl($this->setUrl($action), $this->machine->gaming_user_id, $this->machine->id);
            case 'open_any_point': // 开任意分
                $validator = validator($param, [
                    'open_point' => 'required|numeric|min:1'
                ], [
                    'open_point.required' => trans('open_point_required', [], 'message'),
                    'open_point.numeric' => trans('open_point_numeric', [], 'message'),
                    'open_point.min' => trans('open_point_min', [], 'message'),
                ]);
                if ($validator->fails()) {
                    throw new Exception($validator->errors()->first());
                }
                $result = doCurl($this->setUrl($action) . '/' . $param['open_point'], $this->machine->gaming_user_id, $this->machine->id);
                break;
            case 'plc_open_times': // 开分任意次数
                $validator = validator($param, [
                    'open_times' => 'required|numeric|min:1'
                ], [
                    'open_times.required' => trans('open_times_required', [], 'message'),
                    'open_times.numeric' => trans('open_times_numeric', [], 'message'),
                    'open_times.min' => trans('open_times_min', [], 'message'),
                ]);
                if ($validator->fails()) {
                    throw new Exception($validator->errors()->first());
                }
                $result = doCurl($this->setUrl($action) . '/' . $param['open_times'], $this->machine->gaming_user_id, $this->machine->id);
                break;
            default:
                throw new Exception(trans('exception_msg.action_not_fount', [], 'message'));
        }
        if (!isset($result['result']) || $result['result'] == 0) {
            Log::error('SlotService---machine_connection_failed', [$result, $action, $param, $this->machine->code]);
            saveMachineOperationLog($this->machine, $this->machine->gamingPlayer, json_encode($result), $action, 0, $isSystem);
            throw new Exception(trans('exception_msg.machine_connection_failed', [], 'message'));
        }
        saveMachineOperationLog($this->machine, $this->machine->gamingPlayer, json_encode($result), $action, 1, $isSystem);

        return $result;
    }

    /**
     * 下分并离开机台 停止自動 + 1停 + 2停 + 3停 + 移分OFF + 洗分清零
     * @return string[]
     */
    public function getLeave(): array
    {
        //停止自動 + 1停 + 2停 + 3停 + 移分OFF + 洗分清零
        return ['stop_auto', 'stop_1', 'stop_2', 'stop_3', 'move_off', 'wash_zero'];
    }

    /**
     * 移分ON + 啟動自動
     * @return string[]
     */
    public function getAuto(): array
    {
        return ['move_on', 'start_auto'];
    }

    //開始
    public function getStart(): array
    {
        //移分ON + 壓分 + 開始
        return ['move_on', 'stake_point', 'start_game'];
    }
}