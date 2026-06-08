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
            return $handler($request);
        }

        // 未登录请求（如登录接口）跳过设备验证
        $authHeader = $request->header('Authorization', '');
        if (empty($authHeader)) {
            return $handler($request);
        }

        $device = AdminDevice::query()->where('device_no', $deviceCpuId)->whereNull('deleted_at')->exists();
        $player = checkPlayer();
        $channel = $player->channel;
        // 设备不存在，检查是否开启自动采集
        $setting = SystemSetting::query()->where('feature', 'device_collect')->where('status', 1)->first();
        if (!$device && $setting) {
            AdminDevice::query()->create([
                'channel_id'     => $channel->id ?? 0,
                'department_id'  => $player->department_id,
                'agent_admin_id' => $player->agent_admin_id,
                'store_admin_id' => $player->store_admin_id,
                'device_no'      => $deviceCpuId,
                'device_model'   => $request->header('DeviceModel', ''),
                'status'         => 1,
            ]);
        }


        return $handler($request);
    }
}
