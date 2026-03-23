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
    ];

    public function render(Request $request, Throwable $exception): Response
    {
        if ($exception instanceof JwtTokenExpiredException || $exception instanceof JwtTokenException) {
            return json([
                'code' => 401,
                'msg' => $exception->getMessage()
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