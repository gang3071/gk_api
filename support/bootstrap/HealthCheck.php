<?php

namespace support\bootstrap;

use Webman\Bootstrap;
use Workerman\Worker;
use support\Db;
use support\Redis;

/**
 * 启动后健康检查
 * 在所有服务初始化完成后检查各模块连接状态
 */
class HealthCheck implements Bootstrap
{
    public static function start(?Worker $worker)
    {
        // 只在主进程启动完成后执行一次
        if ($worker !== null) {
            return;
        }

        // 使用全局标记确保只执行一次
        if (defined('HEALTHCHECK_EXECUTED')) {
            return;
        }
        define('HEALTHCHECK_EXECUTED', true);

        // 延迟执行，确保所有服务都已初始化
        \Workerman\Timer::add(1, function() {
            self::runHealthCheck();
        }, [], false);
    }

    private static function runHealthCheck()
    {
        $startTime = microtime(true);

        echo "\n";
        echo "========================================\n";
        echo "🔍 系统健康检查（启动后）\n";
        echo "========================================\n\n";

        $allPassed = true;
        $warnings = [];

        // 1. 检查 MySQL 数据库连接
        echo "📊 MySQL 数据库\n";
        try {
            $dbConfig = config('database.connections.mysql');

            // 处理读写分离配置
            if (isset($dbConfig['write']['host'])) {
                $writeHost = is_array($dbConfig['write']['host']) ? $dbConfig['write']['host'][0] : $dbConfig['write']['host'];
                $readHost = isset($dbConfig['read']['host']) ? (is_array($dbConfig['read']['host']) ? $dbConfig['read']['host'][0] : $dbConfig['read']['host']) : $writeHost;
            } else {
                $writeHost = $readHost = $dbConfig['host'] ?? env('DB_HOST', '127.0.0.1');
            }

            $port = $dbConfig['port'] ?? env('DB_PORT', '3306');
            $database = $dbConfig['database'] ?? env('DB_DATABASE', '');

            echo "   配置: {$writeHost}:{$port}/{$database}\n";
            echo "   读写分离: " . (isset($dbConfig['read']) ? '是' : '否') . "\n";

            // 测试连接
            $pdo = Db::connection()->getPdo();
            $version = $pdo->query('SELECT VERSION()')->fetchColumn();

            echo "   版本: MySQL {$version}\n";
            echo "   状态: ✅ 连接正常\n";

            // 测试查询
            $result = $pdo->query('SELECT 1')->fetchColumn();
            if ($result == 1) {
                echo "   测试: ✅ 查询正常\n";
            }

            echo "\n";
        } catch (\Throwable $e) {
            echo "   状态: ❌ 连接失败\n";
            echo "   错误: {$e->getMessage()}\n";
            echo "   位置: {$e->getFile()}:{$e->getLine()}\n\n";
            $allPassed = false;
        }

        // 2. 检查 Redis 连接
        echo "🔴 Redis 缓存\n";
        try {
            $redisConfig = config('redis.default');
            $host = $redisConfig['host'] ?? '127.0.0.1';
            $port = $redisConfig['port'] ?? 6379;
            $db = $redisConfig['database'] ?? 0;
            $hasPassword = !empty($redisConfig['password']);

            echo "   配置: {$host}:{$port} (DB:{$db})\n";
            echo "   密码: " . ($hasPassword ? '已设置' : '无') . "\n";

            // 测试连接
            $redis = Redis::connection('default');
            $pong = $redis->ping();

            if ($pong === true || $pong === 'PONG' || $pong === '+PONG') {
                echo "   状态: ✅ 连接正常\n";

                // 获取 Redis 信息
                $info = $redis->info('server');
                if (isset($info['redis_version'])) {
                    echo "   版本: Redis {$info['redis_version']}\n";
                }

                // 测试读写
                $testKey = 'healthcheck:' . time();
                $testValue = 'test_' . uniqid();
                $redis->set($testKey, $testValue, 10);
                $getValue = $redis->get($testKey);
                $redis->del($testKey);

                if ($getValue === $testValue) {
                    echo "   测试: ✅ 读写正常\n";
                } else {
                    echo "   测试: ⚠️  读写异常\n";
                    $warnings[] = 'Redis 读写测试失败';
                }
            } else {
                echo "   状态: ⚠️  PING 响应异常\n";
                $warnings[] = 'Redis PING 响应: ' . var_export($pong, true);
            }

            echo "\n";
        } catch (\Throwable $e) {
            echo "   状态: ❌ 连接失败\n";
            echo "   错误: {$e->getMessage()}\n";
            echo "   位置: {$e->getFile()}:{$e->getLine()}\n\n";
            $allPassed = false;
        }

        // 3. 检查 MongoDB 连接
        echo "🍃 MongoDB 数据库\n";

        if (!class_exists('MongoDB\Driver\Manager')) {
            echo "   状态: ⚠️  扩展未安装\n";
            echo "   提示: MongoDB 为可选服务\n\n";
        } else {
            try {
                $mongoHost = env('MONGODB_HOST', '127.0.0.1');
                $mongoPort = env('MONGODB_PORT', 27017);
                $mongoDatabase = env('MONGODB_DATABASE', 'luck3');
                $mongoUsername = env('MONGODB_USERNAME', '');
                $mongoPassword = env('MONGODB_PASSWORD', '');

                echo "   配置: {$mongoHost}:{$mongoPort}/{$mongoDatabase}\n";
                echo "   认证: " . (!empty($mongoUsername) ? '是' : '否') . "\n";

                // 构建连接字符串
                if (!empty($mongoUsername) && !empty($mongoPassword)) {
                    $mongoAuthDatabase = env('MONGODB_AUTH_DATABASE', 'admin');
                    $uri = "mongodb://{$mongoUsername}:{$mongoPassword}@{$mongoHost}:{$mongoPort}/{$mongoAuthDatabase}";
                } else {
                    $uri = "mongodb://{$mongoHost}:{$mongoPort}";
                }

                // 使用原生 MongoDB 扩展测试连接
                $manager = new \MongoDB\Driver\Manager($uri, [
                    'connectTimeoutMS' => 3000,
                    'serverSelectionTimeoutMS' => 3000,
                ]);

                // 执行 ping 命令获取版本信息
                $command = new \MongoDB\Driver\Command(['buildInfo' => 1]);
                $result = $manager->executeCommand($mongoDatabase, $command);
                $buildInfo = current($result->toArray());

                echo "   版本: MongoDB {$buildInfo->version}\n";
                echo "   状态: ✅ 连接正常\n";

                // 测试 ping
                $pingCommand = new \MongoDB\Driver\Command(['ping' => 1]);
                $pingResult = $manager->executeCommand($mongoDatabase, $pingCommand);
                $response = current($pingResult->toArray());

                if (isset($response->ok) && $response->ok == 1) {
                    echo "   测试: ✅ PING 正常\n";
                }

                echo "\n";
            } catch (\Throwable $e) {
                echo "   状态: ⚠️  连接失败\n";
                echo "   错误: {$e->getMessage()}\n";
                echo "   提示: MongoDB 为可选服务，不影响核心功能\n\n";
            }
        }

        // 4. 检查模块配置
        echo "⚙️  模块配置\n";

        // ThinkCache
        $cacheConfig = config('thinkcache');
        $defaultStore = $cacheConfig['default'] ?? 'file';
        echo "   ThinkCache: {$defaultStore}\n";

        // Session
        $sessionConfig = config('session');
        $sessionType = $sessionConfig['type'] ?? 'file';
        echo "   Session: {$sessionType}\n";

        // RateLimiter
        $rateLimiterConfig = config('plugin.webman.rate-limiter.app');
        if ($rateLimiterConfig && isset($rateLimiterConfig['enable']) && $rateLimiterConfig['enable']) {
            $driver = $rateLimiterConfig['driver'] ?? 'auto';
            echo "   RateLimiter: {$driver} (已启用)\n";
        } else {
            echo "   RateLimiter: 未启用\n";
        }

        // Redis Queue
        $redisQueueConfig = config('plugin.webman.redis-queue.process');
        if ($redisQueueConfig && isset($redisQueueConfig['consumer']['enable'])) {
            $enabled = $redisQueueConfig['consumer']['enable'];
            echo "   Redis Queue: " . ($enabled ? '已启用' : '已禁用') . "\n";
        }

        // WebSocket Push
        $pushConfig = config('plugin.webman.push.app');
        if ($pushConfig && isset($pushConfig['enable']) && $pushConfig['enable']) {
            echo "   WebSocket Push: 已启用\n";
        }

        echo "\n";

        // 5. 业务配置
        echo "🎮 业务配置\n";

        // 游戏平台代理
        $proxyEnabled = env('GAME_PLATFORM_PROXY_ENABLE', false);
        if ($proxyEnabled) {
            $proxyHost = env('GAME_PLATFORM_PROXY_HOST', '');
            $proxyPort = env('GAME_PLATFORM_PROXY_PORT', '');
            echo "   游戏平台代理: ✅ 已启用\n";
            echo "   代理地址: {$proxyHost}:{$proxyPort}\n";

            // Telegram 通知
            $telegramEnabled = env('GAME_PLATFORM_PROXY_TELEGRAM_NOTIFY', false);
            $telegramToken = env('TELEGRAM_BOT_TOKEN', '');
            $telegramChatId = env('TELEGRAM_CHAT_ID', '');
            if ($telegramEnabled && !empty($telegramToken) && !empty($telegramChatId)) {
                echo "   Telegram 通知: ✅ 已启用\n";
            } else {
                echo "   Telegram 通知: 未启用\n";
            }
        } else {
            echo "   游戏平台代理: 未启用\n";
        }

        // IP 白名单
        $ipWhitelistEnabled = env('IP_WHITELIST_ENABLE', false);
        echo "   IP 白名单: " . ($ipWhitelistEnabled ? '已启用' : '未启用') . "\n";

        // 调试模式
        $debugMode = env('APP_DEBUG', false);
        echo "   调试模式: " . ($debugMode ? '开启' : '关闭') . "\n";

        $env = env('APP_ENV', 'production');
        echo "   运行环境: {$env}\n";

        echo "\n";

        // 6. 总结
        $duration = round((microtime(true) - $startTime) * 1000, 2);

        echo "========================================\n";
        if ($allPassed) {
            if (count($warnings) > 0) {
                echo "⚠️  核心服务正常，但有 " . count($warnings) . " 个警告\n";
                foreach ($warnings as $i => $warning) {
                    echo "   " . ($i + 1) . ". {$warning}\n";
                }
            } else {
                echo "✅ 所有服务连接正常！\n";
            }
        } else {
            echo "❌ 部分核心服务连接失败！\n";
            echo "⚠️  请检查上述错误信息\n";
        }
        echo "========================================\n";
        echo "检查耗时: {$duration}ms\n";
        echo "检查时间: " . date('Y-m-d H:i:s') . "\n";
        echo "========================================\n\n";
    }
}
