<?php

namespace support;

use support\Redis;
use support\Response;

/**
 * 幂等性处理 Trait（gk_api）
 *
 * 基于 Redis 实现的 request_id 幂等性保证：
 * 1. 不修改数据库表结构
 * 2. 使用 Redis 存储 request_id 和响应结果
 * 3. 15分钟自动过期（足够覆盖99%重试场景）
 * 4. 支持自动返回缓存的响应
 *
 * 使用场景：
 * - 开分（open-score）
 * - 转账（transfer）
 * - 下分（settle）
 * - 其他需要幂等性保护的接口
 */
trait IdempotentTrait
{
    /**
     * 获取 Redis Key 前缀
     */
    private function getIdempotentKeyPrefix(): string
    {
        return 'idempotent:request:';
    }

    /**
     * 获取幂等性记录 TTL（15分钟）
     *
     * 15分钟足够覆盖99%的重试场景：
     * - 网络超时重试：几秒到1分钟
     * - 客户端崩溃重启：几分钟内
     * - 服务端重启：几分钟内
     * - 用户误操作：几秒内
     */
    private function getIdempotentTtl(): int
    {
        return 900;  // 15分钟 = 900秒
    }

    /**
     * 检查并处理幂等性
     *
     * @param string|null $requestId 客户端传递的 request_id
     * @param string $operation 操作类型（用于日志和区分不同操作）
     * @param int|null $playerId 玩家ID（可选，用于日志）
     * @return Response|null 如果是重复请求，返回缓存的响应；否则返回 null
     */
    protected function checkIdempotent(?string $requestId, string $operation, ?int $playerId = null): ?Response
    {
        // 如果客户端未传递 request_id，不进行幂等性检查
        if (empty($requestId)) {
            return null;
        }

        $redisKey = $this->getIdempotentKeyPrefix() . $requestId;

        try {
            $redis = Redis::connection();

            // 检查 Redis 中是否存在该 request_id
            $cachedData = $redis->get($redisKey);

            if ($cachedData !== false && $cachedData !== null) {
                // 反序列化缓存的响应数据
                $responseData = json_decode($cachedData, true);

                if (is_array($responseData)) {
                    // 检查是否是占位状态
                    if (isset($responseData['status']) && $responseData['status'] === 'processing') {
                        \support\Log::info("幂等性拦截：请求处理中", [
                            'request_id' => $requestId,
                            'operation' => $operation,
                            'player_id' => $playerId,
                        ]);

                        // 返回"处理中"的响应（使用 jsonFailResponse 保持一致）
                        return jsonFailResponse(trans('request_processing', [], 'message'));
                    }

                    \support\Log::info("幂等性拦截：重复请求", [
                        'request_id' => $requestId,
                        'operation' => $operation,
                        'player_id' => $playerId,
                        'cached_response' => $responseData
                    ]);

                    // 返回缓存的响应
                    return $this->buildResponseFromCache($responseData);
                }
            }

            return null; // 非重复请求

        } catch (\Throwable $e) {
            // 幂等性检查失败不应阻塞业务，记录日志后继续执行
            \support\Log::error("幂等性检查异常", [
                'request_id' => $requestId,
                'operation' => $operation,
                'player_id' => $playerId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * 提前占位（防止并发重复执行）
     *
     * 在业务逻辑执行前先占位，防止在时间窗口内并发请求生成重复订单。
     * 占位成功后必须调用 saveIdempotent() 保存最终响应，或在失败时调用 releaseIdempotent() 释放占位。
     *
     * @param string|null $requestId 客户端传递的 request_id
     * @param string $operation 操作类型
     * @param int|null $playerId 玩家ID（可选）
     * @return bool true=占位成功，可以继续执行；false=占位失败，已在处理中
     */
    protected function reserveIdempotent(?string $requestId, string $operation, ?int $playerId = null): bool
    {
        // 如果客户端未传递 request_id，不进行占位
        if (empty($requestId)) {
            return true;
        }

        $redisKey = $this->getIdempotentKeyPrefix() . $requestId;

        try {
            $redis = Redis::connection();

            // 占位数据
            $reserveData = [
                'status' => 'processing',
                'operation' => $operation,
                'player_id' => $playerId,
                'started_at' => time(),
            ];

            // 尝试设置占位标记（NX：不存在才设置）
            // 占位时间：30秒（足够完成业务逻辑）
            $reserved = $redis->set(
                $redisKey,
                json_encode($reserveData, JSON_UNESCAPED_UNICODE),
                'EX',
                30,
                'NX'
            );

            if (!$reserved) {
                // 占位失败，说明已经在处理中或已完成
                \support\Log::warning("幂等性占位失败：请求已在处理中", [
                    'request_id' => $requestId,
                    'operation' => $operation,
                    'player_id' => $playerId,
                ]);
                return false;
            }

            \support\Log::info("幂等性占位成功", [
                'request_id' => $requestId,
                'operation' => $operation,
                'player_id' => $playerId,
            ]);

            return true;

        } catch (\Throwable $e) {
            // 占位异常时允许执行（降级策略）
            \support\Log::error("幂等性占位异常", [
                'request_id' => $requestId,
                'operation' => $operation,
                'player_id' => $playerId,
                'error' => $e->getMessage()
            ]);
            return true;
        }
    }

    /**
     * 释放占位（业务失败时调用）
     *
     * 当业务逻辑执行失败时，释放占位标记，允许客户端重试。
     *
     * @param string|null $requestId 客户端传递的 request_id
     * @return void
     */
    protected function releaseIdempotent(?string $requestId): void
    {
        if (empty($requestId)) {
            return;
        }

        $redisKey = $this->getIdempotentKeyPrefix() . $requestId;

        try {
            $redis = Redis::connection();
            $redis->del($redisKey);

            \support\Log::info("幂等性占位已释放", [
                'request_id' => $requestId,
            ]);

        } catch (\Throwable $e) {
            \support\Log::error("幂等性占位释放失败", [
                'request_id' => $requestId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 保存幂等性记录
     *
     * @param string|null $requestId 客户端传递的 request_id
     * @param Response $response 要缓存的响应对象
     * @param string $operation 操作类型
     * @param int|null $playerId 玩家ID（可选，用于日志）
     * @return void
     */
    protected function saveIdempotent(?string $requestId, Response $response, string $operation, ?int $playerId = null): void
    {
        // 如果客户端未传递 request_id，不保存幂等性记录
        if (empty($requestId)) {
            return;
        }

        $redisKey = $this->getIdempotentKeyPrefix() . $requestId;

        try {
            $redis = Redis::connection();

            // 提取响应数据
            $responseData = [
                'status_code' => $response->getStatusCode(),
                'headers' => $response->getHeaders(),
                'body' => $response->rawBody(),
                'operation' => $operation,
                'player_id' => $playerId,
                'created_at' => time()
            ];

            // 序列化并存储到 Redis，设置 24 小时过期
            $ttl = $this->getIdempotentTtl();
            $redis->setex(
                $redisKey,
                $ttl,
                json_encode($responseData, JSON_UNESCAPED_UNICODE)
            );

            \support\Log::info("幂等性记录已保存", [
                'request_id' => $requestId,
                'operation' => $operation,
                'player_id' => $playerId,
                'ttl' => $ttl
            ]);

        } catch (\Throwable $e) {
            // 保存失败不应阻塞业务，仅记录日志
            \support\Log::error("幂等性记录保存失败", [
                'request_id' => $requestId,
                'operation' => $operation,
                'player_id' => $playerId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 从缓存数据构建响应对象
     *
     * @param array $cachedData 缓存的响应数据
     * @return Response
     */
    private function buildResponseFromCache(array $cachedData): Response
    {
        $statusCode = $cachedData['status_code'] ?? 200;
        $headers = $cachedData['headers'] ?? [];
        $body = $cachedData['body'] ?? '';

        return new Response($statusCode, $headers, $body);
    }

    /**
     * 包装业务方法，自动处理幂等性（带占位机制）
     *
     * 使用示例：
     * ```php
     * return $this->withIdempotent($data['request_id'] ?? null, 'open-score', $player->id, function() use ($data, $player) {
     *     // 业务逻辑
     *     return jsonSuccessResponse('success', $result);
     * });
     * ```
     *
     * @param string|null $requestId 客户端传递的 request_id
     * @param string $operation 操作类型
     * @param int|null $playerId 玩家ID（可选）
     * @param callable $callback 业务逻辑回调函数
     * @return Response
     */
    protected function withIdempotent(?string $requestId, string $operation, ?int $playerId, callable $callback): Response
    {
        // 1. 检查幂等性（已完成 或 处理中）
        $cachedResponse = $this->checkIdempotent($requestId, $operation, $playerId);
        if ($cachedResponse !== null) {
            return $cachedResponse;
        }

        // 2. 提前占位（防止并发）
        if (!$this->reserveIdempotent($requestId, $operation, $playerId)) {
            // 占位失败，再次检查状态（可能已完成或正在处理）
            $response = $this->checkIdempotent($requestId, $operation, $playerId);
            return $response ?? jsonFailResponse(trans('request_processing', [], 'message'));
        }

        // 3. 执行业务逻辑
        try {
            $response = $callback();

            // 4. 保存幂等性记录（覆盖占位）
            if ($response instanceof Response) {
                $this->saveIdempotent($requestId, $response, $operation, $playerId);
            }

            return $response;

        } catch (\Throwable $e) {
            // 5. 业务失败，释放占位（允许重试）
            $this->releaseIdempotent($requestId);
            throw $e;
        }
    }
}