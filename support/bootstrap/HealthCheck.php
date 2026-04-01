<?php

namespace support\bootstrap;

use support\Db;
use support\Redis;
use Webman\Bootstrap;
use Workerman\Worker;

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

        // 直接执行健康检查
        self::runHealthCheck();
    }

    private static function out($msg)
    {
        // 输出到控制台
        echo $msg;

        // 同时写入日志文件
        static $logFile = null;
        if ($logFile === null) {
            $logFile = runtime_path() . '/logs/healthcheck.log';
            @file_put_contents($logFile, '');  // 清空旧日志
        }
        @file_put_contents($logFile, $msg, FILE_APPEND);
    }

    private static function runHealthCheck()
    {
        $startTime = microtime(true);

        self::out("\n");
        self::out("========================================\n");
        self::out("🔍 系统健康检查（启动后）\n");
        self::out("========================================\n\n");

        $allPassed = true;
        $warnings = [];

        // 1. 检查 MySQL 数据库连接
        self::out("📊 MySQL 数据库\n");
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

            self::out("   配置: {$writeHost}:{$port}/{$database}\n");
            self::out("   读写分离: " . (isset($dbConfig['read']) ? '是' : '否') . "\n");

            // 测试连接
            $pdo = Db::connection()->getPdo();
            $version = $pdo->query('SELECT VERSION()')->fetchColumn();

            self::out("   版本: MySQL {$version}\n");
            self::out("   状态: ✅ 连接正常\n");

            // 测试查询
            $result = $pdo->query('SELECT 1')->fetchColumn();
            if ($result == 1) {
                self::out("   测试: ✅ 查询正常\n");
            }

            self::out("\n");
        } catch (\Throwable $e) {
            self::out("   状态: ❌ 连接失败\n");
            self::out("   错误: {$e->getMessage()}\n");
            self::out("   位置: {$e->getFile()}:{$e->getLine()}\n\n");
            $allPassed = false;
        }

        // 2. 检查 Redis 连接
        self::out("🔴 Redis 缓存\n");
        try {
            $redisConfig = config('redis.default');
            $host = $redisConfig['host'] ?? '127.0.0.1';
            $port = $redisConfig['port'] ?? 6379;
            $db = $redisConfig['database'] ?? 0;
            $hasPassword = !empty($redisConfig['password']);

            self::out("   配置: {$host}:{$port} (DB:{$db})\n");
            self::out("   密码: " . ($hasPassword ? '已设置' : '无') . "\n");

            // 测试连接
            $redis = Redis::connection('default');
            $pong = $redis->ping();

            if ($pong === true || $pong === 'PONG' || $pong === '+PONG') {
                self::out("   状态: ✅ 连接正常\n");

                // 获取 Redis 信息
                $info = $redis->info('server');
                if (isset($info['redis_version'])) {
                    self::out("   版本: Redis {$info['redis_version']}\n");
                }

                // 测试读写
                $testKey = 'healthcheck:' . time();
                $testValue = 'test_' . uniqid();
                $redis->set($testKey, $testValue, 10);
                $getValue = $redis->get($testKey);
                $redis->del($testKey);

                if ($getValue === $testValue) {
                    self::out("   测试: ✅ 读写正常\n");
                } else {
                    self::out("   测试: ⚠️  读写异常\n");
                    $warnings[] = 'Redis 读写测试失败';
                }
            } else {
                self::out("   状态: ⚠️  PING 响应异常\n");
                $warnings[] = 'Redis PING 响应: ' . var_export($pong, true);
            }

            self::out("\n");
        } catch (\Throwable $e) {
            self::out("   状态: ❌ 连接失败\n");
            self::out("   错误: {$e->getMessage()}\n");
            self::out("   位置: {$e->getFile()}:{$e->getLine()}\n\n");
            $allPassed = false;
        }

        // 3. MongoDB 已移除
        // MongoDB 日志功能已迁移或移除，不再检查

        // 4. 检查模块配置
        self::out("⚙️  模块配置\n");

        // ThinkCache
        $cacheConfig = config('thinkcache');
        $defaultStore = $cacheConfig['default'] ?? 'file';
        self::out("   ThinkCache: {$defaultStore}\n");

        // Session
        $sessionConfig = config('session');
        $sessionType = $sessionConfig['type'] ?? 'file';
        self::out("   Session: {$sessionType}\n");

        // RateLimiter
        $rateLimiterConfig = config('plugin.webman.rate-limiter.app');
        if ($rateLimiterConfig && isset($rateLimiterConfig['enable']) && $rateLimiterConfig['enable']) {
            $driver = $rateLimiterConfig['driver'] ?? 'auto';
            self::out("   RateLimiter: {$driver} (已启用)\n");
        } else {
            self::out("   RateLimiter: 未启用\n");
        }

        // Redis Queue
        $redisQueueConfig = config('plugin.webman.redis-queue.process');
        if ($redisQueueConfig && isset($redisQueueConfig['consumer']['enable'])) {
            $enabled = $redisQueueConfig['consumer']['enable'];
            self::out("   Redis Queue: " . ($enabled ? '已启用' : '已禁用') . "\n");
        }

        // WebSocket Push
        $pushConfig = config('plugin.webman.push.app');
        if ($pushConfig && isset($pushConfig['enable']) && $pushConfig['enable']) {
            self::out("   WebSocket Push: 已启用\n");
        }

        self::out("\n");

        // 5. 业务配置
        self::out("🎮 业务配置\n");

        // 游戏平台代理
        $proxyEnabled = env('GAME_PLATFORM_PROXY_ENABLE', false);
        if ($proxyEnabled) {
            $proxyHost = env('GAME_PLATFORM_PROXY_HOST', '');
            $proxyPort = env('GAME_PLATFORM_PROXY_PORT', '');
            self::out("   游戏平台代理: ✅ 已启用\n");
            self::out("   代理地址: {$proxyHost}:{$proxyPort}\n");

            // Telegram 通知
            $telegramEnabled = env('GAME_PLATFORM_PROXY_TELEGRAM_NOTIFY', false);
            $telegramToken = env('TELEGRAM_BOT_TOKEN', '');
            $telegramChatId = env('TELEGRAM_CHAT_ID', '');
            if ($telegramEnabled && !empty($telegramToken) && !empty($telegramChatId)) {
                self::out("   Telegram 通知: ✅ 已启用\n");
            } else {
                self::out("   Telegram 通知: 未启用\n");
            }
        } else {
            self::out("   游戏平台代理: 未启用\n");
        }

        // IP 白名单
        $ipWhitelistEnabled = env('IP_WHITELIST_ENABLE', false);
        self::out("   IP 白名单: " . ($ipWhitelistEnabled ? '已启用' : '未启用') . "\n");

        // 调试模式
        $debugMode = env('APP_DEBUG', false);
        self::out("   调试模式: " . ($debugMode ? '开启' : '关闭') . "\n");

        $env = env('APP_ENV', 'production');
        self::out("   运行环境: {$env}\n");

        self::out("\n");

        // 6. 总结
        $duration = round((microtime(true) - $startTime) * 1000, 2);

        self::out("========================================\n");
        if ($allPassed) {
            if (count($warnings) > 0) {
                self::out("⚠️  核心服务正常，但有 " . count($warnings) . " 个警告\n");
                foreach ($warnings as $i => $warning) {
                    self::out("   " . ($i + 1) . ". {$warning}\n");
                }
            } else {
                self::out("✅ 所有服务连接正常！\n");
            }
        } else {
            self::out("❌ 部分核心服务连接失败！\n");
            self::out("⚠️  请检查上述错误信息\n");
        }
        self::out("========================================\n");
        self::out("检查耗时: {$duration}ms\n");
        self::out("检查时间: " . date('Y-m-d H:i:s') . "\n");
        self::out("========================================\n\n");
    }
}
