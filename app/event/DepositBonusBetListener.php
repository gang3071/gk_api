<?php

namespace app\event;

use app\service\DepositBonusBetService;
use support\Log;

/**
 * 押码量统计事件监听器
 *
 * 监听游戏押注结算事件，自动统计押码量
 */
class DepositBonusBetListener
{
    /**
     * @var DepositBonusBetService
     */
    protected $betService;

    public function __construct()
    {
        $this->betService = new DepositBonusBetService();
    }

    /**
     * 处理游戏押注结算事件
     *
     * @param object $event 事件对象，包含押注信息
     * @return void
     */
    public function handle($event): void
    {
        try {
            // 从事件中提取押注数据
            $betData = $this->extractBetData($event);

            if (empty($betData)) {
                return;
            }

            // 记录押注并更新押码量
            $this->betService->recordBet($betData);

        } catch (\Exception $e) {
            Log::error('押码量统计事件处理失败: ' . $e->getMessage(), [
                'event' => get_class($event),
            ]);
        }
    }

    /**
     * 从事件对象中提取押注数据
     *
     * @param object $event
     * @return array|null
     */
    protected function extractBetData($event): ?array
    {
        // 根据实际的事件对象结构提取数据
        // 这里需要根据项目实际的事件结构进行调整

        try {
            // 示例：假设事件对象包含这些属性
            return [
                'player_id' => $event->playerId ?? $event->player_id ?? null,
                'bet_amount' => $event->betAmount ?? $event->bet_amount ?? 0,
                'valid_bet_amount' => $event->validBetAmount ?? $event->valid_bet_amount ?? ($event->betAmount ?? $event->bet_amount ?? 0),
                'win_amount' => $event->winAmount ?? $event->win_amount ?? 0,
                'game_type' => $this->determineGameType($event),
                'game_platform' => $event->gamePlatform ?? $event->game_platform ?? '',
                'game_id' => $event->gameId ?? $event->game_id ?? '',
                'game_name' => $event->gameName ?? $event->game_name ?? '',
                'balance_before' => $event->balanceBefore ?? $event->balance_before ?? 0,
                'balance_after' => $event->balanceAfter ?? $event->balance_after ?? 0,
                'bet_time' => $event->betTime ?? $event->bet_time ?? time(),
                'settle_time' => $event->settleTime ?? $event->settle_time ?? time(),
            ];

        } catch (\Exception $e) {
            Log::error('提取押注数据失败: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 判断游戏类型
     *
     * @param object $event
     * @return string
     */
    protected function determineGameType($event): string
    {
        // 根据游戏平台或游戏ID判断游戏类型

        if (isset($event->gameType) || isset($event->game_type)) {
            return $event->gameType ?? $event->game_type;
        }

        // 根据游戏平台判断
        $platform = $event->gamePlatform ?? $event->game_platform ?? '';

        if (strpos($platform, 'slot') !== false || strpos($platform, '机台') !== false) {
            return 'slot'; // 实体机台
        }

        if (strpos($platform, 'electron') !== false || strpos($platform, '电子') !== false) {
            return 'electron'; // 电子游戏
        }

        if (strpos($platform, 'baccarat') !== false || strpos($platform, '百家') !== false) {
            return 'baccarat'; // 真人百家
        }

        if (strpos($platform, 'lottery') !== false || strpos($platform, '彩票') !== false) {
            return 'lottery'; // 彩票
        }

        // 默认返回电子游戏
        return 'electron';
    }
}
