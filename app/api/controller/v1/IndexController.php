<?php

namespace app\api\controller\v1;

use app\model\Announcement;
use app\model\Channel;
use app\model\GameExtend;
use app\model\LevelList;
use app\model\Lottery;
use app\model\NationalPromoter;
use app\model\Notice;
use app\model\PhoneSmsLog;
use app\model\Player;
use app\model\PlayerActivityPhaseRecord;
use app\model\PlayerLotteryRecord;
use app\model\PlayerRechargeRecord;
use app\model\PlayerRegisterRecord;
use app\model\PlayerReverseWaterDetail;
use app\model\PlayerWithdrawRecord;
use app\model\PlayGameRecord;
use app\model\SystemSetting;
use app\exception\PlayerCheckException;
use app\service\ActivityServices;
use app\service\LineServices;
use app\service\SmsServicesServices;
use Illuminate\Support\Carbon;
use Respect\Validation\Exceptions\AllOfException;
use Respect\Validation\Validator as v;
use support\Cache;
use support\Db;
use support\Log;
use support\Request;
use support\Response;
use think\Exception;
use Tinywan\Jwt\JwtToken;
use Webman\Push\PushException;
use Webman\RateLimiter\Annotation\RateLimiter;
use Webman\RedisQueue\Client;

class IndexController
{
    /** 排除  */
    protected $noNeedSign = ['test'];
    
    #[RateLimiter(limit: 5)]
    /**
     * 登录接口
     * @param Request $request
     * @return Response
     */
    public function login(Request $request): Response
    {
        /** @var Channel $channel */
        $channel = Channel::where('department_id', request()->department_id)->first();
        if ($channel->web_login_status == 0) {
            return jsonFailResponse(trans('web_login_close', [], 'message'));
        }
        $data = $request->all();
        $validator = v::key('phone', v::stringType()->notEmpty()->setName(trans('phone', [], 'message')))
            ->key('login_type',
                v::in([1, 2])->notEmpty()->setName(trans('login_type', [], 'message'))); // 1为验证码登录 2为密码登录
        
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        switch ($data['login_type']) {
            case 1:
                return $this->codeLogin($data);
            case 2:
                return $this->passLogin($data);
            default:
                return jsonFailResponse(trans('login_type_error', [], 'message'));
        }
    }
    
    /**
     * 验证码登录
     * @param array $data 登录参数
     * @return Response
     */
    protected function codeLogin(array $data): Response
    {
        $validator = v::key('code', v::stringType()->notEmpty()->setName(trans('phone_code', [], 'message')))
            ->key('country_code',
                v::intVal()->notEmpty()->in(config('sms.open_country_code'))->setName(trans('country_code', [],
                    'message')));
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        // 验证短信
        if (!verifySMS($data['country_code'], $data['phone'], $data['code'], PhoneSmsLog::TYPE_LOGIN)) {
            return jsonFailResponse(trans('phone_code_error', [], 'message'));
        }
        
        /** @var Player $player */
        $player = Player::where('phone', $data['phone'])->where('department_id', request()->department_id)->first();
        if (empty($player)) {
            return jsonFailResponse(trans('player_not_fount', [], 'message'));
        }
        if ($player->status == Player::STATUS_STOP) {
            return jsonFailResponse(trans('player_stop', [], 'message'));
        }
        addLoginRecord($player->id);
        
        return jsonSuccessResponse('success', [
            'token' => JwtToken::generateToken([
                'id' => $player->id,
                'avatar' => $player->avatar,
                'phone' => $player->phone,
                'type' => $player->type,
                'currency' => $player->currency,
                'recommended_code' => $player->recommended_code,
            ]),
            'player_activity_phase' => (new ActivityServices(null, $player))->playerUnreceivedActivity()
        ]);
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 密码登录
     * @param array $data 登录参数
     * @return Response
     */
    protected function passLogin(array $data): Response
    {
        $validator = v::key('password', v::stringType()->notEmpty()->setName(trans('password', [], 'message')));
        
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        
        /** @var Player $player */
        $player = Player::where('phone', $data['phone'])->where('department_id', request()->department_id)->first();
        if (empty($player)) {
            return jsonFailResponse(trans('player_not_fount', [], 'message'));
        }
        if ($player->status == Player::STATUS_STOP) {
            return jsonFailResponse(trans('player_stop', [], 'message'));
        }
        if (empty($player->password)) {
            return jsonFailResponse(trans('must_set_password', [], 'message'));
        }
        if (!password_verify($data['password'], $player->password)) {
            return jsonFailResponse(trans('password_error', [], 'message'));
        }
        addLoginRecord($player->id);
        
        return jsonSuccessResponse('success', [
            'token' => JwtToken::generateToken([
                'id' => $player->id,
                'avatar' => $player->avatar,
                'phone' => $player->phone,
                'type' => $player->type,
                'currency' => $player->currency,
                'recommended_code' => $player->recommended_code,
            ]),
            'player_activity_phase' => (new ActivityServices(null, $player))->playerUnreceivedActivity(),
        ]);
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * @param Request $request
     * @return Response
     * @throws \Exception
     */
    public function lineLogin(Request $request): Response
    {
        /** @var Channel $channel */
        $channel = Channel::where('department_id', request()->department_id)->first();
        if (empty($channel)) {
            return jsonFailResponse(trans('channel_not_found', [], 'message'));
        }
        $data = $request->all();
        $validator = v::key('code', v::stringType()->notEmpty()->setName(trans('code', [], 'message')));
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        try {
            $accessToken = LineServices::getAccessToken($data['code'], $channel->department_id);
            $userData = LineServices::getUserProfile($accessToken);
        } catch (\Exception $e) {
            return jsonFailResponse($e->getMessage(), [], 417);
        }
        /** @var Player $player */
        $player = Player::query()
            ->where('line_user_id', $userData['userId'])
            ->where('department_id', request()->department_id)
            ->first();
        if (!empty($player)) {
            addLoginRecord($player->id);
            return jsonSuccessResponse('success', [
                'token' => JwtToken::generateToken([
                    'id' => $player->id,
                    'avatar' => $player->avatar,
                    'phone' => $player->phone,
                    'type' => $player->type,
                    'currency' => $player->currency,
                    'recommended_code' => $player->recommended_code,
                ]),
                'player_activity_phase' => (new ActivityServices(null, $player))->playerUnreceivedActivity()
            ]);
        }
        
        return jsonFailResponse(trans('line_login_must_phone', [], 'message'), [
            'line_user_id' => $userData['userId'],
            'name' => $userData['displayName'] ?? '',
            'avatar' => $userData['pictureUrl'] ?? '',
        ], 416);
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * LINE绑定
     * @param Request $request
     * @return Response
     */
    public function lineBind(Request $request): Response
    {
        /** @var Channel $channel */
        $channel = Channel::where('department_id', request()->department_id)->first();
        if (empty($channel)) {
            return jsonFailResponse(trans('channel_not_found', [], 'message'));
        }
        if (!LevelList::query()->where('department_id', request()->department_id)->orderBy('must_chip_amount')->exists()) {
            return jsonFailResponse(trans('channel_cant_register', [], 'message'));
        }
        $data = $request->all();
        $validator = v::key('phone', v::stringType()->notEmpty()->length(1, 20)->setName(trans('phone', [], 'message')))
            ->key('code', v::stringType()->notEmpty()->length(1, 10)->setName(trans('phone_code', [], 'message')))
            ->key('line_user_id',
                v::stringType()->notEmpty()->length(1, 100)->setName(trans('line_user_id', [], 'message')))
            ->key('country_code',
                v::intVal()->notEmpty()->in(config('sms.open_country_code'))->setName(trans('country_code', [],
                    'message')))
            ->key('recommended_code', v::stringType()->setName(trans('recommended_code', [], 'message')), false);
        
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        if (Player::query()->where('line_user_id', $data['line_user_id'])->where('phone', '!=',
            $data['phone'])->where('department_id', request()->department_id)->exists()) {
            return jsonFailResponse(trans('has_bind_line', [], 'message'));
        }
        /** @var Player $player */
        $player = Player::where('phone', $data['phone'])->where('department_id', request()->department_id)->first();
        if (!empty($player) && $player->status == Player::STATUS_STOP) {
            return jsonFailResponse(trans('player_stop', [], 'message'));
        }
        if (!empty($player) && !empty($player->line_user_id) && $player->line_user_id != $data['line_user_id']) {
            return jsonFailResponse(trans('has_bind_line', [], 'message'));
        }
        if (!verifySMS($data['country_code'], $data['phone'], $data['code'], PhoneSmsLog::TYPE_LINE_BIND)) {
            return jsonFailResponse(trans('phone_code_error', [], 'message'));
        }
        //全民代理&推广员
        if (!empty($data['recommended_code'])) {
            /** @var Player $recommendPlayer */
            $recommendPlayer = Player::where('recommend_code', $data['recommended_code'])->first();
            if (empty($recommendPlayer)) {
                return jsonFailResponse(trans('recommend_player_not_found', [], 'message'));
            }
        }
        $hasNewPlayer = false;
        // 储存玩家信息
        DB::beginTransaction();
        try {
            if (empty($player)) {
                $player = new Player();
                $hasNewPlayer = true;
                $player->department_id = $channel->department_id;
                $player->phone = $data['phone'];
                $player->uuid = generate15DigitUniqueId();
                $player->country_code = $data['country_code'];
                $player->type = Player::TYPE_PLAYER;
                $player->currency = $channel->currency;
                $player->recommend_id = !empty($recommendPlayer->id) ? $recommendPlayer->id : 0;
                $player->recommended_code = !empty($recommendPlayer->id) ? $data['recommended_code'] : 0;
                $player->recommend_code = createCode();
            }
            $player->line_user_id = $data['line_user_id'] ?? '';
            $player->name = $data['name'] ?? '';
            $avatar = config('def_avatar.1');
            if (!empty($data['avatar'])) {
                $avatar = saveImg($data['avatar']);
            }
            $player->avatar = $avatar;
            $player->save();
            if ($hasNewPlayer) {
                addPlayerExtend($player);
                isset($recommendPlayer) && !empty($recommendPlayer->player_promoter) && $recommendPlayer->player_promoter->increment('player_num');
                addRegisterRecord($player->id, PlayerRegisterRecord::TYPE_CLIENT, $player->department_id);
                
                //全民代理
                $this->nationalPromoter($player);
            } else {
                addLoginRecord($player->id);
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return jsonFailResponse($e->getMessage());
        }
        
        return jsonSuccessResponse('success', [
            'token' => JwtToken::generateToken([
                'id' => $player->id,
                'avatar' => $player->avatar,
                'phone' => $player->phone,
                'type' => $player->type,
                'currency' => $player->currency,
                'recommended_code' => $player->recommended_code,
            ]),
            'player_activity_phase' => (new ActivityServices(null, $player))->playerUnreceivedActivity()
        ]);
    }
    
    
    #[RateLimiter(limit: 5)]
    /**
     * @param Player $player
     * @return void
     */
    public function nationalPromoter(Player $player): void
    {
        $national_promoter = new NationalPromoter;
        $national_promoter->uid = $player->id;
        $national_promoter->recommend_id = $player->recommend_id ?? 0;
        /** @var LevelList $level_min */
        $level_min = LevelList::query()->where('department_id',
            $player->department_id)->orderBy('must_chip_amount')->first();
        $national_promoter->level = $level_min->id;
        $national_promoter->save();
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 注册接口
     * @param Request $request
     * @return Response
     */
    public function register(Request $request): Response
    {
        /** @var Channel $channel */
        $channel = Channel::where('department_id', request()->department_id)->first();
        if ($channel->web_login_status == 0) {
            return jsonFailResponse(trans('web_login_close', [], 'message'));
        }
        if (!LevelList::query()->where('department_id', request()->department_id)->orderBy('must_chip_amount')->exists()) {
            return jsonFailResponse(trans('channel_cant_register', [], 'message'));
        }
        $data = $request->all();
        $validator = v::key('phone', v::stringType()->notEmpty()->length(1, 20)->setName(trans('phone', [], 'message')))
            ->key('code', v::stringType()->notEmpty()->length(1, 10)->setName(trans('phone_code', [], 'message')))
            ->key('country_code',
                v::intVal()->notEmpty()->in(config('sms.open_country_code'))->setName(trans('country_code', [],
                    'message')))
            ->key('password',
                v::stringType()->notEmpty()->alnum()->length(6, 12)->setName(trans('password', [], 'message')))
            ->key('re_password', v::stringType()->notEmpty()->alnum()->length(6,
                12)->equals($data['password'] ?? null)->setName(trans('re_password', [], 'message')))
            ->key('recommended_code', v::stringType()->setName(trans('recommended_code', [], 'message')), false);
        
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        // 验证短信
        if (!verifySMS($data['country_code'], $data['phone'], $data['code'], PhoneSmsLog::TYPE_REGISTER)) {
            return jsonFailResponse(trans('phone_code_error', [], 'message'));
        }
        
        /** @var Player $player */
        $player = Player::where('phone', $data['phone'])->where('department_id', request()->department_id)->first();
        if (!empty($player)) {
            return jsonFailResponse(trans('phone_has_registered', [], 'message'));
        }
        /** @var Channel $channel */
        $channel = Channel::where('department_id', \request()->department_id)->first();
        if (empty($channel)) {
            return jsonFailResponse(trans('channel_not_found', [], 'message'));
        }
        if (!empty($data['recommended_code'])) {
            // 验证推荐码
            /** @var Player $recommendPlayer */
            $recommendPlayer = Player::where('recommend_code', $data['recommended_code'])->first();
            if (empty($recommendPlayer)) {
                return jsonFailResponse(trans('recommend_player_not_found', [], 'message'));
            }
        }
        // 储存玩家信息
        DB::beginTransaction();
        try {
            $player = new Player();
            $player->phone = $data['phone'];
            $player->uuid = generate15DigitUniqueId();
            $player->country_code = $data['country_code'];
            $player->type = Player::TYPE_PLAYER;
            $player->currency = $channel->currency;
            $player->password = $data['password'];
            $player->department_id = $channel->department_id;
            $player->recommend_id = !empty($recommendPlayer->id) ? $recommendPlayer->id : 0;
            $player->recommended_code = !empty($recommendPlayer->id) ? $data['recommended_code'] : 0;
            $player->avatar = config('def_avatar.1');
            $player->recommend_code = createCode();
            $player->save();
            
            isset($recommendPlayer) && !empty($recommendPlayer->player_promoter) && $recommendPlayer->player_promoter->increment('player_num');
            
            addPlayerExtend($player);
            
            addRegisterRecord($player->id, PlayerRegisterRecord::TYPE_CLIENT, $player->department_id);
            
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return jsonFailResponse($e->getMessage());
        }
        
        //全民代理
        $this->nationalPromoter($player);
        return jsonSuccessResponse('success', [
            'id' => $player->id,
            'phone' => $player->phone,
            'avatar' => $player->avatar,
            'type' => $player->type,
            'currency' => $player->currency,
            'flag' => $player->flag,
            'recommend_code' => $player->recommend_code,
            'uuid' => $player->uuid,
        ]);
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 发送短信
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public function sendCode(Request $request): Response
    {
        $data = $request->all();
        $validator = v::key('phone', v::stringType()->notEmpty()->setName(trans('phone', [], 'message')))
            ->key('country_code',
                v::intVal()->notEmpty()->in(config('sms.open_country_code'))->setName(trans('country_code', [],
                    'message')))
            ->key('type', v::notEmpty()->in([
                PhoneSmsLog::TYPE_LOGIN,
                PhoneSmsLog::TYPE_REGISTER,
                PhoneSmsLog::TYPE_CHANGE_PASSWORD,
                PhoneSmsLog::TYPE_BIND_NEW_PHONE,
                PhoneSmsLog::TYPE_TALK_BIND,
                PhoneSmsLog::TYPE_LINE_BIND,
            ])->setName(trans('sms_type', [], 'message')));
        
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        switch ($data['type']) {
            case PhoneSmsLog::TYPE_BIND_NEW_PHONE:
                $player = checkPlayer();
                if (Player::where('phone', $data['phone'])->where('department_id',
                    \request()->department_id)->first()) {
                    return jsonFailResponse(trans('phone_has_register', [], 'message'));
                }
                break;
            case PhoneSmsLog::TYPE_TALK_BIND:
                /** @var Player $player */
                $player = Player::where('phone', $data['phone'])->where('department_id',
                    \request()->department_id)->first();
                if (empty($player)) {
                    return jsonFailResponse(trans('player_not_fount', [], 'message'));
                }
                if ($player->status == Player::STATUS_STOP) {
                    return jsonFailResponse(trans('player_stop', [], 'message'));
                }
                if (!empty($player->talk_user_id)) {
                    return jsonFailResponse(trans('player_has_bind_talk', [], 'message'));
                }
                break;
            case PhoneSmsLog::TYPE_LINE_BIND:
                if (empty($data['line_user_id'])) {
                    return jsonFailResponse(trans('line_user_id_no_found', [], 'message'));
                }
                /** @var Player $player */
                $player = Player::where('phone', $data['phone'])->where('department_id',
                    \request()->department_id)->first();
                if (!empty($player) && $player->status == Player::STATUS_STOP) {
                    return jsonFailResponse(trans('player_stop', [], 'message'));
                }
                if (!empty($player) && !empty($player->line_user_id) && $player->line_user_id != $data['line_user_id']) {
                    return jsonFailResponse(trans('has_bind_line', [], 'message'));
                }
                break;
            case PhoneSmsLog::TYPE_LOGIN:
                /** @var Player $player */
                $player = Player::where('phone', $data['phone'])->where('department_id',
                    \request()->department_id)->first();
                if (empty($player)) {
                    return jsonFailResponse(trans('phone_not_register', [], 'message'));
                }
                break;
            case PhoneSmsLog::TYPE_REGISTER:
                if (Player::where('phone', $data['phone'])->where('department_id',
                    \request()->department_id)->first()) {
                    return jsonFailResponse(trans('phone_has_register', [], 'message'));
                }
                break;
            default:
                break;
        }
        try {
            SmsServicesServices::sendSms($data['country_code'], $data['phone'], $data['type'], $player->id ?? 0,
                $player->name ?? '');
        } catch (\Exception $e) {
            return jsonFailResponse($e->getMessage());
        }
        
        return jsonSuccessResponse('success');
    }
    
    
    #[RateLimiter(limit: 5)]
    /**
     * 登出接口
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public function logout(): Response
    {
        checkPlayer();
        if (JwtToken::clear()) {
            return jsonSuccessResponse('success');
        }
        
        return jsonFailResponse(trans('logout_failed', [], 'message'));
    }
    
    
    #[RateLimiter(limit: 5)]
    /**
     * 公告列表
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public function announcementList(Request $request): Response
    {
        checkPlayer();
        $data = $request->all();
        $validator = v::key('page', v::intVal()->setName(trans('page', [], 'message')))
            ->key('size', v::intVal()->setName(trans('size', [], 'message')));
        
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        
        $announcementList = Announcement::where('status', 1)
            ->where('department_id', \request()->department_id)
            ->orderBy('priority', 'desc')
            ->orderBy('push_time', 'desc')
            ->where('push_time', '<=', date('Y-m-d H:i:s'))
            ->where(function ($query) {
                $query->where(function ($query) {
                    $query->where('valid_time', '>=', date('Y-m-d H:i:s'));
                })->orWhere(function ($query) {
                    $query->whereNull('valid_time');
                });
            })
            ->forPage($data['page'], $data['size'])
            ->get();
        
        $list = [];
        /** @var Announcement $item */
        foreach ($announcementList as $item) {
            $list[] = [
                'announcement_id' => $item->id,
                'title' => $item->title,
                'valid_time' => $item->valid_time,
                'priority' => $item->priority,
                'push_time' => Carbon::parse($item->push_time)->format('Y/m/d'),
            ];
        }
        
        return jsonSuccessResponse('success', [
            'list' => $list
        ]);
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 消息列表
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException
     * @throws PushException|Exception
     */
    public function noticeList(Request $request): Response
    {
        $player = checkPlayer();
        $data = $request->all();
        $validator = v::key('page', v::intVal()->setName(trans('page', [], 'message')))
            ->key('size', v::intVal()->setName(trans('size', [], 'message')));
        
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        $list = [];
        $noticeList = Notice::where('player_id', $player->id)
            ->where('receiver', Notice::RECEIVER_PLAYER)
            ->where('is_private', 1)
            ->whereNull('deleted_at')
            ->forPage($data['page'], $data['size'])
            ->orderBy('status', 'asc')
            ->orderBy('id', 'desc')
            ->get();
        /** @var Notice $notice */
        foreach ($noticeList as $notice) {
            $lotteryRecord = [];
            $hasReceive = 0;
            switch ($notice->type) {
                case Notice::TYPE_LOTTERY:
                    /** @var PlayerLotteryRecord $lotteryRecord */
                    $lotteryRecord = PlayerLotteryRecord::where('id', $notice->source_id)
                        ->select([
                            'id',
                            'machine_name',
                            'machine_code',
                            'status',
                            'amount',
                            'lottery_name',
                            'game_type',
                            'lottery_type',
                            'lottery_rate',
                            'lottery_sort',
                            'source',
                            'play_game_record_id',
                        ])
                        ->first();
                    if ($lotteryRecord->source == PlayerLotteryRecord::SOURCE_MACHINE) {
                        $notice->content = trans('content.' . $notice->type . '_' . $lotteryRecord->lottery_type, [
                            '{machine_type}' => trans('machine_type.' . $lotteryRecord->game_type, [], 'message'),
                            '{machine_code}' => $lotteryRecord->machine_code,
                            '{lottery_name}' => $lotteryRecord->lottery_name,
                            '{amount}' => $lotteryRecord->lottery_type == Lottery::LOTTERY_TYPE_FIXED ? $lotteryRecord->amount : '',
                        ], 'notice');
                    }
                    if ($lotteryRecord->source == PlayerLotteryRecord::SOURCE_GAME) {
                        /** @var PlayGameRecord $playGameRecord */
                        $playGameRecord = PlayGameRecord::query()->where('id', $lotteryRecord->play_game_record_id)->first();
                        /** @var GameExtend $game */
                        $game = GameExtend::query()->where('platform_id', $playGameRecord->platform_id)->where('code', $playGameRecord->game_code)->first();
                        $notice->content = trans('content.' . $notice->type . '_' . $lotteryRecord->lottery_type . '_' . $lotteryRecord->source, [
                            '{game_name}' => $game->name ?? '',
                            '{lottery_name}' => $lotteryRecord->lottery_name,
                            '{amount}' => $lotteryRecord->amount,
                        ], 'notice');
                    }

                    if ($lotteryRecord->status == PlayerLotteryRecord::STATUS_UNREVIEWED) {
                        $hasReceive = 1;
                    }
                    break;
                case Notice::TYPE_RECHARGE_PASS:
                    /** @var PlayerRechargeRecord $playerRechargeRecord */
                    $playerRechargeRecord = PlayerRechargeRecord::where('id', $notice->source_id)->first();
                    if (!empty($playerRechargeRecord)) {
                        $notice->content = trans('content.' . $notice->type,
                            ['{inmoney}' => $playerRechargeRecord->inmoney, '{point}' => $playerRechargeRecord->point],
                            'notice');
                    }
                    break;
                case Notice::TYPE_WITHDRAW_PASS:
                case Notice::TYPE_WITHDRAW_COMPLETE:
                    /** @var PlayerWithdrawRecord $playerWithdrawRecord */
                $playerWithdrawRecord = PlayerWithdrawRecord::where('id', $notice->source_id)->first();
                if (!empty($playerWithdrawRecord)) {
                    $notice->content = trans('content.' . $notice->type,
                        ['{inmoney}' => $playerWithdrawRecord->inmoney, '{point}' => $playerWithdrawRecord->point],
                        'notice');
                }
                    break;
                case Notice::TYPE_ACTIVITY_RECEIVE:
                    /** @var PlayerActivityPhaseRecord $playerActivityPhaseRecord */
                    $playerActivityPhaseRecord = PlayerActivityPhaseRecord::where('id', $notice->source_id)->first();
                    if ($playerActivityPhaseRecord->status == PlayerActivityPhaseRecord::STATUS_UNRECEIVED) {
                        $hasReceive = 1;
                    }
                    break;
                case Notice::TYPE_RECHARGE_REJECT:
                case Notice::TYPE_ACTIVITY_REJECT:
                case Notice::TYPE_WITHDRAW_REJECT:
                    $notice->content = trans('content.' . $notice->type, [], 'notice');
                    break;
                case Notice::TYPE_REVERSE_WATER:
                    //反水是否领取
                    /** @var PlayerReverseWaterDetail $water */
                    $water = PlayerReverseWaterDetail::query()->where('id', $notice->source_id)->first();
                    if (isset($water) && $water->status == 0) {
                        $hasReceive = 1;
                    }
                    break;
                default:
                    break;
            }
            $list[] = [
                'id' => $notice->id,
                'source_id' => $notice->source_id,
                'type' => $notice->type,
                'title' => trans('title.' . $notice->type, [], 'notice'),
                'content' => $notice->content,
                'status' => $notice->status,
                'created_at' => date('Y-m-d H:i:s', strtotime($notice->created_at)),
                'lottery_record' => $lotteryRecord,
                'has_receive' => $hasReceive,
            ];
        }
        // 更新为已读状态
        Notice::where('status', 0)
            ->where('receiver', Notice::RECEIVER_PLAYER)
            ->where('is_private', 1)
            ->where('player_id', $player->id)
            ->whereNull('deleted_at')
            ->update([
                'status' => 1
            ]);
        
        // 发送邮件数量
        sendSocketMessage('player-' . $player->id, [
            'msg_type' => 'player_notice_num',
            'notice_num' => 0,
        ]);
        
        return jsonSuccessResponse('success', $list);
    }
    
    
    #[RateLimiter(limit: 5)]
    /**
     * 公告详情
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public function announcementInfo(Request $request): Response
    {
        checkPlayer();
        $data = $request->all();
        $validator = v::key('announcement_id', v::intVal()->setName(trans('announcement_id', [], 'message')));
        
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        /** @var Announcement $announcement */
        $announcement = Announcement::where('push_time', '<=', date('Y-m-d H:i:s'))
            ->where('status', 1)
            ->where('department_id', \request()->department_id)
            ->where(function ($query) {
                $query->where(function ($query) {
                    $query->where('valid_time', '>=', date('Y-m-d H:i:s'));
                })->orWhere(function ($query) {
                    $query->whereNull('valid_time');
                });
            })
            ->where('id', $data['announcement_id'])
            ->first();
        
        if (empty($announcement)) {
            return jsonFailResponse(trans('announcement_not_found', [], 'message'));
        }
        return jsonSuccessResponse('success', [
            $announcement
        ]);
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 獲取渠道信息數據
     * @return Response
     */
    public function getChannel(): Response
    {
        $siteId = \request()->site_id; // 站点标识
        $cacheKey = "channel_" . $siteId;
        $channel = Cache::get($cacheKey);
        if (empty($channel)) {
            return jsonFailResponse(trans('channel_not_found', [], 'message'));
        }
        if ($channel['status'] == 0 || !empty($channel['deleted_at'])) {
            return jsonFailResponse(trans('channel_not_found', [], 'message'));
        }
        /** @var SystemSetting $systemSetting */
        $systemSetting = SystemSetting::where('feature', 'activity_open')->first();

        // 获取总后台配置（大屏彩金Domain和TURN中继IP）
        $jackpotScreenDomain = '';
        $turnRelayIp = '';

        $jackpotSetting = SystemSetting::where('feature', 'jackpot_screen_domain')
            ->where('department_id', 0)
            ->where('status', 1)
            ->first();
        if ($jackpotSetting) {
            $jackpotScreenDomain = $jackpotSetting->content ?? '';
        }

        $turnSetting = SystemSetting::where('feature', 'turn_relay_ip')
            ->where('department_id', 0)
            ->where('status', 1)
            ->first();
        if ($turnSetting) {
            // 返回加密数据，客户端自己解密（防止传输中被截获）
            $turnRelayIp = $turnSetting->getRawOriginal('content') ?? '';
        }

        if ($channel['machine_media_line'] == 2) {
            $ip = request()->getRealIp();
            if (!empty($ip)) {
                try {
                    if (!isIPInChina($ip)) {
                        $channel['machine_media_line'] = 1;
                    }
                } catch (\Exception) {
                    Log::error('获取玩家IP地区失败');
                }
            }
        }
        // 处理APP更新信息（仅线下渠道）
        $appUpdate = null;
        if (($channel['is_offline'] ?? 0) == 1) {
            $appUpdate = [
                'version_name' => $channel['client_version'] ?? '1.0.0',
                'version_code' => $channel['app_version_code'] ?? 1,
                'update_title' => $channel['app_update_title'] ?? '',
                'update_content' => $channel['app_update_content'] ?? '',
                'force_update' => ($channel['app_force_update'] == 1 || $channel['app_force_update'] == true),
                'download_url' => $channel['app_download_url'] ?? $channel['download_url'] ?? '',
            ];
        }

        return jsonSuccessResponse('success', [
            'id' => $channel['id'],
            'name' => $channel['name'],
            'domain' => $channel['domain'],
            'lang' => $channel['lang'],
            'currency' => $channel['currency'],
            'recharge_status' => ($channel['recharge_status'] == 1 || $channel['recharge_status'] == true),
            'q_talk_recharge_status' => ($channel['q_talk_recharge_status'] == 1 || $channel['q_talk_recharge_status'] == true),
            'q_talk_point_status' => ($channel['q_talk_point_status'] == 1 || $channel['q_talk_point_status'] == true),
            'withdraw_status' => ($channel['withdraw_status'] == 1 || $channel['withdraw_status'] == true),
            'q_talk_withdraw_status' => ($channel['q_talk_withdraw_status'] == 1 || $channel['q_talk_withdraw_status'] == true),
            'web_login_status' => ($channel['web_login_status'] == 1 || $channel['web_login_status'] == true),
            'promotion_status' => ($channel['promotion_status'] == 1 || $channel['promotion_status'] == true),
            'coin_status' => ($channel['coin_status'] == 1 || $channel['coin_status'] == true),
            'download_url' => $channel['download_url'] ?? '',
            'line_client_id' => $channel['line_client_id'] ?? '',
            'line_login_status' => ($channel['line_login_status'] == 1 || $channel['line_login_status'] == true),
            'national_promoter_status' => ($channel['national_promoter_status'] == 1 || $channel['national_promoter_status'] == true),
            'reverse_water_status' => ($channel['reverse_water_status'] == 1 || $channel['reverse_water_status'] == true),
            'discussion_group_status' => ($channel['discussion_group_status'] == 1 || $channel['discussion_group_status'] == true),
            'ranking_status' => ($channel['ranking_status'] == 1 || $channel['ranking_status'] == true),
            'activity_open' => $systemSetting->status ?? 0,
            'status_machine' => ($channel['status_machine'] == 1 || $channel['status_machine'] == true),
            'machine_media_line' => $channel['machine_media_line'],
            'is_offline' => ($channel['is_offline'] ?? 0) == 1,
            // 安卓APP更新信息（仅线下渠道返回）
            'app_update' => $appUpdate,
            // 总后台配置
            'jackpot_screen_domain' => $jackpotScreenDomain,
            'turn_relay_ip' => $turnRelayIp, // 加密数据，客户端需要解密
        ]);
    }
    
    /**
     * 获取客户连接
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public function getChat(): Response
    {
        $player = checkPlayer();
        return jsonSuccessResponse('success', [
            'chat_url' => SystemSetting::query()
                    ->where('department_id', $player->department_id)
                    ->where('status', 1)
                    ->where('feature', 'line_customer')
                    ->value('content') ?? ''
        ]);
    }
    
    /**
     * 获取客户连接
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public function getLineGroup(): Response
    {
        $player = checkPlayer();
        return jsonSuccessResponse('success', [
            'Line_group' => SystemSetting::query()
                    ->where('department_id', $player->department_id)
                    ->where('status', 1)
                    ->where('feature', 'line_discussion_group')
                    ->value('content') ?? ''
        ]);
    }
    
    
    #[RateLimiter(limit: 5)]
    /**
     * 刷新用户token
     * @return Response
     */
    public function refreshToken(): Response
    {
        return jsonFailResponse('请重新登录', [], 401021);
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 获取配置
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public function getSetting(Request $request): Response
    {
        checkPlayer();
        $validator = v::key('feature', v::notEmpty()->stringVal()->setName(trans('feature', [], 'message')))
            ->key('is_channel', v::boolVal()->setName(trans('is_channel', [], 'message')), false);
        $data = $request->all();
        
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        
        $systemSetting = SystemSetting::whereIn('feature',
            $data['feature'] ? explode(',', $data['feature']) : [])->select([
            'feature',
            'num',
            'status',
            'date_start',
            'date_end',
            'content'
        ]);
        if (isset($data['is_channel']) && $data['is_channel']) {
            $systemSetting = $systemSetting->where('department_id', \request()->department_id);
        } elseif (isset($data['is_channel'])) {
            $systemSetting = $systemSetting->where('department_id', 0);
        }
        
        $settings = $systemSetting->get();

        // 对敏感字段保持加密状态，让客户端自己解密（防止传输中被截获）
        $settings->each(function ($setting) {
            if ($setting->feature === 'turn_relay_ip') {
                // 获取未经访问器解密的原始加密数据
                $setting->content = $setting->getRawOriginal('content');
            }
        });

        return jsonSuccessResponse('success', $settings->toArray());
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 机台工控测试
     * @param Request $request
     * @return Response
     * @throws \Exception
     */
    public function test(Request $request): Response
    {
        Client::send('game-lottery', ['player_id' => 7471, 'bet' => 100]);

        return jsonSuccessResponse('success', []);
    }
    
    
    #[RateLimiter(limit: 5)]
    /**
     * 退出游戏
     * @return Response
     * @throws PlayerCheckException
     */
    public function exitGame(): Response
    {
        checkPlayer();
        return jsonSuccessResponse(trans('success', [], 'message'));
    }
}
