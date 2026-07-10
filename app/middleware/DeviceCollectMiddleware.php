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
            return jsonFailResponse(trans('device_cpu_id_required', [], 'message'), [], 403);
        }

        // 检查 device_collect 开关
        $setting = SystemSetting::query()->where('feature', 'device_collect')->where('status', 1)->first();
        if (!$setting) {
            return $handler($request);
        }

        // 查询设备记录（无论是否登录都需要校验设备是否存在）
        /** @var AdminDevice $device */
        $device = AdminDevice::query()->where('device_no', $deviceCpuId)->first();
        if (!$device) {
            return jsonFailResponse(trans('device_not_found', [], 'message'), [], 403);
        }

        // 校验设备状态
        if ($device->status == 0) {
            return jsonFailResponse(trans('device_disabled', [], 'message'), [], 403);
        }

        // 已登录用户：校验跨店
        $authHeader = $request->header('Authorization', '');
        if (!empty($authHeader)) {
            try {
                $player = checkPlayer();
                if ($device->store_admin_id != $player->store_admin_id) {
                    return jsonFailResponse(trans('device_store_mismatch', [], 'message'), [], 403);
                }
            } catch (\Throwable $e) {
                // token 无效或过期，跳过跨店校验
            }
        }

        return $handler($request);
    }
}
