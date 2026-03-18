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

use app\middleware\Lang;
use app\middleware\SiteAuthMiddleware;
use Wengg\WebmanApiSign\ApiSignMiddleware;

return [
    // api应用中间件
    'api' => [
        ApiSignMiddleware::class,
        SiteAuthMiddleware::class,
        Lang::class
    ],
    //单一钱包中间件
    'wallet' => [
        Lang::class
    ],
];
