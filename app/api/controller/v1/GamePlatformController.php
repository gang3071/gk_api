<?php

namespace app\api\controller\v1;

use app\model\AdminConfig;
use app\model\ChannelPlatformReverseWater;
use app\model\Game;
use app\model\GameContent;
use app\model\GamePlatform;
use app\model\GameType;
use app\model\PlayerDeliveryRecord;
use app\model\PlayerDisabledGame;
use app\model\PlayerEnterGameRecord;
use app\model\PlayerGamePlatform;
use app\model\PlayerPlatformCash;
use app\model\PlayerWalletTransfer;
use app\model\StoreSetting;
use app\exception\GameException;
use app\exception\PlayerCheckException;
use app\service\game\GameServiceFactory;
use app\service\GameLotteryServices;
use app\service\GamePlatformProxyService;
use Exception;
use Illuminate\Support\Str;
use Respect\Validation\Exceptions\AllOfException;
use Respect\Validation\Validator as v;
use support\Db;
use support\Log;
use support\Request;
use support\Response;
use Webman\RateLimiter\Annotation\RateLimiter;

class GamePlatformController
{
    /** 排除  */
    protected $noNeedSign = ['walletTransferIN', 'enterGame'];
    
    #[RateLimiter(limit: 5)]
    /**
     * 游戏平台
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public function gamePlatformList(Request $request): Response
    {
        $player = checkPlayer();
        if (empty($player->channel->game_platform)) {
            return jsonFailResponse(trans('platform_no_permission', [], 'message'));
        }

        $data = $request->all();
        $validator = v::key('type', v::intVal()->setName(trans('type', [], 'message')), false)
            ->key('cate_id', v::intVal()->setName(trans('game_cate_id', [], 'message')), false)
            ->key('is_hot', v::intVal()->setName(trans('is_hot', [], 'message')), false)
            ->key('is_new', v::intVal()->setName(trans('is_new', [], 'message')), false)
            ->key('page', v::intVal()->setName(trans('page', [], 'message')), false)
            ->key('size', v::intVal()->setName(trans('size', [], 'message')), false)
            ->key('platform_id', v::intVal()->setName(trans('game_platform_id', [], 'message')), false)
            ->key('display_mode', v::intVal()->setName(trans('display_mode', [], 'message')), false);

        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }

        // 设置默认值
        $data['type'] = $data['type'] ?? 0;
        $data['page'] = $data['page'] ?? 1;
        $data['size'] = $data['size'] ?? 20;

        if ($data['type'] == 1 && $player->status_baccarat == 0) {
            return jsonFailResponse(trans('platform_no_permission', [], 'message'));
        }
        if ($data['type'] == 2 && $player->status_game_platform == 0) {
            return jsonFailResponse(trans('platform_no_permission', [], 'message'));
        }
        // 获取渠道开启的游戏平台ID列表
        $channelPlatformIds = json_decode($player->channel->game_platform, true);

        if (empty($channelPlatformIds)) {
            return jsonSuccessResponse('success', [
                'list' => [],
                'game_list' => [],
                'enter_game_record' => []
            ]);
        }

        // 获取玩家被禁用的游戏平台账号ID列表（status=0表示账号被禁用）
        $disabledPlatformIds = PlayerGamePlatform::query()
            ->where('player_id', $player->id)
            ->where('status', 0)
            ->pluck('platform_id')
            ->toArray();

        // 显示渠道的所有游戏平台，但排除被禁用的平台
        // 注意：玩家首次访问时可能没有PlayerGamePlatform记录，这是正常的
        $allowedPlatformIds = array_diff($channelPlatformIds, $disabledPlatformIds);

        if (empty($allowedPlatformIds)) {
            return jsonSuccessResponse('success', [
                'list' => [],
                'game_list' => [],
                'enter_game_record' => []
            ]);
        }

        $list = GamePlatform::query()
            ->select(['id', 'code', 'name', 'logo', 'cate_id', 'picture'])
            ->where('status', 1)
            ->whereIn('id', $allowedPlatformIds)

            ->when($data['type'] == 1, function ($query) {
                $query->whereRaw("JSON_CONTAINS(`cate_id`, '" . GameType::CATE_LIVE_VIDEO . "')");
            })
            ->when($data['type'] == 2, function ($query) {
                $query->whereRaw("JSON_CONTAINS(`cate_id`, '" . GameType::CATE_COMPUTER_GAME . "')");
            })
            ->orderBy('sort', 'desc')
            ->get();
        $gameData = [];
        $enterGameData = [];
        if (!empty($list) && $data['type'] == 2) {
            $lang = locale();
            $lang = Str::replace('_', '-', $lang);

            // 只对线下渠道应用游戏级别权限控制
            $disabledGameIds = [];
            if ($player->channel->is_offline == 1) {
                // 获取玩家被禁用的游戏ID列表
                $disabledGameIds = PlayerDisabledGame::query()
                    ->where('player_id', $player->id)
                    ->where('status', 1) // status=1 表示禁用生效
                    ->pluck('game_id')
                    ->toArray();
            }

            $gameList = Game::query()
                ->whereIn('platform_id', $allowedPlatformIds)
                ->when(!empty($disabledGameIds), function ($query) use ($disabledGameIds) {
                    // 如果设置了游戏级别权限（线下渠道），则排除被禁用的游戏
                    $query->whereNotIn('id', $disabledGameIds);
                })
                ->whereHas('gamePlatform', function ($query) use ($data) {
                    $query->where('status', 1);
                })
                ->when(!empty($data['is_hot']), function ($query) use ($data) {
                    $query->where('is_hot', 1);
                })
                ->when(!empty($data['is_new']), function ($query) use ($data) {
                    $query->where('is_new', 1);
                })
                ->when(!empty($data['cate_id']) && empty($data['is_hot']), function ($query) use ($data) {
                    $query->where('cate_id', $data['cate_id']);
                })
                ->when(!empty($data['platform_id']), function ($query) use ($data) {
                    $query->where('platform_id', $data['platform_id']);
                })
                ->when(!empty($data['display_mode']) && in_array($data['display_mode'], [Game::DISPLAY_MODE_LANDSCAPE, Game::DISPLAY_MODE_PORTRAIT]), function ($query) use ($data) {
                    // 根据展示类型过滤游戏
                    // DISPLAY_MODE_LANDSCAPE（横版）：查询 DISPLAY_MODE_LANDSCAPE 或 DISPLAY_MODE_ALL
                    // DISPLAY_MODE_PORTRAIT（竖版）：查询 DISPLAY_MODE_PORTRAIT 或 DISPLAY_MODE_ALL
                    // DISPLAY_MODE_ALL（全部）或未传参数：不过滤
                    $query->where(function ($q) use ($data) {
                        $q->where('display_mode', $data['display_mode'])
                          ->orWhere('display_mode', Game::DISPLAY_MODE_ALL); // 全部支持
                    });
                })
                ->where('status', 1)
                ->where('channel_hidden', 'not like', '%' . $player->channel->department_id . '%')
                ->orderBy('sort', 'desc')
                ->orderBy('id', 'desc')
                ->forPage($data['page'], $data['size'])
                ->get();
            /** @var Game $game */
            foreach ($gameList as $game) {
                /** @var GameContent $content */
                $content = $game->gameContent->where('lang', $lang)->first();
                $gameData[] = [
                    'id' => $game->id,
                    'cate_id' => $game->cate_id,
                    'is_new' => $game->is_new,
                    'is_hot' => $game->is_hot,
                    'platform_id' => $game->platform_id,
                    'game_content' => $content
                ];
            }
            $enterGameRecord = PlayerEnterGameRecord::query()
                ->select('game_id', DB::raw('MAX(id) as id'))
                ->where('player_id', $player->id)
                ->groupBy('game_id')
                ->orderBy('id', 'desc')
                ->limit(5)
                ->get();
            
            /** @var PlayerEnterGameRecord $playerEnterGameRecord */
            foreach ($enterGameRecord as $playerEnterGameRecord) {
                /** @var GameContent $gameContent */
                if (!empty($playerEnterGameRecord->game)) {
                    $gameContent = $playerEnterGameRecord->game->gameContent->where('lang', $lang ?? 'zh-CN')->first();
                    $enterGameData[] = [
                        'id' => $playerEnterGameRecord->game->id,
                        'cate_id' => $playerEnterGameRecord->game->cate_id,
                        'is_new' => $playerEnterGameRecord->game->is_new,
                        'is_hot' => $playerEnterGameRecord->game->is_hot,
                        'platform_id' => $playerEnterGameRecord->game->platform_id,
                        'game_content' => $gameContent
                    ];
                }
            }
        }
        if (!empty($list) && $data['type'] == 1) {
            $lang = locale();
            $lang = Str::replace('_', '-', $lang);
            /** @var GamePlatform $item */
            foreach ($list as $item) {
                $item->picture = json_decode($item->picture, true)[$lang]['picture'] ?? '';
            }
        }

        // 根据 enable_physical_machine 配置添加实体机台平台
        if ($data['type'] == 2) { // 仅在电子游戏类型时添加
            $enablePhysicalMachine = $this->checkEnablePhysicalMachine($player);

            if ($enablePhysicalMachine) {
                // 获取 web_name 和 web_logo
                $webName = AdminConfig::query()->where('name', 'web_name')->value('value') ?? '实体机台';
                $webLogo = AdminConfig::query()->where('name', 'web_logo')->value('value') ?? '';

                // 创建虚拟的实体机台平台对象（使用 stdClass 避免 fillable 问题）
                $physicalMachinePlatform = (object)[
                    'id' => 9999, // 使用一个特殊的ID，避免与真实平台冲突
                    'code' => 'physical_machine',
                    'name' => $webName,
                    'logo' => $webLogo,
                    'cate_id' => json_encode([GameType::CATE_COMPUTER_GAME]),
                    'picture' => '',
                    'display_mode' => GamePlatform::DISPLAY_MODE_ALL, // 实体机台支持全部展示模式
                    'has_lobby' => 0, // 实体机台不进入大厅
                ];

                // 添加到列表开头
                $list->prepend($physicalMachinePlatform);
            }
        }

        return jsonSuccessResponse('success', [
            'list' => $list,
            'game_list' => $gameData,
            'recent_games' => $enterGameData,
            'game_lottery_list' => $this->getLotteryPoolData(),
        ]);
    }

    #[RateLimiter(limit: 10)]
    /**
     * 获取游戏平台列表（分类返回电子和真人平台）
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public function getPlatformList(Request $request): Response
    {
        $player = checkPlayer();
        if (empty($player->channel->game_platform)) {
            return jsonFailResponse(trans('platform_no_permission', [], 'message'));
        }

        // 获取渠道开启的游戏平台ID列表
        $channelPlatformIds = json_decode($player->channel->game_platform, true);

        if (empty($channelPlatformIds)) {
            return jsonSuccessResponse('success', [
                'electron_platforms' => [],
                'live_platforms' => []
            ]);
        }

        // 获取玩家被禁用的游戏平台账号ID列表（status=0表示账号被禁用）
        $disabledPlatformIds = PlayerGamePlatform::query()
            ->where('player_id', $player->id)
            ->where('status', 0)
            ->pluck('platform_id')
            ->toArray();

        // 显示渠道的所有游戏平台，但排除被禁用的平台
        $allowedPlatformIds = array_diff($channelPlatformIds, $disabledPlatformIds);

        if (empty($allowedPlatformIds)) {
            return jsonSuccessResponse('success', [
                'electron_platforms' => [],
                'live_platforms' => []
            ]);
        }

        // 获取所有允许的平台
        $allPlatforms = GamePlatform::query()
            ->select(['id', 'code', 'name', 'logo', 'cate_id', 'picture', 'display_mode', 'has_lobby'])
            ->where('status', 1)
            ->whereIn('id', $allowedPlatformIds)
            ->orderBy('sort', 'desc')
            ->get();

        // 分类平台：电子游戏和真人游戏
        $electronPlatforms = collect();
        $livePlatforms = collect();
        $lang = locale();
        $lang = Str::replace('_', '-', $lang);

        foreach ($allPlatforms as $platform) {
            $cateIds = json_decode($platform->cate_id, true);

            // 检查是否包含电子游戏分类
            if (in_array(GameType::CATE_COMPUTER_GAME, $cateIds)) {
                // 检查电子游戏权限
                if ($player->status_game_platform == 1) {
                    $electronPlatforms->push($platform);
                }
            }

            // 检查是否包含真人游戏分类
            if (in_array(GameType::CATE_LIVE_VIDEO, $cateIds)) {
                // 检查真人游戏权限
                if ($player->status_baccarat == 1) {
                    // 处理真人游戏平台的图片
                    $platform->picture = json_decode($platform->picture, true)[$lang]['picture'] ?? '';
                    $livePlatforms->push($platform);
                }
            }
        }

        // 根据 enable_physical_machine 配置添加实体机台平台到电子游戏平台列表
        if ($player->status_game_platform == 1) {
            $enablePhysicalMachine = $this->checkEnablePhysicalMachine($player);

            if ($enablePhysicalMachine) {
                // 获取 web_name 和 web_logo
                $webName = AdminConfig::query()->where('name', 'web_name')->value('value') ?? '实体机台';
                $webLogo = AdminConfig::query()->where('name', 'web_logo')->value('value') ?? '';

                // 创建虚拟的实体机台平台对象（使用 stdClass 避免 fillable 问题）
                $physicalMachinePlatform = (object)[
                    'id' => 9999, // 使用一个特殊的ID，避免与真实平台冲突
                    'code' => 'physical_machine',
                    'name' => $webName,
                    'logo' => $webLogo,
                    'cate_id' => json_encode([GameType::CATE_COMPUTER_GAME]),
                    'picture' => '',
                    'display_mode' => GamePlatform::DISPLAY_MODE_PORTRAIT, // 实体机台支持全部展示模式
                    'has_lobby' => 1, // 实体机台不进入大厅
                ];

                // 添加到电子游戏平台列表开头
                $electronPlatforms->prepend($physicalMachinePlatform);
            }
        }

        return jsonSuccessResponse('success', [
            'electron_platforms' => $electronPlatforms->values(),
            'live_platforms' => $livePlatforms->values()
        ]);
    }

    #[RateLimiter(limit: 10)]
    /**
     * 获取电子游戏列表
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public function getElectronGameList(Request $request): Response
    {
        $player = checkPlayer();

        // 检查电子游戏权限
        if ($player->status_game_platform == 0) {
            return jsonFailResponse(trans('platform_no_permission', [], 'message'));
        }

        if (empty($player->channel->game_platform)) {
            return jsonFailResponse(trans('platform_no_permission', [], 'message'));
        }

        $data = $request->all();

        // 参数验证
        $validator = v::key('cate_id', v::intVal()->setName(trans('game_cate_id', [], 'message')), false)
            ->key('is_hot', v::intVal()->setName(trans('is_hot', [], 'message')), false)
            ->key('is_new', v::intVal()->setName(trans('is_new', [], 'message')), false)
            ->key('platform_id', v::intVal()->setName(trans('game_platform_id', [], 'message')), false)
            ->key('display_mode', v::intVal()->setName(trans('display_mode', [], 'message')), false)
            ->key('page', v::intVal()->setName(trans('page', [], 'message')), false)
            ->key('size', v::intVal()->setName(trans('size', [], 'message')), false);

        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }

        // 设置默认值
        $data['page'] = $data['page'] ?? 1;
        $data['size'] = $data['size'] ?? 20;

        // 获取渠道开启的游戏平台ID列表
        $channelPlatformIds = json_decode($player->channel->game_platform, true);

        if (empty($channelPlatformIds)) {
            return jsonSuccessResponse('success', [
                'game_list' => [],
                'recent_games' => [],
                'total' => 0
            ]);
        }

        // 获取玩家被禁用的游戏平台账号ID列表
        $disabledPlatformIds = PlayerGamePlatform::query()
            ->where('player_id', $player->id)
            ->where('status', 0)
            ->pluck('platform_id')
            ->toArray();

        // 计算允许的平台ID
        $allowedPlatformIds = array_diff($channelPlatformIds, $disabledPlatformIds);

        if (empty($allowedPlatformIds)) {
            return jsonSuccessResponse('success', [
                'game_list' => [],
                'recent_games' => [],
                'total' => 0
            ]);
        }

        // 只对线下渠道应用游戏级别权限控制
        $disabledGameIds = [];
        if ($player->channel->is_offline == 1) {
            $disabledGameIds = PlayerDisabledGame::query()
                ->where('player_id', $player->id)
                ->where('status', 1) // status=1 表示禁用生效
                ->pluck('game_id')
                ->toArray();
        }

        $lang = locale();
        $lang = Str::replace('_', '-', $lang);

        // 查询游戏列表
        $gameQuery = Game::query()
            ->whereIn('platform_id', $allowedPlatformIds)
            ->when(!empty($disabledGameIds), function ($query) use ($disabledGameIds) {
                $query->whereNotIn('id', $disabledGameIds);
            })
            ->whereHas('gamePlatform', function ($query) {
                $query->where('status', 1)
                    ->whereRaw("JSON_CONTAINS(`cate_id`, '" . GameType::CATE_COMPUTER_GAME . "')");
            })
            ->when(!empty($data['is_hot']), function ($query) {
                $query->where('is_hot', 1);
            })
            ->when(!empty($data['is_new']), function ($query) {
                $query->where('is_new', 1);
            })
            ->when(!empty($data['cate_id']) && empty($data['is_hot']), function ($query) use ($data) {
                $query->where('cate_id', $data['cate_id']);
            })
            ->when(!empty($data['platform_id']), function ($query) use ($data) {
                $query->where('platform_id', $data['platform_id']);
            })
            ->when(!empty($data['display_mode']) && in_array($data['display_mode'], [Game::DISPLAY_MODE_LANDSCAPE, Game::DISPLAY_MODE_PORTRAIT]), function ($query) use ($data) {
                $query->where(function ($q) use ($data) {
                    $q->where('display_mode', $data['display_mode'])
                      ->orWhere('display_mode', Game::DISPLAY_MODE_ALL);
                });
            })
            ->where('status', 1)
            ->where('channel_hidden', 'not like', '%' . $player->channel->department_id . '%')
            ->orderBy('sort', 'desc')
            ->orderBy('id', 'desc');

        // 分页获取游戏
        $gameList = $gameQuery->forPage($data['page'], $data['size'])->get();

        $gameData = [];
        /** @var Game $game */
        foreach ($gameList as $game) {
            /** @var GameContent $content */
            $content = $game->gameContent->where('lang', $lang)->first();
            $gameData[] = [
                'id' => $game->id,
                'cate_id' => $game->cate_id,
                'is_new' => $game->is_new,
                'is_hot' => $game->is_hot,
                'platform_id' => $game->platform_id,
                'display_mode' => $game->display_mode,
                'game_content' => $content
            ];
        }

        // 获取最近游戏记录
        $enterGameData = [];
        $enterGameRecord = PlayerEnterGameRecord::query()
            ->select('game_id', DB::raw('MAX(id) as id'))
            ->where('player_id', $player->id)
            ->groupBy('game_id')
            ->orderBy('id', 'desc')
            ->limit(5)
            ->get();

        /** @var PlayerEnterGameRecord $playerEnterGameRecord */
        foreach ($enterGameRecord as $playerEnterGameRecord) {
            if (!empty($playerEnterGameRecord->game)) {
                // 只返回电子游戏类型的最近游戏
                $gamePlatform = $playerEnterGameRecord->game->gamePlatform;
                if ($gamePlatform) {
                    $cateIds = json_decode($gamePlatform->cate_id, true);
                    if (in_array(GameType::CATE_COMPUTER_GAME, $cateIds)) {
                        /** @var GameContent $gameContent */
                        $gameContent = $playerEnterGameRecord->game->gameContent->where('lang', $lang)->first();
                        $enterGameData[] = [
                            'id' => $playerEnterGameRecord->game->id,
                            'cate_id' => $playerEnterGameRecord->game->cate_id,
                            'is_new' => $playerEnterGameRecord->game->is_new,
                            'is_hot' => $playerEnterGameRecord->game->is_hot,
                            'platform_id' => $playerEnterGameRecord->game->platform_id,
                            'display_mode' => $playerEnterGameRecord->game->display_mode,
                            'game_content' => $gameContent
                        ];
                    }
                }
            }
        }

        return jsonSuccessResponse('success', [
            'game_list' => $gameData,
            'recent_games' => $enterGameData,
            'page' => (int)$data['page'],
            'size' => (int)$data['size']
        ]);
    }

    /**
     * 检查是否启用实体机台
     * @param $player
     * @return bool
     */
    private function checkEnablePhysicalMachine($player): bool
    {
        // 默认启用
        $enablePhysicalMachine = true;

        // 获取玩家所属的代理或店家admin_user_id
        $adminUserId = null;

        // 优先使用店家绑定
        if (!empty($player->store_admin_id)) {
            $adminUserId = $player->store_admin_id;
        }
        // 其次使用代理绑定
        elseif (!empty($player->agent_admin_id)) {
            $adminUserId = $player->agent_admin_id;
        }

        // 如果找到了有效的店家/代理配置，获取配置值
        if ($adminUserId) {
            $physicalMachineSetting = StoreSetting::getSetting(
                'enable_physical_machine',
                $player->department_id,
                null,
                $adminUserId
            );
            if ($physicalMachineSetting && $physicalMachineSetting->status == 1) {
                $enablePhysicalMachine = (int)$physicalMachineSetting->num === 1;
            }
        }

        return $enablePhysicalMachine;
    }

    /**
     * 获取彩金池数据
     * @return array
     */
    private function getLotteryPoolData(): array
    {
        try {
            return  $this->formatGameLotteryPool(GameLotteryServices::getLotteryPool());
        } catch (\Throwable $e) {
            Log::error('获取彩金池数据失败: ' . $e->getMessage());
            return [
                'slot_amount' => [],
                'jack_amount' => [],
                'game_lottery_list' => [],
            ];
        }
    }

    /**
     * 格式化电子游戏彩金池数据
     * @param array $gameLotteryPool
     * @return array
     */
    private function formatGameLotteryPool($gameLotteryPool)
    {
        $formattedGamePool = [];

        if (empty($gameLotteryPool)) {
            return $formattedGamePool;
        }

        foreach ($gameLotteryPool as $lottery) {
            $formattedGamePool[] = [
                'id' => $lottery['id'],
                'name' => $lottery['name'],
                'amount' => number_format($lottery['amount'], 2, '.', ''),
            ];
        }

        return $formattedGamePool;
    }

    #[RateLimiter(limit: 5)]
    /**
     * 转点到电子游戏平台
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException
     * @throws Exception
     */
    public function walletTransferOut(Request $request): Response
    {
        // 转发到外网主机（零信任隧道）
        if ($proxyResponse = GamePlatformProxyService::proxyByMethod($request, 'walletTransferOut')) {
            return $proxyResponse;
        }

        $player = checkPlayer();
        $data = $request->all();
        $validator = v::key('game_platform_id',
            v::stringType()->notEmpty()->setName(trans('game_platform_id', [], 'message')))
            ->key('amount', v::floatVal()->notEmpty()->setName(trans('amount', [], 'message')));
        
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        /** @var GamePlatform $gamePlatform */
        $gamePlatform = GamePlatform::query()->find($data['game_platform_id']);
        if (empty($gamePlatform)) {
            return jsonFailResponse(trans('game_platform_not_found', [], 'message'));
        }
        if ($gamePlatform->status != 1) {
            return jsonFailResponse(trans('game_platform_disable', [], 'message'));
        }
        $amount = (float)$data['amount'];
        if ($amount > $player->machine_wallet->money) {
            return jsonFailResponse(trans('your_point_insufficient', [], 'message'));
        }
        $lang = locale();
        $lang = Str::replace('_', '-', $lang);
        $gameService = GameServiceFactory::createService(strtoupper($gamePlatform->code), $player);
        $balance = $gameService->getBalance(['lang' => $lang]);
        //驗證通過
        DB::beginTransaction();
        try {
            //玩家加點數
            /** @var PlayerPlatformCash $machineWallet */
            $machineWallet = PlayerPlatformCash::query()->where('platform_id',
                PlayerPlatformCash::PLATFORM_SELF)->where('player_id', $player->id)->lockForUpdate()->first();
            $beforeGameAmount = $machineWallet->money;
            $playerWalletTransfer = new PlayerWalletTransfer();
            $playerWalletTransfer->player_id = $player->id;
            $playerWalletTransfer->parent_player_id = $player->recommend_id ?? 0;
            $playerWalletTransfer->agent_player_id = $player->recommend_promoter->recommend_id ?? 0;
            $playerWalletTransfer->platform_id = $gamePlatform->id;
            $playerWalletTransfer->department_id = $player->department_id;
            $playerWalletTransfer->type = PlayerWalletTransfer::TYPE_OUT;
            $playerWalletTransfer->amount = abs($amount);
            $playerWalletTransfer->game_amount = $balance;
            $playerWalletTransfer->player_amount = $machineWallet->money;
            $playerWalletTransfer->tradeno = createOrderNo();
            $playerWalletTransfer->platform_no = $gameService->depositAmount([
                'amount' => $amount,
                'order_no' => $playerWalletTransfer->tradeno,
                'lang' => $lang,
            ]);
            $playerWalletTransfer->save();
            // 更新玩家统计
            $machineWallet->money = bcsub($machineWallet->money, $playerWalletTransfer->amount, 2);
            $machineWallet->save();
            
            $playerDeliveryRecord = new PlayerDeliveryRecord;
            $playerDeliveryRecord->player_id = $player->id;
            $playerDeliveryRecord->department_id = $player->department_id;
            $playerDeliveryRecord->target = $playerWalletTransfer->getTable();
            $playerDeliveryRecord->target_id = $playerWalletTransfer->id;
            $playerDeliveryRecord->platform_id = $gamePlatform->id;
            $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_GAME_PLATFORM_OUT;
            $playerDeliveryRecord->source = 'wallet_transfer_out';
            $playerDeliveryRecord->amount = $playerWalletTransfer->amount;
            $playerDeliveryRecord->amount_before = $beforeGameAmount;
            $playerDeliveryRecord->amount_after = $machineWallet->money;
            $playerDeliveryRecord->tradeno = $target->tradeno ?? '';
            $playerDeliveryRecord->remark = $target->remark ?? '';
            $playerDeliveryRecord->user_id = 0;
            $playerDeliveryRecord->user_name = '';
            $playerDeliveryRecord->save();
            
            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            return jsonFailResponse($e->getMessage() ?? trans('system_error', [], 'message'));
        }
        
        return jsonSuccessResponse('success');
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 获取电子游戏平台余额
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public function getBalance(Request $request): Response
    {
        // 转发到外网主机（零信任隧道）
        if ($proxyResponse = GamePlatformProxyService::proxyByMethod($request, 'getBalance')) {
            return $proxyResponse;
        }

        $player = checkPlayer();
        $data = $request->all();
        $validator = v::key('game_platform_id',
            v::stringType()->notEmpty()->setName(trans('game_platform_id', [], 'message')));
        
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        /** @var GamePlatform $gamePlatform */
        $gamePlatform = GamePlatform::query()->find($data['game_platform_id']);
        if (empty($gamePlatform)) {
            return jsonFailResponse(trans('game_platform_not_found', [], 'message'));
        }
        if ($gamePlatform->status != 1) {
            return jsonFailResponse(trans('game_platform_disable', [], 'message'));
        }
        $lang = locale();
        $lang = Str::replace('_', '-', $lang);
        try {
            $balance = GameServiceFactory::createService(strtoupper($gamePlatform->code), $player)->getBalance([
                'lang' => $lang
            ]);
        } catch (Exception $e) {
            return jsonFailResponse($e->getMessage() ?? trans('system_error', [], 'message'));
        }
        
        return jsonSuccessResponse('success', ['balance' => $balance]);
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 获取用户钱包
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public function getWallet(): Response
    {
        // 转发到外网主机（零信任隧道）
        if ($proxyResponse = GamePlatformProxyService::proxyByMethod(request(), 'getWallet')) {
            return $proxyResponse;
        }

        $player = checkPlayer();
        $lang = locale();
        $lang = Str::replace('_', '-', $lang);
        
        $allBalance = 0;
        if (empty($player->channel->game_platform)) {
            return jsonSuccessResponse('success', ['list' => [], 'all_balance' => $allBalance]);
        }
        $gamePlatform = json_decode($player->channel->game_platform, true);
        try {
            $data = PlayerGamePlatform::query()
                ->whereIn('platform_id', $gamePlatform)
                ->where('player_id', $player->id)
                ->get();
            /** @var PlayerGamePlatform $item */
            foreach ($data as $item) {
                $balance = GameServiceFactory::createService(strtoupper($item->gamePlatform->code),
                    $item->player)->getBalance([
                    'lang' => $lang
                ]);
                $list[] = [
                    'id' => $item->id,
                    'logo' => $item->gamePlatform->logo,
                    'name' => $item->gamePlatform->name,
                    'code' => $item->gamePlatform->code,
                    'platform_id' => $item->gamePlatform->id,
                    'balance' => $balance,
                ];
                
                $allBalance += $balance;
            }
        } catch (Exception $e) {
            return jsonFailResponse($e->getMessage() ?? trans('system_error', [], 'message'));
        }
        
        return jsonSuccessResponse('success', ['list' => $list ?? [], 'all_balance' => $allBalance]);
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 全部转出
     * @return Response
     * @throws PlayerCheckException
     */
    public function withdrawAmountAll(): Response
    {
        // 转发到外网主机（零信任隧道）
        if ($proxyResponse = GamePlatformProxyService::proxyByMethod(request(), 'withdrawAmountAll')) {
            return $proxyResponse;
        }

        $player = checkPlayer();
        if (empty($player->channel->game_platform)) {
            return jsonFailResponse(trans('game_platform_not_found', [], 'message'));
        }
        $gamePlatform = json_decode($player->channel->game_platform, true);
        $playerGamePlatformList = PlayerGamePlatform::query()->whereIn('platform_id', $gamePlatform)->where('player_id',
            $player->id)->get();
        if (empty($playerGamePlatformList)) {
            return jsonFailResponse(trans('game_platform_not_found', [], 'message'));
        }
        
        /** @var PlayerGamePlatform $playerGamePlatform */
        foreach ($playerGamePlatformList as $playerGamePlatform) {
            if ($playerGamePlatform->gamePlatform->status != 1) {
                continue;
            }
            $lang = locale();
            $lang = Str::replace('_', '-', $lang);
            try {
                $gameService = GameServiceFactory::createService(strtoupper($playerGamePlatform->gamePlatform->code),
                    $player);
            } catch (\Exception $e) {
                return jsonFailResponse($e->getMessage(), [], 'message');
            }
            $amount = $gameService->getBalance(['lang' => $lang]);
            if ($amount > 0) {
                DB::beginTransaction();
                try {
                    //玩家加點數
                    /** @var PlayerPlatformCash $machineWallet */
                    $machineWallet = PlayerPlatformCash::query()->where('platform_id',
                        PlayerPlatformCash::PLATFORM_SELF)->where('player_id', $player->id)->lockForUpdate()->first();
                    $gamePlatform = $playerGamePlatform->gamePlatform;
                    $playerWalletTransfer = new PlayerWalletTransfer();
                    $playerWalletTransfer->player_id = $player->id;
                    $playerWalletTransfer->parent_player_id = $player->recommend_id ?? 0;
                    $playerWalletTransfer->agent_player_id = $player->recommend_promoter->recommend_id ?? 0;
                    $playerWalletTransfer->platform_id = $gamePlatform->id;
                    $playerWalletTransfer->department_id = $player->department_id;
                    $playerWalletTransfer->type = PlayerWalletTransfer::TYPE_IN;
                    $playerWalletTransfer->game_amount = $amount;
                    $playerWalletTransfer->player_amount = $machineWallet->money;
                    $playerWalletTransfer->tradeno = createOrderNo();
                    $result = $gameService->withdrawAmount([
                        'amount' => $amount,
                        'order_no' => $playerWalletTransfer->tradeno,
                        'lang' => $lang,
                        'take_all' => 'true',
                    ]);
                    $playerWalletTransfer->platform_no = $result['order_id'];
                    $playerWalletTransfer->amount = $result['amount'];
                    $beforeGameAmount = $player->machine_wallet->money;
                    // 更新玩家统计
                    $machineWallet->money = bcadd($machineWallet->money, $playerWalletTransfer->amount, 2);
                    $machineWallet->save();
                    $playerWalletTransfer->save();
                    
                    $playerDeliveryRecord = new PlayerDeliveryRecord;
                    $playerDeliveryRecord->player_id = $player->id;
                    $playerDeliveryRecord->department_id = $player->department_id;
                    $playerDeliveryRecord->target = $playerWalletTransfer->getTable();
                    $playerDeliveryRecord->target_id = $playerWalletTransfer->id;
                    $playerDeliveryRecord->platform_id = $gamePlatform->id;
                    $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_GAME_PLATFORM_IN;
                    $playerDeliveryRecord->source = 'wallet_transfer_in';
                    $playerDeliveryRecord->amount = $playerWalletTransfer->amount;
                    $playerDeliveryRecord->amount_before = $beforeGameAmount;
                    $playerDeliveryRecord->amount_after = $machineWallet->money;
                    $playerDeliveryRecord->tradeno = $target->tradeno ?? '';
                    $playerDeliveryRecord->remark = $target->remark ?? '';
                    $playerDeliveryRecord->user_id = $player->id;
                    $playerDeliveryRecord->user_name = $player->name;
                    $playerDeliveryRecord->save();
                    
                    DB::commit();
                } catch (\Exception $e) {
                    DB::rollBack();
                    return jsonFailResponse($e->getMessage());
                }
            }
        }
        
        return jsonSuccessResponse('success');
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 电子游戏平台转出
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException
     * @throws Exception
     */
    public function walletTransferIN(Request $request): Response
    {
        // 转发到外网主机（零信任隧道）
        if ($proxyResponse = GamePlatformProxyService::proxyByMethod($request, 'walletTransferIN')) {
            return $proxyResponse;
        }

        $player = checkPlayer();
        $data = $request->all();
        $validator = v::key('game_platform_id',
            v::stringType()->notEmpty()->setName(trans('game_platform_id', [], 'message')))
            ->key('take_all', v::in(['false', 'true'])->notEmpty()->setName(trans('take_all', [], 'message')))
            ->key('amount', v::floatVal()->setName(trans('amount', [], 'message')));
        
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        /** @var GamePlatform $gamePlatform */
        $gamePlatform = GamePlatform::query()->find($data['game_platform_id']);
        if (empty($gamePlatform)) {
            return jsonFailResponse(trans('game_platform_not_found', [], 'message'));
        }
        if ($gamePlatform->status != 1) {
            return jsonFailResponse(trans('game_platform_disable', [], 'message'));
        }
        $amount = $data['amount'] ?? 0;
        $lang = locale();
        $lang = Str::replace('_', '-', $lang);
        $gameService = GameServiceFactory::createService(strtoupper($gamePlatform->code), $player);
        $balance = $gameService->getBalance(['lang' => $lang]);
        if ($data['take_all'] == 'false' && $amount > $balance) {
            return jsonFailResponse(trans('insufficient_wallet_balance', [], 'message'));
        }
        if ($data['take_all'] == 'true') {
            if ($balance <= 0) {
                return jsonFailResponse(trans('insufficient_wallet_balance', [], 'message'));
            }
            $amount = $balance;
        }
        DB::beginTransaction();
        try {
            //玩家加點數
            /** @var PlayerPlatformCash $machineWallet */
            $machineWallet = PlayerPlatformCash::query()->where('platform_id',
                PlayerPlatformCash::PLATFORM_SELF)->where('player_id', $player->id)->lockForUpdate()->first();
            $playerWalletTransfer = new PlayerWalletTransfer();
            $playerWalletTransfer->player_id = $player->id;
            $playerWalletTransfer->parent_player_id = $player->recommend_id ?? 0;
            $playerWalletTransfer->agent_player_id = $player->recommend_promoter->recommend_id ?? 0;
            $playerWalletTransfer->platform_id = $gamePlatform->id;
            $playerWalletTransfer->department_id = $player->department_id;
            $playerWalletTransfer->type = PlayerWalletTransfer::TYPE_IN;
            $playerWalletTransfer->game_amount = $balance;
            $playerWalletTransfer->player_amount = $machineWallet->money;
            $playerWalletTransfer->tradeno = createOrderNo();
            $result = $gameService->withdrawAmount([
                'amount' => $amount,
                'order_no' => $playerWalletTransfer->tradeno,
                'lang' => $lang,
                'take_all' => $data['take_all'],
            ]);
            $playerWalletTransfer->platform_no = $result['order_id'];
            $playerWalletTransfer->amount = $result['amount'];
            $beforeGameAmount = $player->machine_wallet->money;
            // 更新玩家统计
            $machineWallet->money = bcadd($machineWallet->money, $playerWalletTransfer->amount, 2);
            $machineWallet->save();
            $playerWalletTransfer->save();
            
            $playerDeliveryRecord = new PlayerDeliveryRecord;
            $playerDeliveryRecord->player_id = $player->id;
            $playerDeliveryRecord->department_id = $player->department_id;
            $playerDeliveryRecord->target = $playerWalletTransfer->getTable();
            $playerDeliveryRecord->target_id = $playerWalletTransfer->id;
            $playerDeliveryRecord->platform_id = $gamePlatform->id;
            $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_GAME_PLATFORM_IN;
            $playerDeliveryRecord->source = 'wallet_transfer_in';
            $playerDeliveryRecord->amount = $playerWalletTransfer->amount;
            $playerDeliveryRecord->amount_before = $beforeGameAmount;
            $playerDeliveryRecord->amount_after = $machineWallet->money;
            $playerDeliveryRecord->tradeno = $target->tradeno ?? '';
            $playerDeliveryRecord->remark = $target->remark ?? '';
            $playerDeliveryRecord->user_id = 0;
            $playerDeliveryRecord->user_name = '';
            $playerDeliveryRecord->save();
            
            DB::commit();
        } catch (Exception|GameException $e) {
            DB::rollBack();
            return jsonFailResponse($e->getMessage() ?? trans('system_error', [], 'message'));
        }
        
        return jsonSuccessResponse('success');
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 进入游戏大厅
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException
     */
    public function lobbyLogin(Request $request): Response
    {
        // 转发到外网主机（零信任隧道）
        if ($proxyResponse = GamePlatformProxyService::proxyByMethod($request, 'lobbyLogin')) {
            return $proxyResponse;
        }

        $player = checkPlayer();
        $data = $request->all();
        $validator = v::key('game_platform_id',
            v::stringType()->notEmpty()->setName(trans('game_platform_id', [], 'message')));
        
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        /** @var GamePlatform $gamePlatform */
        $gamePlatform = GamePlatform::query()->find($data['game_platform_id']);
        if (empty($gamePlatform)) {
            return jsonFailResponse(trans('game_platform_not_found', [], 'message'));
        }
        if ($gamePlatform->status != 1) {
            return jsonFailResponse(trans('game_platform_disable', [], 'message'));
        }
        if ($player->status_baccarat == 0) {
            return jsonFailResponse(trans('game_platform_not_enabled', [], 'message'));
        }
        try {
            $player->machine_wallet()->lockForUpdate()->first();
            $lang = locale();
            $lang = Str::replace('_', '-', $lang);
            $gameService = GameServiceFactory::createService(strtoupper($gamePlatform->code), $player);
            $res = $gameService->lobbyLogin(['lang' => $lang]);
        } catch (Exception $e) {
            return jsonFailResponse($e->getMessage() ?? trans('system_error', [], 'message'));
        }
        
        // $amount = $player->machine_wallet->money;
        // if ($amount > 0) {
        //     DB::beginTransaction();
        //     //驗證通過
        //     try {
        //         //玩家加點數
        //         /** @var PlayerPlatformCash $machineWallet */
        //         $machineWallet = PlayerPlatformCash::query()->where('platform_id',
        //             PlayerPlatformCash::PLATFORM_SELF)->where('player_id', $player->id)->lockForUpdate()->first();
        //         $balance = $gameService->getBalance(['lang' => $lang]);
        //         //驗證通過
        //         $playerWalletTransfer = new PlayerWalletTransfer();
        //         $playerWalletTransfer->player_id = $player->id;
        //         $playerWalletTransfer->parent_player_id = $player->recommend_id ?? 0;
        //         $playerWalletTransfer->agent_player_id = $player->recommend_promoter->recommend_id ?? 0;
        //         $playerWalletTransfer->platform_id = $gamePlatform->id;
        //         $playerWalletTransfer->department_id = $player->department_id;
        //         $playerWalletTransfer->type = PlayerWalletTransfer::TYPE_OUT;
        //         $playerWalletTransfer->amount = abs($amount);
        //         $playerWalletTransfer->game_amount = $balance;
        //         $playerWalletTransfer->player_amount = $machineWallet->money;
        //         $playerWalletTransfer->tradeno = createOrderNo();
        //         $playerWalletTransfer->platform_no = $gameService->depositAmount([
        //             'amount' => $amount,
        //             'order_no' => $playerWalletTransfer->tradeno,
        //             'lang' => $lang,
        //         ]);
        //         $playerWalletTransfer->save();
        //         $beforeGameAmount = $machineWallet->money;
        //         // 更新玩家统计
        //         $machineWallet->money = bcsub($machineWallet->money, $playerWalletTransfer->amount, 2);
        //         $machineWallet->save();

        //         $playerDeliveryRecord = new PlayerDeliveryRecord;
        //         $playerDeliveryRecord->player_id = $player->id;
        //         $playerDeliveryRecord->department_id = $player->department_id;
        //         $playerDeliveryRecord->target = $playerWalletTransfer->getTable();
        //         $playerDeliveryRecord->target_id = $playerWalletTransfer->id;
        //         $playerDeliveryRecord->platform_id = $gamePlatform->id;
        //         $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_GAME_PLATFORM_OUT;
        //         $playerDeliveryRecord->source = 'wallet_transfer_out';
        //         $playerDeliveryRecord->amount = $playerWalletTransfer->amount;
        //         $playerDeliveryRecord->amount_before = $beforeGameAmount;
        //         $playerDeliveryRecord->amount_after = $machineWallet->money;
        //         $playerDeliveryRecord->tradeno = $target->tradeno ?? '';
        //         $playerDeliveryRecord->remark = $target->remark ?? '';
        //         $playerDeliveryRecord->user_id = 0;
        //         $playerDeliveryRecord->user_name = '';
        //         $playerDeliveryRecord->save();

        //         DB::commit();
        //     } catch (Exception $e) {
        //         DB::rollBack();
        //         return jsonFailResponse($e->getMessage() ?? trans('system_error', [], 'message'));
        //     }
        // }
        
        return jsonSuccessResponse('success', ['lobby_url' => $res]);
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 快速电子游戏平台转出
     * @return Response
     * @throws PlayerCheckException
     * @throws Exception
     */
    public function fastTransferAllIN(): Response
    {
        // 转发到外网主机（零信任隧道）
        if ($proxyResponse = GamePlatformProxyService::proxyByMethod(request(), 'fastTransferAllIN')) {
            return $proxyResponse;
        }

        $player = checkPlayer();
        $playerGamePlatform = PlayerGamePlatform::query()->where('player_id', $player->id)->get();
        if (empty($playerGamePlatform)) {
            return jsonSuccessResponse('success');
        }
        $lang = locale();
        $lang = Str::replace('_', '-', $lang);
        /** @var PlayerGamePlatform $item */
        foreach ($playerGamePlatform as $item) {
            try {
                $gameService = GameServiceFactory::createService(strtoupper($item->gamePlatform->code), $player);
                $balance = $gameService->getBalance(['lang' => $lang]);
            } catch (Exception) {
                continue;
            }
            if ($balance > 0) {
                DB::beginTransaction();
                try {
                    //玩家加點數
                    /** @var PlayerPlatformCash $machineWallet */
                    $machineWallet = PlayerPlatformCash::query()->where('platform_id',
                        PlayerPlatformCash::PLATFORM_SELF)->where('player_id', $player->id)->lockForUpdate()->first();
                    $playerWalletTransfer = new PlayerWalletTransfer();
                    $playerWalletTransfer->player_id = $player->id;
                    $playerWalletTransfer->parent_player_id = $player->recommend_id ?? 0;
                    $playerWalletTransfer->agent_player_id = $player->recommend_promoter->recommend_id ?? 0;
                    $playerWalletTransfer->platform_id = $item->platform_id;
                    $playerWalletTransfer->department_id = $player->department_id;
                    $playerWalletTransfer->type = PlayerWalletTransfer::TYPE_IN;
                    $playerWalletTransfer->game_amount = $balance;
                    $playerWalletTransfer->player_amount = $machineWallet->money;
                    $playerWalletTransfer->tradeno = createOrderNo();
                    $result = $gameService->withdrawAmount([
                        'amount' => $balance,
                        'order_no' => $playerWalletTransfer->tradeno,
                        'lang' => $lang,
                        'take_all' => 'true',
                    ]);
                    $playerWalletTransfer->platform_no = $result['order_id'];
                    $playerWalletTransfer->amount = $result['amount'];
                    $playerWalletTransfer->save();
                    $beforeGameAmount = $machineWallet->money;
                    // 更新玩家统计
                    $machineWallet->money = bcadd($machineWallet->money, $playerWalletTransfer->amount, 2);
                    $machineWallet->save();
                    $playerDeliveryRecord = new PlayerDeliveryRecord;
                    $playerDeliveryRecord->player_id = $player->id;
                    $playerDeliveryRecord->department_id = $player->department_id;
                    $playerDeliveryRecord->target = $playerWalletTransfer->getTable();
                    $playerDeliveryRecord->target_id = $playerWalletTransfer->id;
                    $playerDeliveryRecord->platform_id = $item->platform_id;
                    $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_GAME_PLATFORM_IN;
                    $playerDeliveryRecord->source = 'wallet_transfer_in';
                    $playerDeliveryRecord->amount = $playerWalletTransfer->amount;
                    $playerDeliveryRecord->amount_before = $beforeGameAmount;
                    $playerDeliveryRecord->amount_after = $machineWallet->money;
                    $playerDeliveryRecord->tradeno = $target->tradeno ?? '';
                    $playerDeliveryRecord->remark = $target->remark ?? '';
                    $playerDeliveryRecord->user_id = 0;
                    $playerDeliveryRecord->user_name = '';
                    $playerDeliveryRecord->save();
                    DB::commit();
                } catch (Exception) {
                    DB::rollBack();
                    continue;
                }
            }
        }
        
        return jsonSuccessResponse('success');
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 进入游戏
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException
     */
    public function enterGame(Request $request): Response
    {
        // 转发到外网主机（零信任隧道）
        if ($proxyResponse = GamePlatformProxyService::proxyByMethod($request, 'enterGame')) {
            return $proxyResponse;
        }

        $player = checkPlayer();
        $data = $request->all();
        $validator = v::key('game_id',
            v::stringType()->notEmpty()->setName(trans('game_id', [], 'message')));

        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        /** @var Game $game */
        $game = Game::query()
            ->where('id', $data['game_id'])
            ->first();
        if (empty($game)) {
            return jsonFailResponse(trans('game_not_found', [], 'message'));
        }
        if ($game->status == 0) {
            return jsonFailResponse(trans('game_has_disable', [], 'message'));
        }
        if (empty($game->gamePlatform)) {
            return jsonFailResponse(trans('game_platform_not_found', [], 'message'));
        }
        if ($game->gamePlatform->status == 0) {
            return jsonFailResponse(trans('game_platform_has_disable', [], 'message'));
        }
        if (empty($player->channel->game_platform) || !in_array($game->platform_id,
                json_decode($player->channel->game_platform)) || $player->status_game_platform == 0) {
            return jsonFailResponse(trans('game_platform_not_enabled', [], 'message'));
        }

        try {
            $lang = locale();
            $lang = Str::replace('_', '-', $lang);
            $playerEnterGameRecord = new PlayerEnterGameRecord();
            $playerEnterGameRecord->player_id = $player->id;
            $playerEnterGameRecord->department_id = $player->id;
            $playerEnterGameRecord->game_id = $game->id;
            $playerEnterGameRecord->save();
            $gameService = GameServiceFactory::createService(strtoupper($game->gamePlatform->code), $player);
            $res = $gameService->gameLogin($game, $lang);
        } catch (Exception $e) {
            return jsonFailResponse($e->getMessage() ?? trans('system_error', [], 'message'));
        }
        
        return jsonSuccessResponse('success', ['url' => $res, 'display_mode' => $game->display_mode]);
    }
    
    /**
     * 平台反水设置列表
     * @return Response
     * @throws PlayerCheckException
     */
    public function reverseWaterSetting(): Response
    {
        $player = checkPlayer();
        $list = ChannelPlatformReverseWater::query()
            ->where('department_id', $player->department_id)
            ->with([
                'platform:id,name,logo',
                'setting' => function ($query) {
                    $query->orderBy('point');
                }
            ])
            ->get()?->toArray();

        return jsonSuccessResponse('success', $list);
    }

    #[RateLimiter(limit: 5)]
    /**
     * 热门游戏列表
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public function hotGameList(Request $request): Response
    {
        $player = checkPlayer();

        // 检查玩家是否有电子游戏权限
        if (empty($player->channel->game_platform) || $player->status_game_platform == 0) {
            return jsonSuccessResponse('success', ['list' => []]);
        }

        $data = $request->all();
        $validator = v::key('size', v::intVal()->setName(trans('size', [], 'message')), false)
            ->key('page', v::intVal()->setName(trans('page', [], 'message')), false);

        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }

        // 获取渠道开启的游戏平台ID列表
        $channelPlatformIds = json_decode($player->channel->game_platform, true);

        if (empty($channelPlatformIds)) {
            return jsonSuccessResponse('success', ['list' => []]);
        }

        // 获取玩家被禁用的游戏平台账号ID列表
        $disabledPlatformIds = PlayerGamePlatform::query()
            ->where('player_id', $player->id)
            ->where('status', 0)
            ->pluck('platform_id')
            ->toArray();

        // 排除被禁用的平台
        $allowedPlatformIds = array_diff($channelPlatformIds, $disabledPlatformIds);

        if (empty($allowedPlatformIds)) {
            return jsonSuccessResponse('success', ['list' => []]);
        }

        $lang = locale();
        $lang = Str::replace('_', '-', $lang);

        // 只对线下渠道应用游戏级别权限控制
        $disabledGameIds = [];
        if ($player->channel->is_offline == 1) {
            // 获取玩家被禁用的游戏ID列表
            $disabledGameIds = PlayerDisabledGame::query()
                ->where('player_id', $player->id)
                ->where('status', 1) // status=1 表示禁用生效
                ->pluck('game_id')
                ->toArray();
        }

        $page = $data['page'] ?? 1;
        $size = $data['size'] ?? 20;

        // 查询热门游戏
        $gameList = Game::query()
            ->whereIn('platform_id', $allowedPlatformIds)
            ->when(!empty($disabledGameIds), function ($query) use ($disabledGameIds) {
                // 如果设置了游戏级别权限（线下渠道），则排除被禁用的游戏
                $query->whereNotIn('id', $disabledGameIds);
            })
            ->whereHas('gamePlatform', function ($query) {
                $query->where('status', 1)
                    ->whereRaw("JSON_CONTAINS(`cate_id`, '" . GameType::CATE_COMPUTER_GAME . "')");
            })
            ->where('is_hot', 1)  // 只查询热门游戏
            ->where('status', 1)
            ->where('channel_hidden', 'not like', '%' . $player->channel->department_id . '%')
            ->orderBy('sort', 'desc')
            ->orderBy('id', 'desc')
            ->forPage($page, $size)
            ->get();

        $gameData = [];
        /** @var Game $game */
        foreach ($gameList as $game) {
            /** @var GameContent $content */
            $content = $game->gameContent->where('lang', $lang)->first();

            $gameData[] = [
                'id' => $game->id,
                'cate_id' => $game->cate_id,
                'is_new' => $game->is_new,
                'is_hot' => $game->is_hot,
                'platform_id' => $game->platform_id,
                'game_content' => $content
            ];
        }

        return jsonSuccessResponse('success', ['list' => $gameData]);
    }
}
