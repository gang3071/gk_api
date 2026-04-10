<?php

namespace app\service;

use support\Log;
use support\Request;
use support\Response;

/**
 * 游戏平台代理服务
 * 用于转发第三方游戏相关请求到外网主机（通过零信任隧道）
 */
class GamePlatformProxyService
{
    /**
     * 需要转发的接口列表
     */
    const PROXY_ENDPOINTS = [
        'enterGame' => '/api/v1/enter-game',
        'lobbyLogin' => '/api/v1/lobby-login',
        'walletTransferOut' => '/api/v1/wallet-transfer-out',
        'walletTransferIN' => '/api/v1/wallet-transfer-in',
        'getBalance' => '/api/v1/get-balance',
        'getWallet' => '/api/v1/get-wallet',
        'withdrawAmountAll' => '/api/v1/withdrawAmountAll',
        'fastTransferAllIN' => '/api/v1/fast-transfer',
    ];

    /**
     * 检查是否启用代理
     * @return bool
     */
    public static function isEnabled(): bool
    {
        return env('GAME_PLATFORM_PROXY_ENABLE', true);
    }

    /**
     * 代理请求到外网主机
     * @param Request $request 请求对象
     * @param string $endpoint 接口路径（例如：/api/v1/enter-game）
     * @return Response|null 返回响应或 null（表示不需要转发）
     */
    public static function proxy(Request $request, string $endpoint): ?Response
    {
        // 检查是否启用代理
        if (!self::isEnabled()) {
            return null;
        }

        $startTime = microtime(true);

        try {
            // 获取当前登录玩家ID（在转发前验证）
            $playerId = null;
            try {
                $player = checkPlayer();
                if ($player) {
                    $playerId = $player->id;
                }
            } catch (\Throwable $e) {
                // 获取玩家失败，继续转发，让 gk_work 处理
            }

            $workerHost = env('GAME_PLATFORM_PROXY_HOST', '10.140.0.10');
            $workerPort = env('GAME_PLATFORM_PROXY_PORT', '8080');
            $proxyUrl = "http://{$workerHost}:{$workerPort}{$endpoint}";

            Log::info('Game platform proxy request', [
                'endpoint' => $endpoint,
                'proxy_url' => $proxyUrl,
                'player_id' => $playerId,
                'request_data' => $request->all(),
            ]);

            // 使用 cURL 转发请求
            $headers = [
                'Authorization: ' . $request->header('Authorization', ''),
                'Content-Type: application/json',
                'X-Real-IP: ' . $request->getRealIp(),
                'X-Forwarded-For: ' . $request->header('X-Forwarded-For', ''),
                'User-Agent: ' . $request->header('User-Agent', 'Webman-Proxy/1.0'),
                // 传递玩家 ID（已验证）
                'X-Player-Id: ' . ($playerId ?? ''),
                // API 签名验证所需头部
                'appId: ' . $request->header('appId', ''),
                'appKey: ' . $request->header('appKey', ''),
                'timestamp: ' . $request->header('timestamp', ''),
                'nonceStr: ' . $request->header('nonceStr', ''),
                'signature: ' . $request->header('signature', ''),
                'Accept-Language: ' . $request->header('Accept-Language', 'zh-TW'),
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $proxyUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($request->all()));
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_HEADER, false);

            $responseBody = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($responseBody === false) {
                throw new \Exception('cURL request failed: ' . $curlError);
            }

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Log::info('Game platform proxy response', [
                'endpoint' => $endpoint,
                'status' => $httpCode,
                'success' => $httpCode >= 200 && $httpCode < 300,
                'duration_ms' => $duration,
            ]);

            // 发送 Telegram 通知
            self::sendTelegramNotification($endpoint, $request, $responseBody, $httpCode, $duration);

            // 返回外网主机的响应
            return response($responseBody, $httpCode)
                ->withHeaders(['Content-Type' => 'application/json']);

        } catch (\Throwable $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Log::error('Game platform proxy failed', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            // 发送失败通知
            self::sendTelegramNotification($endpoint, $request, null, 500, $duration, $e);

            return json([
                'code' => 500,
                'msg' => '游戏服务暂时不可用，请稍后重试'
            ], 500);
        }
    }

    /**
     * 发送 Telegram 通知
     * @param string $endpoint 接口路径
     * @param Request $request 请求对象
     * @param string|null $responseBody 响应体
     * @param int $httpCode HTTP 状态码
     * @param float $duration 耗时（毫秒）
     * @param \Throwable|null $exception 异常对象
     */
    private static function sendTelegramNotification(
        string $endpoint,
        Request $request,
        ?string $responseBody,
        int $httpCode,
        float $duration,
        ?\Throwable $exception = null
    ): void {
        try {
            // 检查是否启用 Telegram 通知
            $token = env('TELEGRAM_BOT_TOKEN');
            $chatId = env('TELEGRAM_CHAT_ID');
            $enabled = env('GAME_PLATFORM_PROXY_TELEGRAM_NOTIFY', false);

            if (!$enabled || empty($token) || empty($chatId)) {
                return;
            }

            // 判断是否成功
            $isSuccess = $httpCode >= 200 && $httpCode < 300 && !$exception;
            $icon = $isSuccess ? '✅' : '❌';
            $status = $isSuccess ? '成功' : '失败';

            // 构建消息
            $message = "{$icon} *游戏平台代理转发*\n\n";
            $message .= "📅 时间: `" . date('Y-m-d H:i:s') . "`\n";
            $message .= "🔗 接口: `{$endpoint}`\n";
            $message .= "📊 状态: *{$status}* (HTTP {$httpCode})\n";
            $message .= "⏱️ 耗时: `{$duration}ms`\n";
            $message .= "🌐 IP: `" . $request->getRealIp() . "`\n\n";

            // 请求参数（脱敏）
            $requestData = $request->all();
            if (isset($requestData['password'])) {
                $requestData['password'] = '***';
            }
            $message .= "📤 请求参数:\n```json\n" . json_encode($requestData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n```\n\n";

            // 响应数据
            if ($exception) {
                $message .= "❌ 错误信息:\n`" . $exception->getMessage() . "`\n";
                $message .= "📂 文件: `{$exception->getFile()}:{$exception->getLine()}`\n";
            } else {
                $responseData = json_decode($responseBody, true);
                if ($responseData) {
                    // 截取响应数据，避免太长
                    $responseStr = json_encode($responseData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                    if (strlen($responseStr) > 800) {
                        $responseStr = substr($responseStr, 0, 800) . "\n...(已截断)";
                    }
                    $message .= "📥 响应数据:\n```json\n{$responseStr}\n```";
                } else {
                    $message .= "📥 响应: `" . substr($responseBody, 0, 200) . "`";
                }
            }

            // 发送到 Telegram
            self::sendToTelegram($token, $chatId, $message);

        } catch (\Throwable $e) {
            // Telegram 发送失败不影响主流程
            Log::warning('Send telegram notification failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 发送消息到 Telegram
     * @param string $token Bot Token
     * @param string $chatId Chat ID
     * @param string $message 消息内容
     */
    private static function sendToTelegram(string $token, string $chatId, string $message): void
    {
        $url = "https://api.telegram.org/bot{$token}/sendMessage";

        // 确保 UTF-8 编码
        $message = mb_convert_encoding($message, 'UTF-8', 'auto');

        $data = [
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'Markdown',
            'disable_web_page_preview' => true,
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode !== 200) {
            Log::warning('Telegram send failed', [
                'http_code' => $httpCode,
                'response' => $response,
            ]);
        }

        curl_close($ch);
    }

    /**
     * 根据方法名获取对应的接口路径并代理
     * @param Request $request
     * @param string $method 方法名（例如：enterGame）
     * @return Response|null
     */
    public static function proxyByMethod(Request $request, string $method): ?Response
    {
        $endpoint = self::PROXY_ENDPOINTS[$method] ?? null;

        if (!$endpoint) {
            Log::warning('Game platform proxy method not found', [
                'method' => $method,
            ]);
            return null;
        }

        return self::proxy($request, $endpoint);
    }

    /**
     * 获取代理配置信息
     * @return array
     */
    public static function getConfig(): array
    {
        return [
            'enabled' => self::isEnabled(),
            'host' => env('GAME_PLATFORM_PROXY_HOST', '10.140.0.10'),
            'port' => env('GAME_PLATFORM_PROXY_PORT', '8788'),
            'endpoints' => self::PROXY_ENDPOINTS,
        ];
    }
}
