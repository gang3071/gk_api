<?php

/**
 * 玩家积分系统配置
 *
 * 数据流程：
 * 1. 玩家下注 → gk_work 统计打码量
 * 2. gk_work 发送积分队列 → gk_api 消费
 * 3. gk_api 实时累加积分到 Redis
 * 4. 每日定时汇总记录到 MySQL
 *
 * 积分比率（平台/VIP 加成）在 vip_level_point 表中按等级+平台维护，不在此配置。
 */

return [

    // 每日获取上限（积分），0 = 不限制
    'daily_limit' => env('POINTS_DAILY_LIMIT', 0),

    // 活动期间积分倍数（1.0=无活动，2.0=双倍）
    'activity_multiple' => env('POINTS_ACTIVITY_MULTIPLE', 1.0),

    // 活动生效时间范围（null=始终生效）
    'activity_period' => [
        'start' => env('POINTS_ACTIVITY_START', null),
        'end'   => env('POINTS_ACTIVITY_END', null),
    ],

    // Redis 积分 Hash 的缓存过期时间（秒），过期后从 MySQL 重新加载
    'redis_ttl' => 86400 * 365,

    /**
     * 是否启用每日汇总
     *
     * true = 每天凌晨自动汇总前一天的积分
     * false = 仅实时累加，不生成汇总记录
     */
    'daily_summary_enabled' => env('POINTS_DAILY_SUMMARY', true),

    // 积分变动推送开关及阈值（变动量 >= threshold 才推送）
    'push_enabled'   => env('POINTS_PUSH_ENABLED', true),
    'push_threshold' => env('POINTS_PUSH_THRESHOLD', 10),

    // 日志通道
    'log_channel' => 'player_points',
];
