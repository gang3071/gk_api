<?php

namespace app\service;

use app\model\PlayGameRecord;
use app\model\Player;
use app\model\PlayerGameLog;
use app\model\PlayerPointsRecord;
use app\model\StoreSetting;
use app\model\VipLevel;
use support\Db;
use support\Log;

/**
 * 推广积分处理服务（补算式，全部在本项目）
 *
 * 每分钟扫描近期已结算、尚未处理的游戏记录（pgr=三方 play_game_record /
 * pgl=机台 player_game_log），计算并入账：
 *
 *   抽成 = 下級實際積分 × 上級 children_points_ratio%
 *   （下級實際積分 = PlayerPointsService::calculatePoints，与打码积分同一公式，
 *     含活动倍数，比例口径天然零漂移）
 *   上級直接入账（TYPE_PROMOTE_POINTS）+ 下級同額扣回（TYPE_PROMOTE_DEDUCT）
 *
 * 幂等/重试：player_points_record.batch_id 唯一索引即游标——
 *   已入账的记录会被扫描条件排除；处理失败的下一轮自动重扫重试，绝不重复入账。
 *
 * 口径与打码积分投递（GameRecordSyncWorker）一致：仅下注类型、非免费旋转、
 * 店家积分开关开启的记录产生推广积分。
 */
class PromotePointsService
{
    /**
     * 扫描窗口（天）：覆盖结算延迟与任务停机；batch_id 幂等保证不重发
     */
    const SCAN_DAYS = 3;

    /**
     * 每表单轮最大处理条数
     */
    const BATCH_SIZE = 2000;

    /**
     * 扫描并处理一轮推广积分
     *
     * @return array 处理统计
     */
    public static function run(): array
    {
        return [
            'game'    => self::scanGameRecords(),
            'machine' => self::scanMachineLogs(),
        ];
    }

    /**
     * 扫描三方游戏记录（play_game_record）
     */
    private static function scanGameRecords(): int
    {
        $table = (new PlayGameRecord())->getTable();
        $since = date('Y-m-d H:i:s', time() - self::SCAN_DAYS * 86400);

        $records = PlayGameRecord::query()
            ->leftJoin('player_points_record as pp', 'pp.batch_id', '=', Db::raw("CONCAT('children_points:parent:pgr:', {$table}.id)"))
            ->whereNull('pp.id')
            ->where($table . '.settlement_status', PlayGameRecord::SETTLEMENT_STATUS_SETTLED)
            ->where($table . '.bet', '>', 0)
            ->where($table . '.parent_player_id', '>', 0)
            ->where(function ($q) use ($table) {
                $q->whereNull($table . '.type')->orWhere($table . '.type', PlayGameRecord::TYPE_BET);
            })
            // ⚠️ 必须带 created_at（play_game_record 按月分区，不带会扫全部分区）
            ->where($table . '.created_at', '>=', $since)
            ->select($table . '.*')
            ->orderBy($table . '.id')
            ->limit(self::BATCH_SIZE)
            ->get();

        $count = 0;
        foreach ($records as $record) {
            // 免费旋转（betKind=3）不计打码量/积分（bet_kind 未落库，原始请求数据里有）
            $raw = json_decode((string) $record->original_data, true);
            if (is_array($raw) && (int) ($raw['bet_kind'] ?? $raw['betKind'] ?? 1) == 3) {
                continue;
            }

            self::process('pgr', (int) $record->id);
            $count++;
        }

        return $count;
    }

    /**
     * 扫描机台打码记录（player_game_log，弃台/下分）
     */
    private static function scanMachineLogs(): int
    {
        $table = (new PlayerGameLog())->getTable();
        $since = date('Y-m-d H:i:s', time() - self::SCAN_DAYS * 86400);

        $records = PlayerGameLog::query()
            ->leftJoin('player_points_record as pp', 'pp.batch_id', '=', Db::raw("CONCAT('children_points:parent:pgl:', {$table}.id)"))
            ->whereNull('pp.id')
            ->whereIn($table . '.action', [PlayerGameLog::ACTION_LEAVE, PlayerGameLog::ACTION_DOWN])
            ->where($table . '.chip_amount', '>', 0)
            ->where($table . '.parent_player_id', '>', 0)
            ->where($table . '.created_at', '>=', $since)
            ->select($table . '.*')
            ->orderBy($table . '.id')
            ->limit(self::BATCH_SIZE)
            ->get();

        foreach ($records as $record) {
            self::process('pgl', (int) $record->id);
        }

        return count($records);
    }

    /**
     * 处理单条记录的推广积分（口径校验 → 比例 → 计算 → 上級入账 + 下級扣回）
     *
     * @param string $recordType 'pgr'=三方记录 / 'pgl'=机台记录
     * @param int $recordId 游戏记录ID
     */
    public static function process(string $recordType, int $recordId): void
    {
        try {
            // 1. 载入游戏记录
            $record = $recordType === 'pgl'
                ? PlayerGameLog::query()->find($recordId)
                : PlayGameRecord::query()->find($recordId);

            if (!$record) {
                return;
            }

            // 2. 记录口径校验（与打码积分投递 GameRecordSyncWorker 同口径）
            if ($recordType === 'pgr') {
                if (($record->type ?? PlayGameRecord::TYPE_BET) != PlayGameRecord::TYPE_BET
                    || $record->bet <= 0) {
                    return;
                }
                $betAmount  = (float) $record->bet;
                $platformId = (int) $record->platform_id;
            } else {
                if ($record->chip_amount <= 0) {
                    return;
                }
                $betAmount  = (float) $record->chip_amount;
                $platformId = 0; // 机台打码积分按 platform 0
            }

            $childPlayerId  = (int) $record->player_id;
            $parentPlayerId = (int) ($record->parent_player_id ?? 0);

            if ($childPlayerId <= 0 || $parentPlayerId <= 0) {
                return;
            }

            // 3. 下級/上級玩家
            $child = Player::query()->find($childPlayerId);
            $parent = Player::query()->find($parentPlayerId);
            if (!$child || !$parent) {
                Log::channel('player_points')->warning('PromotePoints: 玩家不存在', [
                    'child_player_id'  => $childPlayerId,
                    'parent_player_id' => $parentPlayerId,
                    'record_type'      => $recordType,
                    'record_id'        => $recordId,
                ]);
                return;
            }

            // 4. 店家积分开关（关闭时下級不产生积分，也没有抽成）
            if (! self::isPointsEnabled($child)) {
                return;
            }

            // 5. 上級 VIP 的推广积分比例
            $parentVip = VipLevel::query()->find($parent->vip_level_id);
            $childrenRatio = floatval($parentVip->children_points_ratio ?? 0);
            if ($childrenRatio <= 0) {
                return;
            }

            // 6. 抽成 = 下級實際積分 × children_points_ratio%
            $gross = PlayerPointsService::calculatePoints(
                $betAmount,
                (int) $child->vip_level_id,
                $platformId,
                (int) ($child->department_id ?? 0)
            );
            $promotePoints = round($gross * $childrenRatio / 100, 4);
            if ($promotePoints <= 0) {
                return;
            }

            $adminInfo = ['admin_id' => 0, 'admin_name' => 'system', 'admin_ip' => ''];

            // 7. ✅ 上級直接入账 + 记录（与下級扣回分开 try/catch，任一步失败不连带跳过另一步；
            //    失败后下轮扫描会自动重试，batch_id 保证不重复）
            try {
                PlayerPointsService::addPoints(
                    $parentPlayerId,
                    $promotePoints,
                    PlayerPointsRecord::TYPE_PROMOTE_POINTS,
                    PlayerPointsRecord::SOURCE_PROMOTE_POINTS,
                    "推廣積分（來自下級玩家 {$childPlayerId}）",
                    ['batch_id' => "children_points:parent:{$recordType}:{$recordId}"],
                    $adminInfo
                );

                Log::channel('player_points')->info('PromotePoints: 上级积分入账成功', [
                    'parent_player_id' => $parentPlayerId,
                    'child_player_id'  => $childPlayerId,
                    'promote_points'   => $promotePoints,
                    'record_type'      => $recordType,
                    'record_id'        => $recordId,
                ]);
            } catch (\Throwable $e) {
                Log::channel('player_points')->error('PromotePoints: 上级积分入账失败', [
                    'parent_player_id' => $parentPlayerId,
                    'child_player_id'  => $childPlayerId,
                    'promote_points'   => $promotePoints,
                    'record_type'      => $recordType,
                    'record_id'        => $recordId,
                    'error'            => $e->getMessage(),
                ]);
            }

            // 8. ✅ 下級扣回同額積分（机台、三方都扣：上级拿多少、下级就扣多少）
            try {
                $available = PlayerPointsService::getPlayerPoints($childPlayerId)['available_points'];
                $deduct = min($promotePoints, floatval($available));

                if ($deduct <= 0) {
                    // ⚠️ error 级：扣回跳过绝不能静默，否则上级入账后下级账目对不上
                    Log::channel('player_points')->error('PromotePoints: 下级积分扣回跳过（可用余额为0）', [
                        'child_player_id' => $childPlayerId,
                        'promote_points'  => $promotePoints,
                        'available'       => $available,
                        'record_type'     => $recordType,
                        'record_id'       => $recordId,
                    ]);
                    return;
                }

                if ($deduct < $promotePoints) {
                    Log::channel('player_points')->error('PromotePoints: 下级积分扣回金额不足，按可用余额扣减', [
                        'child_player_id' => $childPlayerId,
                        'promote_points'  => $promotePoints,
                        'available'       => $available,
                        'record_type'     => $recordType,
                        'record_id'       => $recordId,
                    ]);
                }

                // source 经 extraData 覆盖（deductPoints 内部默认按 type 推导 source）
                PlayerPointsService::deductPoints(
                    $childPlayerId,
                    $deduct,
                    PlayerPointsRecord::TYPE_PROMOTE_DEDUCT,
                    "推廣積分抽成（上級玩家 {$parentPlayerId}）",
                    [
                        'batch_id' => "children_points:child:{$recordType}:{$recordId}",
                        'source'   => PlayerPointsRecord::SOURCE_PROMOTE_POINTS,
                    ],
                    $adminInfo
                );

                Log::channel('player_points')->info('PromotePoints: 下级积分扣回成功', [
                    'parent_player_id' => $parentPlayerId,
                    'child_player_id'  => $childPlayerId,
                    'deduct_points'    => $deduct,
                    'record_type'      => $recordType,
                    'record_id'        => $recordId,
                ]);
            } catch (\Throwable $e) {
                Log::channel('player_points')->error('PromotePoints: 下级积分扣回失败', [
                    'parent_player_id' => $parentPlayerId,
                    'child_player_id'  => $childPlayerId,
                    'promote_points'   => $promotePoints,
                    'record_type'      => $recordType,
                    'record_id'        => $recordId,
                    'error'            => $e->getMessage(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::channel('player_points')->error('PromotePoints: 处理异常', [
                'record_type' => $recordType,
                'record_id'   => $recordId,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    /**
     * 玩家所属店家/代理的积分开关（与 gk_work PlayerPointsQueueService::isPointsEnabled 同口径）
     *
     * ⚠️ 不可用 StoreSetting::getSetting()：会把 status=0 的「关闭」行过滤成 null，与「未配置」混淆。
     */
    private static function isPointsEnabled(Player $player): bool
    {
        try {
            $adminUserId = $player->store_admin_id ?: $player->agent_admin_id;
            if (! $adminUserId) {
                return true;
            }

            $setting = StoreSetting::query()
                ->where('feature', 'enable_points')
                ->where('department_id', $player->department_id ?? 0)
                ->where('admin_user_id', $adminUserId)
                ->first();

            return ! $setting || $setting->status == 1;
        } catch (\Throwable $e) {
            Log::channel('player_points')->warning('PromotePoints: 积分开关查询异常，放行', [
                'player_id' => $player->id ?? 0,
                'error'     => $e->getMessage(),
            ]);
            return true;
        }
    }
}
