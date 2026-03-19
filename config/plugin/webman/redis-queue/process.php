<?php
/**
 * Redis 队列进程配置
 * 注意：本项目只作为生产者发送消息，消费者进程在 worker 服务器运行
 * 因此这里禁用消费者进程，避免重复消费
 */
return [
    'consumer'  => [
        'handler'     => Webman\RedisQueue\Process\Consumer::class,
        'count'       => 0, // 设置为 0 禁用消费者进程
        'enable'      => false, // 禁用消费者
        'constructor' => [
            // 消费者类目录
            'consumer_dir' => app_path() . '/queue/redis'
        ]
    ]
];