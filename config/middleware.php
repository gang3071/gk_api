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

use app\middleware\ApiSignGuardMiddleware;
use app\middleware\DeviceCollectMiddleware;
use app\middleware\Lang;
use app\middleware\SiteAuthMiddleware;
use Wengg\WebmanApiSign\ApiSignMiddleware;

return [
    // api应用中间件
    'api' => [
        ApiSignGuardMiddleware::class,  // 签名参数验证（防止异常泄露）
        ApiSignMiddleware::class,       // 签名验证（必需）
        SiteAuthMiddleware::class,      // 站点验证（必需）
        DeviceCollectMiddleware::class, // 设备采集（/api/web 路径排除）
        Lang::class                     // 语言设置（可选）
    ],
    //单一钱包中间件
    'wallet' => [
        Lang::class
    ],
];
