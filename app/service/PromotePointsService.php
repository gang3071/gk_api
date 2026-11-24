<?php

namespace app\service;

use app\model\Player;
use app\model\PlayerPointsRecord;
use app\model\VipLevel;
use support\Log;

/**
 * 推广积分处理服务（消息驱动，与打码积分同消息实时入账）
 *
 * 触发：gk_api player-points 队列消费者（PlayerPoints::consume）在下级打码积分
 * 入账成功后调用。三方（play_game_record）与机台（每注投递的 platform 0 消息）
 * 同一路径、实时到账。
 *
 * 口径（定稿）：
 *   推广积分 = 下級本批【實際入账】积分 × 上級 children_points_ratio%
 *   - 下級积分为 0 或命中每日上限（points_earned = 0）时不计推广
 *   - 上級直接入账（TYPE_PROMOTE_POINTS）+ 下級同額扣回（TYPE_PROMOTE_DEDUCT，机台三方都扣）
 *
 * 不丢 / 不重：
 *   - 与打码积分同消息同生共死——消息丢失时下級也没有积分，账目天然对称，
 *     不存在「下級没积分、上級照拿」的第二事实源；
 *   - batch_id 幂等（player_points_record.batch_id 唯一索引）：队列重投/重试
 *     绝不重复入账（上級入账与下級扣回各自独立幂等，重试只补未完成的一半）；
 *   - 消费失败由队列重试（消费端失败时释放去重标记），重试经 addPointsFromBetting
 *     的批次已处理标记回读 points_earned，推广照常补做。
 */
class PromotePointsService
{
    /**
     * 上級/比例解析缓存 TTL（秒）——机台每注消息高频，避免逐条查库
     */
    private const RESOLVE_CACHE_TTL = 60;

    /**
     * @var array<int, array{exp:int, parent_id:int, child_name:string, parent_name:string, ratio:float}>
     */
    private static array $resolveCache = [];

    /**
     * 处理一条打码积分消息的推广积分（上級入账 + 下級同額扣回）
     *
     * @param int $childPlayerId 下級玩家ID（消息的 player_id）
     * @param float $pointsEarned 下級本批實際入账积分（addPointsFromBetting 返回值）
     * @param string $msgBatchId 消息 batch_id（幂等键来源）
     * @throws \Throwable 入账失败时抛出，由队列重试（batch_id 幂等保证不重复）
     */
    public static function applyForMessage(int $childPlayerId, float $pointsEarned, string $msgBatchId): void
    {
        if ($childPlayerId <= 0 || $pointsEarned <= 0 || $msgBatchId === '') {
            return;
        }

        try {
            $resolved = self::resolveParent($childPlayerId);
            if (!$resolved || $resolved['parent_id'] <= 0) {
                return;
            }

            $ratio = $resolved['ratio'];
            if ($ratio <= 0) {
                return;
            }

            // 抽成 = 下級實際入账积分 × children_points_ratio%
            $promote = round($pointsEarned * $ratio / 100, 4);
            if ($promote <= 0) {
                return;
            }

            $adminInfo = ['admin_id' => 0, 'admin_name' => 'system', 'admin_ip' => ''];

            // ✅ 上級直接入账（batch_id 幂等；失败抛出触发队列重试）
            PlayerPointsService::addPoints(
                $resolved['parent_id'],
                $promote,
                PlayerPointsRecord::TYPE_PROMOTE_POINTS,
                PlayerPointsRecord::SOURCE_PROMOTE_POINTS,
                "推廣積分（來自下級玩家 {$resolved['child_name']}）",
                ['batch_id' => "children_points:parent:msg:{$msgBatchId}"],
                $adminInfo
            );

            Log::channel('player_points')->info('PromotePoints: 上级积分入账成功', [
                'parent_player_id' => $resolved['parent_id'],
                'child_player_id'  => $childPlayerId,
                'promote_points'   => $promote,
                'batch_id'         => $msgBatchId,
            ]);

            // ✅ 下級同額扣回（上级拿多少、下级就扣多少）
            $available = PlayerPointsService::getPlayerPoints($childPlayerId)['available_points'];
            $deduct = min($promote, floatval($available));

            if ($deduct <= 0) {
                // ⚠️ error 级：扣回跳过绝不能静默，否则上级入账后下级账目对不上。
                //    余额不会因重试改变，此处不抛出（抛出只会造成队列空转重试）。
                Log::channel('player_points')->error('PromotePoints: 下级积分扣回跳过（可用余额为0）', [
                    'child_player_id' => $childPlayerId,
                    'promote_points'  => $promote,
                    'available'       => $available,
                    'batch_id'        => $msgBatchId,
                ]);
                return;
            }

            if ($deduct < $promote) {
                Log::channel('player_points')->error('PromotePoints: 下级积分扣回金额不足，按可用余额扣减', [
                    'child_player_id' => $childPlayerId,
                    'promote_points'  => $promote,
                    'deduct_points'   => $deduct,
                    'available'       => $available,
                    'batch_id'        => $msgBatchId,
                ]);
            }

            // source 经 extraData 覆盖（deductPoints 内部默认按 type 推导 source）
            PlayerPointsService::deductPoints(
                $childPlayerId,
                $deduct,
                PlayerPointsRecord::TYPE_PROMOTE_DEDUCT,
                "推廣積分抽成（上級玩家 {$resolved['parent_name']}）",
                [
                    'batch_id' => "children_points:child:msg:{$msgBatchId}",
                    'source'   => PlayerPointsRecord::SOURCE_PROMOTE_POINTS,
                ],
                $adminInfo
            );

            Log::channel('player_points')->info('PromotePoints: 下级积分扣回成功', [
                'parent_player_id' => $resolved['parent_id'],
                'child_player_id'  => $childPlayerId,
                'deduct_points'    => $deduct,
                'batch_id'         => $msgBatchId,
            ]);
        } catch (\Throwable $e) {
            Log::channel('player_points')->error('PromotePoints: 处理失败（等待队列重试）', [
                'child_player_id' => $childPlayerId,
                'points_earned'   => $pointsEarned,
                'batch_id'        => $msgBatchId,
                'error'           => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * 解析下級 → 上級（含上級 VIP 比例、双方名称），带短 TTL 进程内缓存
     *
     * 名称口径与后台「玩家帳號」列一致：name → account → player ID。
     *
     * @return array{exp:int, parent_id:int, child_name:string, parent_name:string, ratio:float}|null
     */
    private static function resolveParent(int $childPlayerId): ?array
    {
        $now = time();
        $cached = self::$resolveCache[$childPlayerId] ?? null;
        if ($cached && $cached['exp'] > $now) {
            return $cached;
        }

        $child = Player::query()->find($childPlayerId);
        if (!$child) {
            Log::channel('player_points')->warning('PromotePoints: 下级玩家不存在', [
                'child_player_id' => $childPlayerId,
            ]);
            return null;
        }

        $entry = [
            'exp'         => $now + self::RESOLVE_CACHE_TTL,
            'parent_id'   => (int) ($child->recommend_id ?? 0),
            'child_name'  => $child->name ?: ($child->account ?: $childPlayerId),
            'parent_name' => '',
            'ratio'       => 0.0,
        ];

        if ($entry['parent_id'] > 0) {
            $parent = Player::query()->find($entry['parent_id']);
            if (!$parent) {
                Log::channel('player_points')->warning('PromotePoints: 上级玩家不存在', [
                    'child_player_id'  => $childPlayerId,
                    'parent_player_id' => $entry['parent_id'],
                ]);
                $entry['parent_id'] = 0;
            } else {
                $entry['parent_name'] = $parent->name ?: ($parent->account ?: $entry['parent_id']);
                $parentVip = VipLevel::query()->find($parent->vip_level_id);
                $entry['ratio'] = floatval($parentVip->children_points_ratio ?? 0);
            }
        }

        self::$resolveCache[$childPlayerId] = $entry;
        return $entry;
    }
}
