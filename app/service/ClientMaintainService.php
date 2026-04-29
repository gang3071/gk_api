<?php

namespace app\service;

use app\model\SystemSetting;
use support\Log;
use support\Redis;

/**
 * 客户端维护服务
 */
class ClientMaintainService
{
    /**
     * Redis 键前缀
     */
    private const REDIS_KEY_PREFIX = 'client_maintain:';

    /**
     * 维护状态缓存键（与 gk_admin 保持一致）
     */
    private const REDIS_KEY_STATUS = self::REDIS_KEY_PREFIX . 'status:';

    /**
     * 获取客户端维护状态（优先从缓存读取）
     *
     * @param int $departmentId 渠道ID（0=全局配置）
     * @return array ['is_maintenance' => bool, 'message' => string]
     */
    public static function getMaintenanceStatus(int $departmentId = 0): array
    {
        try {
            // 优先从缓存读取（使用与 gk_admin 相同的缓存键）
            $cacheKey = self::REDIS_KEY_STATUS . $departmentId;
            $redis = Redis::connection();
            $cached = $redis->get($cacheKey);

            if ($cached) {
                $data = json_decode($cached, true);
                if ($data && isset($data['is_maintenance'])) {
                    // 转换为 API 返回格式
                    return [
                        'is_maintenance' => $data['is_maintenance'],
                        'message' => $data['is_maintenance'] ? '系统维护中，请稍后再试' : '',
                        'maintenance_info' => $data['is_maintenance'] && isset($data['config']) ? [
                            'week' => $data['config']['week'] ?? null,
                            'start_time' => $data['config']['start_time'] ?? null,
                            'end_time' => $data['config']['end_time'] ?? null,
                        ] : null,
                    ];
                }
            }

            // 缓存未命中，查询数据库（降级处理）
            // 优先查询渠道配置，如果没有则查询全局配置
            /** @var SystemSetting $config */
            $config = SystemSetting::query()
                ->where('feature', 'client_maintain')
                ->where(function ($query) use ($departmentId) {
                    if ($departmentId > 0) {
                        $query->where('department_id', $departmentId)
                            ->orWhere('department_id', 0);
                    } else {
                        $query->where('department_id', 0);
                    }
                })
                ->orderByDesc('department_id') // 优先使用渠道配置
                ->first();

            if (!$config) {
                return [
                    'is_maintenance' => false,
                    'message' => '',
                ];
            }

            // 检查配置是否启用（0-关闭，1-打开）
            $isEnabled = $config->status == 1;

            // 只有启用状态才检查维护时间
            $isInMaintenance = $isEnabled && self::isInMaintenanceTime($config);

            $result = [
                'is_maintenance' => $isInMaintenance,
                'message' => $isInMaintenance ? '系统维护中，请稍后再试' : '',
                'maintenance_info' => $isInMaintenance ? [
                    'week' => $config->num,
                    'start_time' => $config->date_start,
                    'end_time' => $config->date_end,
                ] : null,
            ];

            // 回写缓存（5 分钟）
            $cacheData = [
                'is_maintenance' => $isInMaintenance,
                'config' => [
                    'status' => $config->status,
                    'week' => $config->num,
                    'start_time' => $config->date_start,
                    'end_time' => $config->date_end,
                ],
                'message' => $result['message'],
                'updated_at' => time(),
            ];
            $redis->setex($cacheKey, 300, json_encode($cacheData));

            return $result;

        } catch (\Throwable $e) {
            Log::error('获取客户端维护状态失败', [
                'error' => $e->getMessage(),
                'department_id' => $departmentId,
            ]);

            return [
                'is_maintenance' => false,
                'message' => '',
            ];
        }
    }

    /**
     * 判断当前时间是否在维护时间段内
     *
     * @param SystemSetting $config
     * @return bool
     */
    private static function isInMaintenanceTime(SystemSetting $config): bool
    {
        // 当前星期几（1-7，1=星期一，7=星期天）
        $currentWeek = (int)date('N');
        $currentTime = date('H:i:s');

        // 检查星期是否匹配
        if ($config->num != $currentWeek) {
            return false;
        }

        // 检查时间段是否匹配
        if (empty($config->date_start) || empty($config->date_end)) {
            return false;
        }

        return $currentTime >= $config->date_start && $currentTime <= $config->date_end;
    }
}
