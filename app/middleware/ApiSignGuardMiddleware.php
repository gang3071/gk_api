<?php

namespace app\middleware;

use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;
use Wengg\WebmanApiSign\ApiSignMiddleware;
use Wengg\WebmanApiSign\ApiSignService;

/**
 * API 签名验证守护中间件
 *
 * 在 ApiSignMiddleware 之前执行，提前验证必需参数
 * 避免 TypeError 异常泄露服务器路径信息
 */
class ApiSignGuardMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        // 获取签名配置
        $service = new ApiSignService();
        $config = $service->getConfig();

        if (!$config || !$config['enable']) {
            return $handler($request);
        }

        // 获取字段配置
        $fields = $config['fields'];

        // 检查控制器是否需要签名验证
        try {
            $class = new \ReflectionClass($request->controller);
            $properties = $class->getDefaultProperties();
            $noNeedSign = array_map('strtolower', $properties['noNeedSign'] ?? []);
            $needSign = !(in_array(strtolower($request->action), $noNeedSign) || in_array('*', $noNeedSign));
        } catch (\Throwable $e) {
            // 无法获取控制器信息，假设需要签名
            $needSign = true;
        }

        // 如果不需要签名验证，直接放行
        if (!$needSign) {
            return $handler($request);
        }

        // 获取签名参数
        $appId = $request->header($fields['app_id'] ?? 'appId')
              ?: $request->input($fields['app_id'] ?? 'appId');
        $timestamp = $request->header($fields['timestamp'] ?? 'timestamp')
                  ?: $request->input($fields['timestamp'] ?? 'timestamp');
        $nonceStr = $request->header($fields['noncestr'] ?? 'nonceStr')
                 ?: $request->input($fields['noncestr'] ?? 'nonceStr');
        $signature = $request->header($fields['signature'] ?? 'signature')
                  ?: $request->input($fields['signature'] ?? 'signature');

        // 验证必需参数
        $missingParams = [];
        if (empty($appId)) {
            $missingParams[] = $fields['app_id'] ?? 'appId';
        }
        if (empty($timestamp)) {
            $missingParams[] = $fields['timestamp'] ?? 'timestamp';
        }
        if (empty($nonceStr)) {
            $missingParams[] = $fields['noncestr'] ?? 'nonceStr';
        }
        if (empty($signature)) {
            $missingParams[] = $fields['signature'] ?? 'signature';
        }

        // 如果缺少必需参数，返回友好错误
        if (!empty($missingParams)) {
            return jsonFailResponse(
                trans('signature_params_missing', [], 'message')
                    . ': ' . implode(', ', $missingParams),
                [],
                400
            );
        }

        // 参数完整，继续执行
        return $handler($request);
    }
}
