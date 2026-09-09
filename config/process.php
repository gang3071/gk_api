<?php
/**
 * API进程配置
 *
 * 注意：业务进程（定时任务、结算、Socket等）已迁移到yjb_worker项目
 * 本项目仅保留开发监控进程
 */

use process\Monitor;
use process\WalletUnlockWorker;
use Workerman\Worker;

return [
    'monitor' => [
        'handler' => Monitor::class,
        'reloadable' => false,
        'constructor' => [
            'monitorDir' => [
                app_path(),
                config_path(),
                base_path() . '/process',
                base_path() . '/.env',
            ],
            'monitorExtensions' => ['php', 'env'],
            'options' => [
                'enable_file_monitor' => !Worker::$daemonize && DIRECTORY_SEPARATOR === '/',
                'enable_memory_monitor' => DIRECTORY_SEPARATOR === '/',
            ]
        ]
    ],

    // ========================================
    // 钱包解锁进程（订阅余额变化）
    // ========================================
    // 订阅 gk_work 的 Redis Pub/Sub，实时解锁钱包
    // - 延迟 < 50ms（实时性好）
    // - 不影响 gk_work 性能（完全解耦）
    // - 资源开销小（< 10 MB 内存）
    'wallet_unlock' => [
        'handler' => WalletUnlockWorker::class,
        'listen' => '',
        'count' => 1,  // 只需要 1 个进程
        'user' => '',
        'group' => '',
        'reloadable' => true,
        'reusePort' => false,
        'constructor' => [],
    ],

    // ========================================
    // 积分同步定时任务（拆分为3个独立进程）
    // ========================================

    // 每分钟批量同步脏数据（高频打码玩家）
    'points_sync_minutely' => [
        'handler' => \process\PlayerPointsSyncMinutely::class,
        'listen' => '',
        'count' => 1,
        'user' => '',
        'group' => '',
        'reloadable' => true,
        'reusePort' => false,
        'constructor' => [],
    ],

    // 每小时全量同步（兜底机制）
    'points_sync_hourly' => [
        'handler' => \process\PlayerPointsSyncHourly::class,
        'listen' => '',
        'count' => 1,
        'user' => '',
        'group' => '',
        'reloadable' => true,
        'reusePort' => false,
        'constructor' => [],
    ],

    // 每日汇总统计（凌晨2点）
    'points_sync_daily' => [
        'handler' => \process\PlayerPointsSyncDaily::class,
        'listen' => '',
        'count' => 1,
        'user' => '',
        'group' => '',
        'reloadable' => true,
        'reusePort' => false,
        'constructor' => [],
    ],
];
