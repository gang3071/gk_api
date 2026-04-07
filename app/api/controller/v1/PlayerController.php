<?php

namespace app\api\controller\v1;

use app\exception\PlayerCheckException;
use app\filesystem\Filesystem;
use app\model\BankContent;
use app\model\Channel;
use app\model\ChannelRechargeMethod;
use app\model\ChannelRechargeSetting;
use app\model\Currency;
use app\model\GameType;
use app\model\Lottery;
use app\model\Machine;
use app\model\Notice;
use app\model\OpenScoreSetting;
use app\model\PhoneSmsLog;
use app\model\Player;
use app\model\PlayerBank;
use app\model\PlayerDeliveryRecord;
use app\model\PlayerFavoriteMachine;
use app\model\PlayerGameRecord;
use app\model\PlayerLotteryRecord;
use app\model\PlayerPlatformCash;
use app\model\PlayerPresentRecord;
use app\model\PlayerRechargeRecord;
use app\model\PlayerReverseWaterDetail;
use app\model\PlayerWalletTransfer;
use app\model\PlayerWithdrawRecord;
use app\model\PlayGameRecord;
use app\model\Slider;
use app\model\StoreSetting;
use app\model\SystemSetting;
use app\service\GameLotteryServices;
use app\service\LotteryServices;
use app\service\machine\MachineServices;
use app\service\payment\EHpayService;
use app\service\payment\GBpayService;
use app\service\SmsServicesServices;
use Carbon\Carbon;
use Exception;
use GatewayWorker\Lib\Gateway;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Respect\Validation\Exceptions\AllOfException;
use Respect\Validation\Validator as v;
use support\Cache;
use support\Db;
use support\Log;
use support\Request;
use support\Response;
use Webman\RateLimiter\Annotation\RateLimiter;
use Webman\RedisQueue\Client;
use Webman\RedisQueue\Client as queueClient;
use WebmanTech\LaravelHttpClient\Facades\Http;

class PlayerController
{
    /** 排除验签 */
    protected $noNeedSign = ['addBankCard', 'uploadAvatar', 'completeRecharge', 'editBankCard'];
    
    #[RateLimiter(limit: 5)]
    /**
     * 获取用户信息
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public function playerInfo(): Response
    {
        $player = checkPlayer();
        /** @var PlayerPlatformCash $wallet */
        $wallet = PlayerPlatformCash::query()->firstOrCreate(
            [
                'player_id' => $player->id,
                'platform_id' => PlayerPlatformCash::PLATFORM_SELF
            ],
            [
                'player_id' => $player->id,
                'platform_id' => PlayerPlatformCash::PLATFORM_SELF,
                'money' => 0,
            ]
        );
        /** @var SystemSetting $setting */
        $setting = SystemSetting::query()->where('status', 1)->where('feature', 'recharge_order_expiration')->first();
        $machineList = Machine::query()
            ->where('status', 1)
            ->whereHas('machineCategory', function ($query) {
                $query->whereHas('gameType', function ($query) {
                    $query->where('status', 1);
                })->where('status', 1);
            })
            ->where('gaming_user_id', $player->id)
            ->where('maintaining', 0)
            ->where('status', 1)
            ->orderBy('last_game_at', 'desc')
            ->get();
        $playingMachine = [];
        /** @var Machine $machine */
        foreach ($machineList as $machine) {
            $playingMachine[] = [
                'id' => $machine->id,
                'name' => $machine->name,
                'code' => $machine->code,
                'type' => $machine->type,
                'cate_id' => $machine->cate_id
            ];
        }
        if (isset($player->national_promoter)) {
            $national_promoter = '等级' . $player->national_promoter->level_list->national_level->name;
            $national_level = $player->national_promoter->level_list->level;
        } else {
            $player->status_national = 0;
            $national_promoter = '';
            $national_level = '';
        }

        // 获取店家配置
        $storeSettings = $this->getStoreSettings($player);

        return jsonSuccessResponse('success', [
            'id' => $player->id,
            'phone' => $player->phone,
            'avatar' => $player->avatar,
            'type' => $player->type,
            'currency' => $player->currency,
            'flag' => $player->flag,
            'uuid' => $player->uuid,
            'talk_user_id' => $player->talk_user_id,
            'has_set_play_password' => !empty($player->play_password),
            'recommend_code' => $player->recommend_code,
            'recommend_player_uuid' => $player->recommend_player->uuid ?? '',
            'money' => \app\service\WalletService::getBalance($player->id), // ✅ Redis 实时余额
            'name' => $player->name,
            'is_promoter' => $player->is_promoter == 1 && $player->player_promoter->status == 1,
            'recommend_id' => $player->recommend_id ?? 0,
            'country_code' => $player->country_code,
            'machine_play_num' => $player->machine_play_num,
            'wallet_list' => $wallet,
            'recharge_order_expiration' => $setting->num ?? null,
            'playing_machine' => $playingMachine,
            'notice_num' => Notice::query()
                ->where('player_id', $player->id)
                ->where('receiver', Notice::RECEIVER_PLAYER)
                ->where('is_private', 1)
                ->where('status', 0)
                ->count('*'),
            'national_promoter' => $national_promoter,
            'national_level' => $national_level,
            'switch_shop' => $player->switch_shop,
            'status_national' => $player->status_national,
            'status_reverse_water' => $player->status_reverse_water,
            'status_machine' => $player->status_machine,
            'status_baccarat' => $player->status_baccarat,
            'status_offline_open' => $player->status_offline_open,
            'status_game_platform' => $player->status_game_platform,
            'store_settings' => $storeSettings, // 店家配置
        ]);
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 重置密码
     * @param Request $request
     * @return Response
     */
    public function changePassword(Request $request): Response
    {
        $data = $request->all();
        $validator = v::key('phone', v::stringType()->notEmpty()->length(1, 20)->setName(trans('phone', [], 'message')))
            ->key('code', v::stringType()->notEmpty()->length(1, 10)->setName(trans('phone_code', [], 'message')))
            ->key('country_code', v::intVal()->notEmpty()->in([
                PhoneSmsLog::COUNTRY_CODE_JP,
                PhoneSmsLog::COUNTRY_CODE_CH,
                PhoneSmsLog::COUNTRY_CODE_TW
            ])->setName(trans('country_code', [], 'message')))
            ->key('password',
                v::stringType()->notEmpty()->alnum()->length(6, 12)->setName(trans('new_password', [], 'message')))
            ->key('re_password', v::stringType()->notEmpty()->alnum()->length(6,
                12)->equals($data['password'] ?? null)->setName(trans('re_password', [], 'message')));
        
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        
        // 验证短信
        if (!verifySMS($data['country_code'], $data['phone'], $data['code'], PhoneSmsLog::TYPE_CHANGE_PASSWORD)) {
            return jsonFailResponse(trans('phone_code_error', [], 'message'));
        }
        
        /** @var Player $player */
        $player = Player::query()->where(['phone' => $data['phone']])->where('department_id',
            \request()->department_id)->first();
        if (empty($player)) {
            return jsonFailResponse(trans('player_not_fount', [], 'message'));
        }
        
        if ($player->status == Player::STATUS_STOP) {
            return jsonFailResponse(trans('player_stop', [], 'message'));
        }
        $player->password = $data['password'];
        if (!$player->save()) {
            return jsonFailResponse(trans('password_change_error', [], 'message'));
        }
        
        return jsonSuccessResponse('success', [
            'id' => $player->id,
            'phone' => $player->phone,
            'avatar' => $player->avatar,
            'type' => $player->type,
            'currency' => $player->currency,
            'flag' => $player->flag,
            'recommend_code' => $player->recommend_code,
        ]);
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 首页数据
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|\think\Exception
     */
    public function getIndex(Request $request): Response
    {
        $player = checkPlayer();
        $data = $request->all();
        $validator = v::key('game_cate', v::in([
            '',
            GameType::CATE_PHYSICAL_MACHINE,
            GameType::CATE_COMPUTER_GAME,
            GameType::CATE_LIVE_VIDEO
        ])->setName(trans('game_cate', [], 'message')), false);
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        /** @var Machine $playingMachine */
        $playingMachine = Machine::query()
            ->where('status', 1)
            ->whereHas('machineCategory', function ($query) {
                $query->whereHas('gameType', function ($query) {
                    $query->where('status', 1);
                })->where('status', 1);
            })
            ->where('gaming_user_id', $player->id)
            ->where('maintaining', 0)
            ->where('status', 1)
            ->orderBy('last_game_at', 'desc')
            ->get()
            ->first();
        $gameList = GameType::query()->where('status', 1)
            ->orderBy('sort', 'desc')
            ->whereNull('deleted_at')
            ->where('cate', !empty($data['game_cate']) ? $data['game_cate'] : GameType::CATE_PHYSICAL_MACHINE)
            ->whereIn('type', [GameType::TYPE_SLOT, GameType::TYPE_STEEL_BALL])
            ->select(['id', 'type', 'name', 'picture_url', 'status'])
            ->get()
            ->toArray();
        // 获取彩金池数据
        $lotteryData = $this->getLotteryPoolData();

        return jsonSuccessResponse('success', [
            'game_cate_list' => getGameTypeCateList(),
            'game_list' => $gameList,
            'playing_machine' => !empty($playingMachine) ? [
                'id' => $playingMachine->id,
                'name' => $playingMachine->name,
                'code' => $playingMachine->code,
                'type' => $playingMachine->type,
                'cate_id' => $playingMachine->cate_id,
            ] : null,
            'lottery_pool' => $lotteryData,
        ]);
    }

    /**
     * 获取彩金池数据 - 新版：使用独立彩池
     * @return array
     */
    private function getLotteryPoolData(): array
    {
        try {
            $lotteryServices = (new LotteryServices())->setJackLotteryList()->setSlotLotteryList();
            $gameLotteryPool = GameLotteryServices::getLotteryPool();

            return [
                'slot_amount' => $this->formatLotteryList($lotteryServices->slotLotteryList),
                'jack_amount' => $this->formatLotteryList($lotteryServices->jackLotteryList),
                'game_lottery_list' => $this->formatGameLotteryPool($gameLotteryPool),
            ];
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
     * 格式化彩金池金额
     * @param mixed $lotteryPool 彩金池对象
     * @return string|null
     */
    private function formatPoolAmount(mixed $lotteryPool): ?string
    {
        if (!$lotteryPool || !$lotteryPool->amount || $lotteryPool->status != 1) {
            return null;
        }
        return number_format($lotteryPool->amount, 1, '.', '');
    }

    /**
     * 格式化彩金列表 - 新版：使用独立彩池
     * @param  $lotteryList
     * @return array
     */
    private function formatLotteryList($lotteryList): array
    {
        $result = [];
        /** @var Lottery $lottery */
        foreach ($lotteryList as $lottery) {
            // 新版：直接使用 lottery.amount（独立彩池金额）
            $amount = floatval($lottery->amount);

            // 从Redis获取实时金额并累加
            try {
                $redis = \support\Redis::connection()->client();
                $redisKey = LotteryServices::REDIS_KEY_LOTTERY_AMOUNT . $lottery->id;
                $redisAmount = $redis->get($redisKey);
                if ($redisAmount !== false && $redisAmount > 0) {
                    $amount = floatval(bcadd($amount, $redisAmount, 2));
                }
            } catch (\Exception) {
                // 降级使用数据库金额
            }

            // 限制不超过最大金额
            if ($lottery->max_amount > 0) {
                $amount = min($amount, floatval($lottery->max_amount));
            }

            $result[] = [
                'id' => $lottery->id,
                'name' => $lottery->name,
                'amount' => number_format($amount, 2, '.', ''),
                'lotteryMultiple' => 1,
            ];
        }

        return $result;
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
     * 游戏记录
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public function gameRecord(Request $request): Response
    {
        $player = checkPlayer();
        $data = $request->all();
        $validator = v::key('game_id', v::intVal()->setName(trans('game_id', [], 'message')), false)
            ->key('page', v::intVal()->setName(trans('page', [], 'message')))
            ->key('size', v::intVal()->setName(trans('size', [], 'message')));
        
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        
        $playerGameLog = PlayerGameRecord::query()->where('player_id', $player->id)
            ->where('status', PlayerGameRecord::STATUS_END)
            ->orderBy('updated_at', 'desc')
            ->orderBy('status', 'asc')
            ->forPage($data['page'], $data['size']);
        if (!empty($data['game_id'])) {
            $playerGameLog->where('game_id', $data['game_id']);
        }
        $playerGameLogList = $playerGameLog->get();
        $list = [];
        /** @var PlayerGameRecord $item */
        foreach ($playerGameLogList as $item) {
            $list[] = [
                'id' => $item->id,
                'game_id' => $item->game_id,
                'machine_id' => $item->machine_id,
                'machine_name' => $item->machine->name,
                'type' => $item->machine_id,
                'open_point' => $item->open_point,
                'wash_point' => $item->wash_point,
                'open_amount' => $item->open_amount,
                'wash_amount' => $item->wash_amount,
                'after_game_amount' => $item->after_game_amount,
                'created_at' => !empty($item->created_at) ? dateFormat($item->created_at) : '',
                'odds' => $item->odds,
                'status' => $item->status,
                'code' => $item->code,
                'updated_at' => !empty($item->updated_at) ? dateFormat($item->updated_at) : '',
            ];
        }
        $gameList = GameType::query()
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->orderBy('sort', 'desc')
            ->select(['id', 'name'])
            ->get()->toArray();
        
        $gameList = Arr::prepend($gameList, ['id' => 0, 'name' => trans('all', [], 'message')]);
        
        return jsonSuccessResponse('success', [
            'list' => $list,
            'game_list' => $gameList
        ]);
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 编辑玩家名称
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|\think\Exception
     */
    public function editPlayerName(Request $request): Response
    {
        $player = checkPlayer();
        $data = $request->all();
        $validator = v::key('player_name', v::stringVal()->length(1, 50)->setName(trans('player_name', [], 'message')),
            false);
        
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        $player->name = $data['player_name'] ?? '';
        $player->save();
        
        return jsonSuccessResponse('success');
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 编辑保存玩家头像
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|\think\Exception
     */
    public function editPlayerAvatar(Request $request): Response
    {
        checkPlayer();
        $file = $request->file('avatar');
        if ($file && $file->isValid()) {
            $size = $file->getSize();
            if ($file->getSize() >= 1024 * 1024) {
                return jsonFailResponse(trans('image_upload_size_fail', [
                    '{
        size}' => '1M'
                ], 'message'));
            }
            $extension = $file->getUploadExtension();
            if (!in_array($extension, ['png', 'jpg', 'jpeg'])) {
                return jsonFailResponse(trans('image_upload_size_fail', [
                    '{
        size}' => '1M'
                ], 'message'));
            }
            $uploadName = $file->getUploadName();
            $basePath = public_path() . ' / storage / ' . date('Ymd') . DIRECTORY_SEPARATOR;
            $baseUrl = env('APP_URL', 'http://127.0.0.1:8787') . '/storage/' . date('Ymd') . DIRECTORY_SEPARATOR;
            $uniqueId = hash_file('md5', $file->getPathname());
            $saveFilename = $uniqueId . '.' . $file->getUploadExtension();
            $savePath = $basePath . $saveFilename;
            $file->move($savePath);
            
            return jsonSuccessResponse('success', [
                'origin_name' => $uploadName,
                'save_name' => $saveFilename,
                'save_path' => $savePath,
                'url' => $baseUrl . $saveFilename,
                'unique_id' => $uniqueId,
                'size' => $size,
                'mime_type' => $file->getUploadMimeType(),
                'extension' => $extension,
            ]);
        }
        
        return jsonFailResponse(trans('image_upload_fail', [], 'message'));
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 玩家发送短信
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|\think\Exception
     */
    public
    function playerSendCode(
        Request $request
    ): Response {
        $player = checkPlayer();
        $data = $request->all();
        $validator = v::key('type', v::notEmpty()->in([
            PhoneSmsLog::TYPE_CHANGE_PAY_PASSWORD,
            PhoneSmsLog::TYPE_CHANGE_PHONE,
        ])->setName(trans('sms_type', [], 'message')));
        
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        if (empty($player->phone)) {
            return jsonFailResponse(trans('must_bind_phone', [], 'message'));
        }
        try {
            SmsServicesServices::sendSms($player->country_code, $player->phone, $data['type'], $player->id,
                $player->name);
        } catch (Exception $e) {
            return jsonFailResponse($e->getMessage());
        }
        
        return jsonSuccessResponse('success');
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 检查验证码
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|\think\Exception
     */
    public
    function checkPhoneCode(
        Request $request
    ): Response {
        $player = checkPlayer();
        $data = $request->all();
        $validator = v::key('code',
            v::stringType()->notEmpty()->length(1, 10)->setName(trans('phone_code', [], 'message')))
            ->key('type', v::notEmpty()->in([
                PhoneSmsLog::TYPE_CHANGE_PAY_PASSWORD,
                PhoneSmsLog::TYPE_CHANGE_PHONE,
            ])->setName(trans('sms_type', [], 'message')));
        
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        
        // 验证短信
        if (!verifySMS($player->country_code, $player->phone, $data['code'], $data['type'])) {
            return jsonFailResponse(trans('phone_code_error', [], 'message'));
        }
        
        return jsonSuccessResponse('success');
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 绑定新手机号
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|\think\Exception
     */
    public
    function bindNewPhone(
        Request $request
    ): Response {
        $player = checkPlayer();
        $data = $request->all();
        $validator = v::key('phone', v::stringType()->notEmpty()->setName(trans('phone', [], 'message')))
            ->key('code', v::stringType()->notEmpty()->setName(trans('phone_code', [], 'message')))
            ->key('country_code', v::stringType()->notEmpty()->in([
                PhoneSmsLog::COUNTRY_CODE_JP,
                PhoneSmsLog::COUNTRY_CODE_CH,
                PhoneSmsLog::COUNTRY_CODE_TW
            ])->setName(trans('country_code', [], 'message')));
        
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        
        // 验证短信
        if (!verifySMS($data['country_code'], $data['phone'], $data['code'], PhoneSmsLog::TYPE_BIND_NEW_PHONE)) {
            return jsonFailResponse(trans('phone_code_error', [], 'message'));
        }
        
        // 验证手机号是否已注册
        if (Player::where('phone', $data['phone'])->where('department_id', \request()->department_id)->first()) {
            return jsonFailResponse(trans('phone_has_register', [], 'message'));
        }
        $player->phone = $data['phone'];
        $player->country_code = $data['country_code'];
        $player->save();
        
        return jsonSuccessResponse('success');
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 修改支付密码
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public
    function changePlayPassword(
        Request $request
    ): Response {
        $player = checkPlayer();
        $data = $request->all();
        $validator = v::key('code', v::stringType()->notEmpty()->setName(trans('phone_code', [], 'message')))
            ->key('play_password',
                v::stringType()->notEmpty()->alnum()->length(6, 6)->setName(trans('play_password', [], 'message')))
            ->key('re_play_password', v::stringType()->notEmpty()->alnum()->length(6,
                6)->equals($data['play_password'] ?? null)->setName(trans('re_play_password', [], 'message')));
        
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        
        // 验证短信
        if (!verifySMS($player->country_code, $player->phone, $data['code'], PhoneSmsLog::TYPE_CHANGE_PAY_PASSWORD)) {
            return jsonFailResponse(trans('phone_code_error', [], 'message'));
        }
        
        $player->play_password = $data['play_password'];
        $player->save();
        
        return jsonSuccessResponse('success');
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 转点
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public
    function present(
        Request $request
    ): Response {
        $player = checkPlayer();
        $data = $request->all();
        $validator = v::key('uuid', v::stringType()->notEmpty()->setName(trans('uuid', [], 'message')))
            ->key('pay_password', v::stringType()->notEmpty()->setName(trans('pay_password', [], 'message')))
            ->key('amount', v::floatVal()->notEmpty()->setName(trans('amount', [], 'message')));
        
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        
        //贈點功能關閉
        if ($player->status_transfer == 0) {
            return jsonFailResponse(trans('present_disabled', [], 'message'));
        }
        /** @var Player $acceptPlayer */
        $acceptPlayer = Player::where('uuid', $data['uuid'])->whereNull('deleted_at')->where('department_id',
            \request()->department_id)->first();
        if (empty($acceptPlayer)) {
            return jsonFailResponse(trans('present_player_not_found', [], 'message'));
        }
        if ($player->is_coin == 1 && $acceptPlayer->is_coin == 1) {
            return jsonFailResponse(trans('coin_cannot_present', [], 'message'));
        }
        if ($player->is_coin == 0 && $acceptPlayer->is_coin == 0) {
            return jsonFailResponse(trans('player_cannot_present', [], 'message'));
        }
        if (!password_verify($data['pay_password'], $player->play_password) || empty($player->play_password)) {
            return jsonFailResponse(trans('play_password_error', [], 'message'));
        }
        //對方贈點功能關閉
        if ($acceptPlayer->status_transfer == 0) {
            return jsonFailResponse(trans('present_target_disabled', [], 'message'));
        }
        //检查对方账号
        if ($acceptPlayer->status == 0) {
            return jsonFailResponse(trans('present_account_disabled', [], 'message'));
        }
        //不能轉給自己
        if ($player->id == $acceptPlayer->id) {
            return jsonFailResponse(trans('you_cannot_present_own', [], 'message'));
        }
        $amount = (float)$data['amount'];
        // ✅ 从 Redis 读取实时余额
        $currentBalance = \app\service\WalletService::getBalance($player->id);
        if ($amount > $currentBalance) {
            return jsonFailResponse(trans('your_point_insufficient', [], 'message'));
        }
        //驗證通過
        DB::beginTransaction();
        try {
            $tradeno = date('YmdHis') . rand(10000, 99999);

            //使用 Lua 原子操作（Redis 作为唯一实时标准）
            // 1. 扣除转出玩家余额
            $userOriginAmount = \app\service\WalletService::getBalance($player->id, 1);
            $userAfterAmount = \app\service\WalletService::deduct($player->id, $amount, 1);

            // 2. 增加接收玩家余额
            $playerOriginAmount = \app\service\WalletService::getBalance($acceptPlayer->id, 1);
            $playerAfterAmount = \app\service\WalletService::add($acceptPlayer->id, $amount, 1);

            // 添加玩家转点记录
            $playerPresentRecord = new PlayerPresentRecord();
            $playerPresentRecord->user_id = $player->id;
            $playerPresentRecord->player_id = $acceptPlayer->id;
            $playerPresentRecord->department_id = $player->department_id;
            $playerPresentRecord->amount = $data['amount'];
            $playerPresentRecord->tradeno = $tradeno;
            $playerPresentRecord->user_origin_amount = $userOriginAmount;
            $playerPresentRecord->user_after_amount = $userAfterAmount;
            $playerPresentRecord->player_origin_amount = $playerOriginAmount;
            $playerPresentRecord->player_after_amount = $playerAfterAmount;
            $playerPresentRecord->type = PlayerPresentRecord::TYPE_IN;
            if ($player->is_coin == 1) {
                $playerPresentRecord->type = PlayerPresentRecord::TYPE_OUT;
            }
            $playerPresentRecord->save();

            // 写入金流明细 - 转出记录
            $playerDeliveryRecordOut = new PlayerDeliveryRecord();
            $playerDeliveryRecordOut->player_id = $player->id;
            $playerDeliveryRecordOut->department_id = $player->department_id;
            $playerDeliveryRecordOut->target = $playerPresentRecord->getTable();
            $playerDeliveryRecordOut->target_id = $playerPresentRecord->id;
            $playerDeliveryRecordOut->type = PlayerDeliveryRecord::TYPE_PRESENT_OUT;
            $playerDeliveryRecordOut->source = 'present_out';
            $playerDeliveryRecordOut->amount = $amount;
            $playerDeliveryRecordOut->amount_before = $userOriginAmount;
            $playerDeliveryRecordOut->amount_after = $userAfterAmount;
            $playerDeliveryRecordOut->tradeno = $tradeno;
            $playerDeliveryRecordOut->remark = $acceptPlayer->uuid;
            $playerDeliveryRecordOut->save();

            // 写入金流明细 - 转入记录
            $playerDeliveryRecordIn = new PlayerDeliveryRecord();
            $playerDeliveryRecordIn->player_id = $acceptPlayer->id;
            $playerDeliveryRecordIn->department_id = $acceptPlayer->department_id;
            $playerDeliveryRecordIn->target = $playerPresentRecord->getTable();
            $playerDeliveryRecordIn->target_id = $playerPresentRecord->id;
            $playerDeliveryRecordIn->type = PlayerDeliveryRecord::TYPE_PRESENT_IN;
            $playerDeliveryRecordIn->source = 'present_in';
            $playerDeliveryRecordIn->amount = $amount;
            $playerDeliveryRecordIn->amount_before = $playerOriginAmount;
            $playerDeliveryRecordIn->amount_after = $playerAfterAmount;
            $playerDeliveryRecordIn->tradeno = $tradeno;
            $playerDeliveryRecordIn->remark = $player->uuid;
            $playerDeliveryRecordIn->save();

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('present', [$e->getTrace()]);
            return jsonFailResponse($e->getMessage() ?? trans('system_error', [], 'message'));
        }

        return jsonSuccessResponse('success');
    }
    
    
    #[RateLimiter(limit: 5)]
    /**
     * 转点
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public
    function presentNoPassword(
        Request $request
    ): Response {
        $player = checkPlayer();
        $data = $request->all();
        $validator = v::key('uuid', v::stringType()->notEmpty()->setName(trans('uuid', [], 'message')))
            ->key('amount', v::floatVal()->notEmpty()->setName(trans('amount', [], 'message')));
        
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        
        //贈點功能關閉
        if ($player->status_transfer == 0) {
            return jsonFailResponse(trans('present_disabled', [], 'message'));
        }
        /** @var Player $acceptPlayer */
        $acceptPlayer = Player::where('uuid', $data['uuid'])->whereNull('deleted_at')->where('department_id',
            \request()->department_id)->first();
        if (empty($acceptPlayer)) {
            return jsonFailResponse(trans('present_player_not_found', [], 'message'));
        }
        if ($player->is_coin == 1 && $acceptPlayer->is_coin == 1) {
            return jsonFailResponse(trans('coin_cannot_present', [], 'message'));
        }
        if ($player->is_coin == 0 && $acceptPlayer->is_coin == 0) {
            return jsonFailResponse(trans('player_cannot_present', [], 'message'));
        }

        //對方贈點功能關閉
        if ($acceptPlayer->status_transfer == 0) {
            return jsonFailResponse(trans('present_target_disabled', [], 'message'));
        }
        //检查对方账号
        if ($acceptPlayer->status == 0) {
            return jsonFailResponse(trans('present_account_disabled', [], 'message'));
        }
        //不能轉給自己
        if ($player->id == $acceptPlayer->id) {
            return jsonFailResponse(trans('you_cannot_present_own', [], 'message'));
        }
        $amount = (float)$data['amount'];
        // ✅ 从 Redis 读取实时余额
        $currentBalance = \app\service\WalletService::getBalance($player->id);
        if ($amount > $currentBalance) {
            return jsonFailResponse(trans('your_point_insufficient', [], 'message'));
        }
        //驗證通過
        DB::beginTransaction();
        try {
            $tradeno = date('YmdHis') . rand(10000, 99999);

            //使用 Lua 原子操作（Redis 作为唯一实时标准）
            // 1. 扣除转出玩家余额
            $userOriginAmount = \app\service\WalletService::getBalance($player->id, 1);
            $userAfterAmount = \app\service\WalletService::deduct($player->id, $amount, 1);

            // 2. 增加接收玩家余额
            $playerOriginAmount = \app\service\WalletService::getBalance($acceptPlayer->id, 1);
            $playerAfterAmount = \app\service\WalletService::add($acceptPlayer->id, $amount, 1);

            // 添加玩家转点记录
            $playerPresentRecord = new PlayerPresentRecord();
            $playerPresentRecord->user_id = $player->id;
            $playerPresentRecord->player_id = $acceptPlayer->id;
            $playerPresentRecord->department_id = $player->department_id;
            $playerPresentRecord->amount = $data['amount'];
            $playerPresentRecord->tradeno = $tradeno;
            $playerPresentRecord->user_origin_amount = $userOriginAmount;
            $playerPresentRecord->user_after_amount = $userAfterAmount;
            $playerPresentRecord->player_origin_amount = $playerOriginAmount;
            $playerPresentRecord->player_after_amount = $playerAfterAmount;
            $playerPresentRecord->type = PlayerPresentRecord::TYPE_IN;
            if ($player->is_coin == 1) {
                $playerPresentRecord->type = PlayerPresentRecord::TYPE_OUT;
            }
            $playerPresentRecord->save();

            // 写入金流明细 - 转出记录
            $playerDeliveryRecordOut = new PlayerDeliveryRecord();
            $playerDeliveryRecordOut->player_id = $player->id;
            $playerDeliveryRecordOut->department_id = $player->department_id;
            $playerDeliveryRecordOut->target = $playerPresentRecord->getTable();
            $playerDeliveryRecordOut->target_id = $playerPresentRecord->id;
            $playerDeliveryRecordOut->type = PlayerDeliveryRecord::TYPE_PRESENT_OUT;
            $playerDeliveryRecordOut->source = 'present_out';
            $playerDeliveryRecordOut->amount = $amount;
            $playerDeliveryRecordOut->amount_before = $userOriginAmount;
            $playerDeliveryRecordOut->amount_after = $userAfterAmount;
            $playerDeliveryRecordOut->tradeno = $tradeno;
            $playerDeliveryRecordOut->remark = $acceptPlayer->uuid;
            $playerDeliveryRecordOut->save();

            // 写入金流明细 - 转入记录
            $playerDeliveryRecordIn = new PlayerDeliveryRecord();
            $playerDeliveryRecordIn->player_id = $acceptPlayer->id;
            $playerDeliveryRecordIn->department_id = $acceptPlayer->department_id;
            $playerDeliveryRecordIn->target = $playerPresentRecord->getTable();
            $playerDeliveryRecordIn->target_id = $playerPresentRecord->id;
            $playerDeliveryRecordIn->type = PlayerDeliveryRecord::TYPE_PRESENT_IN;
            $playerDeliveryRecordIn->source = 'present_in';
            $playerDeliveryRecordIn->amount = $amount;
            $playerDeliveryRecordIn->amount_before = $playerOriginAmount;
            $playerDeliveryRecordIn->amount_after = $playerAfterAmount;
            $playerDeliveryRecordIn->tradeno = $tradeno;
            $playerDeliveryRecordIn->remark = $player->uuid;
            $playerDeliveryRecordIn->save();

            //统计线下总营收
            if (!empty($player->recommend_id) && $player->recommend_player->player_promoter->recommend_id != 0) {
                //不是最上级
                //总投钞+总开分-总洗分
                $player->recommend_player->player_promoter->total_amount -= $amount;
                $player->recommend_player->player_promoter->children_total_amount -= $amount;
                $player->push();
            }
            
            //统计线下总营收
            if (!empty($acceptPlayer->recommend_id)) {
                //不是最上级
                //总投钞+总开分-总洗分
                $acceptPlayer->recommend_player->player_promoter->total_amount += $amount;
                $acceptPlayer->recommend_player->player_promoter->children_total_amount += $amount;
                $acceptPlayer->push();
            }
            
            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('present', [$e->getTrace()]);
            return jsonFailResponse($e->getMessage() ?? trans('system_error', [], 'message'));
        }
        
        queueClient::send('game-depositAmount', [
            'player_id' => $acceptPlayer->id,
            'amount' => $amount
        ]);
        
        return jsonSuccessResponse('success');
    }
    
    /**
     * 洗分（线下代理提现）
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public
    function presentAuto(
        Request $request
    ): Response {
        $player = checkPlayer();

        // 基础验证
        if ($player->is_coin == 1) {
            return jsonFailResponse(trans('coin_cannot_present', [], 'message'));
        }
        // ✅ 从 Redis 读取实时余额
        $currentBalance = \app\service\WalletService::getBalance($player->id);
        if ($currentBalance <= 0) {
            return jsonFailResponse(trans('your_point_insufficient', [], 'message'));
        }

        // 爆机检查：玩家不能洗分
        $crashCheck = checkMachineCrash($player);
        if ($crashCheck['crashed']) {
            return jsonFailResponse(trans('machine_crashed_cannot_wash_score', [], 'message'));
        }

        // 计算可洗分金额：保留十位，只洗到百位
        $currentMoney = $currentBalance;
        $washAmount = floor($currentMoney / 100) * 100; // 向下取整到百位

        if ($washAmount < 100) {
            return jsonFailResponse(trans('insufficient_balance_100', [], 'message'));
        }

        // 渠道和货币验证
        /** @var Channel $channel */
        $channel = Channel::query()->where('department_id', \request()->department_id)->first();
        if ($player->status_withdraw != 1) {
            return jsonFailResponse(trans('player_withdraw_closed', [], 'message'));
        }
        if ($channel->withdraw_status == 0) {
            return jsonFailResponse(trans('self_withdraw_closed', [], 'message'));
        }
        /** @var Currency $currency */
        $currency = Currency::query()->where('identifying', $channel->currency)->where('status',
            1)->whereNull('deleted_at')->first();
        if (empty($currency)) {
            return jsonFailResponse(trans('currency_no_setting', [], 'message'));
        }
        $money = bcdiv($washAmount, $currency->ratio, 2);

        // 开始事务处理
        DB::beginTransaction();
        try {
            // 生成提现订单
            $playerWithdrawRecord = new PlayerWithdrawRecord();
            $playerWithdrawRecord->player_id = $player->id;
            $playerWithdrawRecord->talk_user_id = $player->talk_user_id;
            $playerWithdrawRecord->department_id = $player->department_id;
            $playerWithdrawRecord->tradeno = createOrderNo();
            $playerWithdrawRecord->player_name = $player->name ?? '';
            $playerWithdrawRecord->player_phone = $player->phone ?? '';
            $playerWithdrawRecord->rate = $currency->ratio;
            $playerWithdrawRecord->actual_rate = $currency->ratio;
            $playerWithdrawRecord->money = $money;
            $playerWithdrawRecord->point = $washAmount;
            $playerWithdrawRecord->fee = 0;
            $playerWithdrawRecord->inmoney = bcsub($playerWithdrawRecord->money, $playerWithdrawRecord->fee, 2);
            $playerWithdrawRecord->currency = $channel->currency;
            $playerWithdrawRecord->bank_name = '';
            $playerWithdrawRecord->account = '';
            $playerWithdrawRecord->account_name = '';
            $playerWithdrawRecord->wallet_address = '';
            $playerWithdrawRecord->qr_code = '';
            $playerWithdrawRecord->type = PlayerWithdrawRecord::TYPE_SELF;
            $playerWithdrawRecord->status = PlayerWithdrawRecord::STATUS_SUCCESS;
            $playerWithdrawRecord->bank_type = 4;
            $playerWithdrawRecord->remark = '線下代理洗分';
            $playerWithdrawRecord->save();

            // ✅ 从 Redis 读取余额（唯一可信源）
            $beforeGameAmount = \app\service\WalletService::getBalance($player->id);

            // ✅ Lua 原子性扣款（自动同步数据库）
            $result = \app\service\WalletService::atomicDecrement(
                $player->id,
                $playerWithdrawRecord->point
            );

            if ($result['ok'] == 0) {
                throw new \Exception('余额不足');
            }

            // 更新玩家提现统计
            $player->player_extend->withdraw_amount = bcadd($player->player_extend->withdraw_amount,
                $playerWithdrawRecord->point, 2);
            $player->push();

            // 写入金流明细
            $playerDeliveryRecord = new PlayerDeliveryRecord;
            $playerDeliveryRecord->player_id = $playerWithdrawRecord->player_id;
            $playerDeliveryRecord->department_id = $playerWithdrawRecord->department_id;
            $playerDeliveryRecord->target = $playerWithdrawRecord->getTable();
            $playerDeliveryRecord->target_id = $playerWithdrawRecord->id;
            $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_WITHDRAWAL;
            $playerDeliveryRecord->withdraw_status = $playerWithdrawRecord->status;
            $playerDeliveryRecord->source = 'channel_withdrawal';
            $playerDeliveryRecord->amount = $playerWithdrawRecord->point;
            $playerDeliveryRecord->amount_before = $beforeGameAmount;
            $playerDeliveryRecord->amount_after = $machineWallet->money;
            $playerDeliveryRecord->tradeno = $playerWithdrawRecord->tradeno ?? '';
            $playerDeliveryRecord->remark = '線下代理洗分';
            $playerDeliveryRecord->save();

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('presentAuto', [$e->getTrace()]);
            return jsonFailResponse($e->getMessage() ?? trans('system_error', [], 'message'));
        }

        return jsonSuccessResponse('success', [
            'amount' => $playerDeliveryRecord->amount,
            'created_at' => date('Y-m-d H:i:s', strtotime($playerDeliveryRecord->created_at)),
            'tradeno' => $playerDeliveryRecord->tradeno,
            'name' => $player->name
        ]);
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 玩家账单记录
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public
    function playerBillingRecord(
        Request $request
    ): Response {
        $player = checkPlayer();
        $data = $request->all();
        $validator = v::key('bill_type', v::stringVal()->setName(trans('bill_type', [], 'message')), false)
            ->key('page', v::intVal()->setName(trans('page', [], 'message')))
            ->key('size', v::intVal()->setName(trans('size', [], 'message')));
        
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        
        $playerDeliveryRecord = PlayerDeliveryRecord::where('player_id', $player->id)
            ->orderBy('id', 'desc')
            ->forPage($data['page'], $data['size']);
        if (isset($data['bill_type']) && !empty($data['bill_type'])) {
            switch ($data['bill_type']) {
                case 'recharge_withdrawal':
                    $playerDeliveryRecord->whereIn('type', [
                        PlayerDeliveryRecord::TYPE_RECHARGE,
                        PlayerDeliveryRecord::TYPE_WITHDRAWAL,
                        PlayerDeliveryRecord::TYPE_WITHDRAWAL_BACK,
                        PlayerDeliveryRecord::TYPE_GAME_PLATFORM_OUT,
                        PlayerDeliveryRecord::TYPE_GAME_PLATFORM_IN,
                    ]);
                    break;
                case 'game':
                    $playerDeliveryRecord->whereIn('type',
                        [PlayerDeliveryRecord::TYPE_MACHINE_UP, PlayerDeliveryRecord::TYPE_MACHINE_DOWN]);
                    break;
                case 'transfer_give':
                    $playerDeliveryRecord->whereIn('type',
                        [PlayerDeliveryRecord::TYPE_PRESENT_IN, PlayerDeliveryRecord::TYPE_PRESENT_OUT]);
                    break;
                case 'all':
                default:
                    break;
            }
        }
        $list = $playerDeliveryRecord->get()->toArray();
        foreach ($list as &$item) {
            switch ($item['type']) {
                case PlayerDeliveryRecord::TYPE_MODIFIED_AMOUNT_ADD:
                    $item['source'] = trans('source.system', [], 'message');
                    $item['target'] = trans('target.modified_amount_add', [], 'message');
                    break;
                case PlayerDeliveryRecord::TYPE_PRESENT_IN:
                    /** @var PlayerPresentRecord $playerPresentRecord */
                    $playerPresentRecord = PlayerPresentRecord::find($item['target_id']);
                    $item['source'] = !empty($playerPresentRecord->user->uuid) ? $playerPresentRecord->user->uuid : '';
                    $item['target'] = trans('target.present_in', [], 'message');
                    break;
                case PlayerDeliveryRecord::TYPE_PRESENT_OUT:
                    /** @var PlayerPresentRecord $playerPresentRecord */
                    $playerPresentRecord = PlayerPresentRecord::find($item['target_id']);
                    $item['amount'] *= -1;
                    $item['source'] = !empty($playerPresentRecord->player->uuid) ? $playerPresentRecord->player->uuid : '';
                    $item['target'] = trans('target.present_out', [], 'message');
                    break;
                case PlayerDeliveryRecord::TYPE_MACHINE_UP:
                    $item['amount'] *= -1;
                    $item['source'] = $item['code'];
                    $item['target'] = trans('target.machine_up', [], 'message');
                    break;
                case PlayerDeliveryRecord::TYPE_MACHINE_DOWN:
                    $item['source'] = $item['code'];
                    $item['target'] = trans('target.machine_down', [], 'message');
                    break;
                case PlayerDeliveryRecord::TYPE_RECHARGE:
                    switch ($item['source']) {
                        case 'artificial_recharge':
                            $item['source'] = trans('source.system', [], 'message');
                            $item['target'] = trans('target.artificial_recharge', [], 'message');
                            break;
                        case 'self_recharge':
                            $item['source'] = trans('source.system', [], 'message');
                            $item['target'] = trans('target.self_recharge', [], 'message');
                            break;
                        case 'talk_recharge':
                            $item['source'] = trans('source.qtalk', [], 'message');
                            $item['target'] = trans('target.talk_recharge', [], 'message');
                            break;
                        case 'coin_recharge':
                            $item['source'] = trans('source.system', [], 'message');
                            $item['target'] = trans('target.coin_recharge', [], 'message');
                            break;
                        case 'gb_recharge':
                            $item['source'] = trans('source.gb', [], 'message');
                            $item['target'] = trans('target.gb_recharge', [], 'message');
                            break;
                    }
                    break;
                case PlayerDeliveryRecord::TYPE_WITHDRAWAL:
                    $item['amount'] *= -1;
                    switch ($item['source']) {
                        case 'artificial_withdrawal':
                            $item['source'] = trans('source.system', [], 'message');
                            $item['target'] = trans('target.artificial_withdrawal', [], 'message');
                            break;
                        case 'talk_withdrawal':
                            $item['source'] = trans('source.qtalk', [], 'message');
                            $item['target'] = trans('target.talk_withdrawal', [], 'message');
                            break;
                        case 'channel_withdrawal':
                            $item['source'] = trans('source.system', [], 'message');
                            $item['target'] = trans('target.channel_withdrawal', [], 'message');
                            break;
                        case 'gb_withdrawal':
                            $item['source'] = trans('source.gb', [], 'message');
                            $item['target'] = trans('target.gb_withdrawal', [], 'message');
                            break;
                    }
                    break;
                case PlayerDeliveryRecord::TYPE_MODIFIED_AMOUNT_DEDUCT:
                    $item['amount'] *= -1;
                    $item['source'] = trans('source.system', [], 'message');
                    $item['target'] = trans('target.modified_amount_deduct', [], 'message');
                    break;
                case PlayerDeliveryRecord::TYPE_WITHDRAWAL_BACK:
                    $item['source'] = trans('source.system', [], 'message');
                    $item['target'] = trans('target.withdrawal_back', [], 'message');
                    break;
                case PlayerDeliveryRecord::TYPE_ACTIVITY_BONUS:
                    $item['source'] = trans('source.system', [], 'message');
                    $item['target'] = trans('target.activity_bonus', [], 'message');
                    break;
                case PlayerDeliveryRecord::TYPE_REGISTER_PRESENT:
                    $item['source'] = trans('source.system', [], 'message');
                    $item['target'] = trans('target.register_present', [], 'message');
                    break;
                case PlayerDeliveryRecord::TYPE_PROFIT:
                    $item['source'] = trans('source.system', [], 'message');
                    $item['target'] = trans('target.profit', [], 'message');
                    break;
                case PlayerDeliveryRecord::TYPE_LOTTERY:
                    $item['source'] = trans('source.system', [], 'message');
                    $item['target'] = trans('target.lottery', [], 'message');
                    break;
                case PlayerDeliveryRecord::TYPE_GAME_PLATFORM_OUT:
                    $item['source'] = trans('source.system', [], 'message');
                    /** @var PlayerWalletTransfer $playerWalletTransfer */
                    $playerWalletTransfer = PlayerWalletTransfer::query()->where('id', $item['target_id'])->first();
                    $item['source'] = $playerWalletTransfer->gamePlatform->name;
                    $item['target'] = trans('target.game_out', [], 'message');
                    break;
                case PlayerDeliveryRecord::TYPE_GAME_PLATFORM_IN:
                    /** @var PlayerWalletTransfer $playerWalletTransfer */
                    $playerWalletTransfer = PlayerWalletTransfer::query()->where('id', $item['target_id'])->first();
                    $item['source'] = $playerWalletTransfer->gamePlatform->name;
                    $item['target'] = trans('target.game_in', [], 'message');
                    break;
                case PlayerDeliveryRecord::TYPE_NATIONAL_INVITE:
                    $item['source'] = trans('source.national_promoter', [], 'message');
                    $item['target'] = trans('target.national_invite', [], 'message');
                    break;
                case PlayerDeliveryRecord::TYPE_RECHARGE_REWARD:
                    $item['source'] = trans('source.national_promoter', [], 'message');
                    $item['target'] = trans('target.player_recharge_record', [], 'message');
                    break;
                case PlayerDeliveryRecord::TYPE_DAMAGE_REBATE:
                    $item['source'] = trans('source.national_promoter', [], 'message');
                    $item['target'] = trans('target.damage_rebate', [], 'message');
                    break;
                case PlayerDeliveryRecord::TYPE_REVERSE_WATER:
                    $item['source'] = trans('source.reverse_water', [], 'message');
                    $item['target'] = trans('target.reverse_water', [], 'message');
                    break;
                case PlayerDeliveryRecord::TYPE_MACHINE:
                    $item['source'] = trans('source.machine_put_coins', [], 'message');
                    $item['target'] = trans('target.machine_put_coins', [], 'message');
                    break;
                case PlayerDeliveryRecord::TYPE_AGENT_OUT:
                    $item['source'] = trans('source.agent_out', [], 'message');
                    $item['target'] = trans('target.agent_out', [], 'message');
                    break;
                case PlayerDeliveryRecord::TYPE_AGENT_IN:
                    $item['source'] = trans('source.agent_in', [], 'message');
                    $item['target'] = trans('target.agent_in', [], 'message');
                    break;
                default:
                    break;
            }
        }
        
        return jsonSuccessResponse('success', [
            'list' => $list,
            'bill_type' => [
                ['type' => 'all', 'name' => trans('bill_type_all', [], 'message')],
                ['type' => 'recharge_withdrawal', 'name' => trans('bill_type_recharge_withdrawal', [], 'message')],
                ['type' => 'game', 'name' => trans('bill_type_game', [], 'message')],
                ['type' => 'transfer_give', 'name' => trans('bill_type_transfer_give', [], 'message')],
            ]
        ]);
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 玩家Q-talk充值
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException
     * @throws Exception
     */
    public
    function talkRecharge(
        Request $request
    ): Response {
        $player = checkPlayer();
        $data = $request->all();
        $validator = v::key('money', v::notEmpty()->intVal()->setName(trans('talk_recharge_point', [], 'message')));
        
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        if ($player->switch_shop != 1) {
            return jsonFailResponse(trans('payment_function_closed', [], 'message'));
        }
        /** @var Channel $channel */
        $channel = Channel::where('department_id', \request()->department_id)->first();
        if (empty($channel)) {
            return jsonFailResponse(trans('channel_not_found', [], 'message'));
        }
        if ($channel->q_talk_point_status == 0) {
            return jsonFailResponse(trans('q_talk_point_status_closed', [], 'message'));
        }
        if (empty($player->talk_user_id)) {
            return jsonFailResponse(trans('please_bind_chat', [], 'message'), [], 403);
        }
        DB::beginTransaction();
        try {
            // 生成订单
            $playerRechargeRecord = new  PlayerRechargeRecord();
            $playerRechargeRecord->player_id = $player->id;
            $playerRechargeRecord->talk_user_id = $player->talk_user_id;
            $playerRechargeRecord->department_id = $player->department_id;
            $playerRechargeRecord->tradeno = createOrderNo();
            $playerRechargeRecord->player_name = $player->name ?? '';
            $playerRechargeRecord->player_phone = $player->phone ?? '';
            $playerRechargeRecord->money = $data['money'];
            $playerRechargeRecord->inmoney = $data['money']; // 实际充值金额
            $playerRechargeRecord->point = $data['money']; // 充值点数(目前Q-talk币和点数比值为1:1)
            $playerRechargeRecord->currency = 'TALK';
            $playerRechargeRecord->save();
            // 请求Q-talk充值
            $response = Http::timeout(5)->asJson()->post(Config('talk.talk_domain') . '/oauth/charge/initiate', [
                'appId' => Config('talk.client_id'),
                'appSecret' => Config('talk.client_secret'),
                'amount' => $playerRechargeRecord->money * 100,// Q-talk充值金额需要乘100
                'oauthAppChargeId' => $playerRechargeRecord->tradeno,
                'userId' => $playerRechargeRecord->talk_user_id,
                'currency' => $playerRechargeRecord->currency,
            ]);
            /** @var PlayerRechargeRecord $rechargeOrder */
            $rechargeOrder = PlayerRechargeRecord::find($playerRechargeRecord->id);
            if ($response->status() == 200) {
                $data = json_decode($response->body(), true);
                if (isset($data['code']) && $data['code'] == 1) {
                    $rechargeOrder->status = PlayerRechargeRecord::STATUS_RECHARGING;
                    $rechargeOrder->talk_tradeno = $data['data']['oauthChargeId'];
                } else {
                    throw new Exception($data['error'] ?? trans('system_error', [], 'message'));
                }
            } else {
                throw new Exception(trans('system_error', [], 'message'));
            }
            
            $rechargeOrder->save();
            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            Log::error($e->getMessage());
            return jsonFailResponse($e->getMessage());
        }
        
        return jsonSuccessResponse('success', [
            'tradeno' => $rechargeOrder->tradeno,
            'order_id' => $rechargeOrder->id,
            'money' => $rechargeOrder->money,
            'currency' => $rechargeOrder->currency,
            'status' => $rechargeOrder->status,
            'talk_tradeno' => (string)$rechargeOrder->talk_tradeno,
        ]);
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 获取Q-talk充值订单状态
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException
     * @throws Exception
     */
    public
    function getTalkRechargeInfo(
        Request $request
    ): Response {
        $player = checkPlayer();
        $data = $request->all();
        $validator = v::key('tradeno', v::stringVal()->notEmpty()->setName(trans('recharge_tradeno', [], 'message')));
        
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        /** @var PlayerRechargeRecord $playerRechargeRecord */
        $playerRechargeRecord = PlayerRechargeRecord::where('tradeno', $data['tradeno'])->where('player_id',
            $player->id)->first();
        if (empty($playerRechargeRecord)) {
            return jsonFailResponse(trans('recharge_record_not_found', [], 'message'));
        }
        if ($playerRechargeRecord->status == PlayerRechargeRecord::STATUS_RECHARGED_SUCCESS) {
            return jsonSuccessResponse('success', [
                'tradeno' => $playerRechargeRecord->tradeno,
                'money' => $playerRechargeRecord->money,
                'player_id' => $playerRechargeRecord->player_id,
                'status' => $playerRechargeRecord->status,
            ]);
        }
        // 发送充值订单到Q-talk
        $response = Http::timeout(5)->asJson()->get(Config('talk.talk_domain') . '/oauth/charge', [
            'appId' => Config('talk.client_id'),
            'appSecret' => Config('talk.client_secret'),
            'recordId' => $playerRechargeRecord->talk_tradeno
        ]);
        if ($response->status() == 200) {
            $data = json_decode($response->body(), true);
            if (isset($data['code']) && $data['code'] == 1) {
                if ($data['status'] == 1) {
                    // 充值成功
                    if (talkPaySuccess($playerRechargeRecord)) {
                        return jsonSuccessResponse('success', [
                            'tradeno' => $playerRechargeRecord->tradeno,
                            'money' => $playerRechargeRecord->money,
                            'player_id' => $playerRechargeRecord->player_id,
                            'status' => PlayerRechargeRecord::STATUS_RECHARGED_SUCCESS,
                        ]);
                    } else {
                        return jsonFailResponse(trans('system_error', [], 'message'));
                    }
                }
            } else {
                return jsonFailResponse(trans('system_error', [], 'message'));
            }
        }
        
        return jsonSuccessResponse('success', [
            'tradeno' => $playerRechargeRecord->tradeno,
            'money' => $playerRechargeRecord->money,
            'player_id' => $playerRechargeRecord->player_id,
            'status' => PlayerRechargeRecord::STATUS_RECHARGED_SUCCESS,
        ]);
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 获取玩家头像
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public
    function getAvatarList(
        Request $request
    ): Response {
        checkPlayer();
        $defAvatarList = config('def_avatar');
        $list = [];
        foreach ($defAvatarList as $k => $v) {
            $list[] = [
                'id' => $k,
                'value' => $v,
            ];
        }
        
        return jsonSuccessResponse('success', [
            'avatar_list' => $list
        ]);
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 修改玩家头像
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public
    function changePlayerAvatar(
        Request $request
    ): Response {
        $player = checkPlayer();
        $data = $request->all();
        $validator = v::key('avatar_id', v::stringVal()->notEmpty()->setName(trans('avatar_id', [], 'message')));
        
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        $defAvatar = config('def_avatar');
        if (!isset($defAvatar[$data['avatar_id']])) {
            return jsonFailResponse(trans('def_avatar_not_found', [], 'message'));
        }
        $player->avatar = $data['avatar_id'];
        $player->save();
        
        return jsonSuccessResponse(trans('change_avatar_success', [], 'message'));
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 玩家提现
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException
     * @throws Exception
     */
    public
    function playerWithdrawal(
        Request $request
    ): Response {
        $player = checkPlayer();
        $data = $request->all();
        $validator = v::key('amount',
            v::intVal()->notEmpty()->min(100)->setName(trans('withdrawal_amount', [], 'message')))
            ->key('type', v::intVal()->notEmpty()->in([
                PlayerWithdrawRecord::TYPE_THIRD,
                PlayerWithdrawRecord::TYPE_SELF,
                PlayerWithdrawRecord::TYPE_GB,
            ])->setName(trans('withdrawal_type', [], 'message')))
            ->key('bank_id', v::intVal()->setName(trans('withdrawal_bank', [], 'message')))
            ->key('play_password',
                v::stringType()->notEmpty()->alnum()->length(6, 6)->setName(trans('play_password', [], 'message')));
        
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        /** @var Channel $channel */
        $channel = Channel::where('department_id', \request()->department_id)->first();
        if ($player->switch_shop != 1) {
            return jsonFailResponse(trans('payment_function_closed', [], 'message'));
        }
        if ($player->status_withdraw != 1) {
            return jsonFailResponse(trans('player_withdraw_closed', [], 'message'));
        }
        // ✅ 从 Redis 读取实时余额（重要：提现检查）
        $currentBalance = \app\service\WalletService::getBalance($player->id);
        if ($currentBalance < $data['amount']) {
            return jsonFailResponse(trans('insufficient_balance', [], 'message'));
        }
        if (!password_verify($data['play_password'], $player->play_password) || empty($player->play_password)) {
            return jsonFailResponse(trans('play_password_error', [], 'message'));
        }
        switch ($data['type']) {
            case PlayerWithdrawRecord::TYPE_THIRD:
                if ($channel->q_talk_withdraw_status == 0) {
                    return jsonFailResponse(trans('third_withdraw_closed', [], 'message'));
                }
                if (empty($player->talk_user_id)) {
                    return jsonFailResponse(trans('please_bind_chat', [], 'message'), [], 403);
                }
                DB::beginTransaction();
                try {
                    // 生成订单
                    $playerWithdrawRecord = new PlayerWithdrawRecord();
                    $playerWithdrawRecord->player_id = $player->id;
                    $playerWithdrawRecord->talk_user_id = $player->talk_user_id;
                    $playerWithdrawRecord->department_id = $player->department_id;
                    $playerWithdrawRecord->tradeno = createOrderNo();
                    $playerWithdrawRecord->player_name = $player->name ?? '';
                    $playerWithdrawRecord->player_phone = $player->phone ?? '';
                    $playerWithdrawRecord->rate = 1;
                    $playerWithdrawRecord->actual_rate = 1;
                    $playerWithdrawRecord->money = $data['amount'];
                    $playerWithdrawRecord->point = $data['amount']; // Q-talk提现 (1游戏点数 = 1Q币)
                    $playerWithdrawRecord->fee = 0;
                    $playerWithdrawRecord->inmoney = bcsub($data['amount'], $playerWithdrawRecord->fee, 2); // 实际提现金额
                    $playerWithdrawRecord->currency = 'TALK';
                    $playerWithdrawRecord->type = PlayerWithdrawRecord::TYPE_THIRD;
                    $playerWithdrawRecord->status == PlayerWithdrawRecord::STATUS_WAIT;
                    // 请求Q-talk提现
                    $response = Http::timeout(5)->asJson()->post(Config('talk.talk_domain') . '/oauth/withdrawCash', [
                        'appId' => Config('talk.client_id'),
                        'appSecret' => Config('talk.client_secret'),
                        /** TODO 这里需要比例换算(需求待确认) */
                        'amount' => $data['amount'] * 100,// Q-talk提现金额需要乘100
                        'oauthAppWithdrawCashId' => $playerWithdrawRecord->tradeno,
                        'userId' => $playerWithdrawRecord->talk_user_id,
                    ]);
                    $beforeGameAmount = $player->machine_wallet->money;
                    if ($response->status() == 200) {
                        $playerWithdrawRecord->talk_result = $response->body();
                        $data = json_decode($response->body(), true);
                        if (isset($data['code']) && $data['code'] == 1) {
                            $playerWithdrawRecord->status = PlayerWithdrawRecord::STATUS_SUCCESS;
                            $playerWithdrawRecord->finish_time = date('Y-m-d H:i:s');
                            $playerWithdrawRecord->talk_tradeno = $data['data']['oauthWithdrawCashId'];

                            // ✅ Lua 原子性扣款（自动同步数据库）
                            $result = \app\service\WalletService::atomicDecrement(
                                $player->id,
                                $playerWithdrawRecord->point
                            );

                            if ($result['ok'] == 0) {
                                throw new \Exception('余额不足');
                            }
                            // 更新玩家统计
                            $player->player_extend->withdraw_amount = bcadd($player->player_extend->withdraw_amount,
                                $playerWithdrawRecord->point, 2);
                            $player->player_extend->third_withdraw_amount = bcadd($player->player_extend->third_withdraw_amount,
                                $playerWithdrawRecord->point, 2);
                            $player->push();
                            // 更新渠道信息
                            $channel->withdraw_amount = bcadd($channel->withdraw_amount, $playerWithdrawRecord->point,
                                2);
                            $channel->third_withdraw_amount = bcadd($channel->third_withdraw_amount,
                                $playerWithdrawRecord->point, 2);
                            $channel->save();
                        } else {
                            $playerWithdrawRecord->status = PlayerWithdrawRecord::STATUS_FAIL;
                        }
                    } else {
                        $playerWithdrawRecord->status = PlayerWithdrawRecord::STATUS_FAIL;
                    }
                    
                    $playerWithdrawRecord->save();
                    if ($playerWithdrawRecord->status == PlayerWithdrawRecord::STATUS_SUCCESS) {
                        //寫入金流明細
                        $playerDeliveryRecord = new PlayerDeliveryRecord;
                        $playerDeliveryRecord->player_id = $playerWithdrawRecord->player_id;
                        $playerDeliveryRecord->department_id = $playerWithdrawRecord->department_id;
                        $playerDeliveryRecord->target = $playerWithdrawRecord->getTable();
                        $playerDeliveryRecord->target_id = $playerWithdrawRecord->id;
                        $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_WITHDRAWAL;
                        $playerDeliveryRecord->withdraw_status = $playerWithdrawRecord->status;
                        $playerDeliveryRecord->source = 'talk_withdrawal';
                        $playerDeliveryRecord->amount = $playerWithdrawRecord->point;
                        $playerDeliveryRecord->amount_before = $beforeGameAmount;
                        $playerDeliveryRecord->amount_after = $player->machine_wallet->money;
                        $playerDeliveryRecord->tradeno = $playerWithdrawRecord->tradeno ?? '';
                        $playerDeliveryRecord->remark = $playerWithdrawRecord->remark ?? '';
                        $playerDeliveryRecord->save();
                    }
                    
                    DB::commit();
                } catch (Exception $e) {
                    DB::rollBack();
                    return jsonFailResponse(trans('system_error', [], 'message'));
                }
                break;
            case PlayerWithdrawRecord::TYPE_SELF:
                if ($channel->withdraw_status == 0) {
                    return jsonFailResponse(trans('self_withdraw_closed', [], 'message'));
                }
                if (empty($data['bank_id'])) {
                    return jsonFailResponse(trans('please_select_player_bank', [], 'message'));
                }
                /** @var Currency $currency */
                $currency = Currency::where('identifying', $channel->currency)->where('status',
                    1)->whereNull('deleted_at')->first();
                if (empty($currency)) {
                    return jsonFailResponse(trans('currency_no_setting', [], 'message'));
                }
                /** @var PlayerBank $playerBank */
                $playerBank = PlayerBank::where('id', $data['bank_id'])->where('player_id',
                    $player->id)->where('status', 1)->whereNull('deleted_at')->first();
                if (empty($playerBank)) {
                    return jsonFailResponse(trans('player_bank_not_found', [], 'message'));
                }
                $money = bcdiv($data['amount'], $currency->ratio, 2);
                if ($playerBank->type == ChannelRechargeMethod::TYPE_ALI) {
                    if ($money < 500) {
                        return jsonFailResponse(trans('eh_min_withdrawals_error', ['min' => 500], 'message'));
                    }
                    if ($money > 10000) {
                        return jsonFailResponse(trans('eh_max_withdrawals_error', ['max' => 20000], 'message'));
                    }
                }
                if ($playerBank->type == ChannelRechargeMethod::TYPE_USDT) {
                    $rate = getUSDTExchangeRate($channel->currency);
                    if (empty($rate)) {
                        return jsonFailResponse(trans('machine_result_error', [], 'message'));
                    }
                    $money = bcdiv($money, bcadd($rate, 0.1, 2), 2);
                }
                DB::beginTransaction();
                try {
                    // 生成订单
                    $playerWithdrawRecord = new PlayerWithdrawRecord();
                    $playerWithdrawRecord->player_id = $player->id;
                    $playerWithdrawRecord->talk_user_id = $player->talk_user_id;
                    $playerWithdrawRecord->department_id = $player->department_id;
                    $playerWithdrawRecord->tradeno = createOrderNo();
                    $playerWithdrawRecord->player_name = $player->name ?? '';
                    $playerWithdrawRecord->player_phone = $player->phone ?? '';
                    $playerWithdrawRecord->rate = $currency->ratio;
                    $playerWithdrawRecord->actual_rate = $currency->ratio;
                    $playerWithdrawRecord->money = $money;
                    $playerWithdrawRecord->point = $data['amount'];
                    $playerWithdrawRecord->fee = 0;
                    $playerWithdrawRecord->inmoney = bcsub($playerWithdrawRecord->money, $playerWithdrawRecord->fee,
                        2); // 实际提现金额
                    $playerWithdrawRecord->currency = $channel->currency;
                    if ($playerBank->type == ChannelRechargeMethod::TYPE_USDT) {
                        $rate = getUSDTExchangeRate($channel->currency);
                        $playerWithdrawRecord->rate = $rate;
                        $playerWithdrawRecord->actual_rate = bcadd($rate, 0.1, 2);
                        $playerWithdrawRecord->currency = 'USDT';
                    }
                    $playerWithdrawRecord->bank_name = $playerBank->bank_name;
                    $playerWithdrawRecord->account = $playerBank->account;
                    $playerWithdrawRecord->account_name = $playerBank->account_name;
                    $playerWithdrawRecord->wallet_address = $playerBank->wallet_address;
                    $playerWithdrawRecord->qr_code = $playerBank->qr_code;
                    $playerWithdrawRecord->type = PlayerWithdrawRecord::TYPE_SELF;
                    $playerWithdrawRecord->status = PlayerWithdrawRecord::STATUS_WAIT;
                    $playerWithdrawRecord->bank_type = $playerBank->type;
                    $playerWithdrawRecord->save();
                    // ✅ 从 Redis 读取余额（唯一可信源）
                    $beforeGameAmount = \app\service\WalletService::getBalance($player->id);

                    // ✅ Lua 原子性扣款（自动同步数据库）
                    $result = \app\service\WalletService::atomicDecrement(
                        $player->id,
                        $playerWithdrawRecord->point
                    );

                    if ($result['ok'] == 0) {
                        throw new \Exception('余额不足');
                    }
                    // 更新玩家统计
                    $player->player_extend->withdraw_amount = bcadd($player->player_extend->withdraw_amount,
                        $playerWithdrawRecord->point, 2);
                    $player->push();
                    //寫入金流明細
                    $playerDeliveryRecord = new PlayerDeliveryRecord;
                    $playerDeliveryRecord->player_id = $playerWithdrawRecord->player_id;
                    $playerDeliveryRecord->department_id = $playerWithdrawRecord->department_id;
                    $playerDeliveryRecord->target = $playerWithdrawRecord->getTable();
                    $playerDeliveryRecord->target_id = $playerWithdrawRecord->id;
                    $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_WITHDRAWAL;
                    $playerDeliveryRecord->withdraw_status = $playerWithdrawRecord->status;
                    $playerDeliveryRecord->source = 'channel_withdrawal';
                    $playerDeliveryRecord->amount = $playerWithdrawRecord->point;
                    $playerDeliveryRecord->amount_before = $beforeGameAmount;
                    $playerDeliveryRecord->amount_after = $result['balance'];
                    $playerDeliveryRecord->tradeno = $playerWithdrawRecord->tradeno ?? '';
                    $playerDeliveryRecord->remark = $playerWithdrawRecord->remark ?? '';
                    $playerDeliveryRecord->save();
                    DB::commit();
                } catch (Exception) {
                    DB::rollBack();
                    return jsonFailResponse(trans('system_error', [], 'message'));
                }
                
                sendSocketMessage('private-admin_group-channel-' . $player->department_id, [
                    'msg_type' => 'player_create_withdraw_order',
                    'id' => $playerWithdrawRecord->id,
                    'player_id' => $player->id,
                    'player_name' => $player->name,
                    'player_phone' => $player->phone,
                    'money' => $playerWithdrawRecord->money,
                    'point' => $playerWithdrawRecord->point,
                    'status' => $playerWithdrawRecord->status,
                    'tradeno' => $playerWithdrawRecord->tradeno,
                ]);
                
                $notice = new Notice();
                $notice->department_id = $playerWithdrawRecord->department_id;
                $notice->player_id = $playerWithdrawRecord->player_id;
                $notice->source_id = $playerWithdrawRecord->id;
                $notice->type = Notice::TYPE_EXAMINE_WITHDRAW;
                $notice->receiver = Notice::RECEIVER_DEPARTMENT;
                $notice->is_private = 0;
                $notice->title = '渠道提现待审核';
                $notice->content = '提现订单待审核,玩家' . (empty($playerWithdrawRecord->player_name) ? $playerWithdrawRecord->player_name : $playerWithdrawRecord->player_phone) . ', 提现游戏点: ' . $playerWithdrawRecord->point . ' 提现金额: ' . $playerWithdrawRecord->money;
                $notice->save();
                break;
            case PlayerWithdrawRecord::TYPE_GB:
                if ($channel->gb_payment_withdraw_status == 0) {
                    return jsonFailResponse(trans('gb_payment_withdraw_closed', [], 'message'));
                }
                if (empty($data['bank_id'])) {
                    return jsonFailResponse(trans('please_select_player_bank', [], 'message'));
                }
                /** @var PlayerBank $playerBank */
                $playerBank = PlayerBank::query()
                    ->where('id', $data['bank_id'])
                    ->where('player_id', $player->id)
                    ->where('type', ChannelRechargeMethod::TYPE_GB)
                    ->where('status', 1)
                    ->whereNull('deleted_at')->first();
                if (empty($playerBank)) {
                    return jsonFailResponse(trans('player_bank_not_found', [], 'message'));
                }
                DB::beginTransaction();
                try {
                    // 生成订单
                    $playerWithdrawRecord = new PlayerWithdrawRecord();
                    $playerWithdrawRecord->player_id = $player->id;
                    $playerWithdrawRecord->talk_user_id = $player->talk_user_id;
                    $playerWithdrawRecord->department_id = $player->department_id;
                    $playerWithdrawRecord->tradeno = createOrderNo();
                    $playerWithdrawRecord->player_name = $player->name ?? '';
                    $playerWithdrawRecord->player_phone = $player->phone ?? '';
                    $playerWithdrawRecord->rate = 1;
                    $playerWithdrawRecord->actual_rate = 1;
                    $playerWithdrawRecord->money = $data['amount'];
                    $playerWithdrawRecord->point = $data['amount'];
                    $playerWithdrawRecord->fee = 0;
                    $playerWithdrawRecord->inmoney = bcsub($playerWithdrawRecord->money, $playerWithdrawRecord->fee,
                        2); // 实际提现金额
                    $playerWithdrawRecord->currency = 'G';
                    $playerWithdrawRecord->bank_name = $playerBank->bank_name;
                    $playerWithdrawRecord->account = $playerBank->account;
                    $playerWithdrawRecord->account_name = $playerBank->gb_nickname;
                    $playerWithdrawRecord->wallet_address = $playerBank->wallet_address;
                    $playerWithdrawRecord->qr_code = $playerBank->qr_code;
                    $playerWithdrawRecord->type = PlayerWithdrawRecord::TYPE_GB;
                    $playerWithdrawRecord->status = PlayerWithdrawRecord::STATUS_WAIT;
                    $playerWithdrawRecord->bank_type = $playerBank->type;
                    $playerWithdrawRecord->save();

                    // ✅ 从 Redis 读取余额（唯一可信源）
                    $beforeGameAmount = \app\service\WalletService::getBalance($player->id);

                    // ✅ Lua 原子性扣款（自动同步数据库）
                    $result = \app\service\WalletService::atomicDecrement(
                        $player->id,
                        $playerWithdrawRecord->point
                    );

                    if ($result['ok'] == 0) {
                        throw new \Exception('余额不足');
                    }
                    // 更新玩家统计
                    $player->player_extend->withdraw_amount = bcadd($player->player_extend->withdraw_amount,
                        $playerWithdrawRecord->point, 2);
                    $player->push();
                    //寫入金流明細
                    $playerDeliveryRecord = new PlayerDeliveryRecord;
                    $playerDeliveryRecord->player_id = $playerWithdrawRecord->player_id;
                    $playerDeliveryRecord->department_id = $playerWithdrawRecord->department_id;
                    $playerDeliveryRecord->target = $playerWithdrawRecord->getTable();
                    $playerDeliveryRecord->target_id = $playerWithdrawRecord->id;
                    $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_WITHDRAWAL;
                    $playerDeliveryRecord->withdraw_status = $playerWithdrawRecord->status;
                    $playerDeliveryRecord->source = 'gb_withdrawal';
                    $playerDeliveryRecord->amount = $playerWithdrawRecord->point;
                    $playerDeliveryRecord->amount_before = $beforeGameAmount;
                    $playerDeliveryRecord->amount_after = $result['balance'];
                    $playerDeliveryRecord->tradeno = $playerWithdrawRecord->tradeno ?? '';
                    $playerDeliveryRecord->remark = $playerWithdrawRecord->remark ?? '';
                    $playerDeliveryRecord->save();
                    DB::commit();
                } catch (Exception) {
                    DB::rollBack();
                    return jsonFailResponse(trans('system_error', [], 'message'));
                }
                
                sendSocketMessage('private-admin_group-channel-' . $player->department_id, [
                    'msg_type' => 'player_create_withdraw_order',
                    'id' => $playerWithdrawRecord->id,
                    'player_id' => $player->id,
                    'player_name' => $player->name,
                    'player_phone' => $player->phone,
                    'money' => $playerWithdrawRecord->money,
                    'point' => $playerWithdrawRecord->point,
                    'status' => $playerWithdrawRecord->status,
                    'tradeno' => $playerWithdrawRecord->tradeno,
                ]);
                
                $notice = new Notice();
                $notice->department_id = $playerWithdrawRecord->department_id;
                $notice->player_id = $playerWithdrawRecord->player_id;
                $notice->source_id = $playerWithdrawRecord->id;
                $notice->type = Notice::TYPE_EXAMINE_WITHDRAW;
                $notice->receiver = Notice::RECEIVER_DEPARTMENT;
                $notice->is_private = 0;
                $notice->title = '购宝提现待审核';
                $notice->content = '提现订单待审核,玩家' . (empty($playerWithdrawRecord->player_name) ? $playerWithdrawRecord->player_name : $playerWithdrawRecord->player_phone) . ', 提现游戏点: ' . $playerWithdrawRecord->point . ' 提现金额: ' . $playerWithdrawRecord->money;
                $notice->save();
                break;
            default:
                return jsonFailResponse(trans('withdrawal_type_error', [], 'message'));
        }
        if ($playerWithdrawRecord->status == PlayerWithdrawRecord::STATUS_FAIL) {
            return jsonFailResponse(trans($playerWithdrawRecord->type == PlayerWithdrawRecord::TYPE_THIRD ? 'qtalk_withdraw_fail' : 'withdraw_fail',
                [], 'message'));
        }
        
        return jsonSuccessResponse('success', [
            'tradeno' => $playerWithdrawRecord->tradeno,
            'order_id' => $playerWithdrawRecord->id,
            'money' => $playerWithdrawRecord->money,
            'currency' => $playerWithdrawRecord->currency,
            'status' => $playerWithdrawRecord->status,
            'talk_tradeno' => (string)$playerWithdrawRecord->talk_tradeno,
        ]);
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 收藏机台
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public
    function favoriteMachine(
        Request $request
    ): Response {
        $player = checkPlayer();
        $data = $request->all();
        $validator = v::key('machine_id', v::intVal()->notEmpty()->setName(trans('machine_id', [], 'message')));
        
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        /** @var Machine $machine */
        $machine = Machine::where([
            'status' => 1,
            'id' => $data['machine_id'],
        ])->whereHas('machineCategory', function ($query) {
            $query->whereHas('gameType', function ($query) {
                $query->where('status', 1);
            })->where('status', 1);
        })->first();
        
        if (empty($machine)) {
            return jsonFailResponse(trans('machine_not_found', [], 'message'));
        }
        $favorite = PlayerFavoriteMachine::where([
            'player_id' => $player->id,
            'machine_id' => $machine->id,
        ])->first();
        
        if (!empty($favorite)) {
            return jsonFailResponse(trans('machine_has_favorite', [], 'message'));
        }
        
        $playerFavorite = new PlayerFavoriteMachine();
        $playerFavorite->player_id = $player->id;
        $playerFavorite->machine_id = $machine->id;
        $playerFavorite->save();
        
        return jsonSuccessResponse(trans('machine_favorite_success', [], 'message'));
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 取消收藏机台
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public
    function cancelFavoriteMachine(
        Request $request
    ): Response {
        $player = checkPlayer();
        $data = $request->all();
        $validator = v::key('machine_id', v::intVal()->notEmpty()->setName(trans('machine_id', [], 'message')));
        
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        
        $favorite = PlayerFavoriteMachine::where([
            'player_id' => $player->id,
            'machine_id' => $data['machine_id'],
        ])->first();
        if (empty($favorite)) {
            return jsonFailResponse(trans('machine_favorite_not_found', [], 'message'));
        }
        
        $favorite->delete();
        
        return jsonSuccessResponse(trans('cancel_favorite_machine_success', [], 'message'));
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 玩家收藏列表
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public
    function favoriteMachineList(
        Request $request
    ): Response {
        $player = checkPlayer();
        $data = $request->all();
        $validator = v::key('is_free', v::boolVal()->setName(trans('is_free', [], 'message')))
            ->key('page', v::intVal()->setName(trans('page', [], 'message')))
            ->key('size', v::intVal()->setName(trans('size', [], 'message')));
        
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        
        $favoriteMachine = PlayerFavoriteMachine::where('player_id', $player->id)->whereHas('machine',
            function ($query) {
                $query->whereHas('machineCategory', function ($query) {
                    $query->where('status', 1)->whereHas('gameType', function ($query) {
                        $query->where('status', 1);
                    });
                })->where('status', 1);
            })->orderBy('created_at', 'desc');
        if ($data['is_free'] == 1) {
            $favoriteMachine = $favoriteMachine->whereHas('machine', function ($query) {
                $query->where('gaming_user_id', 0);
            });
        }
        $machinesList = $favoriteMachine->forPage($data['page'], $data['size'])->get();
        $lang = locale();
        $lang = Str::replace('_', '-', $lang);
        $list = [];
        /** @var PlayerFavoriteMachine $item */
        foreach ($machinesList as $item) {
            $machineServices = MachineServices::createServices($item->machine, $lang);
            $onlineStatus = 'offline';
            switch ($item->machine->type) {
                case GameType::TYPE_SLOT:
                    if (Gateway::isUidOnline($item->machine->domain . ':' . $item->machine->port) && Gateway::isUidOnline($item->machine->auto_card_domain . ':' . $item->machine->auto_card_port)) {
                        $onlineStatus = 'online';
                    }
                    break;
                case GameType::TYPE_STEEL_BALL:
                    if (Gateway::isUidOnline($item->machine->domain . ':' . $item->machine->port)) {
                        $onlineStatus = 'online';
                    }
                    break;
            }
            $nowTurn = $machineServices->now_turn;
            $machineMedia = $item->machine->machine_media->where('status', 1)->sortBy('sort')->makeHidden([
                'user_id',
                'user_name',
                'deleted_at',
                'created_at',
                'updated_at',
                'push_ip',
                'media_ip'
            ])->all();
            $list[] = [
                'id' => $item->machine_id,
                'type' => $item->machine->type,
                'name' => $item->machine->name,
                'cate_id' => $item->machine->cate_id,
                'code' => $item->machine->code,
                'is_use' => $item->machine->is_use,
                'picture_url' => $item->machine->picture_url,
                'gaming_user_id' => $item->machine->gaming_user_id,
                'maintaining' => $item->machine->maintaining,
                'currency' => $item->machine->currency,
                'odds_x' => $item->machine->type == GameType::TYPE_SLOT ? $item->machine->odds_x : $item->machine->machineCategory->name,
                'odds_y' => $item->machine->type == GameType::TYPE_SLOT ? $item->machine->odds_y : '',
                'correct_rate' => $item->machine->correct_rate,
                'has_playing' => $item->machine->gaming_user_id == $player->id ? 2 : 1,
                'keeping' => $machineServices->keeping,
                'gaming' => $item->machine->gaming,
                'now_turn' => $nowTurn ? intval($nowTurn) : 0,
                'now_point' => $nowTurn > 0 ? intval(ceil($nowTurn / 3)) : 0,
                'keep_seconds' => $machineServices->keep_seconds,
                'reward_status' => $machineServices->reward_status,
                'machine_media' => !empty($machineMedia) ? array_values($machineMedia) : [],
                'online_status' => $onlineStatus,
                'cate_name' => $item->machine->machineCategory->name,
                'turn_used_point' => rtrim(rtrim(number_format($item->machine->machineCategory->turn_used_point, 2, '.',
                    ''), '0'), '.'),
            ];
        }
        
        return jsonSuccessResponse(trans('success', [], 'message'), [
            'machines' => $list,
            'machine_marquee' => SystemSetting::where('feature', 'machine_marquee')->where('status',
                    1)->value('content') ?? 0,
        ]);
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 首页广告(轮播图,跑马灯)
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public function homePageAds(): Response
    {
        checkPlayer();
        $slider = Slider::where('status', 1)
            ->where('department_id', \request()->department_id)
            ->whereNull('deleted_at')
            ->orderBy('sort', 'desc')
            ->get();

        return jsonSuccessResponse('success', [
            'slider_list' => $slider,
            'marquee' => SystemSetting::where('feature', 'marquee')
                    ->where('department_id', \request()->department_id)
                    ->where('status', 1)->value('content') ?? '',
        ]);
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 正在游戏的机台
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public
    function playingMachine(): Response
    {
        $player = checkPlayer();
        
        if (empty($player->channel->status_machine) || empty($player->status_machine)) {
            return jsonFailResponse(trans('platform_no_permission', [], 'message'));
        }
        
        $machinesList = Machine::where('status', 1)
            ->whereHas('machineCategory', function ($query) {
                $query->whereHas('gameType', function ($query) {
                    $query->where('status', 1);
                })->where('status', 1);
            })
            ->where('gaming_user_id', $player->id)
            ->where('maintaining', 0)
            ->where('status', 1)
            ->orderBy('sort')
            ->orderBy('id', 'desc')
            ->get();
        $list = [];
        $lang = locale();
        $lang = Str::replace('_', '-', $lang);
        $ip = request()->getRealIp();
        /** @var Channel $channel */
        $channel = Channel::where('department_id', \request()->department_id)->first();
        /** @var Machine $item */
        foreach ($machinesList as &$item) {
            $services = MachineServices::createServices($item, $lang);
            $item->keeping_user_id = $services->keeping_user_id;
            $item->keeping = $services->keeping;
            $item->keep_seconds = $services->keep_seconds;
            if ($item->type == GameType::TYPE_STEEL_BALL) {
                $item->odds_x = $item->machineCategory->name;
                $item->odds_y = '';
            }
            $machineMedia = $item->machine_media->where('status', 1)->where('is_ams', 1)->sortBy('sort')->makeHidden([
                'user_id',
                'user_name',
                'deleted_at',
                'created_at',
                'updated_at',
                'push_ip',
                'media_ip'
            ])->all();
            $machineInfo['machine_media'] = !empty($machineMedia) ? array_values($machineMedia) : [];
            $machineInfo['online_status'] = 'offline';
            $machineInfo['id'] = $item->id;
            $machineInfo['type'] = $item->type;
            $machineInfo['cate_id'] = $item->cate_id;
            $machineInfo['code'] = $item->code;
            $machineInfo['picture_url'] = $item->picture_url;
            $machineInfo['keeping_user_id'] = $item->keeping_user_id;
            $machineInfo['gaming_user_id'] = $item->gaming_user_id;
            $machineInfo['maintaining'] = $item->maintaining;
            $machineInfo['currency'] = $item->currency;
            $machineInfo['odds_x'] = $item->odds_x;
            $machineInfo['odds_y'] = $item->odds_y;
            $machineInfo['keeping'] = $item->keeping;
            $machineInfo['keep_seconds'] = $item->keep_seconds;
            $machineInfo['reward_status'] = $services->reward_status;
            $machineInfo['machine_label'] = $item->machineLabel;
            $machineInfo['cate_name'] = $item->machineCategory->name;
            $machineInfo['turn_used_point'] = rtrim(rtrim(number_format($item->machineCategory->turn_used_point, 2, '.',
                ''), '0'), '.');
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
            switch ($item->type) {
                case GameType::TYPE_SLOT:
                    if (Gateway::isUidOnline($item->domain . ':' . $item->port) && Gateway::isUidOnline($item->auto_card_domain . ':' . $item->auto_card_port)) {
                        $machineInfo['online_status'] = 'online';
                    }
                    break;
                case GameType::TYPE_STEEL_BALL:
                    if (Gateway::isUidOnline($item->domain . ':' . $item->port)) {
                        $machineInfo['online_status'] = 'online';
                    }
                    break;
            }
            $list[] = $machineInfo;
        }
        
        return jsonSuccessResponse('success', [
            'playing_machine' => $list
        ]);
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 添加银行卡
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public function addBankCard(Request $request): Response
    {
        $player = checkPlayer();
        $data = $request->all();
        $validator = v::key('bank_name',
            v::optional(v::stringType()->length(1, 100)->setName(trans('bank_name', [], 'message'))))
            ->key('type', v::in([
                ChannelRechargeMethod::TYPE_USDT,
                ChannelRechargeMethod::TYPE_ALI,
                ChannelRechargeMethod::TYPE_WECHAT,
                ChannelRechargeMethod::TYPE_BANK
            ])->notEmpty()->setName(trans('recharge_method', [], 'message')))
            ->key('account',
                v::optional(v::stringType()->length(1, 255)->setName(trans('bank_account', [], 'message'))))
            ->key('wallet_address',
                v::optional(v::stringType()->length(1, 255)->setName(trans('wallet_address', [], 'message'))))
            ->key('qr_code', v::optional(v::stringType()->setName(trans('qr_code', [], 'message'))));
        if ($data['type'] == ChannelRechargeMethod::TYPE_WECHAT) {
            return jsonFailResponse(trans('wechat_not_open', [], 'message'));
        }
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        if (!empty($data['account']) && PlayerBank::query()->where('account', $data['account'])->where('type',
                $data['type'])->exists()) {
            return jsonFailResponse(trans('bank_card_has_bind', [], 'message'));
        }
        //USDT判断
        if (!empty($data['wallet_address']) && PlayerBank::query()->where('wallet_address',
                $data['wallet_address'])->where('type',
                $data['type'])->exists()) {
            return jsonFailResponse(trans('bank_card_has_bind', [], 'message'));
        }
        
        try {
            if (!empty($data['qr_code'])) {
                $filePath = uploadBase64ToGCS($data['qr_code'], 'qr_code');
                if (!$filePath) {
                    return jsonFailResponse(trans('failed_to_upload_qr_code', [], 'message'));
                }
            }
            $playerBank = new PlayerBank();
            $playerBank->player_id = $player->id;
            $playerBank->bank_name = $data['bank_name'];
            $playerBank->account = $data['account'];
            $playerBank->account_name = $data['account_name'];
            $playerBank->wallet_address = $data['wallet_address'];
            $playerBank->qr_code = $filePath ?? '';
            $playerBank->type = $data['type'];
            $playerBank->save();
        } catch (Exception $e) {
            return jsonFailResponse($e->getMessage());
        }
        
        return jsonSuccessResponse(trans('add_bank_card_success', [], 'message'));
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 编辑银行卡
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public function editBankCard(Request $request): Response
    {
        $player = checkPlayer();
        $data = $request->all();
        // 验证输入数据
        $validator = v::key('id', v::notEmpty()->intVal()->setName(trans('bank_card_id', [], 'message')))
            ->key('bank_name', v::optional(v::stringType()->length(1, 100)->setName(trans('bank_name', [], 'message'))))
            ->key('account',
                v::optional(v::stringType()->length(1, 255)->setName(trans('bank_account', [], 'message'))))
            ->key('wallet_address',
                v::optional(v::stringType()->length(1, 255)->setName(trans('wallet_address', [], 'message'))))
            ->key('qr_code', v::optional(v::stringType()->setName(trans('qr_code', [], 'message'))));
        
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        
        /** @var PlayerBank $playerBank */
        $playerBank = PlayerBank::query()->where('id', $data['id'])->where('player_id', $player->id)->first();
        if (!$playerBank) {
            return jsonFailResponse(trans('bank_card_not_found', [], 'message'));
        }
        if (!empty($data['account']) && PlayerBank::query()->where('account', $data['account'])->where('id', '!=',
                $playerBank->id)->where('type', $playerBank->type)->exists()) {
            return jsonSuccessResponse(trans('bank_card_has_bind', [], 'message'));
        }
        try {
            if (!empty($data['qr_code']) && !empty($data['qr_code'] != $playerBank->qr_code)) {
                $filePath = uploadBase64ToGCS($data['qr_code'], 'qr_code');
                if (!$filePath) {
                    return jsonFailResponse(trans('failed_to_upload_qr_code', [], 'message'));
                }
                $playerBank->qr_code = $filePath;
            }
            $playerBank->bank_name = $data['bank_name'];
            $playerBank->account = $data['account'];
            $playerBank->account_name = $data['account_name'];
            $playerBank->wallet_address = $data['wallet_address'];
            $playerBank->save();
        } catch (Exception $e) {
            return jsonFailResponse($e->getMessage());
        }
        
        return jsonSuccessResponse(trans('edit_bank_card_success', [], 'message'));
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 删除银行卡
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public function deleteBankCard(Request $request): Response
    {
        $player = checkPlayer();
        $data = $request->all();
        $validator = v::key('id', v::notEmpty()->intVal()->setName(trans('bank_card_id', [], 'message')));
        
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        $playerBank = PlayerBank::query()->where('id', $data['id'])->where('player_id', $player->id)->first();
        if (!$playerBank) {
            return jsonFailResponse(trans('bank_card_not_found', [], 'message'));
        }
        if (!$playerBank->delete()) {
            return jsonFailResponse(trans('delete_bank_card_fail', [], 'message'));
        }
        
        return jsonSuccessResponse(trans('delete_bank_card_success', [], 'message'));
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 银行卡列表
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public
    function bankCardList(
        Request $request
    ): Response {
        $player = checkPlayer();
        $data = $request->all();
        $validator = v::key('page', v::intVal()->setName(trans('page', [], 'message')))
            ->key('size', v::intVal()->setName(trans('size', [], 'message')));
        
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        $list = PlayerBank::where('player_id', $player->id)
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->select(['id', 'bank_name', 'account', 'account_name', 'type', 'wallet_address', 'qr_code'])
            ->forPage($data['page'] ?? 1, $data['size'] ?? 10)
            ->orderBy('created_at', 'desc')
            ->get()
            ->toArray();
        return jsonSuccessResponse('success', [
            'bank_list' => $list,
        ]);
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 获取提现方式
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public
    function getWithdrawalWay(): Response
    {
        $player = checkPlayer();
        $bankList = [];
        /** @var Channel $channel */
        $channel = Channel::where('department_id', \request()->department_id)->first();
        if ($channel->withdraw_status == 1) {
            $rate = getUSDTExchangeRate($channel->currency);
            $typeArr = [
                ChannelRechargeMethod::TYPE_ALI,
                ChannelRechargeMethod::TYPE_WECHAT,
                ChannelRechargeMethod::TYPE_BANK
            ];
            if ($channel->gb_payment_withdraw_status == 1) {
                $typeArr[] = ChannelRechargeMethod::TYPE_GB;
            }
            if (!empty($rate)) {
                $typeArr[] = ChannelRechargeMethod::TYPE_USDT;
                $rate = bcadd($rate, 0.1, 2);
            }
            $bankList = PlayerBank::query()
                ->where('player_id', $player->id)
                ->whereIn('type', $typeArr)
                ->where('status', 1)
                ->get();
        }
        
        return jsonSuccessResponse('success', [
            'withdrawal_way' => $bankList,
            'currency' => $channel->currency,
            'usdt_rate' => $rate ?? 0
        ]);
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 获取充值方式
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public
    function getRechargeMethod(
        Request $request
    ): Response {
        checkPlayer();
        $data = $request->all();
        $validator = v::key('amount', v::intVal()->min(0)->setName(trans('recharge_amount', [], 'message')));
        
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        /** @var Channel $channel */
        $channel = Channel::where('department_id', \request()->department_id)->first();
        if (empty($channel)) {
            return jsonFailResponse(trans('channel_not_found', [], 'message'));
        }
        /** @var Currency $currency */
        $currency = Currency::where('identifying', $channel->currency)->where('status',
            1)->whereNull('deleted_at')->select(['id', 'identifying', 'ratio'])->first();
        if (empty($currency)) {
            return jsonFailResponse(trans('currency_no_setting', [], 'message'));
        }
        $usdRate = getUSDTExchangeRate($channel->currency);
        $money = 0;
        $method = ChannelRechargeMethod::where('status', 1)
            ->where('department_id', $channel->department_id)
            ->where('status', 1)
            ->when($usdRate == null, function ($query) {
                $query->where('type', '!=', ChannelRechargeMethod::TYPE_USDT);
            })
            ->whereNull('deleted_at')
            ->select(['id', 'name', 'max', 'min', 'type', 'amount_limit']);
        if (!empty($data['amount'])) {
            $money = bcdiv($data['amount'], $currency->ratio, 2);
            $method->where(function ($query) use ($money) {
                $query->where(function ($query) use ($money) {
                    $query->where('amount_limit', 1)->where('max', '>=', $money)->where('min', '<=', $money);
                })->orWhere(function ($query) use ($money) {
                    $query->where('amount_limit', 0);
                });
            });
        }
        $methodList = $method->get()->toArray() ?? '';
        
        return jsonSuccessResponse('success', [
            'recharge_method' => $methodList,
            'currency' => $currency,
            'money' => $money,
            'usdt_rate' => bcsub($usdRate, 0.1, 2)
        ]);
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 玩家充值
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException
     * @throws Exception
     */
    public function playerRecharge(Request $request): Response
    {
        $player = checkPlayer();
        if ($player->switch_shop != 1) {
            return jsonFailResponse(trans('payment_function_closed', [], 'message'));
        }
        if (!PlayerBank::query()->where('player_id', $player->id)->where('type',
            ChannelRechargeMethod::TYPE_BANK)->exists()) {
            return jsonFailResponse(trans('bind_bank_card', [], 'message'));
        }
        $data = $request->all();
        $validator = v::key('amount', v::notEmpty()->intVal()->min(0)->setName(trans('recharge_amount', [], 'message')))
            ->key('method_id', v::notEmpty()->intVal()->setName(trans('method_id', [], 'message')));
        
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        /** @var SystemSetting $setting */
        $setting = SystemSetting::where('status', 1)->where('num', '>', 0)->where('feature',
            'recharge_order_expiration')->first();
        if (!empty($setting)) {
            $currentTime = Carbon::now();
            PlayerRechargeRecord::query()
                ->where('player_id', $player->id)
                ->where('status', PlayerRechargeRecord::STATUS_WAIT)
                ->where('type', PlayerRechargeRecord::TYPE_SELF)
                ->where('created_at', '<=', $currentTime->subMinutes($setting->num))
                ->update([
                    'status' => PlayerRechargeRecord::STATUS_RECHARGED_SYSTEM_CANCEL,
                    'cancel_time' => date('Y-m-d H:i:s')
                ]);
        }
        
        $rechargeRecord = PlayerRechargeRecord::query()
            ->where('player_id', $player->id)
            ->where('status', PlayerRechargeRecord::STATUS_WAIT)
            ->where('type', PlayerRechargeRecord::TYPE_SELF)
            ->first();
        if (!empty($rechargeRecord)) {
            return jsonFailResponse(trans('has_not_unfinished_recharge', [], 'message'));
        }
        /** @var Channel $channel */
        $channel = Channel::where('department_id', \request()->department_id)->first();
        if (empty($channel)) {
            return jsonFailResponse(trans('channel_not_found', [], 'message'));
        }
        if ($channel->recharge_status == 0) {
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
        /** @var ChannelRechargeMethod $channelRechargeMethod */
        $channelRechargeMethod = ChannelRechargeMethod::query()->whereNull('deleted_at')->where('status', 1)->first();
        if (empty($channelRechargeMethod)) {
            return jsonFailResponse(trans('recharge_method_error', [], 'message'));
        }
        /** @var ChannelRechargeSetting $channelRechargeSetting */
        $channelRechargeSetting = ChannelRechargeSetting::query()
            ->where('department_id', $channel->department_id)
            ->where('status', 1)
            ->where('method_id', $data['method_id'])
            ->where(function ($query) use ($money) {
                $query->where(function ($query) use ($money) {
                    $query->where('max', '>=', $money);
                })->orWhere(function ($query) use ($money) {
                    $query->whereNull('max');
                });
            })
            ->where(function ($query) use ($money) {
                $query->where(function ($query) use ($money) {
                    $query->where('min', '<=', $money);
                })->orWhere(function ($query) use ($money) {
                    $query->whereNull('min');
                });
            })
            ->whereNull('deleted_at')
            ->inRandomOrder()
            ->select([
                'id',
                'method_id',
                'type',
                'name',
                'bank_name',
                'sub_bank',
                'wallet_address',
                'qr_code',
                'account',
                'currency',
                'max',
                'min'
            ])
            ->first();
        if (empty($channelRechargeSetting)) {
            return jsonFailResponse(trans('channel_recharge_setting_not_found', [], 'message'));
        }
        if ($channelRechargeSetting->type == ChannelRechargeMethod::TYPE_USDT) {
            $rate = getUSDTExchangeRate($channel->currency);
            if (empty($rate)) {
                return jsonFailResponse(trans('machine_result_error', [], 'message'));
            }
            $money = bcdiv($money, bcsub($rate, 0.1, 2), 2);
        }
        if ($channelRechargeSetting->type == ChannelRechargeMethod::TYPE_GB && empty($data['trans_pwd'])) {
            return jsonFailResponse(trans('gb_trans_pwd_require', [], 'message'));
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
            $playerRechargeRecord->money = $money;
            $playerRechargeRecord->inmoney = $money;
            $playerRechargeRecord->rate = $currency->ratio;
            $playerRechargeRecord->actual_rate = $currency->ratio;
            $playerRechargeRecord->setting_id = $channelRechargeSetting->id;
            $playerRechargeRecord->point = $data['amount'];
            $playerRechargeRecord->currency = $channel->currency;
            if ($channelRechargeSetting->type == ChannelRechargeMethod::TYPE_USDT) {
                $rate = getUSDTExchangeRate($channel->currency);
                $playerRechargeRecord->rate = $rate;
                $playerRechargeRecord->actual_rate = bcsub($rate, 0.1, 2);
                $playerRechargeRecord->currency = 'USDT';
            }
            $playerRechargeRecord->type = PlayerRechargeRecord::TYPE_SELF;
            $playerRechargeRecord->status = PlayerRechargeRecord::STATUS_WAIT;
            if ($channelRechargeSetting->type == ChannelRechargeMethod::TYPE_GB) {
                if ($channel->gb_payment_recharge_status == 0) {
                    throw new Exception(trans('gb_payment_recharge_close', [], 'message'));
                }
                $beforeGameAmount = \app\service\WalletService::getBalance($player->id);
                (new GBpayService($player))->fastDeposit($playerRechargeRecord->tradeno, $playerRechargeRecord->money,
                    $data['trans_pwd']);
                $playerRechargeRecord->rate = 1;
                $playerRechargeRecord->actual_rate = 1;
                $playerRechargeRecord->currency = 'G';
                $playerRechargeRecord->type = PlayerRechargeRecord::TYPE_GB;
                $playerRechargeRecord->status = PlayerRechargeRecord::STATUS_RECHARGED_SUCCESS;
                $playerRechargeRecord->finish_time = date('Y-m-d H:i:s');
                $playerRechargeRecord->save();

                // ✅ Lua 原子性加款（自动同步数据库）
                $afterGameAmount = \app\service\WalletService::atomicIncrement(
                    $player->id,
                    $playerRechargeRecord->point
                );
                $player->player_extend->recharge_amount = bcadd($player->player_extend->recharge_amount,
                    $playerRechargeRecord->point, 2);
                $player->push();
                //寫入金流明細
                $playerDeliveryRecord = new PlayerDeliveryRecord;
                $playerDeliveryRecord->player_id = $playerRechargeRecord->player_id;
                $playerDeliveryRecord->department_id = $playerRechargeRecord->department_id;
                $playerDeliveryRecord->target = $playerRechargeRecord->getTable();
                $playerDeliveryRecord->target_id = $playerRechargeRecord->id;
                $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_RECHARGE;
                $playerDeliveryRecord->source = 'gb_recharge';
                $playerDeliveryRecord->amount = $playerRechargeRecord->point;
                $playerDeliveryRecord->amount_before = $beforeGameAmount;
                $playerDeliveryRecord->amount_after = $afterGameAmount;
                $playerDeliveryRecord->tradeno = $playerRechargeRecord->tradeno ?? '';
                $playerDeliveryRecord->remark = $playerRechargeRecord->remark ?? '';
                $playerDeliveryRecord->save();
            } elseif ($channelRechargeSetting->type == ChannelRechargeMethod::TYPE_ALI) {
                if ($channel->eh_payment_recharge_status == 0) {
                    throw new Exception(trans('eh_payment_recharge_close', [], 'message'));
                }
                $payRes = (new EHpayService($player))->deposit($playerRechargeRecord->tradeno,
                    $playerRechargeRecord->money);
                log::channel('eh_pay_server')->info('eh支付', $payRes);
                $playerRechargeRecord->talk_tradeno = $payRes['system_order_number'];
                $playerRechargeRecord->rate = 1;
                $playerRechargeRecord->actual_rate = 1;
                $playerRechargeRecord->type = PlayerRechargeRecord::TYPE_EH;
                $playerRechargeRecord->save();
                DB::commit();
                return jsonSuccessResponse('success', ['casher_url' => $payRes['casher_url']]);
            } else {
                $playerRechargeRecord->save();
            }
            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            Log::error($e->getMessage());
            return jsonFailResponse($e->getMessage());
        }
        if (!empty($setting) && $playerRechargeRecord->type == PlayerRechargeRecord::TYPE_SELF) {
            Client::send('cancel_recharge', ['id' => $playerRechargeRecord->id], 60 * $setting->num);
        }
        return jsonSuccessResponse('success', [
            'id' => $playerRechargeRecord->id,
            'tradeno' => $playerRechargeRecord->tradeno,
            'type' => $playerRechargeRecord->channel_recharge_setting->type,
            'player_name' => $playerRechargeRecord->player_name,
            'player_phone' => $playerRechargeRecord->player_phone,
            'point' => $playerRechargeRecord->point,
            'money' => $playerRechargeRecord->money,
            'inmoney' => $playerRechargeRecord->inmoney,
            'currency' => $playerRechargeRecord->currency,
            'reject_reason' => $playerRechargeRecord->reject_reason,
            'remark' => $playerRechargeRecord->remark,
            'created_at' => strtotime($playerRechargeRecord->created_at),
            'certificate' => $playerRechargeRecord->certificate,
            'setting_id' => $playerRechargeRecord->channel_recharge_setting->id,
            'name' => $playerRechargeRecord->channel_recharge_setting->name,
            'bank_name' => $playerRechargeRecord->channel_recharge_setting->bank_name,
            'wallet_address' => $playerRechargeRecord->channel_recharge_setting->wallet_address,
            'qr_code' => $playerRechargeRecord->channel_recharge_setting->qr_code,
            'account' => $playerRechargeRecord->channel_recharge_setting->account,
            'max' => $playerRechargeRecord->channel_recharge_setting->max,
            'min' => $playerRechargeRecord->channel_recharge_setting->min,
        ]);
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 完成充值
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException
     * @throws Exception
     */
    public
    function completeRecharge(
        Request $request
    ): Response {
        set_time_limit(30);
        $player = checkPlayer();
        $data = $request->post();
        $file = $request->file('certificate');
        $validator = v::key('id', v::notEmpty()->intVal()->setName(trans('recharge_record_id', [], 'message')));
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        /** @var PlayerRechargeRecord $rechargeRecord */
        $rechargeRecord = PlayerRechargeRecord::where('player_id', $player->id)->where('id', $data['id'])->first();
        if (empty($rechargeRecord)) {
            return jsonFailResponse(trans('recharge_record_not_found', [], 'message'));
        }
        switch ($rechargeRecord->status) {
            case PlayerRechargeRecord::STATUS_RECHARGING:
                return jsonFailResponse(trans('recharge_record_review_in_progress', [], 'message'));
            case PlayerRechargeRecord::STATUS_RECHARGED_SUCCESS:
                return jsonFailResponse(trans('recharge_completed', [], 'message'));
            case PlayerRechargeRecord::STATUS_RECHARGED_FAIL:
                return jsonFailResponse(trans('recharge_failed', [], 'message'));
            case PlayerRechargeRecord::STATUS_RECHARGED_CANCEL:
                return jsonFailResponse(trans('player_has_cancelled_recharge', [], 'message'));
            case PlayerRechargeRecord::STATUS_RECHARGED_REJECT:
                return jsonFailResponse(trans('recharge_order_review_failed', [], 'message'));
            case PlayerRechargeRecord::STATUS_RECHARGED_SYSTEM_CANCEL:
                return jsonFailResponse(trans('system_cancels_order', [], 'message'));
        }
        try {
            if ($file && $file->isValid()) {
                // 文件大小验证 (2MB)
                if ($file->getSize() >= 1024 * 1024 * 2) {
                    return jsonFailResponse(trans('image_upload_size_fail', ['{size}' => '2M'], 'message'));
                }
                
                // 文件类型验证
                $extension = strtolower($file->getUploadExtension());
                $allowedExtensions = ['png', 'jpg', 'jpeg'];
                if (!in_array($extension, $allowedExtensions)) {
                    return jsonFailResponse(trans('image_upload_type_fail', [], 'message'));
                }
                
                $mimeType = $file->getUploadMimeType();
                $allowedMimes = ['image/jpeg', 'image/jpg', 'image/png'];
                if (!in_array($mimeType, $allowedMimes)) {
                    return jsonFailResponse(trans('image_upload_type_fail', [], 'message'));
                }
                
                try {
                    // 使用 Google Cloud Storage
                    $storage = Filesystem::disk('google_oss');
                    
                    // 生成唯一文件名
                    $saveFilename = time() . '_' . uniqid() . '.' . $extension;
                    $cloudPath = 'certificate/' . date('Ymd') . '/' . $saveFilename;
                    
                    // 读取文件内容
                    $fileContent = file_get_contents($file->getPathname());
                    
                    // 上传到 GCS
                    $result = $storage->put($cloudPath, $fileContent, [
                        'metadata' => [
                            'contentType' => $mimeType,
                            'cacheControl' => 'public, max-age=31536000', // 缓存1年
                        ]
                    ]);
                    
                    if ($result) {
                        // 获取公开访问 URL
                        $filePath = $storage->url($cloudPath);
                        Log::info('证书文件上传成功: ' . $filePath);
                    } else {
                        Log::error('证书文件上传失败，存储返回 false');
                        $filePath = null;
                    }
                    
                } catch (Exception $e) {
                    Log::error('证书文件上传异常: ' . $e->getMessage());
                    $filePath = null;
                }
            } else {
                $filePath = null;
            }
            
            if (!$filePath) {
                return jsonFailResponse(trans('failed_to_upload_recharge_voucher', [], 'message'));
            }
        } catch (Exception $e) {
            Log::error($e->getMessage());
            return jsonFailResponse(trans('image_upload_fail', [], 'message'));
        }
        $rechargeRecord->status = PlayerRechargeRecord::STATUS_RECHARGING;
        $rechargeRecord->certificate = $filePath;
        $rechargeRecord->save();
        
        sendSocketMessage('private-admin_group-channel-' . $player->department_id, [
            'msg_type' => 'player_examine_recharge_order',
            'id' => $rechargeRecord->id,
            'player_id' => $player->id,
            'player_name' => $player->name,
            'player_phone' => $player->phone,
            'money' => $rechargeRecord->money,
            'status' => $rechargeRecord->status,
            'tradeno' => $rechargeRecord->tradeno,
        ]);
        
        $notice = new Notice();
        $notice->department_id = $rechargeRecord->department_id;
        $notice->player_id = $rechargeRecord->player_id;
        $notice->source_id = $rechargeRecord->id;
        $notice->type = Notice::TYPE_EXAMINE_RECHARGE;
        $notice->receiver = Notice::RECEIVER_DEPARTMENT;
        $notice->is_private = 0;
        $notice->title = '充值待稽核';
        $notice->content = '充值訂單待稽核，玩家' . (empty($rechargeRecord->player_name) ? $rechargeRecord->player_name : $rechargeRecord->player_phone) . ', 充值遊戲點: ' . $rechargeRecord->point . ' 充值金額: ' . $rechargeRecord->money;
        $notice->save();
        
        return jsonSuccessResponse('success');
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 取消充值
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException
     * @throws Exception
     */
    public
    function cancelRecharge(
        Request $request
    ): Response {
        $player = checkPlayer();
        $data = $request->all();
        $validator = v::key('id', v::notEmpty()->intVal()->setName(trans('recharge_record_id', [], 'message')));
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        /** @var PlayerRechargeRecord $rechargeRecord */
        $rechargeRecord = PlayerRechargeRecord::where('player_id', $player->id)->where('id', $data['id'])->first();
        if (empty($rechargeRecord)) {
            return jsonFailResponse(trans('recharge_record_not_found', [], 'message'));
        }
        switch ($rechargeRecord->status) {
            case PlayerRechargeRecord::STATUS_RECHARGED_SUCCESS:
                return jsonFailResponse(trans('recharge_completed', [], 'message'));
            case PlayerRechargeRecord::STATUS_RECHARGED_FAIL:
                return jsonFailResponse(trans('recharge_failed', [], 'message'));
            case PlayerRechargeRecord::STATUS_RECHARGED_CANCEL:
                return jsonFailResponse(trans('player_has_cancelled_recharge', [], 'message'));
            case PlayerRechargeRecord::STATUS_RECHARGED_REJECT:
                return jsonFailResponse(trans('recharge_order_review_failed', [], 'message'));
            case PlayerRechargeRecord::STATUS_RECHARGED_SYSTEM_CANCEL:
                return jsonFailResponse(trans('system_cancels_order', [], 'message'));
        }
        try {
            $rechargeRecord->status = PlayerRechargeRecord::STATUS_RECHARGED_CANCEL;
            $rechargeRecord->save();
            
        } catch (Exception $e) {
            Log::error($e->getMessage());
            return jsonFailResponse($e->getMessage());
        }
        
        return jsonSuccessResponse('success');
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 获取充值订单
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException
     * @throws Exception
     */
    public
    function getRecharge(
        Request $request
    ): Response {
        $player = checkPlayer();
        $data = $request->all();
        $validator = v::key('id', v::intVal()->setName(trans('recharge_record_id', [], 'message')), false);
        
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        $rechargeRecordModel = PlayerRechargeRecord::where('player_id', $player->id)
            ->where('type', PlayerRechargeRecord::TYPE_SELF);
        if (isset($data['id']) && !empty($data['id'])) {
            $rechargeRecordModel->where('id', $data['id']);
        } else {
            $rechargeRecordModel->where('status', PlayerRechargeRecord::STATUS_WAIT);
        }
        /** @var PlayerRechargeRecord $rechargeRecord */
        $rechargeRecord = $rechargeRecordModel->first();
        if (empty($rechargeRecord)) {
            return jsonSuccessResponse('success', [
                'recharge_record' => []
            ]);
        }
        
        return jsonSuccessResponse('success', [
            'recharge_record' => [
                'id' => $rechargeRecord->id,
                'tradeno' => $rechargeRecord->tradeno,
                'type' => $rechargeRecord->channel_recharge_setting->type,
                'player_name' => $rechargeRecord->player_name,
                'player_phone' => $rechargeRecord->player_phone,
                'point' => $rechargeRecord->point,
                'money' => $rechargeRecord->money,
                'inmoney' => $rechargeRecord->inmoney,
                'currency' => $rechargeRecord->currency,
                'reject_reason' => $rechargeRecord->reject_reason,
                'remark' => $rechargeRecord->remark,
                'created_at' => strtotime($rechargeRecord->created_at),
                'certificate' => $rechargeRecord->certificate,
                'setting_id' => $rechargeRecord->channel_recharge_setting->id,
                'name' => $rechargeRecord->channel_recharge_setting->name,
                'bank_name' => $rechargeRecord->channel_recharge_setting->bank_name,
                'wallet_address' => $rechargeRecord->channel_recharge_setting->wallet_address,
                'qr_code' => $rechargeRecord->channel_recharge_setting->qr_code,
                'account' => $rechargeRecord->channel_recharge_setting->account,
                'max' => $rechargeRecord->channel_recharge_setting->max,
                'min' => $rechargeRecord->channel_recharge_setting->min,
            ]
        ]);
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 玩家头像
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException
     * @throws Exception
     */
    public function uploadAvatar(Request $request): Response
    {
        $player = checkPlayer();
        $data = $request->post();
        $validator = v::key('avatar', v::notEmpty()->stringVal()->setName(trans('player_avatar', [], 'message')));
        
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        
        try {
            $filePath = uploadBase64ToGCS($data['avatar']);
            
            if (!$filePath) {
                return jsonFailResponse(trans('failed_to_upload_recharge_voucher', [], 'message'));
            }
            $player->avatar = $filePath;
            $player->save();
            
            return jsonSuccessResponse('success', [
                'avatar' => $player->avatar
            ]);
            
        } catch (Exception $e) {
            Log::error($e->getMessage());
            return jsonFailResponse($e->getMessage());
        }
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 富豪榜
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public
    function rankingList(
        Request $request
    ): Response {
        $player = checkPlayer();
        $list = Player::query()
            ->select([
                'player.id',
                'player.name',
                'player.phone',
                'player_platform_cash.money',
                'player.avatar',
                'player.uuid'
            ])
            ->leftJoin('player_platform_cash', 'player.id', '=', 'player_platform_cash.player_id')
            ->where('player.status', 1)
            ->where('player.department_id', $request->department_id)
            ->whereNull('player.deleted_at')
            ->orderBy('player_platform_cash.money', 'desc')
            ->limit(50)
            ->get()
            ->toArray();
        return jsonSuccessResponse('success', [
            'list' => $list,
            'my_ranking' => [
                'id' => $player->id,
                'name' => $player->name,
                'phone' => $player->phone,
                'money' => \app\service\WalletService::getBalance($player->id), // ✅ Redis 实时余额
                'avatar' => $player->avatar,
                'ranking' => Player::query()
                        ->leftJoin('player_platform_cash', 'player.id', '=', 'player_platform_cash.player_id')
                        ->where('player.status', 1)
                        ->where('player.department_id', $request->department_id)
                        ->where('player_platform_cash.money', '>', $player->machine_wallet->money)
                        ->whereNull('player.deleted_at')
                        ->count() + 1
            ]
        ]);
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 银行卡绑定检测
     * @return Response
     * @throws PlayerCheckException
     * @throws Exception
     */
    public function checkBindBankcard(): Response
    {
        $player = checkPlayer();
        
        if (!PlayerBank::query()->where('player_id', $player->id)->where('type',
            ChannelRechargeMethod::TYPE_BANK)->exists()) {
            return jsonFailResponse(trans('bind_bank_card', [], 'message'));
        }
        
        return jsonSuccessResponse('success');
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 银行卡列表
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public function bankList(): Response
    {
        $player = checkPlayer();
        $lang = locale();
        $lang = Str::replace('_', '-', $lang);
        $list = BankContent::query()
            ->where('lang', $lang)
            ->whereHas('bank', function ($query) use ($player) {
                $query->where('department_id', $player->department_id)
                    ->where('status', 1);
            })
            ->select('name', 'pic')
            ->get()->toArray();
        
        return jsonSuccessResponse('success', [
            'bank_list' => $list,
        ]);
    }
    
    
    /**
     * 反水列表
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException
     * @throws \think\Exception
     */
    public function reverseWaterList(Request $request): Response
    {
        $data = $request->all();
        
        $player = checkPlayer();
        //获取用户
        $level = $player->national_promoter()->first()?->level_list()->first();
        $levelInfo = [
            'name' => $level ? $level->national_level()?->first()->name : '',
            'ratio' => $level->reverse_water ?? 0,
        ];
        
        $reverseWaterModel = PlayerReverseWaterDetail::query()->where('player_id', $player->id);
        
        $total = (clone $reverseWaterModel)->sum('reverse_water');
        $list = (clone $reverseWaterModel)->selectRaw('date,max(level_ratio) as level_ratio,sum(point) as all_point,sum(reverse_water) all_reverse_water')->forPage($data['page'] ?? 1,
            $data['size'] ?? 10)
            ->orderBy('date', 'desc')
            ->groupBy('date')
            ->get()
            ->toArray();
        
        $today = Carbon::today()->format('Y-m-d 00:00:00');
        
        //今日总打码量
        $playGameModel = PlayGameRecord::query()->where('created_at', '>=', $today)->where('player_id', $player->id);
        $todayTotal = (clone $playGameModel)->sum('bet');
        $todayDetail = (clone $playGameModel)->with(['gamePlatform:id,name'])->selectRaw('platform_id,sum(bet) as bet')->groupBy('platform_id')->get()->toArray();
        
        return jsonSuccessResponse('success', [
            'list' => $list,
            'total' => $total,
            'level_info' => $levelInfo,
            'today' => [
                'total' => $todayTotal,
                'detail' => $todayDetail
            ]
        ]);
    }
    
    /**
     * 获取反水详情
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException
     */
    public function getReverseWaterDetail(Request $request): Response
    {
        $data = $request->all();
        $player = checkPlayer();
        
        $reverseWaterModel = PlayerReverseWaterDetail::query()
            ->with(['platform:id,name,logo'])
            ->where('player_id', $player->id)
            ->where('date', $data['date'])
            ->get()
            ->toArray();
        
        return jsonSuccessResponse('success', $reverseWaterModel);
    }
    
    /**
     * 领取反水奖励
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException
     */
    public function receiveReverseWater(Request $request): Response
    {
        $data = $request->all();
        $player = checkPlayer();
        
        //查找反水日期
        $id = Notice::query()->where('id', $data['id'])->value('source_id');
        
        $playerReverseWaterDetail = PlayerReverseWaterDetail::query()
            ->where('id', $id)
            ->first();
        
        //获取用户该日期的所有反水金额
        $reverseWater = PlayerReverseWaterDetail::query()
            ->where('player_id', $player->id)
            ->where('settled_date', $playerReverseWaterDetail->settled_date)
            ->where('status', PlayerReverseWaterDetail::STATUS_UNRECEIVED)
            ->where('is_settled', 1)
            ->where('switch', 1)
            ->sum('reverse_water');
        
        if ($reverseWater <= 0) {
            return jsonSuccessResponse('success');
        }
        
        DB::beginTransaction();
        try {
            // ✅ 从 Redis 读取余额（唯一可信源）
            $beforeGameAmount = \app\service\WalletService::getBalance($player->id);

            // ✅ Lua 原子性加款（自动同步数据库）
            $afterGameAmount = \app\service\WalletService::atomicIncrement(
                $player->id,
                $reverseWater
            );
            // 寫入金流明細
            $playerDeliveryRecord = new PlayerDeliveryRecord;
            $playerDeliveryRecord->player_id = $player->id;
            $playerDeliveryRecord->department_id = $player->department_id;
            $playerDeliveryRecord->target = $playerReverseWaterDetail->getTable();
            $playerDeliveryRecord->target_id = $id;
            $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_REVERSE_WATER;
            $playerDeliveryRecord->source = 'reverse_water';
            $playerDeliveryRecord->amount = $reverseWater;
            $playerDeliveryRecord->amount_before = $beforeGameAmount;
            $playerDeliveryRecord->amount_after = $afterGameAmount;
            $playerDeliveryRecord->tradeno = '';
            $playerDeliveryRecord->remark = '';
            $playerDeliveryRecord->save();
            
            PlayerReverseWaterDetail::query()
                ->where('player_id', $player->id)
                ->where('settled_date', $playerReverseWaterDetail->settled_date)
                ->where('status', PlayerReverseWaterDetail::STATUS_UNRECEIVED)
                ->update(['status' => PlayerReverseWaterDetail::STATUS_RECEIVED, 'receive_time' => Carbon::now()]);
            DB::commit();
        } catch (Exception) {
            DB::rollBack();
            return jsonFailResponse(trans('system_error', [], 'message'));
        }
        
        return jsonSuccessResponse('success');
    }
    
    /**
     * 增加观看人数
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException
     */
    public function addViewers(Request $request): Response
    {
        $player = checkPlayer();
        $data = $request->post();
        $validator = v::key('machine_media_push_id', v::intVal()->setName(trans('id', [], 'message')));
        $viewers = [];
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        if (Cache::has('viewers_num_' . $data['machine_media_push_id'])) {
            $viewers = Cache::get('viewers_num_' . $data['machine_media_push_id']);
            if (!in_array($player->id, $viewers)) {
                $viewers[] = $player->id;
            }
        }
        Cache::set('viewers_num_' . $data['machine_media_push_id'], $viewers);
        
        return jsonSuccessResponse('success', ['viewers_num' => count($viewers)]);
    }
    
    /**
     * 减少观看人数
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException
     */
    public function deductViewers(Request $request): Response
    {
        $player = checkPlayer();
        $data = $request->post();
        $validator = v::key('machine_media_push_id', v::intVal()->setName(trans('id', [], 'message')));
        $viewers = [];
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        if (Cache::has('viewers_num_' . $data['machine_media_push_id'])) {
            $viewers = Cache::get('viewers_num_' . $data['machine_media_push_id']);
            $viewers = array_diff($viewers, [$player->id]);
        }
        Cache::set('viewers_num_' . $data['machine_media_push_id'], $viewers);
        
        return jsonSuccessResponse('success', ['viewers_num' => count($viewers)]);
    }

    /**
     * 机台按钮开分
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException
     */
    public function openScore(Request $request): Response
    {
        $player = checkPlayer();
        $data = $request->post();
        // 验证必需参数：score_option (score_1, score_2, score_3, score_4, score_5, score_6, default_scores, custom)
        $validator = v::key('score_option', v::in(['score_1', 'score_2', 'score_3', 'score_4', 'score_5', 'score_6', 'default_scores', 'custom'])->notEmpty()->setName('开分选项'));
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }

        // 如果选择custom选项，验证custom_amount参数
        if ($data['score_option'] === 'custom') {
            $customValidator = v::key('custom_amount', v::numericVal()->min(1)->max(200000)->notEmpty()->setName('自定义金额'));
            try {
                $customValidator->assert($data);
            } catch (AllOfException $e) {
                return jsonFailResponse(getValidationMessages($e));
            }
        }

        // 爆机检查：玩家不能开分
        $crashCheck = checkMachineCrash($player);
        if ($crashCheck['crashed']) {
            return jsonFailResponse(trans('machine_crashed_cannot_open_score', [], 'message'));
        }

        /** @var Channel $channel */
        $channel = Channel::query()->where('department_id', \request()->department_id)->first();
        if (empty($channel)) {
            return jsonFailResponse(trans('channel_not_found', [], 'message'));
        }
        if ($channel->recharge_status == 0) {
            return jsonFailResponse(trans('recharge_closed', [], 'message'));
        }

        // 根据客户端传递的开分选项获取对应的分数
        $scoreOption = $data['score_option'];

        if ($scoreOption === 'custom') {
            // 使用自定义金额
            $scoreAmount = $data['custom_amount'];
        } else {
            // 获取店家开分配置
            // 新架构：使用 store_admin_id 查找店家的开分配置
            if (empty($player->store_admin_id)) {
                return jsonFailResponse(trans('player_not_bind_store', [], 'message'));
            }

            /** @var OpenScoreSetting $openScoreSetting */
            $openScoreSetting = OpenScoreSetting::query()->where('admin_user_id', $player->store_admin_id)->first();
            if (empty($openScoreSetting)) {
                return jsonFailResponse(trans('open_point_config_not_found', [], 'message'));
            }

            $scoreAmount = $openScoreSetting->$scoreOption;
        }

        if ($scoreAmount <= 0) {
            return jsonFailResponse(trans('open_point_amount_invalid', [], 'message'));
        }

        /** @var Currency $currency */
        $currency = Currency::query()->where('identifying', $channel->currency)->where('status',
            1)->whereNull('deleted_at')->select(['id', 'identifying', 'ratio'])->first();
        if (empty($currency)) {
            return jsonFailResponse(trans('currency_no_setting', [], 'message'));
        }

        // 计算实际充值金额（分数转换为货币）
        $money = bcdiv($scoreAmount, $currency->ratio, 2);
        if ($money <= 0) {
            return jsonFailResponse(trans('currency_no_setting', [], 'message'));
        }

        // ✅ 从 Redis 读取余额（唯一可信源）- 在事务外读取
        $beforeGameAmount = \app\service\WalletService::getBalance($player->id);

        DB::beginTransaction();
        try {
            // 生成订单
            $playerRechargeRecord = new  PlayerRechargeRecord();
            $playerRechargeRecord->player_id = $player->id;
            $playerRechargeRecord->talk_user_id = $player->talk_user_id;
            $playerRechargeRecord->department_id = $player->department_id;
            $playerRechargeRecord->tradeno = createOrderNo();
            $playerRechargeRecord->player_name = $player->name ?? '';
            $playerRechargeRecord->player_phone = $player->phone ?? '';
            $playerRechargeRecord->money = $money;
            $playerRechargeRecord->inmoney = $money;
            $playerRechargeRecord->currency = $currency->identifying;
            $playerRechargeRecord->type = PlayerRechargeRecord::TYPE_ARTIFICIAL;
            $playerRechargeRecord->point = $scoreAmount;
            $playerRechargeRecord->status = PlayerRechargeRecord::STATUS_RECHARGED_SUCCESS;
            $playerRechargeRecord->remark = "線下代理開分：{$scoreOption}";
            $playerRechargeRecord->finish_time = date('Y-m-d H:i:s');
            $playerRechargeRecord->user_id = 0;
            $playerRechargeRecord->user_name = '';
            $playerRechargeRecord->save();

            // ✅ Lua 原子性加款（自动同步数据库）
            $afterGameAmount = \app\service\WalletService::atomicIncrement(
                $player->id,
                $playerRechargeRecord->point
            );

            // 更新玩家充值统计
            $player->player_extend->recharge_amount = bcadd($player->player_extend->recharge_amount,
                $playerRechargeRecord->point, 2);
            $player->push();

            // 写入金流明细
            $playerDeliveryRecord = new PlayerDeliveryRecord;
            $playerDeliveryRecord->player_id = $playerRechargeRecord->player_id;
            $playerDeliveryRecord->department_id = $playerRechargeRecord->department_id;
            $playerDeliveryRecord->target = $playerRechargeRecord->getTable();
            $playerDeliveryRecord->target_id = $playerRechargeRecord->id;
            $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_RECHARGE;
            $playerDeliveryRecord->source = 'artificial_recharge';
            $playerDeliveryRecord->amount = $playerRechargeRecord->point;
            $playerDeliveryRecord->amount_before = $beforeGameAmount;
            $playerDeliveryRecord->amount_after = $afterGameAmount;
            $playerDeliveryRecord->tradeno = $playerRechargeRecord->tradeno ?? '';
            $playerDeliveryRecord->remark = '線下代理開分';
            $playerDeliveryRecord->save();

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e->getMessage());
            return jsonFailResponse(trans('system_error', [], 'message'));
        }

        // ✅ 事务提交后检查爆机状态（避免嵌套事务冲突）
        \app\service\WalletService::checkMachineCrashAfterTransaction($player->id, $afterGameAmount, $beforeGameAmount);

        return jsonSuccessResponse('success');
    }

    /**
     * 获取店家配置
     * @param Player $player 当前玩家
     * @return array 店家配置数组
     */
    private function getStoreSettings(Player $player): array
    {
        // 默认值
        $settings = [
            'home_notice' => '', // 默认空字符串
            'enable_physical_machine' => true, // 默认开启
            'enable_live_baccarat' => true, // 默认开启
        ];

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

        // 获取配置 - 首页提醒消息
        $homeNoticeSetting = StoreSetting::getSetting(
            'home_notice',
            $player->department_id,
            null,
            $adminUserId
        );
        if ($homeNoticeSetting && $homeNoticeSetting->status == 1 && !empty($homeNoticeSetting->content)) {
            $settings['home_notice'] = $homeNoticeSetting->content;
        }

        // 获取配置 - 是否开启实体机台（直接使用 status 字段）
        $physicalMachineSetting = StoreSetting::getSetting(
            'enable_physical_machine',
            $player->department_id,
            null,
            $adminUserId
        );
        if ($physicalMachineSetting) {
            $settings['enable_physical_machine'] = $physicalMachineSetting->status == 1;
        }

        // 获取配置 - 是否开启真人百家（直接使用 status 字段）
        $liveBaccaratSetting = StoreSetting::getSetting(
            'enable_live_baccarat',
            $player->department_id,
            null,
            $adminUserId
        );
        if ($liveBaccaratSetting) {
            $settings['enable_live_baccarat'] = $liveBaccaratSetting->status == 1;
        }

        return $settings;
    }

    /**
     * 开分记录列表
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException
     */
    public function getOpenScoreRecords(Request $request): Response
    {
        $player = checkPlayer();
        $data = $request->all();
        // 构建查询（包含充值和投钞记录）
        $query = PlayerDeliveryRecord::query()
            ->where('player_id', $player->id)
            ->whereIn('type', [
                PlayerDeliveryRecord::TYPE_RECHARGE,
                PlayerDeliveryRecord::TYPE_MACHINE
            ])
            ->orderBy('id', 'desc');

        // 按金额筛选
        if (!empty($data['score_option'])) {
            $query->where('amount', $data['score_option']);
        } elseif (!empty($data['custom'])) {
            $query->where('amount', $data['custom']);
        }

        // 分页查询
        $records = $query->forPage($data['page'] ?? 1, $data['size'] ?? 10)->get();

        $list = [];
        /** @var PlayerDeliveryRecord $record */
        foreach ($records as $record) {
            // 类型名称映射
            $typeName = match ($record->type) {
                PlayerDeliveryRecord::TYPE_RECHARGE => trans('recharge', [], 'message'),
                PlayerDeliveryRecord::TYPE_MACHINE => trans('machine_coin_deposit', [], 'message'),
                default => trans('other', [], 'message'),
            };

            $list[] = [
                'id' => $record->id,
                'type' => $record->type,
                'type_name' => $typeName,
                'amount' => $record->amount,
                'amount_before' => $record->amount_before,
                'amount_after' => $record->amount_after,
                'remark' => $record->remark,
                'tradeno' => $record->tradeno,
                'created_at' => date('Y-m-d H:i:s', strtotime($record->created_at)),
            ];
        }

        return jsonSuccessResponse('success', [
            'list' => $list,
        ]);
    }

    /**
     * 洗分记录列表
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException
     */
    public function getPresentAutoRecords(Request $request): Response
    {
        $player = checkPlayer();
        $data = $request->all();

        // 构建查询
        $query = PlayerDeliveryRecord::query()
            ->where('player_id', $player->id)
            ->where('type', PlayerDeliveryRecord::TYPE_WITHDRAWAL)
            ->orderBy('id', 'desc');

        // 分页查询
        $records = $query->forPage($data['page'] ?? 1, $data['size'] ?? 10)->get();

        $list = [];
        /** @var PlayerDeliveryRecord  $record */
        foreach ($records as $record) {
            $statusText = match ($record->withdraw_status) {
                1 => trans('withdraw_status_processing', [], 'message'),
                2 => trans('withdraw_status_success', [], 'message'),
                3 => trans('withdraw_status_failed', [], 'message'),
                4 => trans('withdraw_status_pending_payment', [], 'message'),
                5 => trans('withdraw_status_rejected', [], 'message'),
                6 => trans('withdraw_status_player_canceled', [], 'message'),
                7 => trans('withdraw_status_system_canceled', [], 'message'),
                default => trans('withdraw_status_unknown', [], 'message'),
            };

            $list[] = [
                'id' => $record->id,
                'amount' => $record->amount,
                'amount_before' => $record->amount_before,
                'amount_after' => $record->amount_after,
                'withdraw_status' => $record->withdraw_status,
                'withdraw_status_text' => $statusText,
                'remark' => $record->remark,
                'tradeno' => $record->tradeno,
                'created_at' => date('Y-m-d H:i:s', strtotime($record->created_at)),

            ];
        }

        return jsonSuccessResponse('success', [
            'list' => $list,
        ]);
    }

    /**
     * 彩金中奖记录列表
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException
     */
    public function getLotteryRecords(Request $request): Response
    {
        $player = checkPlayer();
        $data = $request->all();

        // 构建查询
        $query = PlayerLotteryRecord::query()
            ->where('player_id', $player->id)
            ->orderBy('id', 'desc');

        // 按状态筛选
        if (isset($data['status']) && $data['status'] !== '') {
            $query->where('status', $data['status']);
        }

        // 按来源筛选
        if (isset($data['source']) && $data['source'] !== '') {
            $query->where('source', $data['source']);
        }

        // 分页查询
        $records = $query->forPage($data['page'] ?? 1, $data['size'] ?? 10)->get();

        $list = [];
        foreach ($records as $record) {
            // 状态文本
            $statusText = match ($record->status) {
                PlayerLotteryRecord::STATUS_UNREVIEWED => trans('lottery_status_unreviewed', [], 'message'),
                PlayerLotteryRecord::STATUS_REJECT => trans('lottery_status_rejected', [], 'message'),
                PlayerLotteryRecord::STATUS_PASS => trans('lottery_status_passed', [], 'message'),
                PlayerLotteryRecord::STATUS_COMPLETE => trans('lottery_status_completed', [], 'message'),
                default => trans('lottery_status_unknown', [], 'message'),
            };

            // 来源文本
            $sourceText = match ($record->source) {
                PlayerLotteryRecord::SOURCE_MACHINE => trans('lottery_source_machine', [], 'message'),
                PlayerLotteryRecord::SOURCE_GAME => trans('lottery_source_game', [], 'message'),
                PlayerLotteryRecord::SOURCE_MANUAL => trans('lottery_source_manual', [], 'message'),
                default => trans('lottery_source_unknown', [], 'message'),
            };

            $list[] = [
                'id' => $record->id,
                'lottery_name' => $record->lottery_name,
                'amount' => $record->amount,
                'bet' => $record->bet,
                'odds' => $record->odds,
                'machine_name' => $record->machine_name,
                'machine_code' => $record->machine_code,
                'lottery_pool_amount' => $record->lottery_pool_amount,
                'lottery_rate' => $record->lottery_rate,
                'lottery_type' => $record->lottery_type,
                'lottery_multiple' => $record->lottery_multiple,
                'status' => $record->status,
                'status_text' => $statusText,
                'source' => $record->source,
                'source_text' => $sourceText,
                'reject_reason' => $record->reject_reason,
                'audit_at' => $record->audit_at ? date('Y-m-d H:i:s', strtotime($record->audit_at)) : null,
                'created_at' => $record->created_at ? date('Y-m-d H:i:s', strtotime($record->created_at)) : null,
            ];
        }

        return jsonSuccessResponse('success', [
            'list' => $list,
        ]);
    }
}
