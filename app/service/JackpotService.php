<?php

namespace app\service;

use app\model\Machine;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Response;
use support\Log;
use think\Exception;

class JackpotService
{

    public $method = 'POST';

    /** @var Machine */
    private $machine;

    private $domain;

    private $ip;

    private $api_path = 'api/shuangmei/jackpot/v1';

    private $machine_port = '8000';

    public static $action = [
        'plc_open_times',
        'plc_wash_zero',
        'plc_up_turn_100',
        'plc_down_turn',
        'all_up_turn',
        'all_down_turn',
        'plc_turn_point',
        'combine_status',
        'plc_sub_point',
        'display_score',
        'display_turn_point',
        'plc_push_stop',
        'plc_wash_remainder',
        'check_wash_status',
        'reward_switch',
        'plc_up_turn_500',
        'plc_auto_up_turn',
        'plc_start_or_stop',
        'clear_status',
        'load_auto_status',
        'load_total_open',
        'load_total_wash',
        'display_open_point',
        'grant_approval',
        'plc_draw_status',
        'plc_open_1',
        'plc_open_10',
        'check_lottery',
    ];

    public function __construct(Machine $machine)
    {
        $this->machine = $machine;
        $this->domain = $machine->domain;
        $this->ip = $machine->ip;
    }

    /**
     * 获取操作方法
     * @return string[]
     */
    public static function getAction(): array
    {
        return self::$action;
    }

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
            case 'plc_turn_point': // 這支判斷機台運轉的次數，如果沒在跳動就是沒在玩
            case 'plc_push_stop': // push auto 停止
            case 'plc_wash_zero': // 洗分清零
            case 'plc_wash_remainder': // 現場下分
            case 'check_wash_status': // 檢查可否洗分
            case 'reward_switch': // 看表
            case 'plc_up_turn_100': // 上轉100
            case 'plc_up_turn_500': // 上轉500
            case 'plc_down_turn': // 下轉
            case 'plc_auto_up_turn': // 自動上轉
            case 'plc_start_or_stop': // 自動開始/暫停
            case 'plc_push': // push
            case 'plc_push_5hz':
            case 'plc_sub_point': // 下珠
            case 'clear_status': // 清除所有狀態
            case 'load_auto_status': // 自動開始狀態
            case 'load_total_open': // 總開分
            case 'load_total_wash': // 總洗分
            case 'display_open_point': // 取餘分
            case 'grant_approval': // 檢查開贈核准
            case 'all_up_turn': // 全數上轉
            case 'display_score': // 珠數
            case 'display_turn_point': // 轉數
            case 'plc_draw_status': // /開獎狀態
            case 'combine_status': // 機台分數狀態
            case 'plc_open_1': // 開分1次
            case 'plc_open_10': // 開分10次
                $result = doCurl($this->setUrl($action), $this->machine->gaming_user_id, $this->machine->id);
                break;
            case 'check_lottery': // 確認開獎連莊
                return doCurl($this->setUrl($action), $this->machine->gaming_user_id, $this->machine->id);
            case 'open_any_point':
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
            case 'plc_open_times':
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
            case 'all_down_turn':
                $result = doCurl($this->setUrl('all_down_turn') . '/1', $this->machine->gaming_user_id, $this->machine->id);
                break;
            default:
                throw new Exception(trans('exception_msg.action_not_fount', [], 'message'));
        }
        if (!isset($result['result']) || $result['result'] == 0) {
            Log::error('JackpotService---machine_connection_failed', [$result, $action, $param, $this->machine->code]);
            // MongoDB 已移除，条件调用日志函数
            if (function_exists('saveMachineOperationLog')) {
                saveMachineOperationLog($this->machine, $this->machine->gamingPlayer, json_encode($result), $action, 0, $isSystem);
            }
            throw new Exception(trans('exception_msg.machine_connection_failed', [], 'message'));
        }
        // MongoDB 已移除，条件调用日志函数
        if (function_exists('saveMachineOperationLog')) {
            saveMachineOperationLog($this->machine, $this->machine->gamingPlayer, json_encode($result), $action, 1, $isSystem);
        }

        return $result;
    }

    /**
     * 下分立刻机台
     * @return string[]
     */
    public function getLeave(): array
    {
        return ['plc_push_stop', 'plc_sub_point', 'all_down_turn', 'plc_wash_zero'];
    }

    /**
     * 開始
     * @return string[]
     */
    public function getStart(): array
    {
        //移分ON + 壓分 + 開始
        return ['move_on', 'stake_point', 'start_game'];
    }

    /**
     * 自動
     * @return string[]
     */
    public function getAuto(): array
    {
        //移分ON + 啟動自動
        return ['move_on', 'start_auto'];
    }
}