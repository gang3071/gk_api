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

namespace app\middleware;

use app\model\Channel;
use support\Cache;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * 站点验证中间件
 * Class SiteAuthMiddleware
 * @package app\middleware
 */
class SiteAuthMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        // 站点标识
        $siteId = $request->header('Site-Id');
        $isIp = $request->header('Is-Ip', 0);
        $clientVersion = $request->header('Client-Version', '');
        $appVersion = $request->header('App-Version', '');
        // 排除接口
        if (preg_match('/^\/agent\/.*/', $request->path())) {
            return $handler($request);
        }
        // 排除接口
        if ($request->path() == '/api/v1/talk-pay-notify' || $request->path() == '/test' || $request->path() == '/callback-fast-bind' || $request->path() == '/callback-withdraw'
            || $request->path() == '/external/get-password' || $request->path() == '/eh-callback-deposit' || $request->path() == '/eh-callback-withdraws' || $request->path() == '/external/get-lottery-pool-and-records' || $request->path() == '/external/test-check-lottery' || $request->path() == '/external/test-trigger-burst' || $request->path() == '/external/test-get-burst-status' || $request->path() == '/external/test-end-burst' || $request->path() == '/external/test-probability' || $request->path() == '/external/test-send-win-message') {
            return $handler($request);
        }
        if (empty($siteId)) {
            return response('fail', 400);
        }
        $cacheKey = "channel_" . $siteId;
        $channel = Cache::get($cacheKey);
        if (empty($channel)) {
            /** @var Channel $channel */
            $channel = Channel::where('site_id', $siteId)->whereNull('deleted_at')->where('status', 1)->first();
            if (!empty($channel)) {
                $cacheKey = "channel_" . $channel->site_id;
                Cache::set($cacheKey, $channel->toArray());
            } else {
                return response('fail', 400);
            }
        }
        if ($channel['status'] == 0 || !empty($channel['deleted_at'])) {
            return response('fail', 400);
        }
        if (!empty($channel['client_version']) && !empty($clientVersion) && $channel['client_version'] > $clientVersion) {
            return jsonFailResponse(trans('client_version_incorrect', [], 'message'), [], 466);
        }
        if (!empty($channel['app_version_code']) && !empty($appVersion) && $channel['app_version_code'] != $appVersion) {

            return jsonFailResponse(trans('client_version_incorrect', [], 'message'), [
                'version_name' => $channel['client_version'] ?? '1.0.0',
                'version_code' => $channel['app_version_code'] ?? 1,
                'update_title' => $channel['app_update_title'] ?? '',
                'update_content' => $channel['app_update_content'] ?? '',
                'force_update' => ($channel['app_force_update'] == 1 || $channel['app_force_update'] == true),
                'download_url' => $channel['app_download_url'] ?? $channel['download_url'] ?? '',
            ], 466);
        }
        $request->department_id = $channel['department_id'];
        $request->site_id = $siteId;
        $request->is_ip = $isIp;

        return $handler($request);
    }
}
