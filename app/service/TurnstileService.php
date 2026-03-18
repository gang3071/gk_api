<?php

namespace app\service;

use support\Log;

/**
 * Cloudflare Turnstile 人机验证服务
 *
 * 用途：验证 Cloudflare Turnstile Token
 * 文档：https://developers.cloudflare.com/turnstile/
 */
class TurnstileService
{
    /**
     * Turnstile API 验证地址
     */
    const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /**
     * 验证 Turnstile Token
     *
     * @param string $token 前端获取的 cf-turnstile-response
     * @param string|null $remoteIp 用户IP（可选但推荐）
     * @return bool
     */
    public static function verify(string $token, ?string $remoteIp = null): bool
    {
        $secretKey = env('TURNSTILE_SECRET_KEY');

        if (empty($secretKey)) {
            Log::error('Turnstile Secret Key 未配置');
            return false;
        }

        if (empty($token)) {
            Log::warning('Turnstile Token 为空');
            return false;
        }

        try {
            $data = [
                'secret' => $secretKey,
                'response' => $token,
            ];

            // 添加用户IP（可选但推荐，有助于防止 Token 盗用）
            if ($remoteIp) {
                $data['remoteip'] = $remoteIp;
            }

            $ch = curl_init(self::VERIFY_URL);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($data),
                CURLOPT_TIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/x-www-form-urlencoded',
                ],
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                Log::error('Turnstile API 请求失败', [
                    'error' => $error,
                    'http_code' => $httpCode,
                ]);
                return false;
            }

            if ($httpCode !== 200) {
                Log::error('Turnstile API HTTP 错误', [
                    'http_code' => $httpCode,
                    'response' => $response,
                ]);
                return false;
            }

            $result = json_decode($response, true);

            if (!$result) {
                Log::error('Turnstile API 响应解析失败', ['response' => $response]);
                return false;
            }

            // 验证成功
            if (isset($result['success']) && $result['success'] === true) {
                Log::info('Turnstile 验证成功', [
                    'token_preview' => substr($token, 0, 20) . '...',
                    'ip' => $remoteIp,
                    'challenge_ts' => $result['challenge_ts'] ?? null,
                    'hostname' => $result['hostname'] ?? null,
                ]);
                return true;
            }

            // 验证失败，记录错误码
            $errorCodes = $result['error-codes'] ?? [];
            Log::warning('Turnstile 验证失败', [
                'error_codes' => $errorCodes,
                'token_preview' => substr($token, 0, 20) . '...',
                'ip' => $remoteIp,
            ]);

            return false;

        } catch (\Exception $e) {
            Log::error('Turnstile 验证异常', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return false;
        }
    }

    /**
     * 获取客户端IP
     *
     * @return string
     */
    public static function getClientIp(): string
    {
        $request = request();

        // 优先从代理头获取
        $ip = $request->header('x-real-ip');
        if ($ip) {
            return $ip;
        }

        $ip = $request->header('x-forwarded-for');
        if ($ip) {
            $ips = explode(',', $ip);
            return trim($ips[0]);
        }

        // 从连接信息获取
        return $request->connection->getRemoteIp();
    }

    /**
     * 检查 Turnstile 是否启用
     *
     * @return bool
     */
    public static function isEnabled(): bool
    {
        return (bool) env('TURNSTILE_ENABLED', false);
    }

    /**
     * 获取 Site Key（公钥）
     *
     * @return string
     */
    public static function getSiteKey(): string
    {
        return env('TURNSTILE_SITE_KEY', '');
    }
}
