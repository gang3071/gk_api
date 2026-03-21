#!/usr/bin/env php
<?php
require_once __DIR__ . '/vendor/autoload.php';

// 在 Workerman 启动之前执行健康检查
$command = $argv[1] ?? 'start';
if (in_array($command, ['start', 'restart'])) {
    // 直接执行健康检查脚本
    $healthCheckScript = __DIR__ . '/healthcheck.php';
    if (file_exists($healthCheckScript)) {
        require_once $healthCheckScript;
        echo "\n"; // 换行分隔
    }
}

support\App::run();
