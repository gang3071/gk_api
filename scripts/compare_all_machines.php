#!/usr/bin/env php
<?php
/**
 * 批量对比所有机台的数据库和Redis数据差异
 *
 * 用法：php scripts/compare_all_machines.php [--limit=10]
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
use support\Db;

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
echo "批量机台数据对比工具\n";
echo "====================================\n\n";

// 解析参数
$limit = null;
foreach ($argv as $arg) {
    if (strpos($arg, '--limit=') === 0) {
        $limit = (int)str_replace('--limit=', '', $arg);
    }
}

try {
    // 1. 从数据库获取所有机台
    echo "📊 从数据库获取机台列表...\n";
    $query = Db::table('machine')->whereNull('deleted_at');

    if ($limit) {
        $query->limit($limit);
        echo "   (限制: {$limit} 台)\n";
    }

    $machines = $query->get();
    $totalMachines = count($machines);

    echo "✅ 找到 {$totalMachines} 台机台\n\n";

    // 3. 定义需要对比的字段
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
    ];

    // 4. 对比每台机台
    $allDifferences = [];
    $processedCount = 0;
    $foundInRedis = 0;
    $notFoundInRedis = 0;

    echo "开始对比...\n";
    echo str_repeat("-", 50) . "\n\n";

    foreach ($machines as $machine) {
        $processedCount++;
        $machineId = $machine->id;
        $machineCode = $machine->code;

        echo sprintf("[%d/%d] 机台 #%d (%s)...", $processedCount, $totalMachines, $machineId, $machineCode);

        // Redis中机台数据的key格式：machine_tcp_data_cache_{id}_{field}
        $redisKeyPrefix = "machine_tcp_data_cache_{$machineId}_";

        // 从Redis读取各个字段（需要解包二进制数据）
        $redisData = [];
        foreach (array_keys($compareFields) as $field) {
            $key = $redisKeyPrefix . $field;
            $value = $redis->get($key);
            if ($value !== false) {
                // Redis中存储的是自定义6字节格式
                if (strpos($value, "\0") !== false || !ctype_print($value)) {
                    $len = strlen($value);

                    if ($len === 6) {
                        // 6字节格式：只取最后1个字节作为数值
                        $unpacked = unpack('C6', $value);
                        $redisData[$field] = $unpacked[6];
                    } elseif ($len === 4) {
                        $unpacked = unpack('V', $value);
                        $redisData[$field] = $unpacked[1] ?? 0;
                    } elseif ($len === 8) {
                        $unpacked = unpack('P', $value);
                        $redisData[$field] = $unpacked[1] ?? 0;
                    } else {
                        $redisData[$field] = ord(substr($value, -1));
                    }
                } else {
                    $redisData[$field] = is_numeric($value) ? (int)$value : $value;
                }
            }
        }

        if (empty($redisData)) {
            echo " ⚠️  未在Redis中找到\n";
            $notFoundInRedis++;
            continue;
        }

        $foundInRedis++;
        $redisKey = $redisKeyPrefix . '*';

        // 对比字段
        $differences = [];
        foreach ($compareFields as $field => $label) {
            $dbValue = $machine->$field ?? 'N/A';
            $redisValue = $redisData[$field] ?? 'N/A';

            if ($dbValue != $redisValue) {
                $differences[$field] = [
                    'label' => $label,
                    'db' => $dbValue,
                    'redis' => $redisValue,
                ];
            }
        }

        if (!empty($differences)) {
            echo " ❌ 发现 " . count($differences) . " 个差异\n";
            $allDifferences[$machineId] = [
                'code' => $machineCode,
                'redis_key' => $redisKey,
                'differences' => $differences,
            ];
        } else {
            echo " ✅ 数据一致\n";
        }
    }

    echo "\n" . str_repeat("=", 50) . "\n";
    echo "对比完成！\n";
    echo str_repeat("=", 50) . "\n\n";

    // 5. 统计结果
    echo "统计结果:\n";
    echo "  总机台数: {$totalMachines}\n";
    echo "  在Redis中找到: {$foundInRedis}\n";
    echo "  未在Redis中找到: {$notFoundInRedis}\n";
    echo "  数据不一致: " . count($allDifferences) . "\n\n";

    // 6. 输出差异详情
    if (!empty($allDifferences)) {
        echo str_repeat("=", 50) . "\n";
        echo "差异详情\n";
        echo str_repeat("=", 50) . "\n\n";

        foreach ($allDifferences as $machineId => $data) {
            echo "机台 #{$machineId} ({$data['code']}) - Redis Key: {$data['redis_key']}\n";
            echo str_repeat("-", 50) . "\n";

            foreach ($data['differences'] as $field => $diff) {
                echo sprintf(
                    "  %s (%s):\n    DB: %s\n    Redis: %s\n",
                    $field,
                    $diff['label'],
                    var_export($diff['db'], true),
                    var_export($diff['redis'], true)
                );
            }
            echo "\n";
        }

        // 7. 生成CSV报告
        $csvFile = __DIR__ . "/../runtime/all_machines_diff_" . date('YmdHis') . ".csv";
        $csv = fopen($csvFile, 'w');

        // CSV 头
        fputcsv($csv, ['机台ID', '机台编号', 'Redis Key', '字段', '字段名称', '数据库值', 'Redis值']);

        // CSV 数据
        foreach ($allDifferences as $machineId => $data) {
            foreach ($data['differences'] as $field => $diff) {
                fputcsv($csv, [
                    $machineId,
                    $data['code'],
                    $data['redis_key'],
                    $field,
                    $diff['label'],
                    $diff['db'],
                    $diff['redis'],
                ]);
            }
        }

        fclose($csv);
        echo "📄 CSV报告已保存到: {$csvFile}\n\n";

        // 8. 生成统计报告
        echo str_repeat("=", 50) . "\n";
        echo "差异字段统计（按出现次数排序）\n";
        echo str_repeat("=", 50) . "\n\n";

        $fieldStats = [];
        foreach ($allDifferences as $machineId => $data) {
            foreach ($data['differences'] as $field => $diff) {
                if (!isset($fieldStats[$field])) {
                    $fieldStats[$field] = [
                        'label' => $diff['label'],
                        'count' => 0,
                    ];
                }
                $fieldStats[$field]['count']++;
            }
        }

        arsort($fieldStats);

        foreach ($fieldStats as $field => $stats) {
            echo sprintf(
                "  %s (%s): %d 台机台不一致\n",
                $field,
                $stats['label'],
                $stats['count']
            );
        }
        echo "\n";
    } else {
        echo "✅ 所有机台数据都一致！\n\n";
    }

    echo "✅ 批量对比完成！\n";

} catch (Exception $e) {
    echo "❌ 错误: " . $e->getMessage() . "\n";
    echo "堆栈:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}