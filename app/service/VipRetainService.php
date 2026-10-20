<?php

namespace app\service;

use app\model\Player;
use app\model\PlayerVipPeriod;
use app\model\VipLevel;
use support\Db;
use support\Log;

/**
 * VIP 保级结算服务（读取用户信息前补结算）
 *
 * 【为什么需要】
 * 保级判定原本挂在下注链路（gk_admin VipService::handleBet → checkRetain）。
 * 玩家长时间不打码就不会触发下注，周期数据会一直停在「已到期但未结算」的状态，
 * 用户信息接口返回的还是老的保级数据（甚至早就该降级却没降）。
 *
 * 所以在 /me/profile、/player-info 读取前补一次结算。
 *
 * 【触发频率】只在剩余保级天数 <= 0 时才处理
 * 这两个接口客户端会高频轮询，不能每次请求都做结算。
 * 剩余天数由调用方算好传入，未到期直接返回，不写库、不加锁。
 *
 * 【判定规则】与 gk_admin VipService::checkRetain 保持一致：
 * - 窗口打码 ≥ 保级量          → 保级成功，升级经验【不扣】结转，窗口归 0
 * - 窗口打码 < 保级量 且 非最低级 → 降一级并【清空升级经验】
 * - 窗口打码 < 保级量 且 最低级   → 不降级、不清经验，窗口归 0
 *
 * 【两个计数互相独立，不可混用】
 * - 升级经验 = period_bet_amount                （跨窗口结转，升级/降级才清）
 * - 窗口打码 = total_bet_amount - start_bet_amount （每窗口重置）
 *
 *   ⚠️ 保级判定必须用「窗口打码」。若误用升级经验，经验一旦超过保级量
 *      就会永远保级成功（一个钱都不打也过）。
 */
class VipRetainService
{
    /** 结算结果：无需结算 */
    const RESULT_NONE = 'none';

    /** 结算结果：周期缺失，已补建 */
    const RESULT_CREATED = 'created';

    /** 结算结果：保级成功（经验结转，窗口重置） */
    const RESULT_RETAINED = 'retained';

    /** 结算结果：最低级保级失败（不降级、不清经验，窗口重置） */
    const RESULT_MIN_LEVEL_ROLLOVER = 'min_level_rollover';

    /** 结算结果：保级失败，已降级并清空经验 */
    const RESULT_DOWNGRADED = 'downgraded';

    /**
     * 若保级周期已到期，补做保级结算
     *
     * 【触发频率】剩余保级天数由调用方算好传入，> 0 直接返回，不查库、不写库。
     *
     * 幂等：未到期 / 未配置保级 / 无等级时直接返回，不会写库。
     * 并发安全：对周期行加行锁，重复请求串行执行，后到者看到新周期后判定为无需结算。
     *
     * @param Player $player 玩家
     * @param int $remainingDays 剩余保级天数（口径同接口的 retain_remaining_days）
     * @return string 结算结果，见 RESULT_* 常量
     */
    public static function settleIfDue(Player $player, int $remainingDays): string
    {
        // 还有剩余保级天数：还没到处理的时候
        if ($remainingDays > 0) {
            return self::RESULT_NONE;
        }

        if (empty($player->vip_level_id)) {
            return self::RESULT_NONE;
        }

        // 剩余 <= 0 天：用户真的长时间没打码，做保级结算
        try {
            return Db::transaction(function () use ($player) {
                return self::settleInTransaction($player);
            });
        } catch (\Throwable $e) {
            // 结算失败不能影响用户信息读取，只记录日志
            Log::error('[VipRetain] 保级结算失败', [
                'player_id' => $player->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return self::RESULT_NONE;
        }
    }

    /**
     * 事务内执行结算
     */
    private static function settleInTransaction(Player $player): string
    {
        // 先锁玩家行，把同一玩家的结算串行化。
        // 仅锁周期行不够：查不到活跃周期时 lockForUpdate 不加任何锁，
        // 两个并发请求会各自补建出活跃周期。
        Player::query()->where('id', $player->id)->lockForUpdate()->first();

        // 行锁当前等级的活跃保级周期，防止并发重复结算
        /** @var PlayerVipPeriod|null $period */
        $period = PlayerVipPeriod::query()
            ->where('player_id', $player->id)
            ->where('vip_level_id', $player->vip_level_id)
            ->where('period_type', PlayerVipPeriod::PERIOD_TYPE_RETAIN)
            ->where('status', PlayerVipPeriod::STATUS_ONGOING)
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        $level = self::currentLevel($player);

        if (!$period) {
            // 周期缺失：补建，并继承同等级上一周期的升级经验，
            // 否则接口拿不到保级时间，且历史升级经验会永久丢失
            if ($level) {
                $inherited = self::lastExperienceFor($player, $level);

                Log::warning('[VipRetain] 周期缺失，补建并继承历史经验', [
                    'player_id' => $player->id,
                    'level_id' => $level->id,
                    'inherited_experience' => $inherited,
                    'total_bet_amount' => $player->total_bet_amount,
                    'hint' => '走到这里说明周期链断裂过（上一次结算只关了旧周期没建出新周期）',
                ]);

                self::createPeriod($player, $level, $inherited, 0);
                return self::RESULT_CREATED;
            }
            return self::RESULT_NONE;
        }

        // 等级配置缺失：无法判定，不动数据
        if ($level === null) {
            return self::RESULT_NONE;
        }

        // 未配置保级天数 = 不做保级判定
        // （与 gk_admin 的 isExpired(0) 语义不同，此处特意收窄：
        //   读接口调用频繁，天数为 0 若按「立即到期」处理会在每次读取都翻新周期）
        $retainLevelDays = (int)($level->retain_level_days ?? 0);
        if ($retainLevelDays <= 0) {
            return self::RESULT_NONE;
        }

        if (!self::isExpired($period, $retainLevelDays)) {
            return self::RESULT_NONE;
        }

        // ⚠️ 必须用窗口打码量，不能用 period_bet_amount（可结转的升级经验）
        $windowBetAmount = $period->getRetainWindowBetAmount((float)$player->total_bet_amount);
        $retainLevelBetAmount = (float)($level->retain_level_bet_amount ?? 0);

        // ---------- 保级成功：升级经验结转，窗口重置 ----------
        if ($windowBetAmount >= $retainLevelBetAmount) {
            self::rollPeriod($player, $level, $period, (float)$period->period_bet_amount, PlayerVipPeriod::STATUS_COMPLETED);

            Log::info('[VipRetain] 保级成功', [
                'player_id' => $player->id,
                'level_id' => $level->id,
                'window_bet_amount' => $windowBetAmount,
                'retain_level_bet_amount' => $retainLevelBetAmount,
                'carried_experience' => $period->period_bet_amount,
            ]);
            return self::RESULT_RETAINED;
        }

        // ---------- 保级失败 ----------
        $prevLevel = self::prevLevel($level);

        // 最低级：不降级、不清经验，窗口重置
        // ⚠️ 旧周期标 EXPIRED，与 admin checkRetain 一致——只有保级成功才标 COMPLETED
        if (!$prevLevel) {
            self::rollPeriod($player, $level, $period, (float)$period->period_bet_amount, PlayerVipPeriod::STATUS_EXPIRED);

            Log::info('[VipRetain] 保级失败（最低级，不降级）', [
                'player_id' => $player->id,
                'level_id' => $level->id,
                'window_bet_amount' => $windowBetAmount,
                'carried_experience' => $period->period_bet_amount,
            ]);
            return self::RESULT_MIN_LEVEL_ROLLOVER;
        }

        // 非最低级：降一级 + 清空升级经验
        $period->status = PlayerVipPeriod::STATUS_EXPIRED;
        $period->save();

        $player->vip_level_id = $prevLevel->id;
        $player->save();

        self::createPeriod($player, $prevLevel, 0, 0);

        Log::info('[VipRetain] 保级失败，已降级', [
            'player_id' => $player->id,
            'old_level_id' => $level->id,
            'new_level_id' => $prevLevel->id,
            'window_bet_amount' => $windowBetAmount,
            'retain_level_bet_amount' => $retainLevelBetAmount,
        ]);
        return self::RESULT_DOWNGRADED;
    }

    /**
     * 关闭旧周期并开出新周期
     *
     * @param float $carryOverExperience 结转的升级经验
     * @param int $oldStatus 旧周期要写入的状态（保级成功 COMPLETED / 保级失败 EXPIRED）
     */
    private static function rollPeriod(
        Player $player,
        VipLevel $level,
        PlayerVipPeriod $oldPeriod,
        float $carryOverExperience,
        int $oldStatus
    ): void {
        $oldPeriod->status = $oldStatus;
        $oldPeriod->save();

        self::createPeriod($player, $level, $carryOverExperience, 0);
    }

    /**
     * 取同等级最近一条周期的升级经验（用于「周期缺失补建」时继承）
     *
     * ⚠️ 只在补建路径使用。降级后最近一条就是清零的那条，所以不会把经验复活。
     *    若不继承，周期链一旦断裂，历史升级经验就永久丢失。
     */
    private static function lastExperienceFor(Player $player, VipLevel $level): float
    {
        /** @var PlayerVipPeriod|null $last */
        $last = PlayerVipPeriod::query()
            ->where('player_id', $player->id)
            ->where('vip_level_id', $level->id)
            ->where('period_type', PlayerVipPeriod::PERIOD_TYPE_RETAIN)
            ->orderByDesc('id')
            ->first();

        return $last ? max(0, (float)$last->period_bet_amount) : 0.0;
    }

    /**
     * 创建保级周期
     *
     * @param float $carryOverExperience 结转的升级经验
     * @param float $currentBetAmount 触发建周期的那一笔（计入本窗口）
     */
    private static function createPeriod(
        Player $player,
        VipLevel $level,
        float $carryOverExperience,
        float $currentBetAmount
    ): PlayerVipPeriod {
        $carryOverExperience = max(0, $carryOverExperience);
        $currentBetAmount = max(0, $currentBetAmount);

        // 同等级已存在活跃周期时并入经验，避免重复创建、也避免结转被静默丢弃
        /** @var PlayerVipPeriod|null $existing */
        $existing = PlayerVipPeriod::query()
            ->where('player_id', $player->id)
            ->where('vip_level_id', $level->id)
            ->where('period_type', PlayerVipPeriod::PERIOD_TYPE_RETAIN)
            ->where('status', PlayerVipPeriod::STATUS_ONGOING)
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if ($existing) {
            $merged = max(
                (float)$existing->period_bet_amount,
                $carryOverExperience + $currentBetAmount
            );

            if ($merged > (float)$existing->period_bet_amount) {
                $existing->period_bet_amount = $merged;
                $existing->save();

                Log::warning('[VipRetain] 周期已存在，结转经验已并入', [
                    'player_id' => $player->id,
                    'vip_level_id' => $level->id,
                    'existing_period_id' => $existing->id,
                    'carry_over' => $carryOverExperience,
                    'current_bet' => $currentBetAmount,
                    'merged_experience' => $merged,
                ]);
            }

            return $existing;
        }

        return PlayerVipPeriod::query()->create([
            'player_id' => $player->id,
            'vip_level_id' => $level->id,
            'period_type' => PlayerVipPeriod::PERIOD_TYPE_RETAIN,
            // 窗口起点：本窗口打码量 = total_bet_amount - start_bet_amount
            'start_bet_amount' => max(0, (float)$player->total_bet_amount - $currentBetAmount),
            // 升级经验：可结转
            'period_bet_amount' => $carryOverExperience,
            'started_at' => date('Y-m-d H:i:s'),
            'status' => PlayerVipPeriod::STATUS_ONGOING,
        ]);
    }

    /**
     * 当前 VIP 等级
     */
    private static function currentLevel(Player $player): ?VipLevel
    {
        return VipLevel::query()->find($player->vip_level_id);
    }

    /**
     * 上一等级（同渠道链路，只取启用中的）
     *
     * 口径与 gk_admin VipLevel::getPrevLevel 一致：
     * department_id = 0 表示全局等级，对所有渠道可见。
     */
    private static function prevLevel(VipLevel $level): ?VipLevel
    {
        return VipLevel::query()
            ->where('status', VipLevel::STATUS_ENABLED)
            ->whereIn('department_id', [0, $level->department_id])
            ->where('sort', '<', $level->sort)
            ->orderBy('sort', 'desc')
            ->first();
    }

    /**
     * 周期是否已过保级期限
     *
     * 口径与 gk_admin PlayerVipPeriod::isExpired 一致（整秒运算）。
     */
    private static function isExpired(PlayerVipPeriod $period, int $retainLevelDays): bool
    {
        $expireAt = strtotime($period->started_at) + ($retainLevelDays * 86400);
        return time() > $expireAt;
    }
}
