<?php

namespace app\service\machine;

use Exception;
use Illuminate\Http\Client\RequestException;
use support\Log;
use WebmanTech\LaravelHttpClient\Facades\Http;

/**
 * 机台操作客户端
 * 用于调用 gk_work 项目的机台操作接口
 */
class MachineClient
{
    private string $baseUrl;
    private int $timeout;

    /**
     * HTTP客户端连接池（每个worker进程维护一个实例，支持Keep-Alive）
     * @var \Illuminate\Http\Client\PendingRequest|null
     */
    private static $httpClientPool = null;

    /**
     * @param string|null $baseUrl gk_work 的基础 URL，默认从环境变量读取
     * @param int $timeout 请求超时时间（秒）
     */
    public function __construct(?string $baseUrl = null, int $timeout = 10)
    {
        $this->baseUrl = $baseUrl ?? config('services.gk_work.url');
        $this->timeout = $timeout;
    }

    /**
     * 获取HTTP客户端实例（支持连接复用和Keep-Alive）
     *
     * @return \Illuminate\Http\Client\PendingRequest
     */
    private function getHttpClient()
    {
        if (self::$httpClientPool === null) {
            self::$httpClientPool = Http::timeout($this->timeout)
                ->withOptions([
                    'http_version' => '1.1',  // 使用HTTP/1.1（支持Keep-Alive）
                    'curl' => [
                        CURLOPT_FORBID_REUSE => false,    // 允许连接复用
                        CURLOPT_FRESH_CONNECT => false,   // 不强制新连接
                        CURLOPT_MAXCONNECTS => 50,        // 连接池大小（最多保持50个Keep-Alive连接）
                        CURLOPT_TCP_KEEPALIVE => 1,       // 启用TCP Keep-Alive
                        CURLOPT_TCP_KEEPIDLE => 60,       // 空闲60秒后发送探测包
                        CURLOPT_TCP_KEEPINTVL => 10,      // 探测包间隔10秒
                    ],
                ]);

            Log::info('[MachineClient] HTTP连接池已初始化', [
                'worker_id' => posix_getpid(),
                'pool_size' => 50,
                'keep_alive' => true,
            ]);
        }

        return self::$httpClientPool;
    }

    /**
     * 发送机台指令
     *
     * @param int $machineId 机台ID
     * @param string $cmd 指令代码
     * @param int $data 指令数据
     * @param string $lang 语言
     * @param int|null $playerId 玩家ID（可选，用于日志追踪）
     * @return array 返回格式: ['success' => bool, 'data' => array, 'message' => string]
     * @throws Exception
     */
    public function sendCommand(
        int $machineId,
        string $cmd,
        int $data = 0,
        string $lang = 'zh_TW',
        ?int $playerId = null,
        ?array $traceContext = null
    ): array {
        $startTime = microtime(true);
        $requestPayload = [
            'machine_id' => $machineId,
            'cmd' => $cmd,
            'data' => $data,
            'lang' => $lang,
        ];

        // 构建请求headers
        $headers = [
            'Accept-Language' => $lang,
        ];

        // 只在playerId有效时才添加header（避免传递null或0）
        if ($playerId !== null && $playerId > 0) {
            $headers['X-Player-Id'] = $playerId;
        }

        // 添加追踪上下文（用于跨项目日志关联）
        if ($traceContext !== null) {
            if (isset($traceContext['batch_id'])) {
                $headers['X-Batch-Id'] = $traceContext['batch_id'];
            }
            if (isset($traceContext['command_index'])) {
                $headers['X-Command-Index'] = $traceContext['command_index'];
            }
            if (isset($traceContext['command_id'])) {
                $headers['X-Command-Id'] = $traceContext['command_id'];
            }
            if (isset($traceContext['wash_id'])) {
                $headers['X-Wash-Id'] = $traceContext['wash_id'];
            }
        }

        Log::channel('machine_operations')->info('[MachineClient] 发送机台指令 - 请求', [
            'url' => $this->baseUrl . '/api/v1/machine/send-cmd',
            'payload' => $requestPayload,
            'headers' => $headers,
            'player_id' => $playerId,
            'trace_context' => $traceContext,
        ]);

        try {
            // 修复：每次请求使用新的客户端实例，避免 header 累积
            $response = Http::timeout($this->timeout)
                ->withHeaders($headers)
                ->post($this->baseUrl . '/api/v1/machine/send-cmd', $requestPayload);

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $body = $response->json();

            // 改进的成功判断（兼容多种类型）
            $code = $body['code'] ?? 0;
            $isCodeOk = ($code === 200 || $code === '200');

            if ($response->successful() && isset($body['code']) && $isCodeOk) {
                Log::channel('machine_operations')->info('[MachineClient] 指令执行成功 - 响应', [
                    'machine_id' => $machineId,
                    'cmd' => $cmd,
                    'player_id' => $playerId,
                    'duration_ms' => $duration,
                    'status_code' => $response->status(),
                    'response_body' => $body,
                ]);

                return [
                    'success' => true,
                    'data' => $body['data'] ?? [],
                    'message' => $body['msg'] ?? 'success',
                ];
            }

            Log::channel('machine_operations')->warning('[MachineClient] 指令执行失败 - 响应', [
                'machine_id' => $machineId,
                'cmd' => $cmd,
                'status_code' => $response->status(),
                'duration_ms' => $duration,
                'response_body' => $body,
            ]);

            return [
                'success' => false,
                'data' => [],
                'message' => $body['msg'] ?? 'Unknown error',
            ];

        } catch (RequestException $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Log::channel('machine_operations')->error('[MachineClient] HTTP请求异常', [
                'machine_id' => $machineId,
                'cmd' => $cmd,
                'player_id' => $playerId,
                'duration_ms' => $duration,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new Exception(trans('machine_command_failed', [], 'message') . ': ' . $e->getMessage());
        }
    }

    /**
     * 批量发送机台指令
     * 注意：玩家端暂不支持批量发送指令，此方法保留用于未来扩展
     * 当前实现：逐个发送指令
     *
     * @param int $machineId 机台ID
     * @param array $commands 指令数组 [['cmd' => 'xxx', 'data' => 0], ...]
     * @param string $lang 语言
     * @param int|null $playerId 玩家ID（可选，用于日志追踪）
     * @return array 返回格式: ['success' => bool, 'data' => array, 'message' => string]
     * @throws Exception
     */
    public function batchSendCommands(
        int $machineId,
        array $commands,
        string $lang = 'zh_TW',
        ?int $playerId = null,
        ?string $washId = null
    ): array {
        if (empty($commands)) {
            throw new Exception('批量指令列表不能为空');
        }

        $batchId = uniqid('batch_', true);  // 生成唯一批次ID
        $startTime = microtime(true);

        // 提取指令名称列表用于日志
        $cmdNames = array_map(function($cmd) {
            return $cmd['cmd'] ?? 'unknown';
        }, $commands);

        Log::channel('machine_operations')->info('[MachineClient-Batch] 批量发送机台指令 - 开始', [
            'batch_id' => $batchId,
            'wash_id' => $washId,
            'machine_id' => $machineId,
            'commands_count' => count($commands),
            'commands_list' => $cmdNames,
            'commands_detail' => $commands,
            'player_id' => $playerId,
            'lang' => $lang,
            'timestamp' => date('Y-m-d H:i:s'),
        ]);

        // 逐个发送指令（因为玩家端暂不支持批量接口）
        $results = [];
        $successCount = 0;
        $failedCount = 0;

        try {
            foreach ($commands as $index => $command) {
                $cmdStartTime = microtime(true);

                try {
                    $cmd = $command['cmd'] ?? '';
                    $data = (int)($command['data'] ?? 0);

                    if (empty($cmd)) {
                        Log::channel('machine_operations')->warning('[MachineClient-Batch] 指令为空', [
                            'batch_id' => $batchId,
                            'index' => $index,
                            'command' => $command,
                        ]);

                        $results[] = [
                            'index' => $index,
                            'cmd' => $cmd,
                            'success' => false,
                            'message' => '指令不能为空'
                        ];
                        $failedCount++;
                        continue;
                    }

                    Log::channel('machine_operations')->info('[MachineClient-Batch] 发送单个指令', [
                        'batch_id' => $batchId,
                        'index' => $index,
                        'cmd' => $cmd,
                        'data' => $data,
                        'machine_id' => $machineId,
                    ]);

                    // 构建追踪上下文（传递给 gk_work）
                    $traceContext = [
                        'batch_id' => $batchId,
                        'command_index' => $index,
                    ];

                    // 如果有 wash_id，传递给 gk_work
                    if ($washId !== null) {
                        $traceContext['wash_id'] = $washId;
                    }

                    // 如果指令本身带有额外追踪信息，也传递过去
                    if (isset($command['wash_id'])) {
                        $traceContext['wash_id'] = $command['wash_id'];
                    }

                    // 调用单个指令发送（传递追踪上下文）
                    $result = $this->sendCommand($machineId, $cmd, $data, $lang, $playerId, $traceContext);
                    $cmdDuration = round((microtime(true) - $cmdStartTime) * 1000, 2);

                    $results[] = [
                        'index' => $index,
                        'cmd' => $cmd,
                        'data' => $data,
                        'success' => $result['success'],
                        'result' => $result['data'] ?? null,
                        'message' => $result['message'] ?? '',
                        'duration_ms' => $cmdDuration
                    ];

                    if ($result['success']) {
                        $successCount++;
                        Log::channel('machine_operations')->info('[MachineClient-Batch] 指令执行成功', [
                            'batch_id' => $batchId,
                            'index' => $index,
                            'cmd' => $cmd,
                            'duration_ms' => $cmdDuration,
                        ]);
                    } else {
                        $failedCount++;
                        Log::channel('machine_operations')->warning('[MachineClient-Batch] 指令执行失败', [
                            'batch_id' => $batchId,
                            'index' => $index,
                            'cmd' => $cmd,
                            'message' => $result['message'] ?? 'Unknown error',
                            'duration_ms' => $cmdDuration,
                        ]);
                    }

                } catch (Exception $e) {
                    $cmdDuration = round((microtime(true) - $cmdStartTime) * 1000, 2);

                    Log::channel('machine_operations')->error('[MachineClient-Batch] 指令执行异常', [
                        'batch_id' => $batchId,
                        'index' => $index,
                        'cmd' => $command['cmd'] ?? '',
                        'error' => $e->getMessage(),
                        'duration_ms' => $cmdDuration,
                    ]);

                    $results[] = [
                        'index' => $index,
                        'cmd' => $command['cmd'] ?? '',
                        'success' => false,
                        'message' => $e->getMessage(),
                        'duration_ms' => $cmdDuration
                    ];
                    $failedCount++;
                }
            }

            $totalDuration = round((microtime(true) - $startTime) * 1000, 2);

            Log::channel('machine_operations')->info('[MachineClient-Batch] 批量指令执行完成', [
                'batch_id' => $batchId,
                'machine_id' => $machineId,
                'commands_count' => count($commands),
                'success_count' => $successCount,
                'failed_count' => $failedCount,
                'total_duration_ms' => $totalDuration,
                'avg_duration_ms' => count($commands) > 0 ? round($totalDuration / count($commands), 2) : 0,
                'results_summary' => array_map(function($r) {
                    return [
                        'cmd' => $r['cmd'],
                        'success' => $r['success'],
                        'duration_ms' => $r['duration_ms'] ?? 0
                    ];
                }, $results),
                'timestamp' => date('Y-m-d H:i:s'),
            ]);

            // 性能警告
            if ($totalDuration > 2000) {
                Log::channel('machine_operations')->warning('[MachineClient-Batch] 批量指令耗时过长', [
                    'batch_id' => $batchId,
                    'machine_id' => $machineId,
                    'duration_ms' => $totalDuration,
                    'threshold_ms' => 2000,
                    'commands_count' => count($commands),
                ]);
            }

            return [
                'success' => true,
                'data' => [
                    'total_count' => count($commands),
                    'success_count' => $successCount,
                    'failed_count' => $failedCount,
                    'results' => $results
                ],
                'message' => 'success',
            ];

        } catch (Exception $e) {
            $totalDuration = round((microtime(true) - $startTime) * 1000, 2);

            Log::channel('machine_operations')->error('[MachineClient-Batch] 批量指令执行异常', [
                'batch_id' => $batchId,
                'machine_id' => $machineId,
                'commands_count' => count($commands),
                'duration_ms' => $totalDuration,
                'error' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'trace' => $e->getTraceAsString(),
                'timestamp' => date('Y-m-d H:i:s'),
            ]);

            throw $e;
        }
    }

    /**
     * 批量发送机台指令（原始实现，暂时保留但不使用）
     * @deprecated 玩家端暂不支持批量API，使用batchSendCommands逐个发送
     */
    private function batchSendCommandsOld(
        int $machineId,
        array $commands,
        string $lang = 'zh_TW',
        ?int $playerId = null
    ): array {
        if (empty($commands)) {
            throw new Exception('批量指令列表不能为空');
        }

        $startTime = microtime(true);
        $requestPayload = [
            'machine_id' => $machineId,
            'commands' => $commands,
            'lang' => $lang,
        ];

        Log::info('[MachineClient] 批量发送机台指令 - 请求', [
            'url' => $this->baseUrl . '/api/v1/machine/batch-send-cmd',
            'payload' => $requestPayload,
            'commands_count' => count($commands),
            'player_id' => $playerId,
        ]);

        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'Accept-Language' => $lang,
                    'X-Player-Id' => $playerId,
                ])
                ->post($this->baseUrl . '/api/v1/machine/batch-send-cmd', $requestPayload);

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $body = $response->json();

            // 改进的成功判断（兼容多种类型）
            $code = $body['code'] ?? 0;
            $isCodeOk = ($code === 200 || $code === '200');

            if ($response->successful() && isset($body['code']) && $isCodeOk) {
                $successCount = $body['data']['success_count'] ?? 0;
                $failedCount = $body['data']['failed_count'] ?? 0;

                Log::info('[MachineClient] 批量指令执行完成 - 响应', [
                    'machine_id' => $machineId,
                    'commands_count' => count($commands),
                    'success_count' => $successCount,
                    'failed_count' => $failedCount,
                    'duration_ms' => $duration,
                    'status_code' => $response->status(),
                    'response_body' => $body,
                ]);

                return [
                    'success' => true,
                    'data' => $body['data'] ?? [],
                    'message' => $body['msg'] ?? 'success',
                ];
            }

            Log::warning('[MachineClient] 批量指令执行失败 - 响应', [
                'machine_id' => $machineId,
                'commands_count' => count($commands),
                'duration_ms' => $duration,
                'status_code' => $response->status(),
                'response_body' => $body,
            ]);

            return [
                'success' => false,
                'data' => [],
                'message' => $body['msg'] ?? 'Unknown error',
            ];

        } catch (RequestException $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Log::error('[MachineClient] 批量指令HTTP请求异常', [
                'machine_id' => $machineId,
                'commands_count' => count($commands),
                'duration_ms' => $duration,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new Exception(trans('machine_command_failed', [], 'message') . ': ' . $e->getMessage());
        }
    }

    /**
     * 检查机台是否在线
     *
     * 在线状态由 gk_work 计算并返回
     * 注意：未来可优化为从 Redis 读取在线状态（需要 gk_work 同步到 Redis）
     *
     * @param int $machineId 机台ID
     * @param string $lang 语言
     * @return array 返回格式: ['success' => bool, 'data' => ['online' => bool], 'message' => string]
     * @throws Exception
     */
    public function checkOnline(int $machineId, string $lang = 'zh_TW'): array
    {
        $startTime = microtime(true);
        $requestPayload = [
            'machine_id' => $machineId,
            'lang' => $lang,
        ];

        Log::info('[MachineClient] 检查机台在线状态 - 请求', [
            'url' => $this->baseUrl . '/api/v1/machine/check-online',
            'payload' => $requestPayload,
        ]);

        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'Accept-Language' => $lang,
                    'X-Player-Id' => 0, // 检查在线状态不需要特定玩家ID
                ])
                ->post($this->baseUrl . '/api/v1/machine/check-online', $requestPayload);

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $body = $response->json();

            Log::info('[MachineClient] 检查机台在线状态 - 响应', [
                'machine_id' => $machineId,
                'duration_ms' => $duration,
                'status_code' => $response->status(),
                'response_body' => $body,
            ]);

            // 改进的成功判断（兼容多种类型）
            $code = $body['code'] ?? 0;
            $isCodeOk = ($code === 200 || $code === '200');

            if ($response->successful() && isset($body['code']) && $isCodeOk) {
                return [
                    'success' => true,
                    'data' => $body['data'] ?? [],
                    'message' => $body['msg'] ?? 'success',
                ];
            }

            return [
                'success' => false,
                'data' => ['online' => false],
                'message' => $body['msg'] ?? 'Unknown error',
            ];

        } catch (RequestException $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Log::error('[MachineClient] 检查机台在线状态 - HTTP异常', [
                'machine_id' => $machineId,
                'duration_ms' => $duration,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new Exception(trans('check_machine_online_failed', [], 'message') . ': ' . $e->getMessage());
        }
    }

    /**
     * 批量检查机台在线状态
     *
     * 在线状态由 gk_work 计算并返回
     *
     * @param array $machineIds 机台ID数组
     * @param string $lang 语言
     * @return array 返回格式: ['success' => bool, 'data' => ['机台ID' => 'online|offline'], 'message' => string]
     * @throws Exception
     */
    public function batchCheckOnline(array $machineIds, string $lang = 'zh_TW'): array
    {
        $startTime = microtime(true);
        $requestPayload = [
            'machine_ids' => $machineIds,
            'lang' => $lang,
        ];

        Log::info('[MachineClient] 批量检查机台在线状态 - 请求', [
            'url' => $this->baseUrl . '/api/v1/machine/batch-check-online',
            'payload' => $requestPayload,
            'machine_count' => count($machineIds),
        ]);

        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'Accept-Language' => $lang,
                    'X-Player-Id' => 0, // 批量检查在线状态不需要特定玩家ID
                ])
                ->post($this->baseUrl . '/api/v1/machine/batch-check-online', $requestPayload);

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $body = $response->json();

            Log::info('[MachineClient] 批量检查机台在线状态 - 响应', [
                'machine_count' => count($machineIds),
                'duration_ms' => $duration,
                'status_code' => $response->status(),
                'response_body' => $body,
            ]);

            // 改进的成功判断（兼容多种类型）
            $code = $body['code'] ?? 0;
            $isCodeOk = ($code === 200 || $code === '200');

            if ($response->successful() && isset($body['code']) && $isCodeOk) {
                $rawData = $body['data'] ?? [];

                // 处理返回数据格式，转换为 [机台ID => 状态] 格式
                $processedData = [];
                foreach ($rawData['machines'] ?? $rawData as $item) {
                    if (is_array($item) && isset($item['machine_id'])) {
                        $machineId = $item['machine_id'];

                        // 宽松判断在线状态（兼容整数1和布尔值true）
                        // 修复：使用正确的字段名 'is_online'（gk_work接口返回的字段）
                        $online = !empty($item['is_online']);
                        $processedData[$machineId] = $online ? 'online' : 'offline';
                    }
                }

                return [
                    'success' => true,
                    'data' => $processedData,
                    'message' => $body['msg'] ?? 'success',
                ];
            }

            return [
                'success' => false,
                'data' => [],
                'message' => $body['msg'] ?? 'Unknown error',
            ];

        } catch (RequestException $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Log::error('[MachineClient] 批量检查机台在线状态 - HTTP异常', [
                'machine_ids' => $machineIds,
                'machine_count' => count($machineIds),
                'duration_ms' => $duration,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new Exception(trans('batch_check_machine_online_failed', [], 'message') . ': ' . $e->getMessage());
        }
    }

    /**
     * 机台上分操作（由 gk_work 统一处理指令发送）
     *
     * 职责分离：
     * - gk_api: 业务校验、数据库事务、钱包扣款（已完成）
     * - gk_work: 机台硬件操作（清零指令 + 开分指令）
     *
     * @param int $machineId 机台ID
     * @param int $playerId 玩家ID
     * @param float $openScore 开分数量
     * @param array $machineContext 机台上下文数据
     *   - type: 机台类型 (1=钢珠, 2=斯洛, 3=捕鱼)
     *   - control_type: 控制类型 (1=双美, 2=小淞)
     *   - gaming: 是否游戏中
     *   - reward_status: 是否开奖中
     *   - point: 当前余分
     *   - score: 当前得分
     *   - turn: 当前转数
     * @param array $preClearCommands 预清零指令列表 (从 gk_api 传递)
     * @param string $lang 语言
     * @return array 返回格式: ['success' => bool, 'data' => array, 'message' => string]
     * @throws Exception
     */
    public function openMachine(
        int $machineId,
        int $playerId,
        float $openScore,
        array $machineContext,
        array $preClearCommands = [],
        string $lang = 'zh_TW'
    ): array {
        $startTime = microtime(true);
        $requestPayload = [
            'machine_id' => $machineId,
            'player_id' => $playerId,
            'open_score' => $openScore,
            'machine_context' => $machineContext,
            'pre_clear_commands' => $preClearCommands,
            'lang' => $lang,
        ];

        $headers = [
            'Accept-Language' => $lang,
            'X-Player-Id' => $playerId,
        ];

        Log::channel('machine_operations')->info('[MachineClient] 机台上分 - 请求', [
            'url' => $this->baseUrl . '/api/v1/machine/open-point',
            'payload' => $requestPayload,
            'headers' => $headers,
        ]);

        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders($headers)
                ->post($this->baseUrl . '/api/v1/machine/open-point', $requestPayload);

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $body = $response->json();

            $code = $body['code'] ?? 0;
            $isCodeOk = ($code === 200 || $code === '200');

            if ($response->successful() && isset($body['code']) && $isCodeOk) {
                Log::channel('machine_operations')->info('[MachineClient] 机台上分成功 - 响应', [
                    'machine_id' => $machineId,
                    'player_id' => $playerId,
                    'open_score' => $openScore,
                    'duration_ms' => $duration,
                    'status_code' => $response->status(),
                    'response_body' => $body,
                ]);

                return [
                    'success' => true,
                    'data' => $body['data'] ?? [],
                    'message' => $body['msg'] ?? 'success',
                ];
            }

            Log::channel('machine_operations')->warning('[MachineClient] 机台上分失败 - 响应', [
                'machine_id' => $machineId,
                'player_id' => $playerId,
                'open_score' => $openScore,
                'status_code' => $response->status(),
                'duration_ms' => $duration,
                'response_body' => $body,
            ]);

            return [
                'success' => false,
                'data' => [],
                'message' => $body['msg'] ?? 'Unknown error',
            ];

        } catch (RequestException $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Log::channel('machine_operations')->error('[MachineClient] 机台上分异常', [
                'machine_id' => $machineId,
                'player_id' => $playerId,
                'open_score' => $openScore,
                'duration_ms' => $duration,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new Exception(trans('machine_open_point_failed', [], 'message') . ': ' . $e->getMessage());
        }
    }

    /**
     * 机台洗分操作（由 gk_work 统一处理指令发送）
     *
     * 职责分离：
     * - gk_api: 业务校验、数据库事务、钱包加款（已完成）
     * - gk_work: 机台硬件操作（下转、得分转换、读取等批量指令）
     *
     * @param int $machineId 机台ID
     * @param int $playerId 玩家ID
     * @param string $action 动作类型 (leave=弃台, down=下分)
     * @param array $machineContext 机台上下文数据
     *   - type: 机台类型 (1=钢珠, 2=斯洛, 3=捕鱼)
     *   - control_type: 控制类型 (1=双美, 2=小淞)
     *   - gaming: 是否游戏中
     *   - reward_status: 是否开奖中
     *   - point: 当前余分
     *   - score: 当前得分
     *   - turn: 当前转数
     *   - auto: 是否自动上转
     *   - move_point: 是否移动分数
     * @param string $washId 洗分ID(用于日志追踪)
     * @param string $lang 语言
     * @return array 返回格式: ['success' => bool, 'data' => ['wash_point' => float], 'message' => string]
     * @throws Exception
     */
    public function washMachine(
        int $machineId,
        int $playerId,
        string $action,
        array $machineContext,
        string $washId,
        string $lang = 'zh_TW'
    ): array {
        $startTime = microtime(true);
        $requestPayload = [
            'machine_id' => $machineId,
            'player_id' => $playerId,
            'action' => $action,
            'machine_context' => $machineContext,
            'wash_id' => $washId,
            'lang' => $lang,
        ];

        $headers = [
            'Accept-Language' => $lang,
            'X-Player-Id' => $playerId,
            'X-Wash-Id' => $washId,
        ];

        Log::channel('machine_operations')->info('[MachineClient] 机台洗分 - 请求', [
            'url' => $this->baseUrl . '/api/v1/machine/wash-point',
            'payload' => $requestPayload,
            'headers' => $headers,
        ]);

        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders($headers)
                ->post($this->baseUrl . '/api/v1/machine/wash-point', $requestPayload);

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $body = $response->json();

            $code = $body['code'] ?? 0;
            $isCodeOk = ($code === 200 || $code === '200');

            if ($response->successful() && isset($body['code']) && $isCodeOk) {
                Log::channel('machine_operations')->info('[MachineClient] 机台洗分成功 - 响应', [
                    'machine_id' => $machineId,
                    'player_id' => $playerId,
                    'action' => $action,
                    'wash_id' => $washId,
                    'duration_ms' => $duration,
                    'status_code' => $response->status(),
                    'response_body' => $body,
                ]);

                return [
                    'success' => true,
                    'data' => $body['data'] ?? [],
                    'message' => $body['msg'] ?? 'success',
                ];
            }

            Log::channel('machine_operations')->warning('[MachineClient] 机台洗分失败 - 响应', [
                'machine_id' => $machineId,
                'player_id' => $playerId,
                'action' => $action,
                'wash_id' => $washId,
                'status_code' => $response->status(),
                'duration_ms' => $duration,
                'response_body' => $body,
            ]);

            return [
                'success' => false,
                'data' => [],
                'message' => $body['msg'] ?? 'Unknown error',
            ];

        } catch (RequestException $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Log::channel('machine_operations')->error('[MachineClient] 机台洗分异常', [
                'machine_id' => $machineId,
                'player_id' => $playerId,
                'action' => $action,
                'wash_id' => $washId,
                'duration_ms' => $duration,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new Exception(trans('machine_wash_point_failed', [], 'message') . ': ' . $e->getMessage());
        }
    }

    /**
     * 执行机台动作（由 gk_work 统一处理指令发送）
     *
     * 优化点：原本在 gk_api 中需要多次调用 sendCmd()，现在统一在 gk_work 中处理
     * 减少了跨项目的 HTTP 请求次数
     *
     * @param int $machineId 机台ID
     * @param string $action 动作类型 (reward_switch, plc_start_or_stop, plc_push_5hz 等)
     * @param array $context 上下文数据 (如 control_type, reward_status 等)
     * @param string $lang 语言
     * @param int|null $playerId 玩家ID
     * @return array 返回格式: ['success' => bool, 'data' => array, 'message' => string]
     * @throws Exception
     */

    /**
     * 执行机台操作（新接口 - 使用统一服务）
     *
     * 调用 gk_work 的统一操作接口 /api/v1/machine/execute
     *
     * @param int $machineId 机台 ID
     * @param string $action 操作名称
     * @param array $params 操作参数
     * @param string $lang 语言
     * @param int|null $playerId 玩家 ID
     * @return array ['success' => bool, 'data' => array, 'message' => string]
     */
    public function executeOperation(
        int $machineId,
        string $action,
        array $params = [],
        string $lang = 'zh_TW',
        ?int $playerId = null
    ): array {
        $startTime = microtime(true);
        $requestPayload = [
            'machine_id' => $machineId,
            'action' => $action,
            'params' => $params,
        ];

        $headers = [
            'Accept-Language' => $lang,
        ];

        if ($playerId !== null && $playerId > 0) {
            $headers['X-Player-Id'] = $playerId;
        }

        Log::channel('machine_operations')->info('[MachineClient] 执行机台操作（新接口） - 请求', [
            'url' => $this->baseUrl . '/api/v1/machine/execute',
            'payload' => $requestPayload,
            'headers' => $headers,
            'player_id' => $playerId,
        ]);

        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders($headers)
                ->post($this->baseUrl . '/api/v1/machine/execute', $requestPayload);

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $body = $response->json();

            $code = $body['code'] ?? 0;
            $isCodeOk = ($code === 1 || $code === '1');

            if ($response->successful() && isset($body['code']) && $isCodeOk) {
                Log::channel('machine_operations')->info('[MachineClient] 操作执行成功（新接口） - 响应', [
                    'machine_id' => $machineId,
                    'action' => $action,
                    'player_id' => $playerId,
                    'duration_ms' => $duration,
                    'status_code' => $response->status(),
                    'response_body' => $body,
                ]);

                return [
                    'success' => true,
                    'data' => $body['data'] ?? [],
                    'message' => $body['msg'] ?? 'success',
                ];
            }

            Log::channel('machine_operations')->warning('[MachineClient] 操作执行失败（新接口） - 响应', [
                'machine_id' => $machineId,
                'action' => $action,
                'status_code' => $response->status(),
                'duration_ms' => $duration,
                'response_body' => $body,
            ]);

            return [
                'success' => false,
                'data' => [],
                'message' => $body['msg'] ?? 'Unknown error',
            ];

        } catch (RequestException $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Log::channel('machine_operations')->error('[MachineClient] 操作执行异常（新接口）', [
                'machine_id' => $machineId,
                'action' => $action,
                'player_id' => $playerId,
                'duration_ms' => $duration,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'data' => [],
                'message' => trans('machine_action_failed', [], 'message') . ': ' . $e->getMessage(),
            ];
        }
    }
}
