<?php

namespace app\service\machine;

use Exception;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use support\Log;

/**
 * 机台操作客户端
 * 用于调用 gk_work 项目的机台操作接口
 */
class MachineClient
{
    private string $baseUrl;
    private int $timeout;

    /**
     * @param string|null $baseUrl gk_work 的基础 URL，默认从环境变量读取
     * @param int $timeout 请求超时时间（秒）
     */
    public function __construct(?string $baseUrl = null, int $timeout = 30)
    {
        $this->baseUrl = $baseUrl ?? env('GK_WORK_URL', 'http://127.0.0.1:8788');
        $this->timeout = $timeout;
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
        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'Accept-Language' => $lang,
                    'X-Admin-Id' => 0, // 来自客户端API，使用0表示系统调用
                    'X-Player-Id' => $playerId ?? 0,
                ])
                ->post($this->baseUrl . '/api/admin/machine/send-cmd', [
                    'machine_id' => $machineId,
                    'cmd' => $cmd,
                    'data' => $data,
                    'lang' => $lang,
                ]);

            $body = $response->json();

            if ($response->successful() && isset($body['code']) && $body['code'] === 200) {
                Log::info('Machine command sent successfully', [
                    'machine_id' => $machineId,
                    'cmd' => $cmd,
                    'player_id' => $playerId,
                ]);

                return [
                    'success' => true,
                    'data' => $body['data'] ?? [],
                    'message' => $body['msg'] ?? 'success',
                ];
            }

            Log::warning('Machine command failed', [
                'machine_id' => $machineId,
                'cmd' => $cmd,
                'status_code' => $response->status(),
                'response' => $body,
            ]);

            return [
                'success' => false,
                'data' => [],
                'message' => $body['msg'] ?? 'Unknown error',
            ];

        } catch (RequestException $e) {
            Log::error('Machine command HTTP error', [
                'machine_id' => $machineId,
                'cmd' => $cmd,
                'error' => $e->getMessage(),
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
        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'Accept-Language' => $lang,
                    'X-Admin-Id' => 0,
                ])
                ->post($this->baseUrl . '/api/admin/machine/check-online', [
                    'machine_id' => $machineId,
                    'lang' => $lang,
                ]);

            $body = $response->json();

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
            Log::error('Check machine online HTTP error', [
                'machine_id' => $machineId,
                'error' => $e->getMessage(),
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
        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'Accept-Language' => $lang,
                    'X-Admin-Id' => 0,
                ])
                ->post($this->baseUrl . '/api/admin/machine/batch-check-online', [
                    'machine_ids' => $machineIds,
                    'lang' => $lang,
                ]);

            $body = $response->json();

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
            Log::error('Batch check machine online HTTP error', [
                'machine_ids' => $machineIds,
                'error' => $e->getMessage(),
            ]);

            throw new Exception(trans('batch_check_machine_online_failed', [], 'message') . ': ' . $e->getMessage());
        }
    }
}
