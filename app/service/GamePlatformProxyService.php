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

        try {
            $workerHost = env('GAME_PLATFORM_PROXY_HOST', '10.140.0.10');
            $workerPort = env('GAME_PLATFORM_PROXY_PORT', '8788');
            $proxyUrl = "http://{$workerHost}:{$workerPort}{$endpoint}";

            Log::info('Game platform proxy request', [
                'endpoint' => $endpoint,
                'proxy_url' => $proxyUrl,
                'request_data' => $request->all(),
            ]);

            // 使用 cURL 转发请求
            $headers = [
                'Authorization: ' . $request->header('Authorization', ''),
                'Content-Type: application/json',
                'X-Real-IP: ' . $request->getRealIp(),
                'X-Forwarded-For: ' . $request->header('X-Forwarded-For', ''),
                'User-Agent: ' . $request->header('User-Agent', 'Webman-Proxy/1.0'),
                // API 签名验证所需头部
                'appId: ' . $request->header('appId', ''),
                'appKey: ' . $request->header('appKey', ''),
                'timestamp: ' . $request->header('timestamp', ''),
                'nonceStr: ' . $request->header('nonceStr', ''),
                'signature: ' . $request->header('signature', ''),
                'Accept-Language: ' . $request->header('Accept-Language', 'zh-CN'),
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
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($responseBody === false) {
                throw new \Exception('cURL request failed: ' . $curlError);
            }

            Log::info('Game platform proxy response', [
                'endpoint' => $endpoint,
                'status' => $httpCode,
                'success' => $httpCode >= 200 && $httpCode < 300,
            ]);

            // 返回外网主机的响应
            return response($responseBody, $httpCode)
                ->withHeaders(['Content-Type' => 'application/json']);

        } catch (\Throwable $e) {
            Log::error('Game platform proxy failed', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return json([
                'code' => 500,
                'msg' => '游戏服务暂时不可用，请稍后重试'
            ], 500);
        }
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
