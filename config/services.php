<?php
/**
 * 外部服务配置文件
 *
 * 所有 env() 调用必须在配置文件中进行
 * 应用代码应使用 config() 函数读取配置
 */

return [
    // 应用基础配置
    'app' => [
        'url' => env('APP_URL', 'http://127.0.0.1:8787'),
    ],

    // 游戏平台代理服务配置
    'game_platform_proxy' => [
        'enabled' => env('GAME_PLATFORM_PROXY_ENABLE', true),
        'host' => env('GAME_PLATFORM_PROXY_HOST', '10.140.0.10'),
        'port' => env('GAME_PLATFORM_PROXY_PORT', '8080'),
        'telegram' => [
            'notify_enabled' => env('GAME_PLATFORM_PROXY_TELEGRAM_NOTIFY', false),
            'bot_token' => env('TELEGRAM_BOT_TOKEN'),
            'chat_id' => env('TELEGRAM_CHAT_ID'),
        ],
    ],

    // GK Work 服务配置
    'gk_work' => [
        'url' => env('GK_WORK_URL', 'http://127.0.0.1:8788'),
    ],

    // 钱包服务配置
    'wallet' => [
        'cache_enabled' => env('WALLET_CACHE_ENABLED', true),
    ],

    // Turnstile 验证服务配置
    'turnstile' => [
        'enabled' => env('TURNSTILE_ENABLED', false),
        'site_key' => env('TURNSTILE_SITE_KEY', ''),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
    ],

    // 推送服务配置
    'push' => [
        'api_url' => env('PUSH_API_URL', 'http://10.140.0.6:3232'),
        'app_key' => env('PUSH_APP_KEY', '20f94408fc4c52845f162e92a253c7a3'),
        'app_secret' => env('PUSH_APP_SECRET', '3151f8648a6ccd9d4515386f34127e28'),
    ],

    // 攻略服务配置
    'strategy' => [
        'url' => env('STRATEGY_URL', 'http://8.218.226.64:777/#/pages/detail/index?id='),
    ],

    // Google Cloud Storage 配置
    'google_cloud_storage' => [
        'bucket' => env('GOOGLE_CLOUD_STORAGE_BUCKET', 'yjbfile'),
    ],

    // 移动端播放器配置（摸奖券直播）
    'mobile_player' => [
        'license' => env('MOBILE_PLAYER_LICENSE', ''),
        'license_key' => env('MOBILE_PLAYER_LICENSE_KEY', ''),
    ],
];
