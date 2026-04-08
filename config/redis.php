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

return [
    // ========== 向后兼容：default 指向 api ==========
    'default' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'password' => env('REDIS_PASSWORD', null),
        'port' => env('REDIS_PORT', 6379),
        'database' => env('REDIS_DB', 0),
        'timeout' => 2.5,
        'read_timeout' => 2.5,
        'persistent' => true,
        'retry_interval' => 100,

        'options' => [
            'prefix' => env('REDIS_PREFIX', ''),
            'parameters' => [
                'tcp_nodelay' => true,
            ],
        ],
    ],
];
