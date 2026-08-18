<?php

namespace app\api\controller\web;

use app\exception\PlayerCheckException;
use app\model\LotteryTicket;
use app\model\LotteryTicketActivity;
use app\model\LotteryTicketBetProgress;
use app\model\LotteryTicketRecord;
use app\model\LotteryTicketVipConfig;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use support\Request;
use support\Response;

class LotteryTicketController
{
    /**
     * 智能获取摸奖券活动（按优先级返回）
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException
     */
    public function currentActivity(Request $request): Response
    {
        $player = checkPlayer();

        $skipEnded = (bool) $request->input('skip_ended', false);  // 是否跳过已结束活动
        $getPrevious = (bool) $request->input('get_previous', false);  // 是否获取上期活动

        // ---------------------------------------- 上期活動 ----------------------------------------
        if ($getPrevious) {
            // 1. 先获取当前活动
            $currentActivity = self::getSmartActivity($player->department_id, true); // 跳过已结束的活动

            // 2. 查询上期活动：比当前活动 ID 小的、最新的已结束活动
            $query = LotteryTicketActivity::query()
                ->where('department_id', $player->department_id)
                ->where('status', LotteryTicketActivity::STATUS_ENDED)
                ->orderBy('id', 'desc');

            // 如果有当前活动，上期活动必须比它 ID 小
            if ($currentActivity) {
                $query->where('id', '<', $currentActivity->id);
            }

            $activity = $query->first();

            if (! $activity) {
                return jsonSuccessResponse('success', [
                    'has_activity' => false,
                    'activity' => null,
                    'message' => '暂无上期活动'
                ]);
            }

            return self::buildActivityResponse($activity, $player);
        }

        // ---------------------------------------- 默認流程 ----------------------------------------
        $activity = self::getSmartActivity($player->department_id, $skipEnded);

        if (! $activity) {
            return jsonSuccessResponse('success', [
                'has_activity' => false,
                'activity' => null
            ]);
        }

        return self::buildActivityResponse($activity, $player);
    }

    /**
     * 智能获取活动（按优先级）
     * @param int $departmentId
     * @param bool $skipEnded 是否跳过已结束活动（获取最新活动）
     * @return Builder|Model|null
     */
    private function getSmartActivity(int $departmentId, bool $skipEnded = false): Builder|Model|null
    {
        // 优先级1: 开奖中的活动
        $activity = LotteryTicketActivity::query()
            ->where('department_id', $departmentId)
            ->where('status', LotteryTicketActivity::STATUS_DRAWING)
            ->orderBy('id', 'desc')
            ->first();

        // 优先级2: 待开奖的活动（活动已结束，等待开奖）
        if (! $activity) {
            $activity = LotteryTicketActivity::query()
                ->where('department_id', $departmentId)
                ->where('status', LotteryTicketActivity::STATUS_PENDING_DRAW)
                ->orderBy('id', 'desc')
                ->first();
        }

        // 优先级3: 进行中的活动（打码中）
        if (! $activity) {
            $activity = LotteryTicketActivity::query()
                ->where('department_id', $departmentId)
                ->where('status', LotteryTicketActivity::STATUS_ONGOING)
                ->orderBy('id', 'desc')
                ->first();
        }

        // 优先级4: 即将开始的活动（7天内）
        if (! $activity) {
            $activity = LotteryTicketActivity::query()
                ->where('department_id', $departmentId)
                ->where('status', LotteryTicketActivity::STATUS_NOT_STARTED)
                ->where('start_time', '<=', date('Y-m-d H:i:s', strtotime('+7 days')))
                ->orderBy('id', 'desc')
                ->first();
        }

        // 优先级5: 已结束的活动（降级展示，避免空白）
        // 当没有任何活跃活动时，展示最近结束的活动，让用户查看历史记录
        if (!$activity && !$skipEnded) {
            $activity = LotteryTicketActivity::query()
                ->where('department_id', $departmentId)
                ->where('status', LotteryTicketActivity::STATUS_ENDED)
                ->orderBy('id', 'desc')
                ->first();
        }

        return $activity;
    }

    /**
     * 构建活动响应数据（优化版）
     * @param LotteryTicketActivity $activity
     * @param $player
     * @return Response
     */
    private function buildActivityResponse(LotteryTicketActivity $activity, $player): Response
    {
        // 计算倒计时
        $countdown = self::calculateCountdown($activity);

        // 總摸獎券清單
        $ticketsAll = LotteryTicket::query()
            ->where('activity_id', $activity->id)
            ->where('player_id', $player->id)
            ->orderBy('id', 'desc')
            ->pluck('ticket_no')
            ->toArray();
        // ---------------------------------------- 打码进度 ----------------------------------------
        $betProgress = LotteryTicketBetProgress::query()
            ->where('activity_id', $activity->id)
            ->where('player_id', $player->id);

        if ($player->vip_level_id !== null) {
            $betProgress->where('vip_level_id', $player->vip_level_id);
        } else {
            $betProgress->whereNull('vip_level_id');
        }

        $betProgress = $betProgress->first();

        $amountRequired = $betProgress->bet_amount_required ?? 0;
        $amountCurrent = $betProgress->current_bet_amount ?? 0;

        if (! $betProgress) {
            $vipConfig = LotteryTicketVipConfig::query()
                ->where('activity_id', $activity->id)
                ->where('vip_level_id', $player->vip_level_id ?? 0)
                ->where('status', 1)
                ->first();

            $amountRequired = $vipConfig ? $vipConfig->bet_amount_required : 0;
        }

        // ---------------------------------------- 总获奖金额 ----------------------------------------
        $ticketRecord = LotteryTicketRecord::query()
            ->where('activity_id', $activity->id)
            ->where('player_id', $player->id)
            ->where('prize_type', '!=', LotteryTicketRecord::PRIZE_TYPE_EMPTY)
            ->orderBy('id', 'desc');

        $ticketsWin = $ticketRecord->pluck('ticket_no')->toArray();
        $amountPrize = $ticketRecord->sum('prize_amount');
        // ---------------------------------------- 上期結果 ----------------------------------------
        $winners = 0;
        $array = [LotteryTicketActivity::STATUS_DRAWING, LotteryTicketActivity::STATUS_ENDED];

        if (in_array($activity->status, $array)) {
            $winners = LotteryTicketRecord::query()
                ->where('activity_id', $activity->id)
                ->where('prize_type', '!=', LotteryTicketRecord::PRIZE_TYPE_EMPTY)
                ->count();
        }

        return jsonSuccessResponse('success', [
            'has_activity' => true,
            'activity' => [
                'id' => $activity->id,
                'name' => $activity->name,
                'description' => $activity->description,
                'draw_completed_at' => $activity->draw_completed_at,
                'total_prize_amount' => $activity->total_prize_amount,
                'status' => $activity->status,
                'statusText' => self::getActivityStatusText($activity->status),
                'countdown' => $countdown,
                'winners' => $winners
            ],
            'tickets' => [
                'all' => $ticketsAll,
                'allCount' => count($ticketsAll),
                'win' => $ticketsWin
            ],
            'amountRequired' => self::formatAmount($amountRequired),
            'amountCurrent' => self::formatAmount($amountCurrent),
            'amountPrize' => self::formatAmount($amountPrize)
        ]);
    }

    /**
     * 计算倒计时
     * @param LotteryTicketActivity $activity
     * @return array|null
     */
    private function calculateCountdown(LotteryTicketActivity $activity): ?array
    {
        $now = time();

        switch ($activity->status) {
            case LotteryTicketActivity::STATUS_NOT_STARTED:
                // 距离开始时间
                $targetTime = strtotime($activity->start_time);
                $diff = $targetTime - $now;

                if ($diff > 0) {
                    return [
                        'type' => 'start',
                        'label' => '距離活動開始',
                        'seconds' => $diff,
                        'formatted' => self::formatCountdown($diff)
                    ];
                }
                break;

            case LotteryTicketActivity::STATUS_ONGOING:
                // 距离结束时间
                $targetTime = strtotime($activity->end_time);
                $diff = $targetTime - $now;

                if ($diff > 0) {
                    return [
                        'type' => 'end',
                        'label' => '距離活動結束',
                        'seconds' => $diff,
                        'formatted' => self::formatCountdown($diff)
                    ];
                }
                break;

            case LotteryTicketActivity::STATUS_PENDING_DRAW:
                // 待开奖，显示等待开奖提示
                return [
                    'type' => 'pending_draw',
                    'label' => '等待開獎',
                    'seconds' => 0,
                    'formatted' => '等待開獎中'
                ];

            case LotteryTicketActivity::STATUS_DRAWING:
                // 开奖中，无倒计时
                return [
                    'type' => 'drawing',
                    'label' => '開獎進行中',
                    'seconds' => 0,
                    'formatted' => '開獎中'
                ];

            case LotteryTicketActivity::STATUS_ENDED:
                // 已结束，无倒计时
                return [
                    'type' => 'ended',
                    'label' => '活動已結束',
                    'seconds' => 0,
                    'formatted' => '已結束'
                ];
        }

        return null;
    }

    /**
     * 格式化倒计时
     * @param int $seconds
     * @return string
     */
    private function formatCountdown(int $seconds): string
    {
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        if ($days > 0) {
            return sprintf('%d天%02d時%02d分', $days, $hours, $minutes);
        } elseif ($hours > 0) {
            return sprintf('%02d時%02d分', $hours, $minutes);
        } else {
            return sprintf('%02d分', $minutes);
        }
    }

    /**
     * 获取活动状态文本
     * @param int $status
     * @return string
     */
    private function getActivityStatusText(int $status): string
    {
        return match($status) {
            LotteryTicketActivity::STATUS_NOT_STARTED => '即將開始',
            LotteryTicketActivity::STATUS_ONGOING => '進行中',
            LotteryTicketActivity::STATUS_PENDING_DRAW => '待開獎',
            LotteryTicketActivity::STATUS_DRAWING => '開獎中',
            LotteryTicketActivity::STATUS_ENDED => '已結束',
            LotteryTicketActivity::STATUS_CLOSED => '已關閉',
            default => '未知狀態',
        };
    }

    /**
     * 我的獎券
     *
     * 自動取得當前摸獎活動中玩家持有的未使用獎券號清單。
     * @return Response
     * @throws PlayerCheckException
     */
    public function myTickets(): Response
    {
        $player = checkPlayer();

        $activity = self::getSmartActivity($player->department_id, true);

        if (! $activity) {
            return apiSuccessResponse('success', [
                'ticketNumbers' => [],
                'ticketCount' => 0,
                'validUntil' => null,
            ]);
        }

        $ticketNumbers = LotteryTicket::query()
            ->where('player_id', $player->id)
            ->where('activity_id', $activity->id)
            ->where('status', LotteryTicket::STATUS_UNUSED)
            ->orderBy('ticket_no', 'asc')
            ->pluck('ticket_no')
            ->toArray();

        return apiSuccessResponse('success', [
            'ticketNumbers' => $ticketNumbers,
            'ticketCount' => count($ticketNumbers),
            'validUntil' => $activity->end_time,
        ]);
    }

    /**
     * 格式化金额显示（整数不显示小数位）
     * @param float $amount
     * @return float|int
     */
    private function formatAmount(float $amount): float|int
    {
        // 判断是否为整数
        if (floor($amount) == $amount) {
            // 整数：返回整数类型
            return (int)$amount;
        } else {
            // 小数：保留两位小数
            return round($amount, 2);
        }
    }
}
