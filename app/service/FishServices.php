<?php

namespace app\service;

use app\model\Machine;
use think\Exception;

class FishServices
{
    public $method = 'POST';

    private $machine;

    private $domain;

    private $identify_url;

    private $ip;

    private $api_path = 'api/shuangmei/multifunction/v1/output_point_lo_hi';

    private $vision_url = 'api/v1/fish_machine/get_score';

    private $machine_port = '8000';

    private $acting = [
        'up' => 1,
        'down' => 2,
        'down_twice' => 2,
        'left' => 3,
        'right' => 4,
        'press' => 5,
        'shoot' => 6,
        'open_point' => 7,
        'wash_point' => 8,
        'auto' => 6
    ];

    private $delay_ms = [
        'up' => 50,
        'down' => 150,
        'down_twice' => 50,
        'left' => 50,
        'right' => 50,
        'press' => 25,
        'shoot' => 150,
        'open_point' => 20,
        'wash_point' => 240
    ];

    public function __construct(Machine $machine)
    {
        $this->machine = $machine;
        $this->domain = $machine->domain;
        $this->identify_url = $machine->identify_url ?: $machine->domain;
        $this->ip = $machine->ip;
    }

    /**
     * 拼装请求地址
     * @param $feature
     * @return string
     */
    public function setUrl($feature): string
    {
        return $this->domain . '/' . $this->api_path . '/' . $this->ip . '/' . $this->machine_port . '/' . $this->acting[$feature] . '/' . $this->delay_ms[$feature];
    }

    /**
     * 机台操作
     * @param string $action 功能
     * @param array $param 参数
     * @param bool $isSystem 是否系统
     * @return array
     * @throws \Exception
     */
    public function machineAction(string $action, array $param = [], bool $isSystem = false): array
    {
        $result = null;
        try {
            switch ($action) {
                case 'up': // 上
                case 'down': // 下一次 鎖定功能
                case 'down_twice': // 下兩次 切換武器功能
                case 'left': // 左
                case 'right': // 右
                case 'press': // 押分
                case 'shoot': // 發射
                    $result = $this->doMachineCurl($this->setUrl($action));
                    if (!isset($result['result']) || $result['result'] == 0) {
                        throw new Exception(trans('exception_msg.machine_connection_failed', [], 'message'));
                    }
                    break;
                case 'is_auto': // 判斷是否自動中
                    $status = $this->doMachineCurl($this->domain . '/api/shuangmei/multifunction/v1/all_status/' . $this->ip . '/' . $this->machine_port);
                    $result = [];
                    if (isset($status['status'][5]) && $status['status'][5] == 0) {
                        $result['result'] = 1;
                        $result['message'] = '自動ON';
                    } else {
                        $result['result'] = 0;
                        $result['message'] = '自動OFF';
                    }
                    break;
                case 'auto_on': // 啟動自動
                    $result = $this->doMachineCurl($this->domain . '/api/shuangmei/multifunction/v1/output_point_lo/' . $this->ip . '/' . $this->machine_port . '/6');
                    break;
                case 'auto_off': // 關閉自動
                    $result = $this->doMachineCurl($this->domain . '/api/shuangmei/multifunction/v1/output_point_hi/' . $this->ip . '/' . $this->machine_port . '/6');
                    break;
                case 'auto': // 自動
                    $status = $this->doMachineCurl($this->domain . '/api/shuangmei/multifunction/v1/all_status/' . $this->ip . '/' . $this->machine_port);
                    if (isset($status['status'][5])) {
                        $url = $status['status'][5] == 1 ? $this->domain . '/api/shuangmei/multifunction/v1/output_point_lo/' . $this->ip . '/' . $this->machine_port . '/6' : $this->domain . '/api/shuangmei/multifunction/v1/output_point_hi/' . $this->ip . '/' . $this->machine_port . '/6';
                        $result = $this->doMachineCurl($url);
                        if ($result['result'] == 1) {
                            $result['message'] = $status['status'][5] == 1 ? '開啟自動' : '關閉自動';
                        }
                    }
                    break;
                case 'open_point': // 開分
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
                    $url = $this->setUrl('open_point');
                    $times = $param['open_point'] / $this->machine->control_open_point;
                    $result = [];
                    for ($exec = 0, $success = 0; $success < $times; $exec++) {
                        $openResult = $this->doMachineCurl($url, $param);
                        if (isset($openResult['result']) && $openResult['result'] == 1) {
                            $success += 1;
                            $result[] = $openResult;
                        } else {
                            // MongoDB 已移除，条件调用日志函数
                            if (function_exists('saveMachineOperationLog')) {
                                saveMachineOperationLog($this->machine, $this->machine->gamingPlayer, json_encode($openResult), $action, 1, $isSystem);
                            }
                        }
                        usleep(5);
                    }
                    break;
                case 'wash_point': // 洗分
                    $status = $this->all_status();
                    if ($status['status'][1] == 0) {
                        $this->doMachineCurl($this->domain . '/api/shuangmei/multifunction/v1/output_point_hi/' . $this->ip . '/' . $this->machine_port . '/2');
                    }
                    if ($status['status'][5] == 0) {
                        $this->doMachineCurl($this->domain . '/api/shuangmei/multifunction/v1/output_point_hi/' . $this->ip . '/' . $this->machine_port . '/6');
                    }
                    $result = $this->doMachineCurl($this->setUrl('wash_point'));
                    break;
                case 'identify_image': // 图像识别
                    $result = doCurl($this->identify_url . '/' . $this->vision_url . '/' . $this->ip . '/' . $this->machine_port, $this->machine->gaming_user_id, $this->machine->id);
                    if (empty($result) || !isset($result['status']) || $result['status'] != 1) {
                        throw new Exception(trans('exception_msg.machine_connection_failed', [], 'message'));
                    }
                    break;
                case 'lock': // 锁
                    $status = $this->all_status();
                    if (isset($status['status'][1])) {
                        $url = $status['status'][1] == 1 ? $this->domain . '/api/shuangmei/multifunction/v1/output_point_lo/' . $this->ip . '/' . $this->machine_port . '/2' : $this->domain . '/api/shuangmei/multifunction/v1/output_point_hi/' . $this->ip . '/' . $this->machine_port . '/2';
                        $result = $this->doMachineCurl($url);
                        if ($result['result'] == 1) {
                            $result['message'] = $status['status'][1] == 1 ? '開啟鎖定' : '關閉鎖定';
                        }
                    }
                    break;
                default:
                    throw new Exception(trans('exception_msg.action_not_fount', [], 'message'));
            }
        } catch (\Exception $e) {
            // MongoDB 已移除，条件调用日志函数
            if (function_exists('saveMachineOperationLog')) {
                saveMachineOperationLog($this->machine, $this->machine->gamingPlayer, json_encode($result), $action, 0, $isSystem);
            }
            throw new \Exception($e->getMessage());
        }
        // MongoDB 已移除，条件调用日志函数
        if (function_exists('saveMachineOperationLog')) {
            saveMachineOperationLog($this->machine, $this->machine->gamingPlayer, json_encode($result), $action);
        }

        return $result;
    }

    /**
     * 执行机台请求
     * @param string $url
     * @param array $data
     * @return array
     * @throws \Exception
     */
    private function doMachineCurl(string $url, array $data = []): array
    {
        $result = doCurl($url, $this->machine->gaming_user_id, $this->machine->id, $data);
        if (!isset($result['result']) || $result['result'] == 0) {
            throw new Exception(trans('exception_msg.machine_connection_failed', [], 'message'));
        }

        return $result;
    }

    /**
     * 获取所有状态
     * @return array|mixed|null
     * @throws \Exception
     */
    public function all_status()
    {
        $result = doCurl($this->domain . '/api/shuangmei/multifunction/v1/all_status/' . $this->ip . '/' . $this->machine_port, $this->machine->gaming_user_id, $this->machine->id);
        if (!isset($result['result']) || $result['result'] == 0) {
            throw new Exception(trans('exception_msg.machine_connection_failed', [], 'message'));
        }

        return $result;
    }
}
