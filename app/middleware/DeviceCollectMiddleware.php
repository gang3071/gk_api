<?php

namespace app\middleware;

use app\model\AdminDevice;
use app\model\SystemSetting;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

class DeviceCollectMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $deviceCpuId = $request->header('DeviceCpuID', '');
        if (empty($deviceCpuId)) {
            return jsonFailResponse(trans('device_not_found', [], 'message'), [], 403);
        }

        // 未登录请求（如登录接口）跳过设备验证
        $authHeader = $request->header('Authorization', '');
        if (empty($authHeader)) {
            return $handler($request);
        }

        /** @var AdminDevice $device */
        $device = AdminDevice::query()->where('device_no', $deviceCpuId)->whereNull('deleted_at')->first();

        if (!empty($device)) {
            $player = checkPlayer();
            if ($device->store_admin_id != $player->store_admin_id) {
                return jsonFailResponse(trans('device_store_mismatch', [], 'message'), [], 403);
            }
        } else {
            $setting = SystemSetting::query()->where('feature', 'device_collect')->where('status', 1)->first();
            if (!empty($setting)) {
                $player = checkPlayer();
                $channel = $player->channel;

                AdminDevice::query()->create([
                    'channel_id' => $channel->id ?? 0,
                    'department_id' => $player->department_id,
                    'agent_admin_id' => $player->agent_admin_id,
                    'store_admin_id' => $player->store_admin_id,
                    'device_no' => $deviceCpuId,
                    'device_model' => $request->header('DeviceModel', ''),
                    'status' => 1,
                ]);
            } else {
                return jsonFailResponse(trans('device_not_found', [], 'message'), [], 403);
            }
        }

        return $handler($request);
    }
}
