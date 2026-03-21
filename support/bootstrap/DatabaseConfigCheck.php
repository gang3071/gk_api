<?php

namespace support\bootstrap;

use Webman\Bootstrap;
use Workerman\Worker;

/**
 * 数据库配置检查（启动时显示）
 */
class DatabaseConfigCheck implements Bootstrap
{
    public static function start(?Worker $worker)
    {
        // 只在主进程启动时显示，且仅在调试模式下
        if ($worker && $worker->id !== 0) {
            return;
        }

        // 如果设置了 DB_DEBUG=false 则不显示
        if (env('DB_DEBUG', 'true') === 'false') {
            return;
        }

        echo "\n";
        echo "╔════════════════════════════════════════════════════════════════╗\n";
        echo "║          数据库配置检查 (Database Configuration Check)         ║\n";
        echo "╚════════════════════════════════════════════════════════════════╝\n\n";

        self::checkThinkOrmConfig();
        self::checkLaravelDbConfig();

        echo "════════════════════════════════════════════════════════════════\n\n";
    }

    /**
     * 检查 ThinkORM 配置
     */
    private static function checkThinkOrmConfig()
    {
        echo "【ThinkORM 配置】\n";
        echo "────────────────────────────────────────────────────────────────\n";

        $config = config('thinkorm');
        if (empty($config)) {
            echo "  ⚠️  ThinkORM 配置未找到\n\n";
            return;
        }

        $mysql = $config['connections']['mysql'] ?? [];
        $correct_password = 'XN,KeAX>Y~2?>p?k';

        echo "  主库配置:\n";
        echo "    └─ 主机: {$mysql['hostname']}\n";
        echo "    └─ 端口: {$mysql['hostport']}\n";
        echo "    └─ 数据库: {$mysql['database']}\n";
        echo "    └─ 用户名: {$mysql['username']}\n";

        $password = $mysql['password'];
        $password_match = ($password === $correct_password);
        $icon = $password_match ? '✓' : '✗';

        echo "    └─ 密码: " . str_repeat('*', strlen($password)) . " ({$icon} ";
        if ($password_match) {
            echo "正确";
        } else {
            echo "错误 - 长度:" . strlen($password) . ", 应为:" . strlen($correct_password);
        }
        echo ")\n";

        // 检查从库
        if (!empty($mysql['slave'])) {
            echo "\n  从库配置:\n";
            foreach ($mysql['slave'] as $index => $slave) {
                $slave_password = $slave['password'];
                $slave_match = ($slave_password === $correct_password);
                $slave_icon = $slave_match ? '✓' : '✗';

                echo "    └─ 从库 #{$index}: {$slave['hostname']} ({$slave_icon})\n";
            }
        }

        // 显示分布式配置
        echo "\n  分布式配置:\n";
        $deploy = $mysql['deploy'] ?? 0;
        $deploy_status = $deploy ? '启用' : '禁用';
        echo "    └─ deploy: {$deploy} ({$deploy_status}分布式)\n";
        echo "    └─ rw_separate: " . ($mysql['rw_separate'] ? 'true' : 'false') . " (读写分离)\n";
        echo "    └─ master_num: " . ($mysql['master_num'] ?? 1) . "\n";

        // 尝试连接
        echo "\n  连接测试:\n";
        try {
            // 强制使用主库连接
            $result = \think\facade\Db::query("SELECT 1 as test", [], true);  // 第三个参数 true 表示使用主库
            echo "    └─ ✓ ThinkORM 连接成功 (连接到: {$mysql['hostname']}:{$mysql['hostport']})\n";

            $info = \think\facade\Db::query("SELECT USER() as user, DATABASE() as db", [], true);
            if (!empty($info[0])) {
                echo "    └─ 当前用户: {$info[0]['user']}\n";
                echo "    └─ 当前数据库: {$info[0]['db']}\n";
            }
        } catch (\Throwable $e) {
            echo "    └─ ✗ ThinkORM 连接失败\n";
            echo "    └─ 错误: {$e->getMessage()}\n";
            echo "    └─ 错误码: {$e->getCode()}\n";

            if ($e->getCode() == 1045 || strpos($e->getMessage(), '1045') !== false) {
                echo "    └─ ⚠️  认证失败！\n";
                echo "    └─ 提示: Laravel DB能连接成功，说明不是MySQL授权问题\n";
                echo "    └─ 可能原因: ThinkORM的分布式配置或密码处理方式不同\n";

                // 尝试显示实际使用的配置
                echo "\n  调试信息:\n";
                echo "    └─ 尝试禁用分布式模式 (deploy => 0)\n";
                echo "    └─ 或检查从库配置的密码是否正确\n";
            }
        }

        echo "\n";
    }

    /**
     * 检查 Laravel DB 配置
     */
    private static function checkLaravelDbConfig()
    {
        echo "【Laravel Database 配置】\n";
        echo "────────────────────────────────────────────────────────────────\n";

        $config = config('database');
        if (empty($config)) {
            echo "  ⚠️  Laravel Database 配置未找到\n\n";
            return;
        }

        $mysql = $config['connections']['mysql'] ?? [];
        $correct_password = 'XN,KeAX>Y~2?>p?k';

        // 主库（写库）
        if (!empty($mysql['write'])) {
            $write_host = $mysql['write']['host'][0] ?? 'N/A';
            $write_username = $mysql['write']['username'] ?? 'N/A';
            $write_password = $mysql['write']['password'] ?? '';
            $write_match = ($write_password === $correct_password);
            $write_icon = $write_match ? '✓' : '✗';

            echo "  写库配置:\n";
            echo "    └─ 主机: {$write_host}\n";
            echo "    └─ 用户名: {$write_username}\n";
            echo "    └─ 密码: " . str_repeat('*', strlen($write_password)) . " ({$write_icon})\n";
        }

        // 从库（读库）
        if (!empty($mysql['read'])) {
            $read_host = $mysql['read']['host'][0] ?? 'N/A';
            $read_username = $mysql['read']['username'] ?? 'N/A';
            $read_password = $mysql['read']['password'] ?? '';
            $read_match = ($read_password === $correct_password);
            $read_icon = $read_match ? '✓' : '✗';

            echo "\n  读库配置:\n";
            echo "    └─ 主机: {$read_host}\n";
            echo "    └─ 用户名: {$read_username}\n";
            echo "    └─ 密码: " . str_repeat('*', strlen($read_password)) . " ({$read_icon})\n";
        }

        echo "\n  其他配置:\n";
        echo "    └─ 数据库: {$mysql['database']}\n";
        echo "    └─ 端口: {$mysql['port']}\n";
        echo "    └─ 字符集: {$mysql['charset']}\n";

        // 尝试连接
        echo "\n  连接测试:\n";
        try {
            $result = \support\Db::select("SELECT 1 as test");
            $write_host = $mysql['write']['host'][0] ?? $mysql['host'] ?? 'N/A';
            $port = $mysql['port'] ?? '3306';
            echo "    └─ ✓ Laravel DB 连接成功 (连接到: {$write_host}:{$port})\n";

            $info = \support\Db::select("SELECT USER() as user, DATABASE() as db");
            if (!empty($info[0])) {
                echo "    └─ 当前用户: {$info[0]->user}\n";
                echo "    └─ 当前数据库: {$info[0]->db}\n";
            }
        } catch (\Throwable $e) {
            echo "    └─ ✗ Laravel DB 连接失败\n";
            echo "    └─ 错误: {$e->getMessage()}\n";

            // 尝试获取更详细的错误信息
            if (method_exists($e, 'getCode')) {
                echo "    └─ 错误码: {$e->getCode()}\n";
            }

            if (strpos($e->getMessage(), '1045') !== false) {
                echo "    └─ ⚠️  认证失败，请检查密码和IP授权\n";
            }
        }

        echo "\n";
    }
}
