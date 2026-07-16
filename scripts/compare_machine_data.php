#!/usr/bin/env php
<?php
/**
 * 对比数据库和Redis中的机台数据差异
 *
 * 用法：php scripts/compare_machine_data.php [machine_id]
 * 例如：php scripts/compare_machine_data.php 1278
 */

// 定义项目根目录
define('BASE_PATH', __DIR__ . '/..');

// 加载 Webman 框架
require_once BASE_PATH . '/vendor/autoload.php';

// 加载环境变量
$envFile = BASE_PATH . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        putenv(trim($line));
    }
}

// 设置时区
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Asia/Shanghai');

// 加载配置文件
$config = [];
$configFiles = [
    'app', 'database', 'redis', 'server', 'log', 'process',
];
foreach ($configFiles as $configName) {
    $configFile = BASE_PATH . "/config/{$configName}.php";
    if (file_exists($configFile)) {
        $config[$configName] = require $configFile;
    }
}

// 初始化数据库连接
use Illuminate\Database\Capsule\Manager as Capsule;

$capsule = new Capsule;
if (isset($config['database']['connections'])) {
    foreach ($config['database']['connections'] as $name => $dbConfig) {
        $capsule->addConnection($dbConfig, $name);
    }
}
$capsule->setAsGlobal();
$capsule->bootEloquent();

// 初始化 Redis
\support\Redis::connection();

use support\Db;
use support\Redis;

echo "====================================\n";
echo "机台数据对比工具\n";
echo "====================================\n\n";

// 获取机台ID
$machineId = $argv[1] ?? null;

if (!$machineId) {
    echo "❌ 请提供机台ID\n";
    echo "用法: php scripts/compare_machine_data.php <machine_id>\n";
    echo "例如: php scripts/compare_machine_data.php 1278\n";
    exit(1);
}

try {
    // 1. 从数据库获取机台数据
    echo "📊 从数据库获取机台 #{$machineId} 的数据...\n";
    $dbMachine = Db::table('machine')
        ->where('id', $machineId)
        ->first();

    if (!$dbMachine) {
        echo "❌ 数据库中未找到机台 #{$machineId}\n";
        exit(1);
    }

    echo "✅ 数据库数据获取成功\n\n";

    // 2. 从Redis获取机台数据
    echo "📡 从Redis获取机台 #{$machineId} 的数据...\n";
    $redis = Redis::connection();

    // Redis中机台数据的key格式（需要根据实际情况调整）
    $redisKey = "machine:{$machineId}";
    $redisData = $redis->hGetAll($redisKey);

    if (empty($redisData)) {
        echo "⚠️  Redis中未找到key: {$redisKey}\n";
        echo "尝试其他可能的key格式...\n";

        // 尝试其他可能的格式
        $possibleKeys = [
            "machine_{$machineId}",
            "m:{$machineId}",
            "machine_data:{$machineId}",
            "slot_machine:{$machineId}",
        ];

        foreach ($possibleKeys as $key) {
            $redisData = $redis->hGetAll($key);
            if (!empty($redisData)) {
                echo "✅ 找到数据，使用key: {$key}\n\n";
                $redisKey = $key;
                break;
            }
        }

        if (empty($redisData)) {
            echo "❌ Redis中未找到机台数据\n";
            echo "\n尝试列出所有包含 machine 的 keys:\n";
            $keys = $redis->keys("*machine*{$machineId}*");
            if (!empty($keys)) {
                echo "找到以下keys:\n";
                foreach ($keys as $k) {
                    echo "  - {$k}\n";
                }
            } else {
                echo "未找到任何相关keys\n";
            }
            exit(1);
        }
    } else {
        echo "✅ Redis数据获取成功\n\n";
    }

    // 3. 对比关键字段
    echo "====================================\n";
    echo "字段对比结果\n";
    echo "====================================\n\n";

    // 定义需要对比的字段
    $compareFields = [
        'gaming' => '游戏状态',
        'keeping' => '保留状态',
        'gaming_user_id' => '游戏中玩家ID',
        'keeping_user_id' => '保留中玩家ID',
        'turn' => '转数',
        'score' => '得分',
        'point' => '分数',
        'pressure' => '压分',
        'reward_status' => '开奖状态',
        'is_use' => '使用中',
        'maintaining' => '维护状态',
        'auto_up_turn' => '自动上转',
        'move' => '移分',
    ];

    $differences = [];
    $matched = [];

    foreach ($compareFields as $field => $label) {
        $dbValue = $dbMachine->$field ?? 'N/A';
        $redisValue = $redisData[$field] ?? 'N/A';

        if ($dbValue != $redisValue) {
            $differences[] = [
                'field' => $field,
                'label' => $label,
                'db' => $dbValue,
                'redis' => $redisValue,
            ];
        } else {
            $matched[] = [
                'field' => $field,
                'label' => $label,
                'value' => $dbValue,
            ];
        }
    }

    // 输出差异
    if (!empty($differences)) {
        echo "❌ 发现 " . count($differences) . " 个字段不一致:\n\n";

        foreach ($differences as $diff) {
            echo sprintf(
                "字段: %s (%s)\n",
                $diff['field'],
                $diff['label']
            );
            echo sprintf(
                "  数据库: %s\n",
                $diff['db'] === 'N/A' ? 'N/A' : var_export($diff['db'], true)
            );
            echo sprintf(
                "  Redis:  %s\n",
                $diff['redis'] === 'N/A' ? 'N/A' : var_export($diff['redis'], true)
            );
            echo "\n";
        }
    } else {
        echo "✅ 所有字段都一致\n\n";
    }

    // 输出一致的字段（可选）
    if (!empty($matched)) {
        echo "✅ 一致的字段 (" . count($matched) . " 个):\n\n";

        foreach ($matched as $match) {
            echo sprintf(
                "  %s (%s): %s\n",
                $match['field'],
                $match['label'],
                var_export($match['value'], true)
            );
        }
        echo "\n";
    }

    // 4. 输出完整的Redis数据（用于调试）
    echo "====================================\n";
    echo "Redis完整数据 (key: {$redisKey})\n";
    echo "====================================\n\n";

    if (!empty($redisData)) {
        foreach ($redisData as $key => $value) {
            echo sprintf("  %-20s => %s\n", $key, $value);
        }
    }
    echo "\n";

    // 5. 输出数据库完整数据（用于调试）
    echo "====================================\n";
    echo "数据库完整数据\n";
    echo "====================================\n\n";

    $dbArray = (array)$dbMachine;
    foreach ($dbArray as $key => $value) {
        if (is_null($value)) {
            $value = 'NULL';
        }
        echo sprintf("  %-20s => %s\n", $key, $value);
    }
    echo "\n";

    // 6. 生成差异报告
    if (!empty($differences)) {
        echo "====================================\n";
        echo "差异汇总\n";
        echo "====================================\n\n";

        $reportFile = __DIR__ . "/../runtime/machine_diff_{$machineId}_" . date('YmdHis') . ".txt";
        $report = "机台数据差异报告\n";
        $report .= "生成时间: " . date('Y-m-d H:i:s') . "\n";
        $report .= "机台ID: {$machineId}\n";
        $report .= "Redis Key: {$redisKey}\n";
        $report .= str_repeat("=", 50) . "\n\n";

        foreach ($differences as $diff) {
            $report .= sprintf(
                "%s (%s):\n  DB: %s\n  Redis: %s\n\n",
                $diff['field'],
                $diff['label'],
                var_export($diff['db'], true),
                var_export($diff['redis'], true)
            );
        }

        file_put_contents($reportFile, $report);
        echo "📄 差异报告已保存到: {$reportFile}\n\n";
    }

    echo "✅ 对比完成！\n";

} catch (Exception $e) {
    echo "❌ 错误: " . $e->getMessage() . "\n";
    echo "堆栈:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}