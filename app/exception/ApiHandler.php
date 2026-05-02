<?php
/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace app\exception;

use Respect\Validation\Exceptions\NestedValidationException;
use Respect\Validation\Exceptions\ValidationException;
use Throwable;
use Tinywan\Jwt\Exception\JwtCacheTokenException;
use Tinywan\Jwt\Exception\JwtRefreshTokenExpiredException;
use Tinywan\Jwt\Exception\JwtTokenException;
use Tinywan\Jwt\Exception\JwtTokenExpiredException;
use Webman\Exception\ExceptionHandler;
use Webman\Http\Request;
use Webman\Http\Response;

/**
 * Class Handler
 * @package support\exception
 */
class ApiHandler extends ExceptionHandler
{
    public $dontReport = [
        NestedValidationException::class,
        ValidationException::class,
        JwtTokenException::class,
        JwtTokenExpiredException::class,
        JwtRefreshTokenExpiredException::class,
        JwtCacheTokenException::class,
    ];

    public function render(Request $request, Throwable $exception): Response
    {
        // JWT 异常处理 - 区分 Access Token 过期和其他情况
        if ($exception instanceof JwtTokenExpiredException
            || $exception instanceof JwtRefreshTokenExpiredException
            || $exception instanceof JwtTokenException
            || $exception instanceof JwtCacheTokenException) {

            // 获取异常自带的错误码
            $code = $exception->getCode();
            $message = $exception->getMessage();

            // 🔍 测试日志：JWT异常详情
            \support\Log::info('[JWT Exception] JWT异常捕获', [
                'exception_class' => get_class($exception),
                'original_code' => $code,
                'message' => $message,
                'path' => $request->path(),
                'method' => $request->method(),
            ]);

            // 🎯 优先处理：JwtRefreshTokenExpiredException → 401023（刷新token过期，必须重新登录）
            if ($exception instanceof JwtRefreshTokenExpiredException) {
                $code = 401023;
                \support\Log::info('[JWT Exception] Refresh Token过期，返回401023提示重新登录', [
                    'message' => $message,
                ]);

                return json([
                    'code' => $code,
                    'msg' => $message
                ]);
            }

            // 特殊处理：如果异常没有设置错误码或错误码为0，根据异常类型设置默认码
            if (empty($code) || $code == 0) {
                if ($exception instanceof JwtTokenExpiredException) {
                    // Access Token 过期 → 返回 401013，客户端应刷新 token
                    $code = 401;
                    \support\Log::info('[JWT Exception] Access Token过期，返回401013提示刷新', [
                        'message' => $message,
                    ]);
                } elseif ($exception instanceof JwtCacheTokenException) {
                    // 缓存Token异常（可能是其他设备登录）→ 返回 401015，重新登录
                    $code = 401;
                    \support\Log::info('[JWT Exception] 缓存Token异常，返回401015提示重新登录', [
                        'message' => $message,
                    ]);
                } else {
                    // 其他JWT异常 → 返回 401，通用错误
                    $code = 401;
                    \support\Log::info('[JWT Exception] 其他JWT异常，返回401', [
                        'message' => $message,
                    ]);
                }
            }

            // 特殊处理：根据错误消息判断具体场景
            if (str_contains($message, 'refresh') || str_contains($message, 'Refresh')) {
                // Refresh Token相关错误
                if (str_contains($message, 'expired') || str_contains($message, '过期')) {
                    // Refresh Token 过期 → 401023
                    $code = 401023;
                    \support\Log::info('[JWT Exception] Refresh Token过期，返回401023', [
                        'message' => $message,
                    ]);
                } else {
                    // Refresh Token 无效 → 401021
                    $code = 401021;
                    \support\Log::info('[JWT Exception] Refresh Token无效，返回401021', [
                        'message' => $message,
                    ]);
                }
            } elseif (str_contains($message, 'Access') && (str_contains($message, 'expired') || str_contains($message, '过期'))) {
                // Access Token 过期 → 401013（刷新token）
                $code = 401013;
                \support\Log::info('[JWT Exception] Access Token过期，返回401013提示刷新', [
                    'message' => $message,
                ]);
            } elseif (str_contains($message, 'Authorization') || str_contains($message, '缺少')) {
                // 缺少 Authorization → 401000
                $code = 401000;
                \support\Log::info('[JWT Exception] 缺少Authorization，返回401000', [
                    'message' => $message,
                ]);
            } elseif (str_contains($message, 'format') || str_contains($message, '格式')) {
                // Token 格式错误 → 401001
                $code = 401001;
                \support\Log::info('[JWT Exception] Token格式错误，返回401001', [
                    'message' => $message,
                ]);
            } elseif (str_contains($message, 'signature') || str_contains($message, '签名')) {
                // Token 签名无效 → 401011
                $code = 401011;
                \support\Log::info('[JWT Exception] Token签名无效，返回401011', [
                    'message' => $message,
                ]);
            }

            return json([
                'code' => $code,
                'msg' => $message
            ]);
        }

        // 过滤 opis/closure Serializable 接口弃用警告（PHP 8.2+ 兼容性）
        if ($exception instanceof \ErrorException) {
            $message = $exception->getMessage();
            if (str_contains($message, 'SerializableClosure') &&
                str_contains($message, 'Serializable interface')) {
                // 记录到日志，但不返回给客户端
                \support\Log::warning('opis/closure Serializable deprecated warning', [
                    'message' => $message,
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                ]);

                return json([
                    'code' => 500,
                    'msg' => 'Internal Server Error'
                ]);
            }
        }

        return json([
            'code' => $exception->getCode(),
            'msg' => $exception->getMessage()
        ]);
    }
}