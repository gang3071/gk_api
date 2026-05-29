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
        ?int $playerId = null
    ): array {
        $startTime = microtime(true);
        $requestPayload = [
            'machine_id' => $machineId,
            'cmd' => $cmd,
            'data' => $data,
            'lang' => $lang,
        ];

        Log::info('[MachineClient] 发送机台指令 - 请求', [
            'url' => $this->baseUrl . '/api/admin/machine/send-cmd',
            'payload' => $requestPayload,
            'player_id' => $playerId,
        ]);

        try {
            $response = $this->getHttpClient()
                ->withHeaders([
                    'Accept-Language' => $lang,
                    'X-Admin-Id' => 0, // 来自客户端API，使用0表示系统调用
                    'X-Player-Id' => $playerId ?? 0,
                ])
                ->post($this->baseUrl . '/api/admin/machine/send-cmd', $requestPayload);

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $body = $response->json();

            if ($response->successful() && isset($body['code']) && $body['code'] === 200) {
                Log::info('[MachineClient] 指令执行成功 - 响应', [
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

            Log::warning('[MachineClient] 指令执行失败 - 响应', [
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

            Log::error('[MachineClient] HTTP请求异常', [
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
     * 一次HTTP调用发送多个指令，减少网络往返次数
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
            'url' => $this->baseUrl . '/api/admin/machine/batch-send-cmd',
            'payload' => $requestPayload,
            'commands_count' => count($commands),
            'player_id' => $playerId,
        ]);

        try {
            $response = $this->getHttpClient()
                ->withHeaders([
                    'Accept-Language' => $lang,
                    'X-Admin-Id' => 0,
                    'X-Player-Id' => $playerId ?? 0,
                ])
                ->post($this->baseUrl . '/api/admin/machine/batch-send-cmd', $requestPayload);

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $body = $response->json();

            if ($response->successful() && isset($body['code']) && $body['code'] === 200) {
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
            'url' => $this->baseUrl . '/api/admin/machine/check-online',
            'payload' => $requestPayload,
        ]);

        try {
            $response = $this->getHttpClient()
                ->withHeaders([
                    'Accept-Language' => $lang,
                    'X-Admin-Id' => 0,
                ])
                ->post($this->baseUrl . '/api/admin/machine/check-online', $requestPayload);

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $body = $response->json();

            Log::info('[MachineClient] 检查机台在线状态 - 响应', [
                'machine_id' => $machineId,
                'duration_ms' => $duration,
                'status_code' => $response->status(),
                'response_body' => $body,
            ]);

            if ($response->successful() && isset($body['code']) && $body['code'] === 200) {
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
     * @param array $machineIds 机台ID数组
     * @param string $lang 语言
     * @return array 返回格式: ['success' => bool, 'data' => array, 'message' => string]
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
            'url' => $this->baseUrl . '/api/admin/machine/batch-check-online',
            'payload' => $requestPayload,
            'machine_count' => count($machineIds),
        ]);

        try {
            $response = $this->getHttpClient()
                ->withHeaders([
                    'Accept-Language' => $lang,
                    'X-Admin-Id' => 0,
                ])
                ->post($this->baseUrl . '/api/admin/machine/batch-check-online', $requestPayload);

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $body = $response->json();

            Log::info('[MachineClient] 批量检查机台在线状态 - 响应', [
                'machine_count' => count($machineIds),
                'duration_ms' => $duration,
                'status_code' => $response->status(),
                'response_body' => $body,
            ]);

            if ($response->successful() && isset($body['code']) && $body['code'] === 200) {
                return [
                    'success' => true,
                    'data' => $body['data'] ?? [],
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
}
