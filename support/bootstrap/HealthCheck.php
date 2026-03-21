<?php

namespace support\bootstrap;

use Webman\Bootstrap;
use Workerman\Worker;
use support\Db;
use support\Redis;
// MongoDB 动态加载，避免版本兼容性导致启动失败

/**
 * 启动时健康检查
 * 检查 MySQL、Redis、MongoDB 连接是否正常
 */
class HealthCheck implements Bootstrap
{
    /**
     * 输出信息到控制台
     */
    private static function out($message)
    {
        // 直接写到标准输出，确保能看到
        echo $message;
        flush();
    }

    public static function start(?Worker $worker)
    {
        // 只在主进程执行一次
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        $startTime = microtime(true);

        self::out("\n========================================\n");
        self::out("🔍 启动健康检查...\n");
        self::out("========================================\n\n");

        $allPassed = true;
        $errors = [];

        // 1. 检查 MySQL 数据库连接
        self::out("📊 检查 MySQL 连接...\n");
        try {
            $dbConfig = config('database.connections.mysql');
            self::out("   主机: {$dbConfig['host']}:{$dbConfig['port']}\n");
            self::out("   数据库: {$dbConfig['database']}\n");

            // 测试连接
            $pdo = Db::connection()->getPdo();
            $result = $pdo->query('SELECT 1')->fetchColumn();

            if ($result == 1) {
                self::out("   ✅ MySQL 连接正常\n\n");
            } else {
                self::out("   ❌ MySQL 查询失败\n\n");
                $allPassed = false;
                $errors[] = 'MySQL 查询失败';
            }
        } catch (\Throwable $e) {
            self::out("   ❌ MySQL 连接失败: {$e->getMessage()}\n");
            self::out("   📂 {$e->getFile()}:{$e->getLine()}\n\n");
            $allPassed = false;
            $errors[] = 'MySQL: ' . $e->getMessage();
        }

        // 2. 检查 Redis 连接
        self::out("🔴 检查 Redis 连接...\n");
        try {
            $redisConfig = config('redis.default');
            self::out("   主机: {$redisConfig['host']}:{$redisConfig['port']}\n");
            self::out("   数据库: {$redisConfig['database']}\n");

            // 测试连接
            $redis = Redis::connection('default');
            $pong = $redis->ping();

            if ($pong === true || $pong === 'PONG' || $pong === '+PONG') {
                self::out("   ✅ Redis 连接正常\n");

                // 测试读写
                $testKey = 'healthcheck:' . time();
                $redis->set($testKey, 'test', 10);
                $value = $redis->get($testKey);
                $redis->del($testKey);

                if ($value === 'test') {
                    self::out("   ✅ Redis 读写正常\n\n");
                }
            } else {
                self::out("   ❌ Redis PING 失败\n\n");
                $allPassed = false;
                $errors[] = 'Redis PING 失败';
            }
        } catch (\Throwable $e) {
            self::out("   ❌ Redis 连接失败: {$e->getMessage()}\n");
            self::out("   📂 {$e->getFile()}:{$e->getLine()}\n\n");
            $allPassed = false;
            $errors[] = 'Redis: ' . $e->getMessage();
        }

        // 3. 检查 MongoDB 连接
        self::out("🍃 检查 MongoDB 连接...\n");

        // 检查 MongoDB 扩展是否已加载
        if (!class_exists('MongoDB\Driver\Manager')) {
            self::out("   ⚠️  MongoDB 扩展未安装\n");
            self::out("   💡 跳过 MongoDB 检查\n\n");
        } else {
            try {
                $mongoHost = env('MONGODB_HOST', '127.0.0.1');
                $mongoPort = env('MONGODB_PORT', 27017);
                $mongoDatabase = env('MONGODB_DATABASE', 'luck3');
                $mongoUsername = env('MONGODB_USERNAME', '');
                $mongoPassword = env('MONGODB_PASSWORD', '');

                self::out("   主机: {$mongoHost}:{$mongoPort}\n");
                self::out("   数据库: {$mongoDatabase}\n");

                // 构建连接字符串
                if (!empty($mongoUsername) && !empty($mongoPassword)) {
                    $mongoAuthDatabase = env('MONGODB_AUTH_DATABASE', 'admin');
                    $uri = "mongodb://{$mongoUsername}:{$mongoPassword}@{$mongoHost}:{$mongoPort}/{$mongoAuthDatabase}";
                } else {
                    $uri = "mongodb://{$mongoHost}:{$mongoPort}";
                }

                // 使用原生 MongoDB 扩展测试连接，避免库版本兼容性问题
                $manager = new \MongoDB\Driver\Manager($uri, [
                    'connectTimeoutMS' => 3000,
                    'serverSelectionTimeoutMS' => 3000,
                ]);

                // 执行简单的 ping 命令
                $command = new \MongoDB\Driver\Command(['ping' => 1]);
                $result = $manager->executeCommand($mongoDatabase, $command);
                $response = current($result->toArray());

                if (isset($response->ok) && $response->ok == 1) {
                    self::out("   ✅ MongoDB 连接正常\n\n");
                } else {
                    self::out("   ❌ MongoDB PING 失败\n\n");
                    $allPassed = false;
                    $errors[] = 'MongoDB PING 失败';
                }
            } catch (\Throwable $e) {
                $errorMsg = $e->getMessage();
                self::out("   ❌ MongoDB 连接失败: {$errorMsg}\n\n");
                // MongoDB 失败不影响启动，只记录警告
                self::out("   💡 提示: MongoDB 连接失败不会阻止启动\n\n");
            }
        }

        // 4. 检查配置
        self::out("⚙️  检查系统配置...\n");

        // ThinkCache
        $cacheConfig = config('thinkcache');
        $defaultStore = $cacheConfig['default'] ?? 'file';
        self::out("   ThinkCache 驱动: {$defaultStore}\n");

        // Session
        $sessionConfig = config('session');
        $sessionType = $sessionConfig['type'] ?? 'file';
        self::out("   Session 驱动: {$sessionType}\n");

        // RateLimiter
        $rateLimiterConfig = config('plugin.webman.rate-limiter.app');
        if ($rateLimiterConfig && isset($rateLimiterConfig['enable']) && $rateLimiterConfig['enable']) {
            $driver = $rateLimiterConfig['driver'] ?? 'auto';
            self::out("   RateLimiter 驱动: {$driver}\n");

            if ($driver === 'redis' || $driver === 'auto') {
                self::out("   💡 提示: RateLimiter 可能使用 Redis\n");
            }
        } else {
            self::out("   RateLimiter: 未启用\n");
        }

        // 游戏平台代理
        $proxyEnabled = env('GAME_PLATFORM_PROXY_ENABLE', false);
        if ($proxyEnabled) {
            $proxyHost = env('GAME_PLATFORM_PROXY_HOST', '');
            $proxyPort = env('GAME_PLATFORM_PROXY_PORT', '');
            self::out("   游戏平台代理: ✅ 已启用 ({$proxyHost}:{$proxyPort})\n");
        } else {
            self::out("   游戏平台代理: 未启用\n");
        }

        // Telegram 通知
        $telegramEnabled = env('GAME_PLATFORM_PROXY_TELEGRAM_NOTIFY', false);
        $telegramToken = env('TELEGRAM_BOT_TOKEN', '');
        $telegramChatId = env('TELEGRAM_CHAT_ID', '');
        if ($telegramEnabled && !empty($telegramToken) && !empty($telegramChatId)) {
            self::out("   Telegram 通知: ✅ 已启用\n");
        } else {
            self::out("   Telegram 通知: 未启用\n");
        }

        self::out("\n");

        // 5. 总结
        $duration = round((microtime(true) - $startTime) * 1000, 2);

        self::out("========================================\n");
        if ($allPassed) {
            self::out("✅ 所有核心服务连接正常！\n");
            self::out("========================================\n");
            self::out("检查耗时: {$duration}ms\n\n");
        } else {
            self::out("❌ 检测到 " . count($errors) . " 个问题：\n");
            foreach ($errors as $i => $error) {
                self::out("   " . ($i + 1) . ". {$error}\n");
            }
            self::out("========================================\n");
            self::out("检查耗时: {$duration}ms\n\n");
            self::out("⚠️  警告: 继续启动可能导致运行时错误！\n");
            self::out("按 Ctrl+C 可以取消启动...\n\n");

            // 给用户3秒时间决定
            for ($i = 3; $i > 0; $i--) {
                self::out("倒计时: {$i} 秒...\r");
                sleep(1);
            }
            self::out("\n继续启动...\n\n");
        }
    }
}
