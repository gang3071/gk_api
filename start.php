#!/usr/bin/env php
<?php
require_once __DIR__ . '/vendor/autoload.php';

// 在 Workerman 启动之前执行健康检查
$command = $argv[1] ?? 'start';
if (in_array($command, ['start', 'restart'])) {
    echo "\n========================================\n";
    echo "🔍 启动健康检查\n";
    echo "========================================\n\n";

    $startTime = microtime(true);
    $allPassed = true;
    $errors = [];

    // 加载配置
    support\App::loadAllConfig(['route']);

    // 1. MySQL
    echo "📊 MySQL... ";
    try {
        $pdo = support\Db::connection()->getPdo();
        $pdo->query('SELECT 1')->fetchColumn();
        echo "✅\n";
    } catch (\Throwable $e) {
        echo "❌ {$e->getMessage()}\n";
        $allPassed = false;
        $errors[] = 'MySQL';
    }

    // 2. Redis
    echo "🔴 Redis... ";
    try {
        $redis = support\Redis::connection('default');
        $redis->ping();
        echo "✅\n";
    } catch (\Throwable $e) {
        echo "❌ {$e->getMessage()}\n";
        $allPassed = false;
        $errors[] = 'Redis';
    }

    // 3. MongoDB
    echo "🍃 MongoDB... ";
    try {
        $mongoHost = env('MONGODB_HOST', '127.0.0.1');
        $mongoPort = env('MONGODB_PORT', 27017);
        $mongoDatabase = env('MONGODB_DATABASE', 'luck3');
        $mongoUsername = env('MONGODB_USERNAME', '');
        $mongoPassword = env('MONGODB_PASSWORD', '');

        if (!empty($mongoUsername) && !empty($mongoPassword)) {
            $uri = "mongodb://{$mongoUsername}:{$mongoPassword}@{$mongoHost}:{$mongoPort}/" . env('MONGODB_AUTH_DATABASE', 'admin');
        } else {
            $uri = "mongodb://{$mongoHost}:{$mongoPort}";
        }

        $manager = new \MongoDB\Driver\Manager($uri, ['connectTimeoutMS' => 3000]);
        $command = new \MongoDB\Driver\Command(['ping' => 1]);
        $manager->executeCommand($mongoDatabase, $command);
        echo "✅\n";
    } catch (\Throwable $e) {
        echo "⚠️  (可选)\n";
    }

    $duration = round((microtime(true) - $startTime) * 1000, 2);
    echo "\n";

    if ($allPassed) {
        echo "✅ 检查通过 ({$duration}ms)\n";
    } else {
        echo "❌ 检查失败: " . implode(', ', $errors) . " ({$duration}ms)\n";
        echo "⚠️  按 Ctrl+C 取消启动，或等待 3 秒继续...\n";
        for ($i = 3; $i > 0; $i--) {
            echo "\r倒计时: {$i} 秒...";
            sleep(1);
        }
        echo "\n";
    }

    echo "========================================\n\n";
}

support\App::run();
