<?php

namespace app\service;

use support\Log;
use support\Redis;

/**
 * 设备认证服务
 *
 * 提供设备签名生成、JWT令牌颁发和验证等功能
 */
class DeviceAuthService
{
    /**
     * 生成HMAC-SHA256签名
     *
     * 算法：HMAC-SHA256(device_no|timestamp, device_secret)
     *
     * @param string $deviceNo 设备号
     * @param int $timestamp Unix时间戳
     * @param string $secret 设备密钥
     * @return string 64位十六进制签名
     */
    public static function generateSignature(string $deviceNo, int $timestamp, string $secret): string
    {
        $data = $deviceNo . '|' . $timestamp;
        return hash_hmac('sha256', $data, $secret);
    }

    /**
     * 颁发访问令牌（简化版JWT）
     *
     * 格式：base64(header).base64(payload).signature
     *
     * Payload:
     * - iss: 颁发者
     * - sub: 设备号
     * - iat: 颁发时间
     * - exp: 过期时间
     * - ip: 绑定IP
     * - jti: 令牌唯一ID
     *
     * @param string $deviceNo 设备号
     * @param string $ip IP地址
     * @return string JWT token
     */
    public static function issueAccessToken(string $deviceNo, string $ip): string
    {
        $config = config('device.token', [
            'ttl' => 1800,
            'algorithm' => 'HS256',
            'issuer' => 'device-auth',
        ]);

        $now = time();

        // Header
        $header = [
            'typ' => 'JWT',
            'alg' => $config['algorithm'],
        ];

        // Payload
        $payload = [
            'iss' => $config['issuer'],              // 颁发者
            'sub' => $deviceNo,                      // 主题（设备号）
            'iat' => $now,                           // 颁发时间
            'exp' => $now + $config['ttl'],          // 过期时间
            'ip' => $ip,                             // 绑定IP
            'jti' => bin2hex(random_bytes(16)),      // 令牌唯一ID
        ];

        // Base64编码
        $headerEncoded = self::base64UrlEncode(json_encode($header));
        $payloadEncoded = self::base64UrlEncode(json_encode($payload));

        // 签名
        $secret = config('device.secret_key', 'change_me');
        $signature = hash_hmac('sha256', $headerEncoded . '.' . $payloadEncoded, $secret);
        $signatureEncoded = self::base64UrlEncode(hex2bin($signature));

        return $headerEncoded . '.' . $payloadEncoded . '.' . $signatureEncoded;
    }

    /**
     * 验证访问令牌
     *
     * @param string $token JWT token
     * @param string $ip 当前IP地址
     * @return array|null 返回解码后的payload，失败返回null
     */
    public static function verifyAccessToken(string $token, string $ip): ?array
    {
        try {
            // 分割JWT
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                return null;
            }

            [$headerEncoded, $payloadEncoded, $signatureEncoded] = $parts;

            // 验证签名
            $secret = config('device.secret_key', 'change_me');
            $expectedSignature = hash_hmac('sha256', $headerEncoded . '.' . $payloadEncoded, $secret);
            $expectedSignatureEncoded = self::base64UrlEncode(hex2bin($expectedSignature));

            if (!hash_equals($expectedSignatureEncoded, $signatureEncoded)) {
                Log::warning('JWT签名验证失败');
                return null;
            }

            // 解码Payload
            $payload = json_decode(self::base64UrlDecode($payloadEncoded), true);
            if (!$payload) {
                return null;
            }

            // 验证过期时间
            if (isset($payload['exp']) && $payload['exp'] < time()) {
                return null;
            }

            // 验证IP绑定
            if (isset($payload['ip']) && $payload['ip'] !== $ip) {
                Log::warning('JWT IP验证失败', [
                    'expected_ip' => $payload['ip'],
                    'actual_ip' => $ip,
                ]);
                return null;
            }

            return $payload;
        } catch (\Exception $e) {
            Log::warning('JWT验证异常: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 验证设备请求（完整流程）
     *
     * 验证步骤：
     * 1. 验证令牌格式和签名
     * 2. 验证设备号是否匹配
     * 3. 从Redis验证令牌是否在白名单
     *
     * @param string $deviceNo 设备号
     * @param string $ip IP地址
     * @param string $token 访问令牌
     * @return bool
     */
    public static function verifyDeviceRequest(string $deviceNo, string $ip, string $token): bool
    {
        // 1. 验证令牌格式和签名
        $payload = self::verifyAccessToken($token, $ip);
        if (!$payload) {
            return false;
        }

        // 2. 验证设备号是否匹配
        if (!isset($payload['sub']) || $payload['sub'] !== $deviceNo) {
            return false;
        }

        // 3. 从Redis验证令牌是否在白名单（防止令牌被撤销后仍可用）
        $key = "device_token:{$deviceNo}:{$ip}";
        $cachedToken = Redis::get($key);

        return $cachedToken === $token;
    }

    /**
     * Base64 URL编码
     *
     * @param string $data
     * @return string
     */
    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64 URL解码
     *
     * @param string $data
     * @return string
     */
    private static function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }

    /**
     * 验证请求签名（额外的API签名验证）
     *
     * 用于业务API接口的签名验证，防止数据篡改
     *
     * 算法：HMAC-SHA256(device_no|timestamp|body, device_secret)
     *
     * @param string $deviceNo 设备号
     * @param int $timestamp 时间戳
     * @param string $body 请求体
     * @param string $signature 客户端签名
     * @param string $secret 设备密钥
     * @return bool
     */
    public static function verifyRequestSignature(
        string $deviceNo,
        int $timestamp,
        string $body,
        string $signature,
        string $secret
    ): bool {
        // 验证时间戳
        $tolerance = config('device.timestamp_tolerance', 300);
        if (abs(time() - $timestamp) > $tolerance) {
            return false;
        }

        // 生成期望的签名
        $data = $deviceNo . '|' . $timestamp . '|' . $body;
        $expectedSignature = hash_hmac('sha256', $data, $secret);

        return hash_equals($expectedSignature, $signature);
    }
}
