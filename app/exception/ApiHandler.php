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
        // JWT 异常处理 - 只区分 Access Token 过期（刷新）和其他情况（重新登录）
        if ($exception instanceof JwtTokenExpiredException
            || $exception instanceof JwtRefreshTokenExpiredException
            || $exception instanceof JwtTokenException
            || $exception instanceof JwtCacheTokenException) {

            $message = $exception->getMessage();

            // 🔍 测试日志：JWT异常详情
            \support\Log::info('[JWT Exception] JWT异常捕获', [
                'exception_class' => get_class($exception),
                'message' => $message,
                'path' => $request->path(),
                'method' => $request->method(),
            ]);

            // ⚡ Access Token 过期 → 401013（客户端应刷新 token）
            if ($exception instanceof JwtTokenExpiredException) {
                \support\Log::info('[JWT Exception] Access Token过期，返回401013提示刷新');
                return json([
                    'code' => 401013,
                    'msg' => $message
                ]);
            }

            // 🔴 其他所有 JWT 异常 → 401（客户端应重新登录）
            \support\Log::info('[JWT Exception] JWT异常，返回401提示重新登录', [
                'exception_type' => get_class($exception),
            ]);
            return json([
                'code' => 401,
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

        // 其他异常处理 - 避免泄露服务器路径
        $code = $exception->getCode() ?: 500;
        $message = $exception->getMessage();

        // 🛡️ 生产环境隐藏敏感信息
        if (!config('app.debug', false)) {
            // TypeError、ArgumentCountError 等 PHP 内部错误不应暴露给客户端
            if ($exception instanceof \TypeError
                || $exception instanceof \ArgumentCountError
                || $exception instanceof \ParseError
                || $exception instanceof \Error) {

                // 记录完整错误到日志
                \support\Log::error('[API Exception] PHP Internal Error', [
                    'exception_class' => get_class($exception),
                    'message' => $message,
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                    'trace' => $exception->getTraceAsString(),
                ]);

                return json([
                    'code' => 500,
                    'msg' => trans('system_error', [], 'message')
                ]);
            }

            // 过滤包含文件路径的错误信息
            if (preg_match('/\/www\/|\/home\/|\/var\/|\/usr\/|[A-Z]:\\\\|called in/', $message)) {
                \support\Log::error('[API Exception] Path Leaked in Message', [
                    'exception_class' => get_class($exception),
                    'original_message' => $message,
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                ]);

                return json([
                    'code' => $code ?: 500,
                    'msg' => trans('system_error', [], 'message')
                ]);
            }
        }

        // Debug 模式或安全的错误信息，直接返回
        return json([
            'code' => $code,
            'msg' => $message
        ]);
    }
}