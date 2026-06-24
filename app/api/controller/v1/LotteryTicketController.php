<?php

namespace app\api\controller\v1;

use app\exception\PlayerCheckException;
use app\model\LotteryTicket;
use app\model\LotteryTicketActivity;
use app\model\LotteryTicketBetProgress;
use app\model\LotteryTicketPrizeLevel;
use app\model\LotteryTicketRecord;
use app\model\LotteryTicketVipConfig;
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
        // ✅ 使用10秒缓存（缩短缓存时间，解决活动状态变化延迟问题）
        $cacheKey = "lottery_activity:smart:{$departmentId}";

        try {
            $activity = \support\Cache::get($cacheKey);

            if ($activity === null) {
                // 优先级1: 开奖中的活动（最高优先级）
                $activity = LotteryTicketActivity::query()
                    ->where('department_id', $departmentId)
                    ->where('status', LotteryTicketActivity::STATUS_DRAWING)
                    ->orderBy('id', 'desc')
                    ->first();

                if (!$activity) {
                    // 优先级2: 待开奖的活动（活动已结束，等待开奖）
                    $activity = LotteryTicketActivity::query()
                        ->where('department_id', $departmentId)
                        ->where('status', LotteryTicketActivity::STATUS_PENDING_DRAW)
                        ->orderBy('id', 'desc')
                        ->first();
                }

                if (!$activity) {
                    // 优先级3: 进行中的活动（打码中）
                    $activity = LotteryTicketActivity::query()
                        ->where('department_id', $departmentId)
                        ->where('status', LotteryTicketActivity::STATUS_ONGOING)
                        ->orderBy('id', 'desc')
                        ->first();
                }

                if (!$activity) {
                    // 优先级4: 即将开始的活动（7天内）
                    $activity = LotteryTicketActivity::query()
                        ->where('department_id', $departmentId)
                        ->where('status', LotteryTicketActivity::STATUS_NOT_STARTED)
                        ->where('start_time', '<=', date('Y-m-d H:i:s', strtotime('+7 days')))
                        ->orderBy('id', 'desc')
                        ->first();
                }

                if (!$activity) {
                    // 优先级5: 刚结束的活动（如果没有下期活动，仍然展示）
                    $activity = LotteryTicketActivity::query()
                        ->where('department_id', $departmentId)
                        ->where('status', LotteryTicketActivity::STATUS_ENDED)
                        ->orderBy('id', 'desc')
                        ->first();
                }

                // ✅ 缩短缓存时间为10秒（解决活动状态变化时延迟问题）
                \support\Cache::set($cacheKey, $activity ?: false, 10);
            }

            return $activity ?: null;

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
        $prizeLevels = \support\Cache::get($cacheKey);

        if ($prizeLevels === null) {
            $prizeLevels = LotteryTicketPrizeLevel::query()
                ->where('activity_id', $activity->id)
                ->orderBy('level_rank')
                ->select(['level_rank', 'level_name', 'prize_amount', 'prize_count'])
                ->get()
                ->toArray();

            \support\Cache::set($cacheKey, $prizeLevels, 3600);
        }

        // 优化1.5: VIP配置缓存（1小时，活动期间不变）
        $vipConfigCacheKey = "lottery_activity:{$activity->id}:vip_configs";
        $vipConfigs = \support\Cache::get($vipConfigCacheKey);

        if ($vipConfigs === null) {
            $vipConfigs = \app\model\LotteryTicketVipConfig::query()
                ->with('vipLevel:id,name') // ✅ 修正：使用 name 字段而不是 level
                ->where('activity_id', $activity->id)
                ->where('status', 1) // 只返回启用的配置
                ->orderBy('vip_level_id')
                ->get()
                ->map(function ($config) {
                    return [
                        'vip_level_id' => $config->vip_level_id,
                        'vip_level_name' => $config->vipLevel ? $config->vipLevel->name : ('VIP' . $config->vip_level_id), // ✅ 修正：使用 name 属性
                        'bet_amount_required' => (float) $config->bet_amount_required,
                        'ticket_count' => $config->ticket_count,
                    ];
                })
                ->toArray();

            \support\Cache::set($vipConfigCacheKey, $vipConfigs, 3600);
        }

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

        // ✅ 获取玩家在当期活动的总获奖金额（包含未发放的中奖金额）
        $myTotalPrizeAmount = LotteryTicketRecord::query()
            ->where('activity_id', $activity->id)
            ->where('player_id', $player->id)
            ->where('prize_type', '!=', LotteryTicketRecord::PRIZE_TYPE_EMPTY) // 排除未中奖的记录
            ->sum('prize_amount');

        // ✅ 获取玩家的VIP配置（基础打码量和发券数）
        $vipConfig = \app\model\LotteryTicketVipConfig::query()
            ->where('activity_id', $activity->id)
            ->where('vip_level_id', $player->vip_level_id ?? 0)
            ->where('status', 1)
            ->first();

        // 如果没有找到VIP配置，使用默认值
        $baseBetAmount = $vipConfig ? $vipConfig->bet_amount_required : 0;
        $baseTicketCount = $vipConfig ? $vipConfig->ticket_count : 0;

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
        /** @var LotteryTicketBetProgress $betProgress */
        $betProgress = $query->first();

        // 如果有打码进度记录，使用记录中的数据；否则返回基础配置
        if ($betProgress) {
            // ✅ 计算当前周期内的打码进度（对基础打码量取模）
            $currentCycleBetAmount = fmod((float) $betProgress->current_bet_amount, (float) $betProgress->bet_amount_required);
            $currentCyclePercent = $betProgress->bet_amount_required > 0
                ? ($currentCycleBetAmount / $betProgress->bet_amount_required) * 100
                : 0;
            $currentCycleRemaining = max(0, $betProgress->bet_amount_required - $currentCycleBetAmount);

            $progress = [
                'bet_amount_required' => (float) $betProgress->bet_amount_required,
                'current_bet_amount' => (float) $currentCycleBetAmount,  // ✅ 当前周期打码量（取模后）
                'progress_percent' => (float) $currentCyclePercent,      // ✅ 当前周期进度百分比
                'remaining_bet_amount' => (float) $currentCycleRemaining, // ✅ 当前周期剩余打码量
                'cycles_completed' => $betProgress->cycles_completed,
                'total_tickets_issued' => $betProgress->total_tickets_issued,
                'ticket_count_per_cycle' => $betProgress->ticket_count_per_cycle,
                'total_bet_amount' => (float) $betProgress->current_bet_amount, // ✅ 累计总打码量
            ];
        } else {
            // 没有打码进度记录，返回初始状态（使用VIP配置的基础值）
            $progress = [
                'bet_amount_required' => (float) $baseBetAmount,
                'current_bet_amount' => 0.00,
                'progress_percent' => 0.00,
                'remaining_bet_amount' => (float) $baseBetAmount,
                'cycles_completed' => 0,
                'total_tickets_issued' => 0,
                'ticket_count_per_cycle' => $baseTicketCount,
                'total_bet_amount' => 0.00, // ✅ 累计总打码量
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
                'my_total_prize_amount' => (float)$myTotalPrizeAmount, // ✅ 我的总获奖金额
                'countdown' => $countdown,

                // ✅ 开奖状态（线下摇球，无ball_result字段）
                'has_drawn' => in_array($activity->status, [
                    LotteryTicketActivity::STATUS_DRAWING,
                    LotteryTicketActivity::STATUS_ENDED,
                ]),

                // ✅ 直播相关（只在直播中时返回播放地址）
                'stream_name' => $activity->live_url ?? null,  // ⭐ 流名称（备用）
                'play_urls' => $this->generatePlayUrls($activity->live_url, $activity->live_status ?? 0),  // ⭐ 完整播放地址（仅直播中时生成）
                'live_status' => $activity->live_status ?? 0,
                'live_status_text' => $this->getLiveStatusText($activity->live_status ?? 0),

                // ✅ 中奖总人数（已开奖时显示）
                'total_winners' => in_array($activity->status, [
                    LotteryTicketActivity::STATUS_DRAWING,
                    LotteryTicketActivity::STATUS_ENDED,
                ])
                    ? LotteryTicketRecord::where('activity_id', $activity->id)->count()
                    : 0,
            ],
            'prize_levels' => $prizeLevels,
            'vip_configs' => $vipConfigs,
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

            case LotteryTicketActivity::STATUS_PENDING_DRAW:
                // ⭐ 待开奖，显示等待开奖提示
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
            LotteryTicketActivity::STATUS_PENDING_DRAW => '待開獎',  // ⭐ 新增
            LotteryTicketActivity::STATUS_DRAWING => '開獎中',
            LotteryTicketActivity::STATUS_ENDED => '已結束',
            LotteryTicketActivity::STATUS_CLOSED => '已關閉',
            default => '未知狀態',
        };
    }

    /**
     * 生成完整的直播播放地址（只在直播中时生成）
     * @param string|null $streamName 流名称
     * @param int $liveStatus 直播状态
     * @return array|null
     */
    private function generatePlayUrls(?string $streamName, int $liveStatus): ?array
    {
        // ✅ 只在直播中（live_status = 1）时才返回播放地址
        if ($liveStatus !== LotteryTicketActivity::LIVE_STATUS_ONGOING) {
            return [
                'webrtc' => '', // 推荐：超低延迟 <1秒
                'flv' => '',       // 备选：HTTP-FLV
                'hls' => '',       // 备选：HLS（兼容性好）
                'expire_time' => '',
                'expire_timestamp' => '',
                'region' => '', // CN（大陆）或 Global（全球）
                'license' => '',
                'license_key' => '',
            ];
        }

        if (empty($streamName)) {
            return [
                'webrtc' => '', // 推荐：超低延迟 <1秒
                'flv' => '',       // 备选：HTTP-FLV
                'hls' => '',       // 备选：HLS（兼容性好）
                'expire_time' => '',
                'expire_timestamp' => '',
                'region' => '', // CN（大陆）或 Global（全球）
                'license' => '',
                'license_key' => '',
            ];
        }

        try {
            // 使用固定配置ID=1，生成30天有效期的播放地址
            $urls = generateLotteryLiveUrls(1, $streamName, 30);

            return [
                'webrtc' => $urls['webrtc'], // 推荐：超低延迟 <1秒
                'flv' => $urls['flv'],       // 备选：HTTP-FLV
                'hls' => $urls['hls'],       // 备选：HLS（兼容性好）
                'expire_time' => $urls['expire_time'],
                'expire_timestamp' => $urls['expire_timestamp'],
                'region' => $urls['region'], // CN（大陆）或 Global（全球）
                'license' => $urls['license'] ?? '', // 播放器 License URL
                'license_key' => $urls['license_key'] ?? '', // 播放器 License Key
            ];
        } catch (\Exception $e) {
            // 生成播放地址失败时记录日志
            \support\Log::warning('生成摸奖券直播播放地址失败', [
                'stream_name' => $streamName,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * 获取直播状态文本
     * @param int $status
     * @return string
     */
    private function getLiveStatusText(int $status): string
    {
        return match($status) {
            LotteryTicketActivity::LIVE_STATUS_NOT_STARTED => '未開播',
            LotteryTicketActivity::LIVE_STATUS_ONGOING => '直播中',
            LotteryTicketActivity::LIVE_STATUS_ENDED => '已結束',
            default => '未開播',
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
                'prize_type' => $record->prize_type,
                'prize_name' => $record->prize_name,
                'prize_level' => $record->prize_level,
                'prize_level_name' => $record->prize_level_name,
                'prize_amount' => (float)$record->prize_amount,
                'status' => $record->status,
                'status_text' => $this->getRecordStatusText($record->status),
                'granted_at' => $record->granted_at,
                'distributed_at' => $record->distributed_at,
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
            LotteryTicketRecord::STATUS_CLAIMED => '已发放',
            LotteryTicketRecord::STATUS_EXPIRED => '已过期',
            LotteryTicketRecord::STATUS_CANCELLED => '已取消',
            LotteryTicketRecord::STATUS_PROCESSING => '发放中',
            LotteryTicketRecord::STATUS_FAILED => '发放失败',
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
            return jsonFailResponse(trans('lottery_activity_not_found', [], 'message'));
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
        /** @var LotteryTicketBetProgress $betProgress */
        $betProgress = $query->first();

        // ✅ 如果没有打码进度记录，返回初始状态（从活动配置获取打码要求）
        if (!$betProgress) {
            // 获取该玩家 VIP 等级对应的打码配置
            $vipConfig = LotteryTicketVipConfig::query()
                ->where('activity_id', $data['activity_id'])
                ->where('vip_level_id', $player->vip_level_id ?: 0)
                ->first();

            // 如果没有配置，使用活动默认配置
            $betAmountRequired = $vipConfig ? $vipConfig->bet_amount_required : $activity->bet_amount_required;
            $ticketCountPerCycle = $vipConfig ? $vipConfig->tickets_per_round : $activity->tickets_per_round;

            return jsonSuccessResponse('success', [
                'activity_id' => $data['activity_id'],
                'player_id' => $player->id,
                'vip_level_id' => $player->vip_level_id,
                'bet_amount_required' => $betAmountRequired ?? 0,
                'current_bet_amount' => 0,
                'progress_percent' => 0,
                'remaining_bet_amount' => $betAmountRequired ?? 0,
                'cycles_completed' => 0,
                'total_tickets_issued' => 0,
                'ticket_count_per_cycle' => $ticketCountPerCycle ?? 0,
                'total_bet_amount' => 0, // ✅ 累计总打码量
                'status' => 0, // 0 = 未开始
                'updated_at' => null,
            ]);
        }

        // ✅ 计算当前周期内的打码进度（对基础打码量取模）
        $currentCycleBetAmount = fmod((float) $betProgress->current_bet_amount, (float) $betProgress->bet_amount_required);
        $currentCyclePercent = $betProgress->bet_amount_required > 0
            ? ($currentCycleBetAmount / $betProgress->bet_amount_required) * 100
            : 0;
        $currentCycleRemaining = max(0, $betProgress->bet_amount_required - $currentCycleBetAmount);

        return jsonSuccessResponse('success', [
            'activity_id' => $betProgress->activity_id,
            'player_id' => $betProgress->player_id,
            'vip_level_id' => $betProgress->vip_level_id,
            'bet_amount_required' => $betProgress->bet_amount_required,
            'current_bet_amount' => $currentCycleBetAmount,       // ✅ 当前周期打码量（取模后）
            'progress_percent' => $currentCyclePercent,            // ✅ 当前周期进度百分比
            'remaining_bet_amount' => $currentCycleRemaining,      // ✅ 当前周期剩余打码量
            'cycles_completed' => $betProgress->cycles_completed,
            'total_tickets_issued' => $betProgress->total_tickets_issued,
            'ticket_count_per_cycle' => $betProgress->ticket_count_per_cycle,
            'total_bet_amount' => $betProgress->current_bet_amount, // ✅ 累计总打码量
            'status' => $betProgress->status,
            'updated_at' => $betProgress->updated_at,
        ]);
    }
}
