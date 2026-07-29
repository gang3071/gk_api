<?php

namespace app\api\controller\v1;

use app\model\AdminDevice;
use app\model\AdminUser;
use app\model\Notice;
use Exception;
use Respect\Validation\Validator as v;
use support\Cache;
use support\Log;
use support\Request;
use support\Response;
use yzh52521\WebmanLock\Locker;

/**
 * 设备服务铃控制器
 *
 * 功能：H5 玩家端请求服务铃
 * 流程：验证设备 → 防重复检查 → 推送消息到店家后台 → 记录日志
 */
class DeviceServiceController
{
    /**
     * 请求服务铃
     *
     * 业务场景：
     * - 玩家在 H5 端点击「呼叫服务」按钮
     * - 系统推送消息到店家后台 WebSocket
     * - 店家后台自动播放语音：「{设备名称}呼叫服务」
     *
     * 防重复机制：
     * - Redis 锁：同一设备 5 秒内只能请求一次
     * - 防止玩家误操作连续点击
     * - 防止恶意刷接口
     *
     * @param Request $request
     * @return Response
     */
    public function callService(Request $request): Response
    {
        try {
            // ========== 1. 验证请求参数 ==========
            $data = $request->all();

            $validator = v::key('device_no', v::stringVal()->notEmpty()->setName('设备号'));

            try {
                $validator->assert($data);
            } catch (\Respect\Validation\Exceptions\AllOfException $e) {
                return jsonFailResponse(getValidationMessages($e));
            }

            $deviceNo = $data['device_no'];

            // ========== 2. 查询设备信息 ==========
            $device = AdminDevice::where('device_no', $deviceNo)
                ->where('status', 1) // 只允许启用状态的设备
                ->first();

            if (!$device) {
                return jsonFailResponse(trans('device_not_found', [], 'message'));
            }

            // 检查设备是否绑定店家
            if (empty($device->store_admin_id)) {
                return jsonFailResponse(trans('device_not_bind_store', [], 'message'));
            }

            // ========== 3. 防重复检查（Redis 锁） ==========
            $lockKey = "service:call:device:{$device->id}";
            $lockTtl = 5; // 5 秒内只能请求一次

            // 尝试获取锁（非阻塞）
            $lock = Locker::lock($lockKey, $lockTtl, false);

            if (!$lock) {
                // 获取锁失败 = 5 秒内已经请求过
                $remainingTtl = Cache::ttl($lockKey);

                return jsonFailResponse(trans('service_call_waiting', [], 'message'), [
                    'retry_after' => $remainingTtl
                ]);
            }

            // ========== 4. 推送消息到店家后台 ==========
            try {
                // 获取店家管理员信息
                $storeAdmin = AdminUser::find($device->store_admin_id);

                if (!$storeAdmin) {
                    throw new Exception('店家管理员不存在');
                }

                // 数据一致性校验：设备和店家管理员必须属于同一部门
                if ($device->department_id != $storeAdmin->department_id) {
                    Log::error('设备和店家管理员部门不一致', [
                        'device_id' => $device->id,
                        'device_department_id' => $device->department_id,
                        'store_admin_id' => $storeAdmin->id,
                        'store_admin_department_id' => $storeAdmin->department_id,
                    ]);
                    throw new Exception('设备和店家管理员部门不一致，数据异常');
                }

                // WebSocket 频道名称
                // 格式：private-store-{department_id}-{user_id}
                $channelName = "private-store-{$storeAdmin->department_id}-{$storeAdmin->id}";

                // 推送数据（只保留播报必要的字段）
                $pushData = [
                    'type' => 'service_call',
                    'device_name' => $device->device_name,
                    'voice_url' => $device->voice_url,
                ];

                // 使用项目标准方法发送 WebSocket 推送
                $result = sendSocketMessage($channelName, $pushData, 'service_bell');

                if ($result === false) {
                    throw new Exception('WebSocket 推送失败');
                }

                // 保存消息到数据库（用于消息列表展示）
                // 注意：使用设备的 department_id，确保数据一致性
                Notice::create([
                    'department_id' => $device->department_id,  // 使用设备的部门ID（与店家管理员必须一致）
                    'source_id' => $device->id, // 设备ID
                    'type' => Notice::TYPE_SERVICE_CALL,
                    'receiver' => Notice::RECEIVER_DEPARTMENT, // 子站（店家后台）
                    'admin_id' => $storeAdmin->id,
                    'admin_name' => $storeAdmin->nickname,
                    'status' => 0, // 未读
                ]);

                // 记录日志
                Log::info('设备服务铃请求成功', [
                    'device_id' => $device->id,
                    'device_name' => $device->device_name,
                    'device_no' => $device->device_no,
                    'store_admin_id' => $storeAdmin->id,
                    'store_admin_name' => $storeAdmin->nickname,
                    'channel' => $channelName,
                    'ip' => $request->getRealIp(),
                    'user_agent' => $request->header('user-agent'),
                ]);

            } catch (Exception $e) {
                // 推送失败，释放锁（允许重试）
                Locker::unlock($lock);

                Log::error('设备服务铃推送失败', [
                    'device_id' => $device->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                return jsonFailResponse(trans('service_call_push_failed', [], 'message'));
            }

            // ========== 5. 返回成功响应 ==========
            return jsonSuccessResponse(trans('service_call_success', [], 'message'), [
                'device_name' => $device->device_name,
                'retry_after' => $lockTtl, // 告知前端多久后可以再次请求
            ]);

        } catch (Exception $e) {
            Log::error('设备服务铃请求异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all(),
            ]);

            return jsonFailResponse(trans('system_error', [], 'message'));
        }
    }
}