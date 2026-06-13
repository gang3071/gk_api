<?php

namespace app\api\controller\v1;

use app\exception\PlayerCheckException;
use app\model\LotteryTicket;
use app\model\LotteryTicketActivity;
use app\model\LotteryTicketBetProgress;
use app\model\LotteryTicketPrizeLevel;
use app\model\LotteryTicketRecord;
use Respect\Validation\Exceptions\AllOfException;
use Respect\Validation\Validator as v;
use support\Request;
use support\Response;
use Webman\RateLimiter\Annotation\RateLimiter;

/**
 * 摸奖券玩家端API
 */
class LotteryTicketController
{
    #[RateLimiter(limit: 10)]
    /**
     * 智能获取摸奖券活动（按优先级返回）
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException
     */
    public function getCurrentActivity(Request $request): Response
    {
        $player = checkPlayer();

        // 智能获取活动（按优先级）
        $activity = $this->getSmartActivity($player->department_id);

        if (!$activity) {
            return jsonSuccessResponse('success', [
                'has_activity' => false,
                'activity' => null
            ]);
        }

        // 构建活动响应数据
        return $this->buildActivityResponse($activity, $player);
    }

    /**
     * 智能获取活动（按优先级）- 使用缓存优化
     * ✅ 增加异常处理，防止数据库异常导致雪崩
     * @param int $departmentId
     * @return LotteryTicketActivity|null
     */
    private function getSmartActivity(int $departmentId): ?LotteryTicketActivity
    {
        // 使用1分钟缓存
        $cacheKey = "lottery_activity:smart:{$departmentId}";

        try {
            return \support\Cache::remember($cacheKey, 60, function() use ($departmentId) {
            // 优先级1: 开奖中的活动（最高优先级）
            $activity = LotteryTicketActivity::query()
                ->where('department_id', $departmentId)
                ->where('status', LotteryTicketActivity::STATUS_DRAWING)
                ->first();

            if ($activity) {
                return $activity;
            }

            // 优先级2: 进行中的活动（打码中）
            $activity = LotteryTicketActivity::query()
                ->where('department_id', $departmentId)
                ->where('status', LotteryTicketActivity::STATUS_ONGOING)
                ->first();

            if ($activity) {
                return $activity;
            }

            // 优先级3: 预热中的活动
            $activity = LotteryTicketActivity::query()
                ->where('department_id', $departmentId)
                ->whereIn('status', [
                    LotteryTicketActivity::STATUS_PREHEATING,
                    LotteryTicketActivity::STATUS_BETTING
                ])
                ->orderBy('start_time', 'asc')
                ->first();

            if ($activity) {
                return $activity;
            }

            // 优先级4: 即将开始的活动（7天内）
            $activity = LotteryTicketActivity::query()
                ->where('department_id', $departmentId)
                ->where('status', LotteryTicketActivity::STATUS_NOT_STARTED)
                ->where('start_time', '<=', date('Y-m-d H:i:s', strtotime('+7 days')))
                ->orderBy('start_time', 'asc')
                ->first();

            if ($activity) {
                return $activity;
            }

            // 优先级5: 刚结束的活动（如果没有下期活动，仍然展示）
            $activity = LotteryTicketActivity::query()
                ->where('department_id', $departmentId)
                ->where('status', LotteryTicketActivity::STATUS_ENDED)
                ->orderBy('end_time', 'desc')
                ->first();

            return $activity;
        });

        } catch (\Exception $e) {
            // ✅ 异常处理：记录日志，返回null，不影响主流程
            \support\Log::error('[摸奖券] 智能活动查询失败', [
                'department_id' => $departmentId,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            // 降级：返回null
            return null;
        }
    }

    /**
     * 构建活动响应数据（优化版）
     * @param LotteryTicketActivity $activity
     * @param $player
     * @return Response
     */
    private function buildActivityResponse(LotteryTicketActivity $activity, $player): Response
    {
        // 优化1: 奖品等级缓存（1小时，活动期间不变）
        $cacheKey = "lottery_activity:{$activity->id}:prize_levels";
        $prizeLevels = \support\Cache::remember($cacheKey, 3600, function() use ($activity) {
            return LotteryTicketPrizeLevel::query()
                ->where('activity_id', $activity->id)
                ->orderBy('level_rank')
                ->select(['level_rank', 'level_name', 'prize_type', 'prize_amount', 'prize_count'])
                ->get()
                ->toArray();
        });

        // 优化2: 合并奖券统计查询（2次COUNT合并为1次）
        $ticketStats = LotteryTicket::query()
            ->selectRaw('
                COUNT(CASE WHEN status IN (0,1,3,4) THEN 1 END) as total_count,
                COUNT(CASE WHEN status = 3 THEN 1 END) as win_count
            ')
            ->where('activity_id', $activity->id)
            ->where('player_id', $player->id)
            ->first();

        $myTicketCount = $ticketStats->total_count ?? 0;
        $myWinCount = $ticketStats->win_count ?? 0;

        // ✅ 获取玩家打码进度（处理vip_level_id可能为null的情况）
        $query = LotteryTicketBetProgress::query()
            ->where('activity_id', $activity->id)
            ->where('player_id', $player->id);

        // ✅ 处理vip_level_id为null的情况
        if ($player->vip_level_id !== null) {
            $query->where('vip_level_id', $player->vip_level_id);
        } else {
            $query->whereNull('vip_level_id');
        }

        $betProgress = $query->first();

        $progress = null;
        if ($betProgress) {
            $progress = [
                'bet_amount_required' => $betProgress->bet_amount_required,
                'current_bet_amount' => $betProgress->current_bet_amount,
                'progress_percent' => $betProgress->progress_percent,
                'remaining_bet_amount' => $betProgress->remaining_bet_amount,
                'cycles_completed' => $betProgress->cycles_completed,
                'total_tickets_issued' => $betProgress->total_tickets_issued,
                'ticket_count_per_cycle' => $betProgress->ticket_count_per_cycle,
            ];
        }

        // 计算倒计时
        $countdown = $this->calculateCountdown($activity);

        return jsonSuccessResponse('success', [
            'has_activity' => true,
            'activity' => [
                'id' => $activity->id,
                'name' => $activity->name,
                'description' => $activity->description,
                'cover_image' => $activity->cover_image,
                'start_time' => $activity->start_time,
                'end_time' => $activity->end_time,
                'status' => $activity->status,
                'status_text' => $this->getActivityStatusText($activity->status),
                'my_ticket_count' => $myTicketCount,
                'my_win_count' => $myWinCount,
                'countdown' => $countdown,

                // ✅ 开奖结果（摇球号码）
                'ball_result' => $activity->ball_result
                    ? json_decode($activity->ball_result, true)
                    : null,

                // ✅ 直播地址
                'live_url' => $activity->live_url ?? null,

                // ✅ 中奖总人数（已开奖时显示）
                'total_winners' => $activity->ball_result
                    ? LotteryTicketRecord::where('activity_id', $activity->id)->count()
                    : 0,
            ],
            'prize_levels' => $prizeLevels,
            'bet_progress' => $progress,
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
            case LotteryTicketActivity::STATUS_PREHEATING:
                // 距离开始时间
                $targetTime = strtotime($activity->start_time);
                $diff = $targetTime - $now;

                if ($diff > 0) {
                    return [
                        'type' => 'start',
                        'label' => '距離活動開始',
                        'seconds' => $diff,
                        'formatted' => $this->formatCountdown($diff)
                    ];
                }
                break;

            case LotteryTicketActivity::STATUS_BETTING:
            case LotteryTicketActivity::STATUS_ONGOING:
                // 距离结束时间
                $targetTime = strtotime($activity->end_time);
                $diff = $targetTime - $now;

                if ($diff > 0) {
                    return [
                        'type' => 'end',
                        'label' => '距離活動結束',
                        'seconds' => $diff,
                        'formatted' => $this->formatCountdown($diff)
                    ];
                }
                break;

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
            LotteryTicketActivity::STATUS_PREHEATING => '活動預熱',
            LotteryTicketActivity::STATUS_BETTING => '打碼中',
            LotteryTicketActivity::STATUS_ONGOING => '進行中',
            LotteryTicketActivity::STATUS_DRAWING => '開獎中',
            LotteryTicketActivity::STATUS_ENDED => '已結束',
            LotteryTicketActivity::STATUS_CLOSED => '已關閉',
            default => '未知狀態',
        };
    }

    #[RateLimiter(limit: 10)]
    /**
     * 获取我的奖券列表
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException
     */
    public function myTickets(Request $request): Response
    {
        $player = checkPlayer();
        $data = $request->all();

        $validator = v::key('activity_id', v::intVal()->setName('活动ID'))
            ->key('page', v::intVal()->setName('页码'), false)
            ->key('size', v::intVal()->setName('每页数量'), false);

        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }

        // ✅ 验证活动访问权限
        $activity = LotteryTicketActivity::query()
            ->where('id', $data['activity_id'])
            ->where('department_id', $player->department_id)
            ->first();

        if (!$activity) {
            return jsonFailResponse('活动不存在或无权访问');
        }

        $page = $data['page'] ?? 1;
        $size = min($data['size'] ?? 20, 100);

        // ✅ 修复：使用统一的状态常量
        $query = LotteryTicket::query()
            ->where('activity_id', $data['activity_id'])
            ->where('player_id', $player->id)
            ->whereIn('status', [
                LotteryTicket::STATUS_UNUSED,  // 未使用
                LotteryTicket::STATUS_USED,    // 已使用（包含中奖和未中奖）
                LotteryTicket::STATUS_EXPIRED  // 已过期
            ]);

        $total = $query->count();
        $tickets = $query->orderBy('created_at', 'desc')
            ->forPage($page, $size)
            ->get();

        $list = [];
        foreach ($tickets as $ticket) {
            // ✅ 修复：通过 LotteryTicketRecord 判断是否中奖
            $winningRecord = LotteryTicketRecord::where('ticket_id', $ticket->id)->first();
            $isWinning = !empty($winningRecord);

            $list[] = [
                'id' => $ticket->id,
                'ticket_no' => $ticket->ticket_no,
                'source' => $ticket->source,
                'source_text' => $this->getSourceText($ticket->source),
                'status' => $ticket->status,
                'status_text' => $this->getStatusText($ticket->status),
                'is_winning' => $isWinning,  // ✅ 通过中奖记录判断
                'prize_level' => $winningRecord->prize_level ?? null,
                'prize_amount' => $winningRecord->prize_amount ?? 0,
                'issued_at' => $ticket->issued_at,
                'expired_at' => $ticket->expired_at,
                'created_at' => $ticket->created_at,
            ];
        }

        return jsonSuccessResponse('success', [
            'tickets' => $list,
            'total' => $total,
            'page' => $page,
            'size' => $size,
        ]);
    }

    /**
     * 获取奖券来源文本（✅ 已统一为字符串类型）
     */
    private function getSourceText(string $source): string
    {
        return match ($source) {
            LotteryTicket::SOURCE_RECHARGE => '充值赠送',
            LotteryTicket::SOURCE_MANUAL => '手动发放',
            LotteryTicket::SOURCE_ACTIVITY => '活动赠送',
            'betting' => '打码获得',  // 兼容旧数据
            default => '未知来源',
        };
    }

    /**
     * 获取奖券状态文本（✅ 已统一常量）
     */
    private function getStatusText(int $status): string
    {
        return match ($status) {
            LotteryTicket::STATUS_UNUSED => '未使用',
            LotteryTicket::STATUS_USED => '已使用',
            LotteryTicket::STATUS_EXPIRED => '已过期',
            default => '未知',
        };
    }

    #[RateLimiter(limit: 10)]
    /**
     * 获取中奖记录
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException
     */
    public function winningRecords(Request $request): Response
    {
        $player = checkPlayer();
        $data = $request->all();

        $validator = v::key('activity_id', v::intVal()->setName('活动ID'), false)
            ->key('page', v::intVal()->setName('页码'), false)
            ->key('size', v::intVal()->setName('每页数量'), false);

        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }

        $page = $data['page'] ?? 1;
        $size = min($data['size'] ?? 20, 100);

        $query = LotteryTicketRecord::query()
            ->where('player_id', $player->id)
            ->when(!empty($data['activity_id']), function ($query) use ($data) {
                $query->where('activity_id', $data['activity_id']);
            });

        $total = $query->count();
        $records = $query->orderBy('created_at', 'desc')
            ->forPage($page, $size)
            ->get();

        $list = [];
        foreach ($records as $record) {
            $list[] = [
                'id' => $record->id,
                'activity_id' => $record->activity_id,
                'activity_name' => $record->activity_name,
                'ticket_no' => $record->ticket_no,
                'prize_level' => $record->prize_level,
                'prize_level_name' => $record->prize_level_name,
                'prize_amount' => $record->prize_amount,
                'status' => $record->status,
                'status_text' => $this->getRecordStatusText($record->status),
                'granted_at' => $record->granted_at,
                'created_at' => $record->created_at,
            ];
        }

        return jsonSuccessResponse('success', [
            'records' => $list,
            'total' => $total,
            'page' => $page,
            'size' => $size,
        ]);
    }

    /**
     * 获取中奖记录状态文本
     */
    private function getRecordStatusText(int $status): string
    {
        return match ($status) {
            LotteryTicketRecord::STATUS_PENDING => '待发放',
            LotteryTicketRecord::STATUS_GRANTED => '已发放',
            LotteryTicketRecord::STATUS_EXPIRED => '已过期',
            default => '未知',
        };
    }

    #[RateLimiter(limit: 10)]
    /**
     * 获取打码进度
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException
     */
    public function betProgress(Request $request): Response
    {
        $player = checkPlayer();
        $data = $request->all();

        $validator = v::key('activity_id', v::intVal()->setName('活动ID'));

        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }

        // ✅ 验证活动访问权限
        $activity = LotteryTicketActivity::query()
            ->where('id', $data['activity_id'])
            ->where('department_id', $player->department_id)
            ->first();

        if (!$activity) {
            return jsonFailResponse('活动不存在或无权访问');
        }

        // ✅ 处理vip_level_id可能为null的情况
        $query = LotteryTicketBetProgress::query()
            ->where('activity_id', $data['activity_id'])
            ->where('player_id', $player->id);

        if ($player->vip_level_id !== null) {
            $query->where('vip_level_id', $player->vip_level_id);
        } else {
            $query->whereNull('vip_level_id');
        }

        $betProgress = $query->first();

        if (!$betProgress) {
            return jsonFailResponse('未找到打码进度记录');
        }

        return jsonSuccessResponse('success', [
            'activity_id' => $betProgress->activity_id,
            'player_id' => $betProgress->player_id,
            'vip_level_id' => $betProgress->vip_level_id,
            'bet_amount_required' => $betProgress->bet_amount_required,
            'current_bet_amount' => $betProgress->current_bet_amount,
            'progress_percent' => $betProgress->progress_percent,
            'remaining_bet_amount' => $betProgress->remaining_bet_amount,
            'cycles_completed' => $betProgress->cycles_completed,
            'total_tickets_issued' => $betProgress->total_tickets_issued,
            'ticket_count_per_cycle' => $betProgress->ticket_count_per_cycle,
            'status' => $betProgress->status,
            'updated_at' => $betProgress->updated_at,
        ]);
    }
}
