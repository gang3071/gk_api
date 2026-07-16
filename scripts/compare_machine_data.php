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
use Illuminate\Redis\RedisManager;
use support\Db;
use support\Redis;

// 获取数据库配置
$databaseConfig = $config['database'] ?? [];
if (empty($databaseConfig['connections'])) {
    echo "❌ 数据库配置未找到\n";
    echo "正在尝试直接从环境变量读取...\n";

    $databaseConfig = [
        'default' => getenv('DB_CONNECTION') ?: 'mysql',
        'connections' => [
            'mysql' => [
                'driver' => 'mysql',
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => getenv('DB_PORT') ?: 3306,
                'database' => getenv('DB_DATABASE') ?: '',
                'username' => getenv('DB_USERNAME') ?: 'root',
                'password' => getenv('DB_PASSWORD') ?: '',
                'charset' => getenv('DB_CHARSET') ?: 'utf8mb4',
                'collation' => getenv('DB_COLLATION') ?: 'utf8mb4_unicode_ci',
                'prefix' => getenv('DB_PREFIX') ?: '',
            ],
        ],
    ];
}

$capsule = new Capsule;

// 添加所有数据库连接
foreach ($databaseConfig['connections'] as $name => $dbConfig) {
    $capsule->addConnection($dbConfig, $name);
}

// 设置默认连接
$defaultConnection = $databaseConfig['default'] ?? 'mysql';
$capsule->getDatabaseManager()->setDefaultConnection($defaultConnection);

$capsule->setAsGlobal();
$capsule->bootEloquent();

// 初始化 Redis（使用原生 Redis 扩展）
$redisConfig = $config['redis'] ?? [];
$redisHost = getenv('REDIS_HOST') ?: '127.0.0.1';
$redisPort = getenv('REDIS_PORT') ?: 6379;
$redisPassword = getenv('REDIS_PASSWORD') ?: null;
$redisDb = getenv('REDIS_DB') ?: 0;

// 创建 Redis 连接
$redis = new \Redis();
try {
    $redis->connect($redisHost, $redisPort);
    if ($redisPassword) {
        $redis->auth($redisPassword);
    }
    $redis->select($redisDb);
} catch (Exception $e) {
    echo "❌ Redis连接失败: " . $e->getMessage() . "\n";
    echo "   Host: {$redisHost}:{$redisPort}\n";
    echo "   DB: {$redisDb}\n";
    exit(1);
}

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

    // Redis中机台数据的key格式：machine_tcp_data_cache_{id}_{field}
    $redisKeyPrefix = "machine_tcp_data_cache_{$machineId}_";

    // 定义需要读取的字段
    $fieldsToRead = [
        'gaming', 'keeping', 'gaming_user_id', 'keeping_user_id',
        'turn', 'score', 'point', 'pressure', 'reward_status',
        'is_use', 'maintaining', 'auto_up_turn', 'move'
    ];

    // 从Redis读取各个字段（需要解包二进制数据）
    $redisData = [];
    $redisRawData = [];  // 保存原始数据用于调试
    foreach ($fieldsToRead as $field) {
        $key = $redisKeyPrefix . $field;
        $value = $redis->get($key);
        if ($value !== false) {
            $redisRawData[$field] = bin2hex($value);  // 保存十六进制格式

            // Redis中存储的是自定义6字节格式
            // 格式：前5字节头部 + 最后1字节数据
            // 例如：000000020601 -> gaming = 1
            if (strpos($value, "\0") !== false || !ctype_print($value)) {
                $len = strlen($value);

                if ($len === 6) {
                    // 6字节格式：只取最后1个字节作为数值
                    $unpacked = unpack('C6', $value);  // C = unsigned char (1 byte)
                    $redisData[$field] = $unpacked[6];  // 第6个字节
                } elseif ($len === 4) {
                    // 4字节整数（小端序）
                    $unpacked = unpack('V', $value);
                    $redisData[$field] = $unpacked[1] ?? 0;
                } elseif ($len === 8) {
                    // 8字节整数（小端序）
                    $unpacked = unpack('P', $value);
                    $redisData[$field] = $unpacked[1] ?? 0;
                } else {
                    // 其他长度，尝试解析为整数
                    $redisData[$field] = ord(substr($value, -1));  // 取最后一字节
                }
            } else {
                // 纯文本数据
                $redisData[$field] = is_numeric($value) ? (int)$value : $value;
            }
        }
    }

    if (empty($redisData)) {
        echo "❌ Redis中未找到机台数据\n";
        echo "\n尝试列出所有包含 machine_tcp_data_cache_{$machineId}_ 的 keys:\n";
        $keys = $redis->keys("{$redisKeyPrefix}*");
        if (!empty($keys)) {
            echo "找到以下keys:\n";
            foreach ($keys as $k) {
                echo "  - {$k}\n";
            }
            echo "\n提示：找到了keys但字段列表可能不完整，请检查 \$fieldsToRead 数组\n";
        } else {
            echo "未找到任何相关keys\n";
        }
        exit(1);
    } else {
        echo "✅ Redis数据获取成功（找到 " . count($redisData) . " 个字段）\n\n";
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
    echo "Redis完整数据 (key prefix: {$redisKeyPrefix})\n";
    echo "====================================\n\n";

    if (!empty($redisData)) {
        foreach ($redisData as $key => $value) {
            $hexValue = $redisRawData[$key] ?? 'N/A';
            echo sprintf("  %-20s => %-10s (hex: %s)\n", $key, $value, $hexValue);
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
        $report .= "Redis Key Prefix: {$redisKeyPrefix}\n";
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