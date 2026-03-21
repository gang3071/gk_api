#!/usr/bin/env php
<?php
require_once __DIR__ . '/vendor/autoload.php';

// 健康检查 - 只在 start 或 restart 时执行
$command = $argv[1] ?? 'start';
if (in_array($command, ['start', 'restart'])) {
    support\App::loadAllConfig(['route']);

    echo "\n========================================\n";
    echo "🔍 系统健康检查\n";
    echo "========================================\n\n";

    $start = microtime(true);
    $errors = [];

    // MySQL
    echo "📊 MySQL... ";
    try {
        $db = config('database.connections.mysql');
        $host = $db['write']['host'][0] ?? $db['host'] ?? env('DB_HOST');
        $port = $db['port'] ?? 3306;
        $name = $db['database'] ?? env('DB_DATABASE');
        $user = $db['write']['username'] ?? $db['username'] ?? env('DB_USERNAME');
        $pass = $db['write']['password'] ?? $db['password'] ?? env('DB_PASSWORD');

        $pdo = new PDO("mysql:host={$host};port={$port};dbname={$name}", $user, $pass, [PDO::ATTR_TIMEOUT => 3]);
        $ver = $pdo->query('SELECT VERSION()')->fetchColumn();
        echo "✅ v{$ver}\n";
    } catch (\Throwable $e) {
        echo "❌ {$e->getMessage()}\n";
        $errors[] = 'MySQL';
    }

    // Redis
    echo "🔴 Redis... ";
    try {
        $r = config('redis.default');
        $host = $r['host'] ?? '127.0.0.1';
        $port = $r['port'] ?? 6379;
        $pass = $r['password'] ?? null;
        $db = $r['database'] ?? 0;

        $redis = new Redis();
        $redis->connect($host, $port, 3);
        if ($pass) $redis->auth($pass);
        $redis->select($db);
        $redis->ping();
        $info = $redis->info('server');
        $ver = $info['redis_version'] ?? 'unknown';
        echo "✅ v{$ver} ({$host}:{$port})\n";
        $redis->close();
    } catch (\Throwable $e) {
        echo "❌ {$e->getMessage()}\n";
        $errors[] = 'Redis';
    }

    // MongoDB
    echo "🍃 MongoDB... ";
    try {
        $host = env('MONGODB_HOST', '127.0.0.1');
        $port = env('MONGODB_PORT', 27017);
        $user = env('MONGODB_USERNAME', '');
        $pass = env('MONGODB_PASSWORD', '');

        if ($user && $pass) {
            $uri = "mongodb://{$user}:{$pass}@{$host}:{$port}/" . env('MONGODB_AUTH_DATABASE', 'admin');
        } else {
            $uri = "mongodb://{$host}:{$port}";
        }

        $m = new \MongoDB\Driver\Manager($uri, ['connectTimeoutMS' => 3000]);
        $cmd = new \MongoDB\Driver\Command(['buildInfo' => 1]);
        $res = $m->executeCommand(env('MONGODB_DATABASE', 'test'), $cmd);
        $info = current($res->toArray());
        echo "✅ v{$info->version}\n";
    } catch (\Throwable $e) {
        echo "⚠️  可选服务\n";
    }

    $time = round((microtime(true) - $start) * 1000, 2);

    echo "\n";
    if (count($errors) > 0) {
        echo "❌ 失败: " . implode(', ', $errors) . "\n";
        echo "⚠️  按 Ctrl+C 取消，或等待 3 秒继续...\n";
        for ($i = 3; $i > 0; $i--) {
            echo "\r倒计时: {$i} 秒  ";
            sleep(1);
        }
        echo "\n";
    } else {
        echo "✅ 所有服务正常\n";
    }
    echo "========================================\n";
    echo "检查耗时: {$time}ms\n\n";
}

support\App::run();
