<?php

namespace app\api\controller\v1;

use app\exception\PlayerCheckException;
use app\model\Channel;
use app\model\ChannelMachine;
use app\model\Currency;
use app\model\GameType;
use app\model\Machine;
use app\model\MachineCategory;
use app\model\MachineCategoryGiveRule;
use app\model\MachineKeepingLog;
use app\model\MachineLabelExtend;
use app\model\MachineLotteryRecord;
use app\model\MachineMedia;
use app\model\MachineMediaPush;
use app\model\MachineTencentPlay;
use app\model\OpenScoreSetting;
use app\model\Player;
use app\model\PlayerDeliveryRecord;
use app\model\PlayerFavoriteMachine;
use app\model\PlayerGameLog;
use app\model\PlayerGameRecord;
use app\model\PlayerGiftRecord;
use app\model\PlayerLotteryRecord;
use app\model\PlayerPlatformCash;
use app\model\PlayerRechargeRecord;
use app\model\PlayHistory;
use app\model\SystemSetting;
use app\service\ActivityServices;
use app\service\machine\Jackpot;
use app\service\machine\MachineClient;
use app\service\machine\MachineServices;
use app\service\machine\Slot;
use app\service\MediaServer;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Respect\Validation\Exceptions\AllOfException;
use Respect\Validation\Validator as v;
use support\Cache;
use support\Db;
use support\Log;
use support\Request;
use support\Response;
use Webman\Push\PushException;
use Webman\RateLimiter\Annotation\RateLimiter;
use Webman\RedisQueue\Client as queueClient;
use yzh52521\WebmanLock\Locker;

class MachineController
{
    use \support\IdempotentTrait;

    /** 排除验签 */
    protected $noNeedSign = [];

    /**
     * 检查机台是否在线（通过 gk_work 接口）
     * @param int $machineId
     * @return bool
     */
    private function isMachineOnline(int $machineId): bool
    {
        try {
            $client = new MachineClient();
            $result = $client->checkOnline($machineId);
            return $result['success'] && ($result['data']['online'] ?? false);
        } catch (Exception $e) {
            Log::error('Check machine online failed', [
                'machine_id' => $machineId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    #[RateLimiter(limit: 5)]
    /**
     * 机台列表
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException
     * @throws Exception
     */
    public function machineList(Request $request): Response
    {
        $player = checkPlayer();
        $data = $request->all();
        $validator = v::key('game_id', v::intVal()->notEmpty()->setName(trans('game_id', [], 'message')))
            ->key('cate_id', v::intVal()->setName(trans('cate_id', [], 'message')), false)
            ->key('page', v::intVal()->setName(trans('page', [], 'message')))
            ->key('size', v::intVal()->setName(trans('size', [], 'message')))
            ->key('name', v::stringVal()->setName(trans('name', [], 'message')), false);
        
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        if (empty($player->channel->status_machine) || empty($player->status_machine)) {
            return jsonFailResponse(trans('platform_no_permission', [], 'message'));
        }
        $gameName = $data['name'] ?? '';
        $limit = $data['limit'] ?? 4;
        //機台維護中
        if (machineMaintaining()) {
            return jsonFailResponse(trans('machine_maintaining', [], 'message'));
        }
        /** @var GameType $gameType */
        $gameType = GameType::find($data['game_id']);
        $lang = locale();
        if (!$gameType) {
            return jsonFailResponse(trans('game_type_not_fount', [], 'message'));
        }
        // 目前只处理实体机台
        if ($gameType->cate == GameType::CATE_PHYSICAL_MACHINE) {
            $machineCategory = MachineCategory::query()
                ->where('machine_category.status', 1)
                ->where('machine_category.game_id', $gameType->id)
                ->when(!empty($lang) && $lang != 'zh-CN', function (Builder $q) use ($lang) {
                    $q->leftJoin('machine_category_extend', function ($join) use ($lang) {
                        $join->on('machine_category_extend.cate_id', '=', 'machine_category.id')
                            ->where('machine_category_extend.lang', '=', $lang);
                    })->select([
                        'machine_category.id',
                        'machine_category.game_id',
                        'machine_category_extend.name',
                        'machine_category.picture_url',
                        'machine_category.status',
                        'machine_category.sort'
                    ]);
                })
                ->when(!empty($lang) && $lang == 'zh-CN', function (Builder $q) use ($lang) {
                    $q->select([
                        'machine_category.id',
                        'machine_category.game_id',
                        'machine_category.name',
                        'machine_category.picture_url',
                        'machine_category.status',
                        'machine_category.sort'
                    ]);
                })
                ->orderBy('machine_category.sort', 'desc')
                ->whereNull('machine_category.deleted_at')
                ->get()
                ->toArray();
            if (empty($machineCategory)) {
                return jsonFailResponse(trans('machine_category_not_fount', [], 'message'));
            }
            $list = Machine::query()
                ->select([
                    'machine.label_id',
                    'machine.type',
                    'machine_label.picture_url',
                    'machine_label.name',
                    'machine_label.point',
                    'machine_label.turn',
                    'machine_label.score',
                    'machine_label.courtyard',
                    'machine_label.correct_rate',
                    'machine.odds_x',
                    'machine.odds_y',
                    'machine.cate_id',
                    'machine_category.name as cate_name',
                    'machine_category.turn_used_point',
                    DB::raw('COUNT(DISTINCT CASE WHEN machine.gaming = 1 OR machine.is_use = 1 THEN machine.id END) as use_num'),
                    DB::raw('COUNT(DISTINCT CASE WHEN machine.gaming = 0 and machine.is_use = 0 THEN machine.id END) as idle_num'),
                ])
                ->leftjoin('machine_label', 'machine.label_id', '=', 'machine_label.id')
                ->leftjoin('machine_label_extend', 'machine_label_extend.label_id', '=', 'machine_label.id')
                ->leftjoin('channel_machine', 'machine.id', '=', 'channel_machine.machine_id')
                ->leftjoin('machine_category', 'machine.cate_id', '=', 'machine_category.id')
                ->where('channel_machine.department_id', $request->department_id)
                ->where('machine.type', $gameType->type)
                ->where('machine.status', 1)
                ->where('machine_label.status', 1)
                ->groupBy(
                    'machine.label_id',
                    'machine.type',
                    'machine.odds_x',
                    'machine.cate_id',
                    'machine.odds_y')
                ->orderBy('machine_label.sort', 'desc')
                ->orderBy('machine_label.id', 'desc')
                ->when($data['cate_id'], function (Builder $q, $value) {
                    $q->where('cate_id', $value);
                })
                ->when($gameName, function (Builder $q, $value) {
                    $q->where('machine_label_extend.name', 'like', "%$value%");
                })
                ->forPage($data['page'], $data['size'])
                ->get();
            if (empty($list)) {
                return jsonFailResponse(trans('machine_not_found', [], 'message'));
            }
            $data = [];
            if ($lang != 'zh-CN') {
                $labelExtendList = MachineLabelExtend::query()
                    ->where('lang', $lang)
                    ->get()
                    ->mapWithKeys(function ($item) {
                        return [
                            $item->label_id => [
                                'name' => $item->name,
                                'picture_url' => $item->picture_url
                            ]
                        ];
                    })
                    ->toArray();
            }
            foreach ($list as $item) {
                $data[] = [
                    'id' => $item['label_id'],
                    'type' => $item['type'],
                    'picture_url' => $lang != 'zh-CN' ? ($labelExtendList[$item['label_id']]['picture_url'] ?? '') : $item['machineLabel']['picture_url'],
                    'point' => $item['machineLabel']['point'],
                    'turn' => $item['machineLabel']['turn'],
                    'score' => $item['machineLabel']['score'],
                    'courtyard' => $item['machineLabel']['courtyard'],
                    'correct_rate' => $item['machineLabel']['correct_rate'],
                    'odds_x' => $item['odds_x'],
                    'odds_y' => $item['odds_y'],
                    'use_num' => $item['use_num'],
                    'idle_num' => $item['idle_num'],
                    'cate_name' => $item['cate_name'],
                    'name' => $lang != 'zh-CN' ? ($labelExtendList[$item['label_id']]['name'] ?? '') : $item['machineLabel']['name'],
                    'turn_used_point' => rtrim(rtrim(number_format($item['turn_used_point'], 3, '.', ''), '0'), '.'),
                ];
            }
            $historyMachine = PlayerGameRecord::query()
                ->with([
                    'machine:id,label_id,type,odds_x,odds_y',
                    'machine.machineLabel:id,name,picture_url,point,score,turn'
                ])
                ->with([
                    'machine:id,label_id,type,odds_x,odds_y',
                    'machine.machineLabel:id,name,picture_url,point,score,turn,courtyard,correct_rate'
                ])
                ->selectRaw('max(id) as id,machine_id')
                ->where('player_id', $player->id)
                ->where('type', $gameType->type)
                ->orderBy('id', 'desc')
                ->groupBy('machine_id')
                ->limit($limit)
                ->get()->toArray();
            $recentMachines = [];
            foreach ($historyMachine as $item) {
                if (is_null($item['machine'])) {
                    continue;
                }
                $recentMachines[] = [
                    'id' => $item['machine']['label_id'],
                    'type' => $item['machine']['type'],
                    'name' => $lang != 'zh-CN' ? ($labelExtendList[$item['machine']['label_id']]['name'] ?? '') : ($item['machine']['machine_label']['name'] ?? ''),
                    'picture_url' => $lang != 'zh-CN' ? ($labelExtendList[$item['machine']['label_id']]['picture_url'] ?? '') : ($item['machine']['machine_label']['picture_url'] ?? ''),
                    'point' => $item['machine']['machine_label']['point'] ?? '',
                    'score' => $item['machine']['machine_label']['score'] ?? '',
                    'turn' => $item['machine']['machine_label']['turn'] ?? '',
                    'odds_x' => $item['machine']['odds_x'],
                    'odds_y' => $item['machine']['odds_y'],
                ];
            }
            return jsonSuccessResponse('success', [
                'machine_category' => $machineCategory,
                'machines' => $data,
                'machine_marquee' => SystemSetting::where('feature', 'marquee')->where('department_id',
                        \request()->department_id)->where('status', 1)->value('content') ?? '',
                'recent_machines' => $recentMachines,
            ]);
        }
        
        return jsonFailResponse(trans('machine_not_fount', [], 'message'));
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 机台列表
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException
     * @throws Exception
     */
    public function machineDataList(Request $request): Response
    {
        $player = checkPlayer();
        $data = $request->all();
        $validator = v::key('odds_x', v::floatVal()->notEmpty()->setName(trans('odds_x', [], 'message')))
            ->key('odds_y', v::floatVal()->setName(trans('odds_y', [], 'message')))
            ->key('label_id', v::intVal()->setName(trans('label_id', [], 'message')));
        
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        if (empty($player->channel->status_machine) || empty($player->status_machine)) {
            return jsonFailResponse(trans('platform_no_permission', [], 'message'));
        }
        $machineIds = ChannelMachine::query()->where('department_id',
            $request->department_id)->get()->pluck('machine_id');
        if (empty($machineIds)) {
            return jsonFailResponse(trans('machine_not_found', [], 'message'));
        }
        $machineList = Machine::query()
            ->when($data['label_id'], function (Builder $q, $value) {
                $q->where('label_id', $value);
            })
            ->when($data['odds_y'], function (Builder $q, $value) {
                $q->where('odds_y', $value);
            })
            ->when($data['odds_x'], function (Builder $q, $value) {
                $q->where('odds_x', $value);
            })
            ->where('status', 1)
            ->whereIn('id', $machineIds)
            ->get();
        $lang = locale() ?? 'zh_TW';
        $lang = Str::replace('_', '-', $lang);

        // 批量检查机台在线状态
        $machineIds = $machineList->pluck('id')->toArray();
        $onlineStatusMap = [];
        try {
            $client = new MachineClient();
            $result = $client->batchCheckOnline($machineIds);
            if ($result['success'] && isset($result['data'])) {
                $onlineStatusMap = $result['data'];
            }
            Log::info('main_online', [$result]);
        } catch (Exception $e) {
            Log::error('Batch check machine online failed', ['error' => $e->getMessage()]);
        }

        $data = [];
        /** @var Machine $machine */
        foreach ($machineList as $machine) {
            $machineServices = MachineServices::createServices($machine, $lang);
            $onlineStatus = $onlineStatusMap[$machine->id] ?? 'offline';
            $nowTurn = $machineServices->now_turn;
            if ($machine->type == GameType::TYPE_SLOT) {
                $nowTurn = $nowTurn > 0 ? intval(ceil($nowTurn / 3)) : 0;
            }
            $data[] = [
                'id' => $machine->id,
                'name' => $machine->name,
                'code' => $machine->code,
                'maintaining' => $machine->maintaining,
                'gaming_user_id' => $machine->gaming_user_id,
                'keeping' => $machineServices->keeping,
                'gaming' => $machineServices->gaming,
                'is_use' => $machine->is_use,
                'reward_status' => $machineServices->reward_status,
                'now_turn' => $nowTurn ? intval($nowTurn) : 0,
                'turn' => $machine->machineLabel->turn,
                'score' => $machine->machineLabel->score,
                'point' => $machine->machineLabel->point,
                'correct_rate' => $machine->correct_rate,
                'odds_x' => $machine->odds_x,
                'odds_y' => $machine->odds_y,
                'keep_seconds' => $machineServices->keep_seconds,
                'online_status' => $onlineStatus,
            ];
        }
        
        return jsonSuccessResponse('success', [
            'machine' => $data,
        ]);
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 机台详情
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException
     * @throws Exception
     */
    public function machineInfo(Request $request): Response
    {
        $player = checkPlayer();
        
        $data = $request->all();
        $validator = v::key('machine_id', v::intVal()->notEmpty()->setName(trans('machine_id', [], 'message')));
        
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        
        if (empty($player->channel->status_machine) || empty($player->status_machine)) {
            return jsonFailResponse(trans('platform_no_permission', [], 'message'));
        }
        
        //機台維護中
        if (machineMaintaining()) {
            return jsonFailResponse(trans('machine_maintaining', [], 'message'));
        }
        
        $machine_play_num = $player->machine_play_num ?? 1;
        /** @var Machine $machine */
        $machine = Machine::with([
            'machineCategory' => function ($query) {
                $query->select('id', 'name');
            }
        ])->whereHas('machineCategory', function ($query) {
            $query->select('id', 'name')->whereNull('deleted_at')->where('status', 1);
        })->select([
            'id',
            'domain',
            'gaming',
            'auto_card_domain',
            'auto_card_port',
            'type',
            'control_type',
            'cate_id',
            'code',
            'picture_url',
            'keeping_user_id',
            'gaming_user_id',
            'maintaining',
            'port',
            'currency',
            'odds_x',
            'odds_y',
            'status',
            'push_auto',
            'auto_up_turn',
            'keeping',
            'keep_seconds',
            'is_use',
            'strategy_id',
            'label_id'
        ])->find($data['machine_id']);
        
        //機台不存在
        if (!$machine) {
            return jsonFailResponse(trans('machine_not_fount', [], 'message'));
        }
        // 检查机台是否在线
        if (!$this->isMachineOnline($machine->id)) {
            return jsonFailResponse(trans('machine_has_offline', ['{code}' => $machine->code], 'message'));
        }
        //檢查玩家是否有權限玩
        /** @var PlayerPlatformCash $platform */
        $platform = PlayerPlatformCash::query()
            ->where('platform_id', PlayerPlatformCash::PLATFORM_SELF)
            ->where('player_id', $player->id)
            ->first();
        if ($platform && $platform->status == 0) {
            return jsonFailResponse(trans('platform_no_permission', [], 'message'));
        }
        
        //關閉
        if ($machine->status != 1) {
            return jsonFailResponse(trans('machine_closing', [], 'message'));
        }
        
        //維護
        if ($machine->maintaining != 0) {
            return jsonFailResponse(trans('machine_maintaining', [], 'message'));
        }
        //使用
        if ($machine->is_use == 1) {
            return jsonFailResponse(trans('machine_gaming', [], 'message'));
        }
        $lang = locale() ?? 'zh_TW';
        $lang = Str::replace('_', '-', $lang);
        $services = MachineServices::createServices($machine, $lang);
        $machine->keeping_user_id = $services->keeping_user_id;
        $machine->keeping = $services->keeping;
        $machine->keep_seconds = $services->keep_seconds;
        $machine->push_auto = $services->auto;
        $machine->auto_up_turn = $services->auto;
        $hasSpectator = false;
        //非同個玩家
        if ($machine->gaming_user_id != 0 && $machine->gaming_user_id != $player->id) {
            if ($services->reward_status == 0) {
                return jsonFailResponse(trans('machine_gaming', [], 'message'));
            } else {
                $hasSpectator = true;
            }
        }
        if (!$hasSpectator) {
            //僅能玩一台
            if ($machine_play_num == 1) {
                //檢查自己是否有其他台正在遊玩
                $selfMachine = Machine::where('gaming_user_id', $player->id)->where('id', '!=', $machine->id)->first();
                if ($selfMachine) {
                    try {
                        kickSingleMachinePlayer($player->id);
                    } catch (Exception $e) {
                        return jsonFailResponse($e->getMessage());
                    }
                }
                
                //再次檢查
                $selfMachine = Machine::where('gaming_user_id', $player->id)->where('id', '!=', $machine->id)->first();
                if ($selfMachine) {
                    return jsonFailResponse(trans('machine_only_one', [], 'message'));
                }
            }
            
            //可以玩多台
            if ($machine_play_num > 1) {
                $self_machine_count = Machine::where('gaming_user_id', $player->id)->where('id', '!=',
                    $machine->id)->count();
                if ($machine_play_num <= $self_machine_count) {
                    return jsonFailResponse(trans('machine_only_msg1', [], 'message') . trans('machine_only_msg2', [],
                            'message'));
                }
            }
        } else {
            $nowNum = getViewers($machine->id);
            if ($nowNum === false) {
                return jsonFailResponse(trans('media_abnormal', [], 'message'));
            }
            $spectatorNum = SystemSetting::query()->where('feature', 'spectator_num')->where('status',
                1)->value('num') ?? 0;
            if ($spectatorNum && $nowNum >= $spectatorNum) {
                return jsonFailResponse(trans('max_number_limit', [], 'message'));
            }
        }
        
        if ($lang != 'zh-CN') {
            /** @var MachineLabelExtend $labelExtend */
            $labelExtend = MachineLabelExtend::query()->where('lang', $lang)->where('label_id',
                $machine->label_id)->first();
            $name = empty($labelExtend->name) ? ($machine->name ?? '') : $labelExtend->name;
            $pictureUrl = empty($labelExtend->picture_url) ? ($machine->picture_url ?? '') : $labelExtend->picture_url;
        } else {
            $name = $machine->name;
            $pictureUrl = $machine->picture_url;
        }
        $machineInfo['id'] = $machine->id;
        $machineInfo['has_spectator'] = $hasSpectator;
        $machineInfo['type'] = $machine->type;
        $machineInfo['name'] = $name;
        $machineInfo['cate_id'] = $machine->cate_id;
        $machineInfo['code'] = $machine->code;
        $machineInfo['picture_url'] = $pictureUrl;
        $machineInfo['keeping_user_id'] = $services->keeping_user_id;
        $machineInfo['gaming_user_id'] = $machine->gaming_user_id;
        $machineInfo['maintaining'] = $machine->maintaining;
        $machineInfo['currency'] = $machine->currency;
        $machineInfo['odds_x'] = $machine->odds_x;
        $machineInfo['odds_y'] = $machine->odds_y;
        $machineInfo['correct_rate'] = $machine->correct_rate;
        $machineInfo['status'] = $machine->status;
        $machineInfo['push_auto'] = $services->auto;
        $machineInfo['auto_up_turn'] = $services->auto;
        $machineInfo['keeping'] = $services->keeping;
        $machineInfo['keep_seconds'] = $services->keep_seconds;
        $machineInfo['is_use'] = $machine->is_use;
        $machineInfo['reward_status'] = $services->reward_status;
        $machineInfo['strategy_id'] = $machine->strategy_id;
        $machineInfo['machine_category']['id'] = $machine->machineCategory->id;
        $machineInfo['machine_category']['name'] = $machine->machineCategory->name;
        $machineInfo['has_favorite'] = PlayerFavoriteMachine::query()
            ->where('machine_id', $machine->id)
            ->where('player_id', $player->id)
            ->count();
        
        $machineMedia = $machine->machine_media->where('status', 1)->where('is_ams', 1)->sortBy('sort')->makeHidden([
            'user_id',
            'user_name',
            'deleted_at',
            'created_at',
            'updated_at',
            'push_ip',
            'media_ip'
        ])->all();
        $machineInfo['machine_media'] = !empty($machineMedia) ? array_values($machineMedia) : [];
        $machineInfo['machine_marquee'] = SystemSetting::query()
            ->where('feature', 'machine_marquee')
            ->where('department_id', \request()->department_id)
            ->where('status', 1)
            ->value('content') ?? '';
        $machineServices = MachineServices::createServices($machine, $lang);
        $nowTurn = $machineServices->now_turn;
        if ($machine->type == GameType::TYPE_SLOT) {
            $nowTurn = $nowTurn > 0 ? intval(ceil($nowTurn / 3)) : 0;
        }
        $machineInfo['now_turn'] = $nowTurn;
        $machineInfo['machine_lottery_record'] = MachineLotteryRecord::query()
            ->select(['id', 'machine_id', 'use_turn'])
            ->where('machine_id', $machine->id)
            ->orderBy('id', 'desc')
            ->limit(19)
            ->get()
            ->toArray();
        $machineInfo['machine_strategy'] = $machine->strategy_id ? getStrategyUrl($machine->strategy_id) : '';
        /** @var Channel $channel */
        $channel = Channel::where('department_id', \request()->department_id)->first();
        $playRouteNum = Cache::get('machine_play_route_num', 1);
        switch ($channel->machine_media_line) {
            case 1:
            case 2:
            $machineInfo['play_route'] = 0;
                break;
            case 3:
                $machineInfo['play_route'] = $playRouteNum % 2;
                Cache::set('machine_play_route_num', $playRouteNum + 1, 24 * 60 * 60);
                break;
        }
        
        return jsonSuccessResponse('success', $machineInfo);
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 钢珠操作
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException
     */
    public function jackPotAction(Request $request): Response
    {
        try {
            /**@var Player $player */
            /**@var Machine $machine */
            /**@var Jackpot $services */
            [$player, $machine, $data, $action, $services, $hasLottery] = $this->checkAction($request,
                GameType::TYPE_STEEL_BALL);
            $result = [];
            //机台锁定
            if ($services->has_lock == 1 && $action != 'combine_status') {
                return jsonFailResponse(trans('machine_has_lock', [], 'message'), []);
            }
            //機台沒有人 觀看中
            if ($machine->gaming_user_id == 0 && ($action == 'pressure_score' || $action == 'combine_status')) {
                return jsonSuccessResponse('success');
            }
            //不同人
            if ($machine->gaming_user_id != 0 && $machine->gaming_user_id != $player->id) {
                return jsonFailResponse(trans('machine_is_using_msg1', [], 'message'), [], 101);
            }
            //特殊會員  不能操作使用中的機台
            if (!in_array($action, [
                    'combine_status',
                    'reward_switch'
                ]) && $machine->gaming_user_id != 0 && $machine->gaming_user_id != $player->id) {
                return jsonFailResponse(trans('machine_is_using', [], 'message'));
            }
            //尚未綁定位子僅能操作上分功能
            if ($machine->gaming_user_id == 0 && !in_array($action,
                    ['plc_open_1', 'plc_open_10', 'plc_open_times', 'reward_switch', 'plc_push_5hz', 'plc_push_stop', 'plc_start_or_stop'])) {
                return jsonFailResponse(trans('no_open_point', [], 'message'));
            }
            /** @var Player $gaming_player */
            $gaming_player = Player::find($machine->gaming_user_id);
            if ($services->reward_status == 1 && !in_array($action,
                    ['reward_switch', 'combine_status', 'plc_start_or_stop', 'plc_push_5hz', 'plc_push_stop'])) {
                return jsonFailResponse(trans('machine_is_opening', [], 'message'));
            }
            switch ($action) {
                case 'plc_open_1':
                case 'plc_open_10':
                case 'plc_open_times': // 上分
                    // 幂等性保护：request_id 为可选参数
                    $requestId = $request->input('request_id');

                    // 如果传了 request_id，则进行幂等性检查
                    if (!empty($requestId)) {
                        // 第一阶段：检查并预留幂等性（在业务校验之前）
                        $existingResponse = $this->checkIdempotent($requestId, 'jackpot_action_' . $action, $player->id);
                        if ($existingResponse) {
                            return $existingResponse;
                        }
                        if (!$this->reserveIdempotent($requestId, 'jackpot_action_' . $action, $player->id)) {
                            return jsonFailResponse('请求正在处理中，请稍后');
                        }
                    }

                    // 业务校验和逻辑执行（包裹在 try-catch 中）
                    try {
                        if ($services->reward_status == 1) {
                            throw new Exception(trans('machine_reward_drawing', ['{code}' => $machine->code], 'message'));
                        }
                        //上分一次扣多少
                        $money = 100;
                        if ($action == 'plc_open_10') {
                            $money = 1000;
                        }
                        if ($action == 'plc_open_times') {
                            $money = (int)$data['open_point'] ?? 0;
                        }
                        if ($money <= 0) {
                            if (empty($data['give_rule_id'])) {
                                throw new Exception(trans('machine_open_amount_error', [], 'message'));
                            }
                        }
                        //是否是开分赠点
                        $this->givePoints($player, $machine, $data['give_rule_id'] ?? 0, $money);

                        // 第三阶段：保存幂等性记录（如果有 request_id）
                        $response = jsonSuccessResponse('success', []);
                        if (!empty($requestId)) {
                            $this->saveIdempotent($requestId, $response, 'jackpot_action_' . $action, $player->id);
                        }
                        return $response;
                    } catch (Exception $e) {
                        // 业务失败，释放预留（如果有 request_id）
                        if (!empty($requestId)) {
                            $this->releaseIdempotent($requestId);
                        }
                        throw $e;
                    }
                    break;
                case 'combine_status': // 机台履历
                    /** @var PlayerGameRecord $playerGameRecord */
                    $playerGameRecord = PlayerGameRecord::query()
                        ->where('machine_id', $machine->id)
                        ->where('player_id', $player->id)
                        ->where('status', PlayerGameRecord::STATUS_START)
                        ->orderBy('created_at', 'desc')
                        ->first();

                    // 初始化默认值（防止 null 错误）
                    $gamingTurnPoint = 0;
                    $playerGameLog = ['total_turn_point' => 0];

                    // 只有在有游戏记录时才查询日志和计算转数
                    if ($playerGameRecord) {
                        $playerGameLog = PlayerGameLog::query()
                            ->where('game_record_id', $playerGameRecord->id)
                            ->selectRaw('sum(turn_point) as total_turn_point')
                            ->first()
                            ->toArray();

                        // 根据机器类型选择不同的计算方式
                        if ($machine->control_type == Machine::CONTROL_TYPE_SONG) {
                            // 小淞机器：使用实时追踪的 player_win_number + 历史记录的累加
                            $gamingTurnPoint = $services->player_win_number + (!empty($playerGameLog['total_turn_point']) ? $playerGameLog['total_turn_point'] : 0);
                        } else {
                            // 双美机器：使用原有逻辑（基于 turn 和 player_turn_point）
                            $nowTurnPoint = $services->turn;
                            $gamingTurnPoint = $nowTurnPoint - $machine->player_turn_point + (!empty($playerGameLog['total_turn_point']) ? $playerGameLog['total_turn_point'] : 0);
                        }
                    }

                    if ($gamingTurnPoint <= 0 || $machine->gaming_user_id == 0) {
                        $gamingTurnPoint = 0;
                    }

                    // ✅ 从 Redis 读取实时余额（防御性检查）
                    if (!$gaming_player) {
                        $gaming_player = $player;  // 机台无人时使用请求玩家
                    }
                    $gameAmount = floorToPointSecondNumber(
                        \app\service\WalletService::getBalance($gaming_player->id)
                    );
                    
                    $machinePoint = $services->point ?? 0;
                    $givePoint = getGivePoints($player->id, $machine->id);
                    $washPoint = floor($machinePoint * ($machine->odds_x ?? 1) / ($machine->odds_y ?? 1));
                    $result['gaming_turn_point'] = $gamingTurnPoint;
                    $result['game_amount'] = $gameAmount ?? 0;
                    $result['is_opening'] = $services->reward_status;
                    $result['open_point'] = $services->player_open_point;
                    $result['wash_point'] = $washPoint;
                    $result['machine_point'] = $services->point;
                    $result['machine_score'] = $services->score;
                    $result['gift_point'] = !empty($givePoint['gift_point']) ? $givePoint['gift_point'] : 0;
                    $activityServices = new ActivityServices($machine, $player);
                    $result['player_activity_bonus'] = $activityServices->playerActivityBonus();

                    // 使用之前查询的 $playerGameRecord，避免重复查询
                    if ($playerGameRecord) {
                        $result['open_point'] = $playerGameRecord->open_amount;
                        $result['total_wash_point'] = $playerGameRecord->wash_amount ?? 0;
                    } else {
                        $result['open_point'] = 0;
                        $result['total_wash_point'] = 0;
                    }
                    break;
                case 'reward_switch': // 看表
                case 'plc_start_or_stop': // 自动开始/暂停
                case 'plc_push_5hz': // push auto
                case 'plc_push_stop': // push stop
                case 'plc_down_turn': // 下转
                case 'all_down_turn': // 下转all
                case 'plc_up_turn_100': // 上转
                case 'all_up_turn': // 上转all
                    // 下转和上转操作需要额外业务校验
                    if (in_array($action, ['plc_down_turn', 'all_down_turn'])) {
                        if ($services->reward_status == 1) {
                            return jsonFailResponse(trans('machine_reward_drawing', ['{code}' => $machine->code],
                                'message'));
                        }
                        $giftPoint = getGivePoints($player->id, $machine->id);
                        if (!empty($giftPoint)) {
                            return jsonFailResponse(trans('open_give_not_down', ['{code}' => $machine->code],
                                'message'));
                        }
                    }

                    // 上转操作需要额外业务校验
                    if (in_array($action, ['plc_up_turn_100', 'all_up_turn'])) {
                        if ($services->reward_status == 1) {
                            return jsonFailResponse(trans('machine_reward_drawing', ['{code}' => $machine->code],
                                'message'));
                        }
                        if ($services->point <= 0) {
                            return jsonFailResponse(trans('point_zero_not_up', [], 'message'));
                        }
                    }

                    // ✅ 调用 gk_work 统一服务（新接口）
                    // 原本每个 action 都要调用一次 sendCmd，现在由 MachineOperationService 统一处理
                    $client = new MachineClient();
                    $lang = locale() ?? 'zh_TW';
                    $lang = \Illuminate\Support\Str::replace('_', '-', $lang);

                    $actionResult = $client->executeOperation(
                        $machine->id,
                        $action,
                        [
                            'reward_status' => $services->reward_status,
                            'point' => $services->point,
                        ],
                        $lang,
                        $player->id
                    );

                    if (!$actionResult['success']) {
                        return jsonFailResponse($actionResult['message']);
                    }
                    break;
                case 'leave': // 下分弃台
                case 'down': // 下分
                    // 幂等性保护：request_id 为可选参数
                    $requestId = $request->input('request_id');

                    // 如果传了 request_id，则进行幂等性检查
                    if (!empty($requestId)) {
                        // 第一阶段：检查并预留幂等性（在业务校验之前）
                        $existingResponse = $this->checkIdempotent($requestId, 'jackpot_action_' . $action, $player->id);
                        if ($existingResponse) {
                            return $existingResponse;
                        }
                        if (!$this->reserveIdempotent($requestId, 'jackpot_action_' . $action, $player->id)) {
                            return jsonFailResponse('请求正在处理中，请稍后');
                        }
                    }

                    // 业务校验和逻辑执行（包裹在 try-catch 中）
                    try {
                        if ($machine->gaming_user_id == 0) {
                            throw new Exception(trans('no_open_point', [], 'message'));
                        }
                        if ($services->reward_status == 1) {
                            throw new Exception(trans('machine_reward_drawing', ['{code}' => $machine->code], 'message'));
                        }

                        // 调用优化后的 machineWashV2 (将机台指令发送迁移到 gk_work)
                        $washResult = $this->machineWashV2($player, $machine, $action, 0, $hasLottery);

                        // 第三阶段：保存幂等性记录（如果有 request_id）
                        $response = jsonSuccessResponse('success', is_array($washResult) ? $washResult : []);
                        if (!empty($requestId)) {
                            $this->saveIdempotent($requestId, $response, 'jackpot_action_' . $action, $player->id);
                        }
                        return $response;
                    } catch (Exception $e) {
                        // 业务失败，释放预留（如果有 request_id）
                        if (!empty($requestId)) {
                            $this->releaseIdempotent($requestId);
                        }
                        throw $e;
                    }
                    break;
            }
            
        } catch (Exception $e) {
            return jsonFailResponse($e->getMessage() ?? trans('system_error', [], 'message'));
        }
        
        return jsonSuccessResponse('success', $result);
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 操作验证
     * @param $request
     * @param $type
     * @return array
     * @throws PlayerCheckException
     * @throws Exception
     */
    protected function checkAction($request, $type): array
    {
        $player = checkPlayer();
        
        $data = $request->all();
        $validator = v::key('machine_id', v::intVal()->notEmpty()->setName(trans('machine_id', [], 'message')))
            ->key('action', v::stringVal()->notEmpty()->setName(trans('action', [], 'message')))
            ->key('give_rule_id', v::intVal()->setName(trans('give_rule_id', [], 'message')), false)
            ->key('open_point', v::floatVal()->setName(trans('open_point', [], 'message')), false);
        
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            throw new Exception(getValidationMessages($e));
        }
        /** @var Machine $machine */
        // 先查找机台是否存在（不限制类型）
        $machine = Machine::find($data['machine_id']);

        // 机台不存在
        if (!$machine) {
            Log::error('[checkAction] 机台不存在', [
                'machine_id' => $data['machine_id'],
                'expected_type' => $type,
                'player_id' => $player->id,
            ]);
            throw new Exception(trans('machine_not_fount', [], 'message'));
        }

        // 机台类型不匹配
        if ($machine->type != $type) {
            $typeNames = [
                GameType::TYPE_STEEL_BALL => trans('machine_type_steel_ball', [], 'message'),  // 钢珠机
                GameType::TYPE_SLOT => trans('machine_type_slot', [], 'message'),              // 斯洛机
                GameType::TYPE_FISH => trans('machine_type_fish', [], 'message'),              // 捕鱼机
            ];

            Log::error('[checkAction] 机台类型不匹配', [
                'machine_id' => $data['machine_id'],
                'expected_type' => $type,
                'expected_type_name' => $typeNames[$type] ?? 'Unknown',
                'actual_type' => $machine->type,
                'actual_type_name' => $typeNames[$machine->type] ?? 'Unknown',
                'action' => $data['action'] ?? null,
                'player_id' => $player->id,
            ]);

            throw new Exception(trans('machine_type_error', [
                '{expected}' => $typeNames[$type] ?? 'Unknown',
                '{actual}' => $typeNames[$machine->type] ?? 'Unknown'
            ], 'message'));
        }
        if ($machine->status != 1) {
            throw new Exception(trans('machine_closing', [], 'message'));
        }
        if ($machine->maintaining != 0) {
            throw new Exception(trans('machine_maintaining', [], 'message'));
        }
        if (machineMaintaining()) {
            throw new Exception(trans('machine_maintaining', [], 'message'));
        }
        switch ($machine->type) {
            // 检查机台是否在线
            default:
                if (!$this->isMachineOnline($machine->id)) {
                    throw new Exception(trans('machine_has_offline', ['{code}' => $machine->code], 'message'));
                }
        }
        
        $lang = locale() ?? 'zh_TW';
        $lang = Str::replace('_', '-', $lang);
        
        return [
            $player,
            $machine,
            $data,
            $data['action'],
            MachineServices::createServices($machine, $lang),
            (!empty($data['has_lottery']) && $data['has_lottery'] == 1)
        ];
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 开分赠点（优化版：业务校验 + 调用 gk_work）
     *
     * 职责拆分：
     * 1. gk_api (本方法): 业务校验、数据库事务、钱包扣款
     * 2. gk_work: 机台硬件操作(清零 + 开分指令)
     *
     * @param Player $player
     * @param Machine $machine
     * @param int $ruleId
     * @param int $money
     * @return void
     * @throws Exception
     */
    private function givePoints(Player $player, Machine $machine, int $ruleId, int $money): void
    {
        $machineCategoryGiveRule = null;
        $giftScore = 0;

        // 1. 开赠规则校验
        if ($ruleId) {
            /**@var MachineCategoryGiveRule $machineCategoryGiveRule */
            $machineCategoryGiveRule = MachineCategoryGiveRule::where('id', $ruleId)->first();
            if (empty($machineCategoryGiveRule)) {
                throw new Exception(trans('give_amount_rule_err', [], 'message'));
            }
            /** @var PlayerGiftRecord $PlayerGiftRecord */
            $PlayerGiftRecordCount = PlayerGiftRecord::where('player_id', $player->id)
                ->where('machine_category_give_rule_id', $ruleId)
                ->where('give_num', '>', 0)
                ->whereDate('created_at', date('Y-m-d'))
                ->count();
            if ($PlayerGiftRecordCount >= $machineCategoryGiveRule->give_rule_num) {
                throw new Exception(trans('give_amount_condition_limit', [], 'message'));
            }

            $money = $machineCategoryGiveRule->open_num;
            $giftScore = $machineCategoryGiveRule->give_num;
        }

        // 2. 执行上分逻辑（通过 machineOpenAnyV2）
        $this->machineOpenAnyV2($player, $machine, $money, $giftScore, $machineCategoryGiveRule);
    }

    /**
     * 机台上分逻辑（V2 优化版）
     *
     * 优化点：
     * 1. 业务逻辑在 gk_api 完成（校验、数据库、钱包）
     * 2. 机台硬件操作在 gk_work 完成（清零 + 开分指令）
     * 3. 减少跨项目请求，提升性能
     *
     * @param Player $player
     * @param Machine $machine
     * @param float $money
     * @param float $giftScore
     * @param MachineCategoryGiveRule|null $machineCategoryGiveRule
     * @return void
     * @throws Exception
     */
    private function machineOpenAnyV2(
        Player                   $player,
        Machine                  $machine,
        float                    $money,
        float                    $giftScore,
        ?MachineCategoryGiveRule $machineCategoryGiveRule
    ): void {
        // 增加业务锁（与 machineWashV2 使用同一把锁，防止上下分并发）
        // 锁超时15秒 > gk_work超时5秒，防止锁提前释放导致并发冲突
        $actionLockerKey = 'machine_operation_lock_' . $machine->id;
        $lock = Locker::lock($actionLockerKey, 15, true);

        try {
            if (!$lock->acquire()) {
                throw new Exception(trans('machine_is_using_msg1', [], 'message'));
            }

            Log::info('[MachineOpenAnyV2] 开始上分', [
                'player_id' => $player->id,
                'machine_id' => $machine->id,
                'money' => $money,
                'gift_score' => $giftScore,
            ]);

            $lang = locale() ?? 'zh_TW';
            $lang = \Illuminate\Support\Str::replace('_', '-', $lang);
            $services = MachineServices::createServices($machine, $lang);

            // ========== 阶段 1: 业务校验 ==========
            $giftCache = Cache::get('gift_cache_' . $machine->id . '_' . $player->id);
            if ($giftCache) {
                switch ($machine->type) {
                    case GameType::TYPE_STEEL_BALL:
                        if ($services->turn == 0) {
                            $services->gift_bet = 0;
                            Cache::delete('gift_cache_' . $machine->id . '_' . $player->id);
                        } else {
                            throw new Exception(trans('currently_remaining_score', [], 'message'));
                        }
                        break;
                    case GameType::TYPE_SLOT:
                        if ($services->reward_status == 1) {
                            if ($services->point <= 0 && !empty($machineCategoryGiveRule)) {
                                throw new Exception(trans('reward_again_open_give', [], 'message'));
                            }
                            if ($services->point > 0) {
                                throw new Exception(trans('reward_point_zero_open_give', [], 'message'));
                            }
                        } else {
                            if ($services->point > 0) {
                                throw new Exception(trans('use_give_point_zero', [], 'message'));
                            }
                        }
                        break;
                }
            }

            // 余额检查
            $currentBalance = \app\service\WalletService::getBalance($player->id);
            if ($currentBalance < $money) {
                throw new Exception(trans('game_amount_insufficient', [], 'message'));
            }

            // 上分频率限制
            if ($services->last_point_at + 5 >= time() && Cache::has('machine_open_point' . $machine->id . '_' . $player->id)) {
                throw new Exception(trans('machine_open_wash_too_fast', [], 'message'));
            }

            // 可以玩多台检查
            if ($player->machine_play_num > 1) {
                $self_machine_count = Machine::query()->where('gaming_user_id', $player->id)->where('id', '!=',
                    $machine->id)->count();
                if ($player->machine_play_num <= $self_machine_count) {
                    throw new Exception(trans('machine_only_msg1', [], 'message'));
                }
            }
            Cache::set('machine_open_point' . $machine->id . '_' . $player->id, 1, 5);

            // ========== 阶段 2: 准备清零指令列表 ==========
            $preClearCommands = [];
            if ($machine->gaming == 0 && $services->reward_status == 0) {
                switch ($machine->type) {
                    case GameType::TYPE_SLOT:
                        if ($services->point > 0) {
                            $preClearCommands[] = ['cmd' => 'WASH_ZERO', 'data' => 0];
                        }
                        break;
                    case GameType::TYPE_STEEL_BALL:
                        if ($machine->control_type == Machine::CONTROL_TYPE_MEI) {
                            if ($services->score > 0) {
                                $preClearCommands[] = ['cmd' => 'SCORE_TO_POINT', 'data' => 0];
                            }
                            if ($services->turn > 0) {
                                $preClearCommands[] = ['cmd' => 'TURN_DOWN_ALL', 'data' => 0];
                            }
                            if ($services->point > 0) {
                                $preClearCommands[] = ['cmd' => 'WASH_ZERO', 'data' => 0];
                            }
                        }
                        if ($machine->control_type == Machine::CONTROL_TYPE_SONG) {
                            $preClearCommands[] = ['cmd' => 'MACHINE_SCORE', 'data' => 0];
                            if ($services->score > 0) {
                                $preClearCommands[] = ['cmd' => 'SCORE_TO_POINT', 'data' => 0];
                            }
                            $preClearCommands[] = ['cmd' => 'MACHINE_TURN', 'data' => 0];
                            if ($services->turn > 0) {
                                $preClearCommands[] = ['cmd' => 'TURN_DOWN_ALL', 'data' => 0];
                            }
                            $preClearCommands[] = ['cmd' => 'MACHINE_POINT', 'data' => 0];
                            if ($services->point > 0) {
                                $preClearCommands[] = ['cmd' => 'WASH_ZERO', 'data' => 0];
                            }
                        }
                        break;
                }
            }

            // ========== 阶段 3: 业务校验（事务前） ==========
            // 3.1 检查玩家开赠权限
            if ($player->status_open_point == 0) {
                throw new Exception(trans('machine_present_error_msg2', [], 'message'));
            }

            // 3.2 计算开分数量并校验
            $openScore = checkMachineOpenAny($machine, $money, $giftScore);
            if ($machine->min_point != 0 && $machine->min_point > $openScore) {
                throw new Exception(trans('machine_min_open', [], 'message') . $machine->min_point);
            }
            if ($machine->max_point != 0 && ($machine->max_point < $services->point || $machine->max_point < $openScore || $machine->max_point < ($services->point + $openScore))) {
                throw new Exception(trans('machine_max_open', [], 'message') . $machine->max_point);
            }

            // 3.3 斯洛分数限制
            if ($machine->type == GameType::TYPE_SLOT) {
                if ($services->point + $openScore > 4000) {
                    throw new Exception(trans('machine_wash_limit_msg1', [], 'message'));
                }
            }

            // ========== 阶段 4: 数据库事务 ==========
            DB::beginTransaction();
            $walletDeducted = false;  // 标记钱包是否已扣款
            try {
                // 4.1 扣除玩家钱包
                $beforeGameAmount = \app\service\WalletService::getBalance($player->id, 1);
                $afterGameAmount = \app\service\WalletService::deduct($player->id, $money, 1);
                $walletDeducted = true;  // 标记已扣款

                // 4.2 记录游戏局记录
                /** @var PlayerGameRecord $gameRecord */
                $gameRecord = PlayerGameRecord::query()->where('machine_id', $machine->id)
                    ->where('player_id', $player->id)
                    ->where('status', PlayerGameRecord::STATUS_START)
                    ->orderBy('created_at', 'desc')
                    ->first();

                if (empty($gameRecord)) {
                    // 首次上分，创建新的游戏记录
                    $gameRecord = new PlayerGameRecord();
                    $gameRecord->machine_id = $machine->id;
                    $gameRecord->player_id = $player->id;
                    $gameRecord->status = PlayerGameRecord::STATUS_START;
                    $gameRecord->open_point = 0;
                    $gameRecord->open_amount = 0;
                    $gameRecord->give_amount = 0;
                    $gameRecord->wash_point = 0;
                    $gameRecord->wash_amount = 0;
                    $gameRecord->after_game_amount = $beforeGameAmount;
                    $gameRecord->created_at = date('Y-m-d H:i:s');
                    $gameRecord->updated_at = date('Y-m-d H:i:s');
                    // 先保存以获取 ID
                    $gameRecord->save();

                    Log::info('[MachineOpenAnyV2] 创建新的游戏记录', [
                        'player_id' => $player->id,
                        'machine_id' => $machine->id,
                        'game_record_id' => $gameRecord->id,
                    ]);
                }

                // 更新游戏记录
                if (time() - strtotime($gameRecord->updated_at) > 24 * 60 * 60 * 2 && $machine->gaming_user_id != $player->id) {
                    $gameRecord->status = PlayerGameRecord::STATUS_END;
                }
                $gameRecord->open_point = bcadd($gameRecord->open_point, $openScore, 2);
                $gameRecord->open_amount = bcadd($gameRecord->open_amount, $money, 2);
                $gameRecord->give_amount = bcadd($gameRecord->give_amount, $giftScore, 2);
                $gameRecord->save();

                // 4.3 创建游戏日志（现在 $gameRecord 一定不为 null）
                $playerGameLog = createGameLog($gameRecord, $machine, $player, $openScore, $money, $afterGameAmount,
                    $giftScore, $beforeGameAmount);

                // 4.4 记录开赠记录
                if ($machineCategoryGiveRule) {
                    $playersGiftRecord = new PlayerGiftRecord();
                    $playersGiftRecord->player_game_log_id = $playerGameLog->id;
                    $playersGiftRecord->machine_category_give_rule_id = $machineCategoryGiveRule->id;
                    $playersGiftRecord->machine_id = $machine->id;
                    $playersGiftRecord->player_id = $player->id;
                    $playersGiftRecord->player_name = $player->name;
                    $playersGiftRecord->machine_name = $machine->name;
                    $playersGiftRecord->machine_type = $machine->type;
                    $playersGiftRecord->open_num = $machineCategoryGiveRule->open_num;
                    $playersGiftRecord->give_num = $machineCategoryGiveRule->give_num;
                    $playersGiftRecord->condition = $machineCategoryGiveRule->condition;
                    $playersGiftRecord->created_at = date('Y-m-d H:i:s');
                    $playersGiftRecord->updated_at = date('Y-m-d H:i:s');
                    $playersGiftRecord->save();
                }

                // 4.5 更新机台状态
                if ($machine->gaming == 0) {
                    $machine->last_game_at = date('Y-m-d H:i:s');
                }
                $machine->gaming = 1;
                $machine->gaming_user_id = $player->id;
                $machine->last_point_at = date('Y-m-d H:i:s');
                $machine->save();

                // 4.6 写入金流明细
                createPlayerDeliveryRecord($player, $playerGameLog, $machine, $money, $beforeGameAmount, $afterGameAmount);

                // 4.7 活动记录
                if ($player->channel && $player->channel->activity_status == 1) {
                    $ActivityServices = new ActivityServices($machine, $player);
                    $ActivityServices->addPlayerActivityRecord();
                }

                // ========== 阶段 5: 调用 gk_work 执行机台硬件操作（在 commit 之前）==========
                // 设置gk_work超时为5秒（< 锁超时15秒），确保锁不会提前释放
                $client = new MachineClient(null, 5);
                $machineContext = [
                    'type' => $machine->type,
                    'control_type' => $machine->control_type,
                    'gaming' => $machine->gaming,
                    'reward_status' => $services->reward_status,
                    'point' => $services->point,
                    'score' => $services->score,
                    'turn' => $services->turn,
                ];

                $result = $client->openMachine(
                    $machine->id,
                    $player->id,
                    $openScore,
                    $machineContext,
                    $preClearCommands,
                    $lang
                );

                if (!$result['success']) {
                    // gk_work 失败，抛出异常触发回滚
                    Log::error('[MachineOpenAnyV2] gk_work 机台指令发送失败，准备回滚', [
                        'player_id' => $player->id,
                        'machine_id' => $machine->id,
                        'open_score' => $openScore,
                        'error' => $result['message'],
                    ]);

                    throw new Exception(trans('machine_open_command_failed', [], 'message'));
                }

                // ========== 阶段 6: gk_work 成功后提交事务 ==========
                DB::commit();

                Log::info('[MachineOpenAnyV2] 上分完成（数据库已提交，硬件已上分）', [
                    'player_id' => $player->id,
                    'machine_id' => $machine->id,
                    'open_score' => $openScore,
                    'after_amount' => $afterGameAmount,
                ]);

            } catch (\Exception $e) {
                DB::rollback();

                Log::error('[MachineOpenAnyV2] 数据库事务失败', [
                    'player_id' => $player->id,
                    'machine_id' => $machine->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'wallet_deducted' => $walletDeducted,
                ]);

                // 只有在已扣款的情况下才回滚 Redis 余额
                if ($walletDeducted) {
                    try {
                        \app\service\WalletService::add($player->id, $money, 1);
                        Log::info('[MachineOpenAnyV2] Redis余额回滚成功', [
                            'player_id' => $player->id,
                            'refund_amount' => $money
                        ]);
                    } catch (\Exception $refundError) {
                        Log::critical('[MachineOpenAnyV2] Redis余额回滚失败，需人工介入', [
                            'player_id' => $player->id,
                            'machine_id' => $machine->id,
                            'refund_amount' => $money,
                            'error' => $refundError->getMessage()
                        ]);
                    }
                }

                throw $e;
            }

        } finally {
            // 添加异常保护，防止锁释放失败导致死锁
            try {
                if ($lock->isAcquired()) {
                    $lock->release();
                }
            } catch (\Exception $lockError) {
                Log::critical('[MachineOpenAnyV2] 锁释放失败，可能导致死锁', [
                    'machine_id' => $machine->id,
                    'lock_key' => $actionLockerKey,
                    'error' => $lockError->getMessage(),
                ]);
            }
        }
    }

    #[RateLimiter(limit: 5)]
    /**
     * 斯洛机台操作
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException
     */
    public function slotAction(Request $request): Response
    {
        //驗證通過
        try {
            /**@var Player $player */
            /**@var Machine $machine */
            /**@var Slot $services */
            [$player, $machine, $data, $action, $services, $hasLottery] = $this->checkAction($request,
                GameType::TYPE_SLOT);
            $result = [];
            //机台锁定
            if ($services->has_lock == 1 && $action != 'pressure_score') {
                return jsonFailResponse(trans('machine_has_lock', [], 'message'), []);
            }
            switch ($action) {
                case 'open_1':
                case 'open_10':
                case 'plc_open_times':
                    // 幂等性保护：request_id 为可选参数
                    $requestId = $request->input('request_id');

                    // 如果传了 request_id，则进行幂等性检查
                    if (!empty($requestId)) {
                        // 第一阶段：检查并预留幂等性（在业务校验之前）
                        $existingResponse = $this->checkIdempotent($requestId, 'slot_action_' . $action, $player->id);
                        if ($existingResponse) {
                            return $existingResponse;
                        }
                        if (!$this->reserveIdempotent($requestId, 'slot_action_' . $action, $player->id)) {
                            return jsonFailResponse('请求正在处理中，请稍后');
                        }
                    }

                    // 业务校验和逻辑执行（包裹在 try-catch 中）
                    try {
                        if ($machine->gaming_user_id != 0 && $machine->gaming_user_id != $player->id) {
                            throw new Exception(trans('machine_is_using_msg1', [], 'message'));
                        }
                        if ($services->auto == 1 && $machine->gaming_user_id == $player->id) {
                            throw new Exception(trans('slot_machine_must_stop_auto', [], 'message'));
                        }
                        $money = 100;
                        if ($action == 'open_10') {
                            $money = 1000;
                        }
                        if ($action == 'plc_open_times') {
                            $money = (int)$data['open_point'] ?? 0;
                        }
                        if ($money <= 0) {
                            if (empty($data['give_rule_id'])) {
                                throw new Exception(trans('machine_open_amount_error', [], 'message'));
                            }
                        }
                        if ($services->reward_status == 1 && !empty($data['give_rule_id'])) {
                            throw new Exception(trans('reward_not_open_give', [], 'message'));
                        }
                        if ($services->reward_status == 0 && $services->point > 0 && !empty($data['give_rule_id'])) {
                            throw new Exception(trans('point_zero_open_give', [], 'message'));
                        }

                        // 执行业务逻辑（givePoints 内部已包含事务）
                        $this->givePoints($player, $machine, $data['give_rule_id'] ?? 0, $money);

                        // 第三阶段：保存幂等性记录（如果有 request_id）
                        $response = jsonSuccessResponse('success', []);
                        if (!empty($requestId)) {
                            $this->saveIdempotent($requestId, $response, 'slot_action_' . $action, $player->id);
                        }
                        return $response;
                    } catch (Exception $e) {
                        // 业务失败，释放预留（如果有 request_id）
                        if (!empty($requestId)) {
                            $this->releaseIdempotent($requestId);
                        }
                        throw $e;
                    }
                    break;
                case 'leave':
                case 'down':
                    // 幂等性保护：request_id 为可选参数
                    $requestId = $request->input('request_id');

                    // 如果传了 request_id，则进行幂等性检查
                    if (!empty($requestId)) {
                        // 第一阶段：检查并预留幂等性（在业务校验之前）
                        $existingResponse = $this->checkIdempotent($requestId, 'slot_action_' . $action, $player->id);
                        if ($existingResponse) {
                            return $existingResponse;
                        }
                        if (!$this->reserveIdempotent($requestId, 'slot_action_' . $action, $player->id)) {
                            return jsonFailResponse('请求正在处理中，请稍后');
                        }
                    }

                    // 业务校验和逻辑执行（包裹在 try-catch 中）
                    try {
                        if ($machine->gaming_user_id == 0) {
                            throw new Exception(trans('no_open_point', [], 'message'));
                        }
                        if ($machine->gaming_user_id != 0 && $machine->gaming_user_id != $player->id) {
                            throw new Exception(trans('machine_is_using_msg1', [], 'message'));
                        }
                        if ($services->auto == 1 && $machine->control_type == Machine::CONTROL_TYPE_MEI) {
                            throw new Exception(trans('slot_machine_must_stop_auto', [], 'message'));
                        }
                        if ($services->reward_status == 1 && $action == 'leave') {
                            throw new Exception(trans('reward_not_down_leave', [], 'message'));
                        }

                        // 执行业务逻辑（使用优化后的 machineWashV2）
                        $machineWashResult = $this->machineWashV2($player, $machine, $action, 0, $hasLottery);
                        if ($machineWashResult instanceof PlayerLotteryRecord) {
                            $result = $machineWashResult->toArray();
                            $result['has_lottery'] = false;
                        } elseif ($machineWashResult != null) {
                            $result = $machineWashResult;
                        }

                        // 第三阶段：保存幂等性记录（如果有 request_id）
                        $response = jsonSuccessResponse('success', $result === true ? [] : $result);
                        if (!empty($requestId)) {
                            $this->saveIdempotent($requestId, $response, 'slot_action_' . $action, $player->id);
                        }
                        return $response;
                    } catch (Exception $e) {
                        // 业务失败，释放预留（如果有 request_id）
                        if (!empty($requestId)) {
                            $this->releaseIdempotent($requestId);
                        }
                        throw $e;
                    }
                break;
                case 'start':
                case 'auto':
                case 'stop_auto':
                    // 统一加锁机制（15秒超时 + finally 保护）
                    $actionLockerKey = 'machine_operation_lock_' . $machine->id;
                    $lock = Locker::lock($actionLockerKey, 15, true);

                    try {
                        if (!$lock->acquire()) {
                            return jsonFailResponse(trans('busy_operations', [], 'message'));
                        }

                        // 业务校验
                        if ($action === 'start') {
                            if ($machine->gaming_user_id == 0) {
                                return jsonFailResponse(trans('no_open_point', [], 'message'));
                            }
                            if ($machine->gaming_user_id != 0 && $machine->gaming_user_id != $player->id) {
                                return jsonFailResponse(trans('machine_is_using_msg1', [], 'message'));
                            }
                            if ($services->auto == 1) {
                                return jsonFailResponse(trans('slot_machine_must_stop_auto', [], 'message'));
                            }
                        } elseif ($action === 'auto' || $action === 'stop_auto') {
                            if ($machine->gaming_user_id == 0) {
                                return jsonFailResponse(trans('no_open_point', [], 'message'));
                            }
                            if ($machine->gaming_user_id != 0 && $machine->gaming_user_id != $player->id) {
                                return jsonFailResponse(trans('machine_is_using_msg1', [], 'message'));
                            }
                        }

                        // ✅ 调用 gk_work 统一服务（新接口）
                        // 硬件逻辑（move_point、PRESSURE、control_type）全部在 MachineOperationService 中处理
                        $client = new MachineClient(null, 5);
                        $lang = locale() ?? 'zh_TW';
                        $lang = \Illuminate\Support\Str::replace('_', '-', $lang);

                        $actionResult = $client->executeOperation(
                            $machine->id,
                            $action,
                            [
                                'move_point' => $services->move_point,  // 移分状态（仅双美斯洛使用）
                                'auto' => $services->auto,  // 自动状态
                                'is_special' => $machine->is_special,  // 特殊机台标记
                            ],
                            $lang,
                            $player->id
                        );

                        if (!$actionResult['success']) {
                            return jsonFailResponse($actionResult['message']);
                        }

                    } finally {
                        try {
                            if ($lock->isAcquired()) {
                                $lock->release();
                            }
                        } catch (\Exception $lockError) {
                            Log::critical('[SlotAction] 锁释放失败', [
                                'machine_id' => $machine->id,
                                'action' => $action,
                                'lock_key' => $actionLockerKey,
                                'error' => $lockError->getMessage(),
                            ]);
                        }
                    }
                    break;
                case 'out_1_pulse':
                case 'stop_1':
                case 'stop_2':
                case 'stop_3':
                    // 业务校验
                    if ($action === 'out_1_pulse') {
                        if ($machine->gaming_user_id != 0 && $machine->gaming_user_id != $player->id) {
                            return jsonFailResponse(trans('machine_is_using_msg1', [], 'message'));
                        }
                    } else {
                        // stop_1/2/3 的校验
                        if ($machine->gaming_user_id == 0) {
                            return jsonFailResponse(trans('no_open_point', [], 'message'));
                        }
                        if ($machine->gaming_user_id != 0 && $machine->gaming_user_id != $player->id) {
                            return jsonFailResponse(trans('machine_is_using_msg1', [], 'message'));
                        }
                        if ($services->auto == 1 && $machine->is_special == 1) {
                            return jsonFailResponse(trans('slot_machine_must_stop_auto', [], 'message'));
                        }
                    }

                    // ✅ 调用 gk_work 统一服务（新接口）
                    $client = new MachineClient(null, 5);
                    $lang = locale() ?? 'zh_TW';
                    $lang = \Illuminate\Support\Str::replace('_', '-', $lang);

                    $actionResult = $client->executeOperation(
                        $machine->id,
                        $action,
                        [
                            'auto' => $services->auto,
                            'is_special' => $machine->is_special,
                        ],
                        $lang,
                        $player->id
                    );

                    if (!$actionResult['success']) {
                        return jsonFailResponse($actionResult['message']);
                    }
                    break;
                case 'pressure_score':
                    if ($machine->gaming_user_id == 0) {
                        return jsonSuccessResponse('success');
                    }
                    /** @var PlayerGameRecord $playerGameRecord */
                    $playerGameRecord = PlayerGameRecord::query()
                        ->where('machine_id', $machine->id)
                        ->where('player_id', $player->id)
                        ->where('status', PlayerGameRecord::STATUS_START)
                        ->orderBy('created_at', 'desc')
                        ->first();

                    // 初始化默认值，防止 null 错误
                    $playerGameLog = ['total_pressure' => 0, 'total_score' => 0];
                    $gamingPressure = 0;
                    $gamingScore = 0;

                    // 只有存在游戏记录时才查询日志和计算
                    if ($playerGameRecord) {
                        $playerGameLog = PlayerGameLog::query()
                            ->where('game_record_id', $playerGameRecord->id)
                            ->selectRaw('sum(pressure) as total_pressure,sum(score) as total_score')
                            ->first()
                            ->toArray();

                        $services->sendCmd($services::READ_BET, 0, 'player', $player->id);
                        $services->sendCmd($services::READ_WIN, 0, 'player', $player->id);

                        // 玩家当局游戏压分
                        $gamingPressure = $services->bet - $services->player_pressure + (!empty($playerGameLog['total_pressure']) ? $playerGameLog['total_pressure'] : 0);
                        if ($gamingPressure <= 0 || $machine->gaming_user_id == 0) {
                            $gamingPressure = 0;
                        }
                        // 玩家当局游戏得分
                        $gamingScore = $services->win - $services->player_score + (!empty($playerGameLog['total_score']) ? $playerGameLog['total_score'] : 0);
                        if ($gamingScore <= 0 || $machine->gaming_user_id == 0) {
                            $gamingScore = 0;
                        }
                    }

                    $givePoint = getGivePoints($player->id, $machine->id);
                    $result['pressure'] = $services->bet;
                    $result['gaming_pressure'] = $gamingPressure; // 总押注
                    $result['gaming_score'] = $gamingScore; // 总得分
                    $result['seven_display'] = $services->point; // 当前余分
                    $result['gift_point'] = !empty($givePoint['gift_point']) ? $givePoint['gift_point'] : 0;
                    $result['wash_point'] = floor((($services->point)) * ($machine->odds_x ?? 1) / ($machine->odds_y ?? 1));
                    $activityServices = new ActivityServices($machine, $player);
                    $result['player_activity_bonus'] = $activityServices->playerActivityBonus(); // 本轮活动奖励
                    $result['open_point'] = $playerGameRecord ? $playerGameRecord->open_amount : 0; // 总上点
                    $result['total_wash_point'] = $playerGameRecord ? ($playerGameRecord->wash_amount ?? 0) : 0; // 总下点
                    break;
                default:
                    throw new Exception(trans('system_error', [], 'message'));
            }
            
        } catch (Exception $e) {
            Log::error('错误了', [$e->getMessage(), $e->getTraceAsString()]);
            return jsonFailResponse($e->getMessage() ?? trans('system_error', [], 'message'));
        }
        
        return jsonSuccessResponse('success', $result === true ? [] : $result);
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 捕鱼机台操作
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException
     * @throws \think\Exception
     */
    public function fishAction(Request $request): Response
    {
        try {
            /**@var Player $player */
            /**@var Machine $machine */
            [$player, $machine, $data, $action, $service] = $this->checkAction($request, GameType::TYPE_FISH);
            if ($machine->gaming_user_id != 0 && $machine->gaming_user_id != $player->id) {
                return jsonFailResponse(trans('machine_is_using_msg1', [], 'message'), [], 101);
            }
            switch ($action) {
                case 'up': // 上
                case 'down': // 下一次 鎖定功能
                case 'down_twice': // 下兩次 切換武器功能
                case 'left': // 左
                case 'right': // 右
                case 'press': // 押分
                case 'shoot': // 發射
                case 'auto_on': // 啟動自動
                case 'auto_off': // 關閉自動
                case 'auto': // 自動
                case 'identify_image': // 自動
                    $service->machineAction($action);
                    break;
                case 'open_point': // 開分
                    // 幂等性保护：request_id 为可选参数
                    $requestId = $request->input('request_id');

                    // 如果传了 request_id，则进行幂等性检查
                    if (!empty($requestId)) {
                        // 第一阶段：检查并预留幂等性（在业务校验之前）
                        $existingResponse = $this->checkIdempotent($requestId, 'fish_action_open_point', $player->id);
                        if ($existingResponse) {
                            return $existingResponse;
                        }
                        if (!$this->reserveIdempotent($requestId, 'fish_action_open_point', $player->id)) {
                            return jsonFailResponse('请求正在处理中，请稍后');
                        }
                    }

                    // 业务校验和逻辑执行（包裹在 try-catch 中）
                    try {
                        $money = $data['open_point'] ?? 0;
                        if ($money <= 0) {
                            throw new Exception(trans('machine_open_amount_error', [], 'message'));
                        }
                        // 执行开分
                        fishMachineOpenAny($player, $machine, $money, $service);

                        // 第三阶段：保存幂等性记录（如果有 request_id）
                        $response = jsonSuccessResponse('success');
                        if (!empty($requestId)) {
                            $this->saveIdempotent($requestId, $response, 'fish_action_open_point', $player->id);
                        }
                        return $response;
                    } catch (Exception $e) {
                        // 业务失败，释放预留（如果有 request_id）
                        if (!empty($requestId)) {
                            $this->releaseIdempotent($requestId);
                        }
                        throw $e;
                    }
                    break;
                case 'wash_point': // 洗分
                    // 幂等性保护：request_id 为可选参数
                    $requestId = $request->input('request_id');

                    // 如果传了 request_id，则进行幂等性检查
                    if (!empty($requestId)) {
                        // 第一阶段：检查并预留幂等性（在业务校验之前）
                        $existingResponse = $this->checkIdempotent($requestId, 'fish_action_wash_point', $player->id);
                        if ($existingResponse) {
                            return $existingResponse;
                        }
                        if (!$this->reserveIdempotent($requestId, 'fish_action_wash_point', $player->id)) {
                            return jsonFailResponse('请求正在处理中，请稍后');
                        }
                    }

                    // 业务校验和逻辑执行（包裹在 try-catch 中）
                    try {
                        //尚未綁定位子
                        if ($machine->gaming_user_id == 0) {
                            throw new Exception(trans('no_open_point', [], 'message'));
                        }
                        // 增加业务锁
                        $actionLockerKey = 'action_locker_key_machine_' . $machine->id . '_player_' . $player->id;
                        $lock = Locker::lock($actionLockerKey, 5, true);
                        if (!$lock->acquire()) {
                            Log::error('业务锁异常--这里不处理异常');
                        }

                        fishMachineWash($player, $machine, $service, $action);

                        // 第三阶段：保存幂等性记录（如果有 request_id）
                        $response = jsonSuccessResponse('success');
                        if (!empty($requestId)) {
                            $this->saveIdempotent($requestId, $response, 'fish_action_wash_point', $player->id);
                        }
                        return $response;
                    } catch (Exception $e) {
                        // 业务失败，释放预留（如果有 request_id）
                        if (!empty($requestId)) {
                            $this->releaseIdempotent($requestId);
                        }
                        throw $e;
                    }
                    break;
                case 'lock': // 锁
                    break;
                default:
                    throw new \think\Exception(trans('exception_msg.action_not_fount', [], 'message'));
            }
        } catch (Exception $e) {
            return jsonFailResponse($e->getMessage() ?? trans('system_error', [], 'message'));
        }
        
        return jsonSuccessResponse('success');
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 保留机台
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException
     * @throws Exception
     */
    public function machineKeep(Request $request): Response
    {
        $player = checkPlayer();
        
        $data = $request->all();
        $validator = v::key('machine_id', v::intVal()->notEmpty()->setName(trans('machine_id', [], 'message')));
        
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        
        /** @var Machine $machine */
        $machine = Machine::find($data['machine_id']);
        
        if (!$machine) {
            return jsonFailResponse(trans('machine_not_found', [], 'message'));
        }
        $lang = locale() ?? 'zh_TW';
        $lang = Str::replace('_', '-', $lang);
        $services = MachineServices::createServices($machine, $lang);
        if ($machine->gaming_user_id == 0) {
            return jsonFailResponse(trans('no_open_point', [], 'message'));
        }
        
        if ($services->keeping == 1) {
            return jsonFailResponse(trans('has_keeping', [], 'message'));
        }
        
        if ($machine->gaming_user_id != 0 && $machine->gaming_user_id != $player->id) {
            return jsonFailResponse(trans('machine_is_using', [], 'message'));
        }
        //鋼珠自動中不能保留
        if ($machine->type == GameType::TYPE_STEEL_BALL) {
            if ($services->auto == 1) {
                return jsonFailResponse(trans('machine_cannot_keeping', [], 'message'));
            }
        }
        
        //斯洛自動中不能保留
        if ($machine->type == GameType::TYPE_SLOT && $machine->auto_up_turn == 1) {
            return jsonFailResponse(trans('machine_cannot_keeping', [], 'message'));
        }
        DB::beginTransaction();
        try {
            $currentTime = time();
            $services->keeping = 1;
            $services->keeping_user_id = $player->id;
            $services->last_keep_at = $currentTime;
            $services->last_play_time = $currentTime;
            // 记录保留日志
            $machineKeepingLog = new MachineKeepingLog();
            $machineKeepingLog->player_id = $player->id;
            $machineKeepingLog->machine_id = $machine->id;
            $machineKeepingLog->machine_name = $machine->name;
            $machineKeepingLog->is_system = 2;
            $machineKeepingLog->department_id = $player->department_id;
            $machineKeepingLog->save();
            
            sendSocketMessage('player-' . $machine->gaming_user_id . '-' . $machine->id, [
                'msg_type' => 'player_machine_keeping',
                'player_id' => $machine->gaming_user_id,
                'machine_id' => $machine->id,
                'keep_seconds' => $services->keep_seconds,
                'keeping' => $services->keeping
            ]);
            sendSocketMessage('player-' . $machine->gaming_user_id, [
                'msg_type' => 'player_machine_keeping',
                'player_id' => $machine->gaming_user_id,
                'machine_id' => $machine->id,
                'keep_seconds' => $services->keep_seconds,
                'keeping' => $services->keeping
            ]);
            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            return jsonFailResponse($e->getMessage());
        }
        
        return jsonSuccessResponse(trans('keeping_success', [], 'message'));
    }
    
    
    /**
     * 机器投钞
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException
     * @throws \think\Exception
     */
    public function rechargeAndWithdraw(Request $request): Response
    {
        $player = checkPlayer();
        $data = $request->all();

        // ==================== 阶段1：占位前检查 ====================
        // 参数验证
        $validator = v::arrayType()
            ->key('amount', v::numericVal()->notEmpty()->setName(trans('amount', [], 'store_machine_message')));
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }

        // Player属性检查
        if ($player->status_offline_open == 0) {
            return jsonFailResponse(trans('recharge_closed', [], 'message'));
        }

        // ==================== 阶段2：幂等性处理 ====================
        $requestId = $data['request_id'] ?? null;
        $idempotentResponse = $this->checkIdempotent($requestId, 'machine-recharge', $player->id);
        if ($idempotentResponse !== null) {
            return $idempotentResponse;
        }

        if (!$this->reserveIdempotent($requestId, 'machine-recharge', $player->id)) {
            $response = $this->checkIdempotent($requestId, 'machine-recharge', $player->id);
            return $response ?? jsonFailResponse(trans('request_processing', [], 'message'));
        }

        // ==================== 阶段3：占位后检查（需要查库，失败需释放） ====================
        // 爆机检查
        $crashCheck = checkMachineCrash($player);
        if ($crashCheck['crashed']) {
            $this->releaseIdempotent($requestId);
            return jsonFailResponse(trans('machine_crashed_cannot_recharge', [], 'message'));
        }

        // 渠道检查
        /** @var Channel $channel */
        $channel = Channel::where('department_id', \request()->department_id)->first();
        if (empty($channel)) {
            $this->releaseIdempotent($requestId);
            return jsonFailResponse(trans('channel_not_found', [], 'message'));
        }
        if ($channel->recharge_status == 0) {
            $this->releaseIdempotent($requestId);
            return jsonFailResponse(trans('recharge_closed', [], 'message'));
        }
        
        /** @var Currency $currency */
        $currency = Currency::where('identifying', $channel->currency)->where('status',
            1)->whereNull('deleted_at')->select(['id', 'identifying', 'ratio'])->first();
        if (empty($currency)) {
            return jsonFailResponse(trans('currency_no_setting', [], 'message'));
        }
        $money = bcdiv($data['amount'], $currency->ratio, 2);
        if ($money <= 0) {
            return jsonFailResponse(trans('currency_no_setting', [], 'message'));
        }
        
        DB::beginTransaction();
        try {
            // 生成充值订单
            $playerRechargeRecord = new  PlayerRechargeRecord();
            $playerRechargeRecord->player_id = $player->id;
            $playerRechargeRecord->talk_user_id = $player->talk_user_id;
            $playerRechargeRecord->department_id = $player->department_id;
            $playerRechargeRecord->tradeno = createOrderNo();
            $playerRechargeRecord->player_name = $player->name ?? '';
            $playerRechargeRecord->player_phone = $player->phone ?? '';
            $playerRechargeRecord->money = $data['amount'];
            $playerRechargeRecord->inmoney = $data['amount'];
            $playerRechargeRecord->rate = $currency->ratio;
            $playerRechargeRecord->actual_rate = $currency->ratio;
            $playerRechargeRecord->setting_id = 0;
            $playerRechargeRecord->point = $money;
            $playerRechargeRecord->currency = $channel->currency;
            $playerRechargeRecord->type = PlayerRechargeRecord::TYPE_MACHINE;  //投钞类型
            $playerRechargeRecord->status = PlayerRechargeRecord::STATUS_RECHARGED_SUCCESS;
            $playerRechargeRecord->finish_time = date('Y-m-d H:i:s');
            $playerRechargeRecord->save();

            // ✅ 从 Redis 读取余额（唯一可信源）
            $beforeGameAmount = \app\service\WalletService::getBalance($player->id);

            // ✅ 玩家钱包加款 - Lua 原子操作（自动同步数据库）
            $incrementResult = \app\service\WalletService::atomicIncrement(
                $player->id,
                $money
            );
            $afterGameAmount = $incrementResult['balance'];

            $player->player_extend->machine_put_amount = bcadd($player->player_extend->machine_put_amount,
                $playerRechargeRecord->money, 2);
            $player->player_extend->machine_put_point = bcadd($player->player_extend->machine_put_point,
                $playerRechargeRecord->point, 2);
            $player->player_extend->recharge_amount = bcadd($player->player_extend->recharge_amount,
                $playerRechargeRecord->point, 2);

            $player->push();
            $playerDeliveryRecord = new PlayerDeliveryRecord;
            $playerDeliveryRecord->player_id = $playerRechargeRecord->player_id;
            $playerDeliveryRecord->department_id = $playerRechargeRecord->department_id;
            $playerDeliveryRecord->target = $playerRechargeRecord->getTable();
            $playerDeliveryRecord->target_id = $playerRechargeRecord->id;
            $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_MACHINE;
            $playerDeliveryRecord->source = 'machine_put_coins';
            $playerDeliveryRecord->amount = $playerRechargeRecord->point;
            $playerDeliveryRecord->amount_before = $incrementResult['old'] ?? $beforeGameAmount;
            $playerDeliveryRecord->amount_after = $afterGameAmount;
            $playerDeliveryRecord->tradeno = $playerRechargeRecord->tradeno ?? '';
            $playerDeliveryRecord->remark = $playerRechargeRecord->remark ?? '';
            $playerDeliveryRecord->save();
            
            DB::commit();

            // 发送队列消息
            queueClient::send('game-depositAmount', [
                'player_id' => $player->id,
                'amount' => $playerRechargeRecord->point,
            ]);

            // 保存幂等性记录（覆盖占位）
            $response = jsonSuccessResponse('success');
            $this->saveIdempotent($requestId, $response, 'machine-recharge', $player->id);

            return $response;
        } catch (Exception $e) {
            DB::rollBack();
            // 释放占位（允许重试）
            $this->releaseIdempotent($requestId);
            Log::error($e->getMessage());
            return jsonFailResponse($e->getMessage());
        }
    }
    
    /**
     * 账务查询
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException
     * @throws \think\Exception
     */
    public function accountingList(Request $request): Response
    {
        checkPlayer();
        $data = $request->all();
        //早中晚时间段查询
        $validator = v::key('time',
            v::intVal()->notEmpty()->in([1, 2, 3, 4])->setName(trans('validation_error', [], 'message')))
            ->key('player_id', v::notEmpty()->setName(trans('validation_error', [], 'message')))
            ->key('date', v::notEmpty()->setName(trans('validation_error', [], 'message')));
        
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        
        $date = Carbon::createFromDate($data['date']);
        $startTime = $date->startOfDay()->toDateTimeString();
        $endTime = $date->endOfDay()->toDateTimeString();
        
        switch ($data['time']) {
            case '1':
                //早
                $startTime = $date->setTime(8, 0)->toDateTimeString();
                $endTime = $date->setTime(16, 0)->toDateTimeString();
                break;
            case '2':
                //中
                $startTime = $date->setTime(16, 0)->toDateTimeString();
                $endTime = $date->setTime(24, 0)->toDateTimeString();
                break;
            case '3':
                //晚上
                $startTime = $date->addDay()->startOfDay()->toDateTimeString();
                $endTime = $date->setTime(8, 0)->toDateTimeString();
                break;
        }
        
        $model = PlayerDeliveryRecord::query()->whereBetween('created_at', [$startTime, $endTime])
            ->where('player_id', $data['player_id']);
        
        $totalPutCount = (clone $model)->where('type',
            PlayerDeliveryRecord::TYPE_MACHINE)->withSum('recharge as total_point', 'point')->get()->sum('total_point');
        
        $totalPresentOut = (clone $model)->where('type', PlayerDeliveryRecord::TYPE_PRESENT_OUT)->sum('amount');
        $totalPresentIn = (clone $model)->where('type', PlayerDeliveryRecord::TYPE_PRESENT_IN)->sum('amount');
        //投钞增加下级汇总
        $totalScore = round($totalPutCount + $totalPresentIn - $totalPresentOut, 2);
        $totalPoint = (clone $model)->where('type',
            PlayerDeliveryRecord::TYPE_MACHINE)->selectRaw('sum(amount_after - amount_before) as point')->value('point') ?? 0;
        //查询用户流水
        $result = (clone $model)
            ->whereIn('type', [
                PlayerDeliveryRecord::TYPE_PRESENT_IN,
                PlayerDeliveryRecord::TYPE_PRESENT_OUT,
                PlayerDeliveryRecord::TYPE_MACHINE
            ])
            ->forPage($data['page'], 20)
            ->orderBy('id', 'desc')
            ->get();
        
        $list = [];
        
        /** @var PlayerDeliveryRecord $item */
        foreach ($result as $item) {
            switch ($item->type) {
                case PlayerDeliveryRecord::TYPE_PRESENT_IN:
                    $item->target = trans('machine.present_in', [], 'message');
                    $item->amount = '+' . $item->amount;
                    break;
                case PlayerDeliveryRecord::TYPE_PRESENT_OUT:
                    $item->target = trans('machine.present_out', [], 'message');
                    $item->amount = '-' . $item->amount;
                    break;
                case PlayerDeliveryRecord::TYPE_MACHINE:
                    $item->target = trans('target.machine_put_coins', [], 'message');
                    $item->amount = '+' . $item->amount;
                    break;
                default:
                    break;
            }
            $list[] = [
                'id' => $item->id,
                'amount' => $item->amount <= 0 ? $item->amount : '+' . $item->amount,
                'source' => $item->target,
                'point' => $item->amount_after - $item->amount_before,
                'amount_after' => $item->amount_after,
                'created_at' => date('Y-m-d H:i:s', strtotime($item->created_at)),
            ];
        }
        
        return jsonSuccessResponse(trans('success'),
            compact('totalPutCount', 'totalPresentIn', 'totalPresentOut', 'totalPoint', 'totalScore', 'list'));
        
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 取消机台保留
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|PushException
     * @throws Exception
     */
    public function machineKeepCancel(Request $request): Response
    {
        $player = checkPlayer();
        
        $data = $request->all();
        $validator = v::key('machine_id', v::intVal()->notEmpty()->setName(trans('machine_id', [], 'message')));
        
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        /** @var Machine $machine */
        $machine = Machine::find($data['machine_id']);
        $lang = locale() ?? 'zh_TW';
        $lang = Str::replace('_', '-', $lang);
        $services = MachineServices::createServices($machine, $lang);
        if (!$machine) {
            return jsonFailResponse(trans('machine_not_found', [], 'message'));
        }
        
        if ($machine->gaming_user_id == 0) {
            return jsonFailResponse(trans('no_open_point', [], 'message'));
        }
        
        if ($services->keeping == 0) {
            return jsonFailResponse(trans('machine_is_unkeeping', [], 'message'));
        }
        
        if ($machine->gaming_user_id != 0 && $machine->gaming_user_id != $player->id) {
            return jsonFailResponse(trans('no_open_point', [], 'message'));
        }
        
        $services->keeping = 0;
        $services->keeping_user_id = 0;
        $services->last_play_time = time();
        // 更新保留日志
        updateKeepingLog($machine->id, $player->id);
        
        sendSocketMessage('player-' . $machine->gaming_user_id . '-' . $machine->id, [
            'msg_type' => 'player_machine_keeping',
            'player_id' => $machine->gaming_user_id,
            'machine_id' => $machine->id,
            'keep_seconds' => $services->keep_seconds,
            'keeping' => $services->keeping
        ]);
        sendSocketMessage('player-' . $machine->gaming_user_id, [
            'msg_type' => 'player_machine_keeping',
            'player_id' => $machine->gaming_user_id,
            'machine_id' => $machine->id,
            'keep_seconds' => $services->keep_seconds,
            'keeping' => $services->keeping
        ]);
        
        return jsonSuccessResponse(trans('keeping_cancel_success', [], 'message'));
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 开增是否可以正常洗分(正常洗分：true , 不正常洗分： false)
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException
     * @throws \think\Exception
     * @throws Exception
     */
    public function ifKeyOutCondition(Request $request): Response
    {
        $player = checkPlayer();
        $data = $request->all();
        $validator = v::key('machine_id', v::intVal()->notEmpty()->setName(trans('machine_id', [], 'message')));
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        /** @var Machine $machine */
        $machine = Machine::find($data['machine_id']);
        if (!$machine) {
            throw new Exception(trans('machine_not_fount', [], 'message'));
        }
        $allowWashPoint = true;
        $giftPoint = getGivePoints($player->id, $machine->id);
        if ($giftPoint) {
            $allowWashPoint = false;
        }
        
        return jsonSuccessResponse('success', ['allow_wash_point' => $allowWashPoint]);
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 开增：机台开增规则列表
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|\think\Exception|PushException
     */
    public function showOpenPointRule(Request $request): Response
    {
        $player = checkPlayer();
        $data = $request->all();
        $validator = v::key('machine_id', v::intVal()->notEmpty()->setName(trans('machine_id', [], 'message')));
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        /** @var Machine $machine */
        $machine = Machine::with([
            'machineCategory',
            'machineCategory.machineCategoryGiveRule'
        ])->find($data['machine_id']);
        if (!$machine) {
            return jsonFailResponse(trans('machine_not_found', [], 'message'));
        }
        $machineCategoryGiveRule = $machine->machineCategory->machineCategoryGiveRule;
        if (empty($machineCategoryGiveRule)) {
            return jsonFailResponse(trans('give_rule_not_found', [], 'message'));
        }
        /** @var PlayerGiftRecord $PlayerGiftRecord */
        $PlayerGiftRecord = PlayerGiftRecord::where('player_id', $player->id)
            ->whereDate('created_at', date('Y-m-d'))
            ->get();
        $giveRuleArr = [];
        /** @var MachineCategoryGiveRule $ruleVal */
        foreach ($machineCategoryGiveRule as $ruleVal) {
            $usedNum = 0;
            /** @var PlayerGiftRecord $recodeVal */
            foreach ($PlayerGiftRecord as $recodeVal) {
                if ($ruleVal->id == $recodeVal->machine_category_give_rule_id) {
                    $usedNum++;
                }
            }
            $giveRuleArr[] = [
                'id' => $ruleVal->id,
                'machine_category_id' => $ruleVal->machine_category_id,
                'open_num' => $ruleVal->open_num,
                'give_num' => $ruleVal->give_num,
                'condition' => abs($ruleVal->condition),
                'status' => $ruleVal->status,
                'give_rule_num' => $ruleVal->give_rule_num,
                'used_give_rule_num' => $usedNum,
            ];
        }
        $givePoint = getGivePoints($player->id, $machine->id);
        
        return jsonSuccessResponse('success', [
            'machineCategory' => [
                'id' => $machine->machineCategory->id,
                'game_id' => $machine->machineCategory->game_id,
                'name' => $machine->machineCategory->name,
                'keep_minutes' => $machine->machineCategory->keep_minutes,
                'if_se_give_point' => (!empty($givePoint) && isset($givePoint['gift_point']) && $givePoint['gift_point'] > 0),
                'is_allow_client_give_point' => isAllowClientGivePoint($machine, $player),
                'machine_category_give_rule' => $giveRuleArr,
            ]
        ]);
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 获取腾讯云线路
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException
     * @throws Exception
     */
    public function getTencentMedia(Request $request): Response
    {
        $player = checkPlayer();
        $data = $request->all();
        $validator = v::key('media_id', v::intVal()->notEmpty()->setName(trans('media_id', [], 'message')));
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        $ip = request()->getRealIp();
        /** @var MachineMedia $machineMedia */
        $machineMedia = MachineMedia::query()->where('status', 1)->whereNull('deleted_at')->find($data['media_id']);
        if (empty($machineMedia)) {
            return jsonFailResponse(trans('media_not_found', [], 'message'));
        }
        /** @var MachineTencentPlay $machineTencentPlay */
        $machineTencentPlay = MachineTencentPlay::query()->where('status', 1)->first();
        if (empty($machineTencentPlay)) {
            return jsonFailResponse(trans('media_abnormal', [], 'message'));
        }
        /** @var MachineMediaPush $machineMediaPush */
        $machineMediaPush = MachineMediaPush::query()->where('media_id', $machineMedia->id)->first();
        try {
            if (empty($machineMediaPush)) {
                $mediaServer = new MediaServer($machineMedia->push_ip, $machineMedia->media_app);
                $pushData = getPushUrl($machineMedia->machine->code, $machineTencentPlay->push_domain,
                    $machineTencentPlay->push_key);
                $mediaServer->rtmpEndpoint($pushData['rtmp_url'], $pushData['endpoint_service_id'],
                    $machineMedia->stream_name);
                $pullUrl = [
                    'play_url' => getPullUrl($machineMedia->machine->code . '_' . $pushData['endpoint_service_id'],
                        $machineTencentPlay->id, $ip),
                    'license' => $machineTencentPlay->license,
                    'license_key' => $machineTencentPlay->license_key,
                ];
                $machineMediaPush = new MachineMediaPush();
                $machineMediaPush->machine_id = $machineMedia->machine_id;
                $machineMediaPush->media_id = $machineMedia->id;
                $machineMediaPush->machine_tencent_play_id = $machineTencentPlay->id;
                $machineMediaPush->endpoint_service_id = $pushData['endpoint_service_id'];
                $machineMediaPush->expiration_date = $pushData['expiration_date'];
                $machineMediaPush->machine_code = $machineMedia->machine->code;
                $machineMediaPush->created_at = date('Y-m-d H:i:s');
                $machineMediaPush->updated_at = date('Y-m-d H:i:s');
                $machineMediaPush->status = 1;
                $machineMediaPush->rtmp_url = $pushData['rtmp_url'];
                $machineMediaPush->save();
            } else {
                $mediaServer = new MediaServer($machineMediaPush->media->push_ip, $machineMediaPush->media->media_app);
                $streamInfo = $mediaServer->getBroadcasts($machineMedia->stream_name);
                $hasEndPoint = false;
                if (!empty($streamInfo['endPointList'])) {
                    foreach ($streamInfo['endPointList'] as $endPoint) {
                        if ($endPoint['endpointServiceId'] == $machineMediaPush->endpoint_service_id) {
                            $hasEndPoint = true;
                            break;
                        }
                    }
                }
                if ($machineMediaPush->status == 0 || !$hasEndPoint) {
                    $pushData = getPushUrl($machineMediaPush->media->machine->code,
                        $machineMediaPush->machineTencentPlay->push_domain,
                        $machineMediaPush->machineTencentPlay->push_key);
                    $mediaServer->rtmpEndpoint($pushData['rtmp_url'], $pushData['endpoint_service_id'],
                        $machineMediaPush->media->stream_name);
                    
                    $pullUrl = [
                        'play_url' => getPullUrl($machineMediaPush->machine_code . '_' . $pushData['endpoint_service_id'],
                            $machineMediaPush->machine_tencent_play_id, $ip),
                        'license' => $machineMediaPush->machineTencentPlay->license,
                        'license_key' => $machineMediaPush->machineTencentPlay->license_key,
                    ];
                    $machineMediaPush->status = 1;
                    $machineMediaPush->rtmp_url = $pushData['rtmp_url'];
                    $machineMediaPush->endpoint_service_id = $pushData['endpoint_service_id'];
                    $machineMediaPush->save();
                } else {
                    $pullUrl = [
                        'play_url' => getPullUrl($machineMediaPush->machine_code . '_' . $machineMediaPush->endpoint_service_id,
                            $machineMediaPush->machine_tencent_play_id, $ip),
                        'license' => $machineMediaPush->machineTencentPlay->license,
                        'license_key' => $machineMediaPush->machineTencentPlay->license_key,
                        'id' => $machineMediaPush->id,
                    ];
                }
            }
        } catch (Exception $e) {
            return jsonFailResponse(trans('media_abnormal', [], 'message') . $e->getMessage());
        }
        
        $playHistory = new PlayHistory();
        $playHistory->player_id = $player->id;
        $playHistory->department_id = $player->department_id;
        $playHistory->machine_id = $machineMedia->machine_id;
        $playHistory->ip = $ip ?? '';
        $playHistory->save();
        Cache::set('machine_play_' . $machineMediaPush->id, 1, 60);
        
        return jsonSuccessResponse('success', $pullUrl);
    }
    
    /**
     * 获取下级的数据汇总
     * @param $playerId
     * @param $startTime
     * @param $endTime
     * @return array
     */
    private function childrenTotal($playerId, $startTime, $endTime): array
    {
        $children = Player::query()->whereIn('recommend_id',
            $playerId)->whereNull('deleted_at')->pluck('id')->toArray();
        if (empty($children)) {
            return [];
        }
        
        $delivery = PlayerDeliveryRecord::query()
            ->join('player_recharge_record', 'player_recharge_record.id', '=', 'player_delivery_record.target_id')
            ->whereBetween('player_delivery_record.created_at', [$startTime, $endTime])
            ->whereIn('player_delivery_record.player_id', $children)->whereIn('player_delivery_record.type', [
                PlayerDeliveryRecord::TYPE_MACHINE,
                PlayerDeliveryRecord::TYPE_PRESENT_IN,
                PlayerDeliveryRecord::TYPE_PRESENT_OUT
            ])->select([
                'player_delivery_record.id',
                'player_delivery_record.amount',
                'player_recharge_record.point'
            ])->get()->toArray();
        
        return array_merge($delivery, $this->childrenTotal($children, $startTime, $endTime));
    }

    #[RateLimiter(limit: 5)]
    /**
     * 获取开分配置
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException
     */
    public function getOpenScoreSetting(Request $request): Response
    {
        $player = checkPlayer();

        // 获取当前玩家的开分配置
        $setting = OpenScoreSetting::query()
            ->where('admin_user_id', $player->store_admin_id)
            ->first();

        // 如果没有配置，返回默认配置
        if (!$setting) {
            $scores = ['score_1' => 100, 'score_2' => 500, 'score_3' => 1000, 'score_4' => 5000, 'score_5' => 10000, 'score_6' => 20000];
        } else {
            // 获取配置的开分选项
            $scores = [];
            for ($i = 1; $i <= 6; $i++) {
                $key = 'score_' . $i;
                if ($setting->$key > 0) {
                    $scores[$key] = $setting->$key;
                }
            }
        }

        return jsonSuccessResponse('success', [
            'scores' => $scores
        ]);
    }

    /**
     * 机台洗分逻辑（V2 优化版 - 仅用于钢珠机）
     *
     * 优化点：
     * 1. 业务校验在 gk_api 完成
     * 2. 机台指令发送统一在 gk_work 批量处理
     * 3. 数据库事务等待 gk_work 返回洗分数量后再处理
     *
     * @param Player $player
     * @param Machine $machine
     * @param string $action leave=弃台, down=下分
     * @param int $is_system
     * @param bool $hasLottery
     * @return bool|array
     * @throws Exception
     */
    private function machineWashV2(
        Player  $player,
        Machine $machine,
        string  $action = 'leave',
        int     $is_system = 0,
        bool    $hasLottery = false
    ): bool|array {
        // 添加分布式锁（与 machineOpenAnyV2 使用同一把锁，防止上下分并发）
        // 锁超时15秒 > gk_work超时5秒，防止锁提前释放导致并发冲突
        $actionLockerKey = 'machine_operation_lock_' . $machine->id;
        $lock = Locker::lock($actionLockerKey, 15, true);

        try {
            if (!$lock->acquire()) {
                throw new Exception(trans('machine_is_using_msg1', [], 'message'));
            }

            $washId = uniqid('wash_', true);
            $startTime = microtime(true);

            Log::channel('machine_operations')->info('[MachineWashV2] 开始洗分', [
                'wash_id' => $washId,
                'player_id' => $player->id,
                'machine_id' => $machine->id,
                'action' => $action,
                'timestamp' => date('Y-m-d H:i:s'),
            ]);

            $lang = locale() ?? 'zh_TW';
            $lang = \Illuminate\Support\Str::replace('_', '-', $lang);
            $services = MachineServices::createServices($machine, $lang);

            // ========== 阶段 1: 业务校验 ==========
            if ($services->last_point_at + 5 >= time()) {
                throw new Exception(trans('exception_msg.point_must_5seconds', [], 'message', $lang));
            }

            // 赠点检查
            $giftPoint = getGivePoints($player->id, $machine->id);
            Log::channel('machine_operations')->info('[MachineWashV2] 赠点信息', [
                'wash_id' => $washId,
                'gift_point' => $giftPoint,
            ]);

            // ========== 阶段 2: 调用 gk_work 执行机台硬件操作并获取洗分数量 ==========
            // 设置gk_work超时为5秒（< 锁超时15秒），确保锁不会提前释放
            $client = new MachineClient(null, 5);
            $machineContext = [
                'type' => $machine->type,
                'control_type' => $machine->control_type,
                'gaming' => $machine->gaming,
                'reward_status' => $services->reward_status,
                'point' => $services->point,
                'score' => $services->score,
                'turn' => $services->turn,
                'auto' => $services->auto ?? 0,
                'move_point' => $services->move_point ?? 0,
            ];

            $result = $client->washMachine(
                $machine->id,
                $player->id,
                $action,
                $machineContext,
                $washId,
                $lang
            );

            if (!$result['success']) {
                throw new Exception($result['message'] ?? trans('machine_wash_command_failed', [], 'message'));
            }

            // 从 gk_work 获取洗分数量
            $washPoint = $result['data']['wash_point'] ?? 0;

            Log::channel('machine_operations')->info('[MachineWashV2] gk_work 返回洗分数量', [
                'wash_id' => $washId,
                'wash_point' => $washPoint,
            ]);

            // ========== 阶段 3: 数据库事务处理 ==========
            // 计算钱包加款金额（提到 try 外，避免 catch 块访问未定义变量）
            $gameAmount = 0;
            if ($washPoint > 0) {
                $gameAmount = floor($washPoint * ($machine->odds_x ?? 1) / ($machine->odds_y ?? 1));
            }

            DB::beginTransaction();
            $walletAdded = false;  // 标记钱包是否已加款
            try {

                // 获取余额并加款
                $beforeGameAmount = \app\service\WalletService::getBalance($player->id, 1);
                if ($gameAmount > 0) {
                    $afterGameAmount = \app\service\WalletService::add($player->id, $gameAmount, 1);
                    $walletAdded = true;  // 标记已加款
                } else {
                    $afterGameAmount = $beforeGameAmount;
                }

                // 记录游戏局
                /** @var PlayerGameRecord $gameRecord */
                $gameRecord = PlayerGameRecord::query()->where('machine_id', $machine->id)
                    ->where('player_id', $player->id)
                    ->where('status', PlayerGameRecord::STATUS_START)
                    ->orderBy('created_at', 'desc')
                    ->first();

                // 防御性检查：理论上下分时必须有游戏记录
                if (empty($gameRecord)) {
                    Log::error('[MachineWashV2] 没有找到游戏记录，无法下分', [
                        'wash_id' => $washId,
                        'player_id' => $player->id,
                        'machine_id' => $machine->id,
                    ]);
                    throw new Exception(trans('no_game_record_found', [], 'message'));
                }

                // 更新游戏记录
                $gameRecord->wash_point = bcadd($gameRecord->wash_point, $washPoint, 2);
                $gameRecord->wash_amount = bcadd($gameRecord->wash_amount, $gameAmount, 2);
                $gameRecord->after_game_amount = $afterGameAmount;

                if ($action == 'leave') {
                    $gameRecord->status = PlayerGameRecord::STATUS_END;
                    $diff = bcsub($gameRecord->wash_amount, $gameRecord->open_amount, 2);
                    nationalPromoterSettlement([
                        ['player_id' => $player->id, 'bet' => 0, 'diff' => $diff]
                    ]);

                    if (!empty($player->recommend_id)) {
                        $recommendPromoter = Player::query()->find($player->recommend_id);
                        $gameRecord->national_damage_ratio = $recommendPromoter->national_promoter->level_list->damage_rebate_ratio ?? 0;
                    }
                }
                $gameRecord->save();

                // 创建游戏日志（现在 $gameRecord 一定不为 null）
                $playerGameLog = new PlayerGameLog();
                $playerGameLog->game_record_id = $gameRecord->id;
                $playerGameLog->player_id = $player->id;
                $playerGameLog->machine_id = $machine->id;
                $playerGameLog->turn_point = $washPoint;
                $playerGameLog->turn_money = $gameAmount;
                $playerGameLog->before_game_amount = $beforeGameAmount;
                $playerGameLog->after_game_amount = $afterGameAmount;
                $playerGameLog->bet_money = 0;
                $playerGameLog->operate = $action == 'leave' ? '弃台' : '下分';
                $playerGameLog->created_at = date('Y-m-d H:i:s');
                $playerGameLog->updated_at = date('Y-m-d H:i:s');
                $playerGameLog->save();

                // 更新机台状态
                if ($action == 'leave') {
                    $machine->gaming = 0;
                    $machine->gaming_user_id = 0;
                    $machine->is_use = 0;
                    $machine->last_wash_at = date('Y-m-d H:i:s');
                }
                $machine->save();

                // 写入金流明细
                createPlayerDeliveryRecord($player, $playerGameLog, $machine, $gameAmount, $beforeGameAmount, $afterGameAmount);

                // 清除赠点缓存
                if (!empty($giftPoint)) {
                    Cache::delete('gift_cache_' . $machine->id . '_' . $player->id);
                    Cache::delete('gift_cache_point_' . $machine->id . '_' . $player->id);
                }

                // 更新 Redis 缓存
                $services->last_point_at = time();
                $services->last_play_time = time();

                DB::commit();

                Log::channel('machine_operations')->info('[MachineWashV2] 洗分完成', [
                    'wash_id' => $washId,
                    'player_id' => $player->id,
                    'machine_id' => $machine->id,
                    'wash_point' => $washPoint,
                    'game_amount' => $gameAmount,
                    'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
                ]);

                // 发送 Socket 消息
                if ($action == 'leave') {
                    sendSocketMessage('player-' . $player->id . '-' . $machine->id, [
                        'msg_type' => 'machine_leave',
                        'machine_id' => $machine->id,
                    ]);
                    sendSocketMessage('player-' . $player->id, [
                        'msg_type' => 'machine_leave',
                        'machine_id' => $machine->id,
                    ]);
                }

                return true;

            } catch (\Exception $e) {
                DB::rollback();

                Log::error('[MachineWashV2] 数据库事务失败', [
                    'wash_id' => $washId,
                    'player_id' => $player->id,
                    'machine_id' => $machine->id,
                    'error' => $e->getMessage(),
                    'wallet_added' => $walletAdded,
                ]);

                // 只有在已加款的情况下才回滚钱包
                if ($walletAdded && $gameAmount > 0) {
                    try {
                        \app\service\WalletService::deduct($player->id, $gameAmount, 1);
                        Log::info('[MachineWashV2] Redis余额回滚成功', [
                            'wash_id' => $washId,
                            'refund_amount' => $gameAmount
                        ]);
                    } catch (\Exception $refundError) {
                        Log::critical('[MachineWashV2] Redis余额回滚失败，需人工介入', [
                            'wash_id' => $washId,
                            'player_id' => $player->id,
                            'machine_id' => $machine->id,
                            'refund_amount' => $gameAmount,
                            'error' => $refundError->getMessage()
                        ]);
                    }
                }

                throw $e;
            }

        } finally {
            // 添加异常保护，防止锁释放失败导致死锁
            try {
                if ($lock->isAcquired()) {
                    $lock->release();
                }
            } catch (\Exception $lockError) {
                Log::critical('[MachineWashV2] 锁释放失败，可能导致死锁', [
                    'machine_id' => $machine->id,
                    'lock_key' => $actionLockerKey,
                    'error' => $lockError->getMessage(),
                ]);
            }
        }
    }
}
