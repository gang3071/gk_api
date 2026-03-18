<?php
/**
 * API进程配置
 *
 * 注意：业务进程（定时任务、结算、Socket等）已迁移到yjb_worker项目
 * 本项目仅保留开发监控进程
 */

use process\Monitor;
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
];
