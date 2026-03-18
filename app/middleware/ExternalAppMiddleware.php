<?php

namespace app\middleware;

use app\model\ExternalApp;
use Firebase\JWT\SignatureInvalidException;
use support\Cache;
use Tinywan\Jwt\Exception\JwtCacheTokenException;
use Tinywan\Jwt\JwtToken;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

class ExternalAppMiddleware implements MiddlewareInterface
{
    /**
     * @throws \Exception
     */
    public function process(Request $request, callable $handler): Response
    {
        try {
            $id = JwtToken::getCurrentId();
        } catch (SignatureInvalidException|JwtCacheTokenException|\Exception) {
            return jsonFailResponse('Access Token无效', [], 0);
        }
        $agentKey = "agent_" . $id;
        $externalApp = Cache::get($agentKey);
        if (empty($externalApp)) {
            /** @var ExternalApp $externalApp */
            $externalApp = ExternalApp::query()
                ->whereHas('channel', function ($query) {
                    $query->whereNull('deleted_at')->where('status', 1);
                })
                ->where('app_id', $id)
                ->where('status', 1)
                ->whereNull('deleted_at')
                ->first();
            if (!empty($externalApp)) {
                Cache::set($agentKey, $externalApp);
            } else {
                return jsonFailResponse('应用不存在', [], 0);
            }
        }
        if (!empty($externalApp->white_ip) && !in_array(request()->getRealIp(),
                explode(',', $externalApp->white_ip))) {
            return jsonFailResponse('IP认证不通过', [], 0);
        }
        
        $request->department_id = $externalApp->department_id;
        
        return $handler($request);
    }
}