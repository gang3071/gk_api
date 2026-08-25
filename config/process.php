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
];
