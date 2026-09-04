<?php

namespace app\middleware;

use app\model\AdminDevice;
use app\model\SystemSetting;
use support\Cache;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * 储值机版本号验证中间件
 * Class ChuzhiVersionMiddleware
 * @package app\middleware
 */
class ChuzhiVersionMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        // 获取版本号接口不做版本校验
        if ($request->path() === '/chuzhi/ticket/version') {
            return $handler($request);
        }

        // 获取设备CPU ID
        $deviceCpuId = $request->header('DeviceCpuID', '');
        if (empty($deviceCpuId)) {
            return jsonFailResponse(trans('device_cpu_id_required', [], 'message'));
        }

        // 获取储值机版本号
        $clientVersion = $request->header('CHUZHI_VERSION', '');

        // 通过设备CPU ID查找设备
        $cacheKey = "chuzhi_device_" . $deviceCpuId;
        $device = Cache::get($cacheKey);

        if (empty($device)) {
            /** @var AdminDevice $device */
            $device = AdminDevice::query()
                ->where('device_no', $deviceCpuId)
                ->where('status', 1)
                ->first();

            if (empty($device)) {
                return jsonFailResponse(trans('device_not_found', [], 'message'));
            }

            // 验证是否为储值机
            if ((int)$device->device_type !== AdminDevice::TYPE_VENDING_MACHINE) {
                return jsonFailResponse(trans('device_not_storage_machine', [], 'message'));
            }

            // 缓存设备信息（5分钟）
            Cache::set($cacheKey, $device->toArray(), 300);
        } else {
            // 从缓存数组转换为对象检查
            if (isset($device['device_type']) && (int)$device['device_type'] !== AdminDevice::TYPE_VENDING_MACHINE) {
                return jsonFailResponse(trans('device_not_storage_machine', [], 'message'));
            }
        }

        $departmentId = $device['department_id'] ?? $device->department_id;

        // 获取渠道配置的版本号
        $versionCacheKey = "chuzhi_version_" . $departmentId;
        $settingVersion = Cache::get($versionCacheKey);

        if (empty($settingVersion)) {
            $setting = SystemSetting::where('department_id', $departmentId)
                ->where('feature', 'ticket_machine_version')
                ->where('status', 1)
                ->first();

            $settingVersion = $setting ? $setting->content : '';
            // 缓存版本号（10分钟）
            Cache::set($versionCacheKey, $settingVersion, 600);
        }

        // 版本号比对（只有配置了版本号且客户端传递了版本号才进行比对）
        if (!empty($settingVersion) && !empty($clientVersion) && $settingVersion !== $clientVersion) {
            // 获取下载链接
            $downloadCacheKey = "chuzhi_download_" . $departmentId;
            $downloadUrl = Cache::get($downloadCacheKey);

            if (empty($downloadUrl)) {
                $downloadSetting = SystemSetting::where('department_id', $departmentId)
                    ->where('feature', 'ticket_machine_download_url')
                    ->where('status', 1)
                    ->first();

                $downloadUrl = $downloadSetting ? $downloadSetting->content : '';
                // 缓存下载链接（10分钟）
                Cache::set($downloadCacheKey, $downloadUrl, 600);
            }

            return jsonFailResponse(trans('chuzhi_version_incorrect', [], 'message'), [
                'ticket_machine_version' => $settingVersion,
                'ticket_machine_download_url' => $downloadUrl,
            ], 466);
        }

        // 将设备信息传递给后续处理
        $request->chuzhi_device = $device;
        $request->department_id = $departmentId;

        return $handler($request);
    }
}
