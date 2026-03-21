<?php

namespace support\bootstrap;

use Webman\Bootstrap;
use Workerman\Worker;
use support\Db;
use support\Redis;
use MongoDB\Client as MongoDBClient;

/**
 * 启动时健康检查
 * 检查 MySQL、Redis、MongoDB 连接是否正常
 */
class HealthCheck implements Bootstrap
{
    public static function start(?Worker $worker)
    {
        if ($worker) {
            return;
        }

        echo "\n========================================\n";
        echo "🔍 启动健康检查...\n";
        echo "========================================\n\n";

        $allPassed = true;

        // 1. 检查 MySQL 数据库连接
        echo "📊 检查 MySQL 连接...\n";
        try {
            $dbConfig = config('database.connections.mysql');
            echo "   - 主机: {$dbConfig['host']}:{$dbConfig['port']}\n";
            echo "   - 数据库: {$dbConfig['database']}\n";

            // 测试连接
            $pdo = Db::connection()->getPdo();
            $result = $pdo->query('SELECT 1')->fetchColumn();

            if ($result == 1) {
                echo "   ✅ MySQL 连接正常\n\n";
            } else {
                echo "   ❌ MySQL 查询失败\n\n";
                $allPassed = false;
            }
        } catch (\Throwable $e) {
            echo "   ❌ MySQL 连接失败: {$e->getMessage()}\n";
            echo "   📂 文件: {$e->getFile()}:{$e->getLine()}\n\n";
            $allPassed = false;
        }

        // 2. 检查 Redis 连接
        echo "🔴 检查 Redis 连接...\n";
        try {
            $redisConfig = config('redis.default');
            echo "   - 主机: {$redisConfig['host']}:{$redisConfig['port']}\n";
            echo "   - 数据库: {$redisConfig['database']}\n";

            // 测试连接
            $redis = Redis::connection('default');
            $pong = $redis->ping();

            if ($pong === true || $pong === 'PONG' || $pong === '+PONG') {
                echo "   ✅ Redis 连接正常\n\n";
            } else {
                echo "   ❌ Redis PING 失败\n\n";
                $allPassed = false;
            }
        } catch (\Throwable $e) {
            echo "   ❌ Redis 连接失败: {$e->getMessage()}\n";
            echo "   📂 文件: {$e->getFile()}:{$e->getLine()}\n\n";
            $allPassed = false;
        }

        // 3. 检查 MongoDB 连接
        echo "🍃 检查 MongoDB 连接...\n";
        try {
            $mongoHost = env('MONGODB_HOST', '127.0.0.1');
            $mongoPort = env('MONGODB_PORT', 27017);
            $mongoDatabase = env('MONGODB_DATABASE', 'luck3');
            $mongoUsername = env('MONGODB_USERNAME', '');
            $mongoPassword = env('MONGODB_PASSWORD', '');

            echo "   - 主机: {$mongoHost}:{$mongoPort}\n";
            echo "   - 数据库: {$mongoDatabase}\n";

            // 构建连接字符串
            if (!empty($mongoUsername) && !empty($mongoPassword)) {
                $mongoAuthDatabase = env('MONGODB_AUTH_DATABASE', 'admin');
                $uri = "mongodb://{$mongoUsername}:{$mongoPassword}@{$mongoHost}:{$mongoPort}/{$mongoAuthDatabase}";
            } else {
                $uri = "mongodb://{$mongoHost}:{$mongoPort}";
            }

            // 测试连接
            $client = new MongoDBClient($uri, [], [
                'connectTimeoutMS' => 3000,
                'serverSelectionTimeoutMS' => 3000,
            ]);

            // 执行 ping 命令
            $admin = $client->selectDatabase($mongoDatabase);
            $result = $admin->command(['ping' => 1]);

            if (isset($result['ok']) && $result['ok'] == 1) {
                echo "   ✅ MongoDB 连接正常\n\n";
            } else {
                echo "   ❌ MongoDB PING 失败\n\n";
                $allPassed = false;
            }
        } catch (\Throwable $e) {
            echo "   ❌ MongoDB 连接失败: {$e->getMessage()}\n";
            echo "   📂 文件: {$e->getFile()}:{$e->getLine()}\n\n";
            $allPassed = false;
        }

        // 4. 检查 ThinkCache 配置
        echo "💾 检查 ThinkCache 配置...\n";
        try {
            $cacheConfig = config('thinkcache');
            $defaultStore = $cacheConfig['default'] ?? 'file';
            echo "   - 默认驱动: {$defaultStore}\n";

            if ($defaultStore === 'redis') {
                echo "   ⚠️  警告: 使用 Redis 缓存，确保 Redis 连接正常\n\n";
            } else {
                echo "   ✅ 使用文件缓存\n\n";
            }
        } catch (\Throwable $e) {
            echo "   ❌ ThinkCache 配置检查失败: {$e->getMessage()}\n\n";
        }

        // 5. 检查 Session 配置
        echo "🔐 检查 Session 配置...\n";
        try {
            $sessionConfig = config('session');
            $sessionType = $sessionConfig['type'] ?? 'file';
            echo "   - 驱动类型: {$sessionType}\n";

            if ($sessionType === 'redis') {
                echo "   ⚠️  警告: 使用 Redis Session，确保 Redis 连接正常\n\n";
            } else {
                echo "   ✅ 使用文件 Session\n\n";
            }
        } catch (\Throwable $e) {
            echo "   ❌ Session 配置检查失败: {$e->getMessage()}\n\n";
        }

        // 6. 检查限流器配置
        echo "⏱️  检查 RateLimiter 配置...\n";
        try {
            $rateLimiterConfig = config('plugin.webman.rate-limiter.app');
            if ($rateLimiterConfig && isset($rateLimiterConfig['enable']) && $rateLimiterConfig['enable']) {
                $driver = $rateLimiterConfig['driver'] ?? 'auto';
                echo "   - 驱动类型: {$driver}\n";

                if ($driver === 'redis' || $driver === 'auto') {
                    echo "   ⚠️  警告: RateLimiter 可能使用 Redis，确保 Redis 连接正常\n";
                    echo "   💡 提示: 如果 Redis 连接失败，建议改为 'memory' 驱动\n\n";
                } else {
                    echo "   ✅ 使用 {$driver} 驱动\n\n";
                }
            } else {
                echo "   ℹ️  RateLimiter 未启用\n\n";
            }
        } catch (\Throwable $e) {
            echo "   ❌ RateLimiter 配置检查失败: {$e->getMessage()}\n\n";
        }

        // 7. 总结
        echo "========================================\n";
        if ($allPassed) {
            echo "✅ 所有核心服务连接正常，可以启动！\n";
        } else {
            echo "❌ 部分服务连接失败，请检查配置后再启动！\n";
            echo "\n💡 提示：\n";
            echo "   - 检查 .env 配置是否正确\n";
            echo "   - 确保 MySQL、Redis、MongoDB 服务已启动\n";
            echo "   - 检查网络连接和防火墙设置\n";
            echo "\n⚠️  警告：强制继续启动可能导致运行时错误！\n";
        }
        echo "========================================\n\n";

        if (!$allPassed) {
            // 给用户 5 秒时间决定是否继续
            echo "按 Ctrl+C 取消启动，或等待 5 秒后自动继续...\n";
            sleep(5);
            echo "继续启动...\n\n";
        }
    }
}
