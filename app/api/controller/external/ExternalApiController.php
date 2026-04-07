<?php

namespace app\api\controller\external;

use app\model\Channel;
use app\model\ChannelFinancialRecord;
use app\model\ChannelRechargeMethod;
use app\model\ExternalApp;
use app\model\GameLottery;
use app\model\GameType;
use app\model\Lottery;
use app\model\Machine;
use app\model\NationalInvite;
use app\model\NationalProfitRecord;
use app\model\NationalPromoter;
use app\model\Notice;
use app\model\Player;
use app\model\PlayerBank;
use app\model\PlayerDeliveryRecord;
use app\model\PlayerGameLog;
use app\model\PlayerLotteryRecord;
use app\model\PlayerRechargeRecord;
use app\model\PlayerWithdrawRecord;
use app\model\SystemSetting;
use app\service\GameLotteryServices;
use app\service\LotteryServices;
use app\service\machine\MachineServices;
use app\service\payment\EHpayService;
use app\service\payment\GBpayService;
use Exception;
use Respect\Validation\Exceptions\AllOfException;
use Respect\Validation\Validator as v;
use support\Cache;
use support\Db;
use support\Log;
use support\Request;
use support\Response;
use Tinywan\Jwt\JwtToken;
use Webman\Push\PushException;
use Webman\RateLimiter\Annotation\RateLimiter;

class ExternalApiController
{
    /** 排除  */
    protected $noNeedSign = ['channelInfo', 'callbackFastBind', 'callbackWithdraw','getPassword','ehCallbackDeposit','ehCallbackWithdraws','getLotteryPoolAndRecords','testCheckLottery','testTriggerBurst','testGetBurstStatus','testEndBurst','testProbability', 'testSendWinMessage'];

    #[RateLimiter(limit: 5)]
    /**
     * 获取请求令牌
     * @param Request $request
     * @return Response
     */
    public function getAccessToken(Request $request): Response
    {
        // 验证传递字段
        $data = $request->post();
        $validator = v::key('appID',
            v::stringType()->notEmpty()->length(10, 30)->setName(trans('appID', [], 'message')))
            ->key('appSecret', v::stringType()->notEmpty()->length(10, 30)->setName(trans('appSecret', [], 'message')));
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e), [], 0);
        }
        // 验证授权应用
        /** @var ExternalApp $externalApp */
        $externalApp = ExternalApp::where('app_id', $data['appID'])->where('app_secret',
            $data['appSecret'])->whereNull('deleted_at')->where('status', 1)->first();
        if (empty($externalApp)) {
            return jsonFailResponse(trans('app_not_found', [], 'message'), [], 0);
        }
        // 验证服务器ip
        if (!empty($externalApp->white_ip) && !in_array(request()->getRealIp(), explode(',', $externalApp->white_ip))) {
            return jsonFailResponse(trans('ip_auth_failed', [], 'message'), [], 0);
        }

        // 返回授权token
        return jsonSuccessResponse('success', JwtToken::generateToken([
            'id' => base64_encode($externalApp->id),
            'app_id' => $externalApp->app_id,
        ]));
    }

    #[RateLimiter(limit: 5)]
    /**
     * 获取玩家信息
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function getPlayerGame(Request $request): Response
    {
        $data = $request->post();
        $validator = v::key('talk_user_id', v::notEmpty()->intVal()->setName(trans('talk_user_id', [], 'message')))
            ->key('start_time', v::stringVal()->setName(trans('start_time', [], 'message')), false)
            ->key('end_time', v::stringVal()->setName(trans('end_time', [], 'message')), false);

        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e), [], 0);
        }
        /** @var Player $player */
        $player = Player::whereNull('deleted_at')->where('talk_user_id', $data['talk_user_id'])->where('status',
            1)->first();
        if (empty($player)) {
            return jsonSuccessResponse('success', [
                'total_pressure' => 0,
                'total_score' => 0,
                'total_turn_point' => 0,
            ]);
        }
        $playerGameLog = PlayerGameLog::where('player_id', $player->id)
            ->selectRaw('sum(pressure) as total_pressure,
                        sum(score) as total_score,
                        sum(turn_point) as total_turn_point');
        if (!empty($data['start_time'])) {
            $playerGameLog->where('created_at', '>=', $data['start_time']);
        }
        if (!empty($data['end_time'])) {
            $playerGameLog->where('created_at', '<=', $data['end_time']);
        }
        $playerGameLog = $playerGameLog->first()->toArray();

        //遊戲中玩家
        $gamingMachines = Machine::with(['machineCategory', 'gamingPlayer'])
            ->where('gaming', 1)
            ->where('gaming_user_id', $player->id)
            ->orderBy('type', 'asc')
            ->get();
        if ($gamingMachines) {
            /** @var Machine $machine */
            foreach ($gamingMachines as $machine) {
                try {
                    $services = MachineServices::createServices($machine);
                    if ($machine->type == GameType::TYPE_STEEL_BALL) {
                        $playerGameLog['total_turn_point'] = $playerGameLog['total_turn_point'] + $services->player_win_number;
                    } else {
                        if ($services->bet > $machine->player_pressure) {
                            $playerGameLog['total_pressure'] = $playerGameLog['total_pressure'] + $services->bet - $services->player_pressure;
                        }
                        if ($services->score > $services->player_score) {
                            $playerGameLog['total_score'] = $playerGameLog['total_score'] + $services->score - $services->player_score;
                        }
                    }
                } catch (Exception $e) {
                    Log::error('getPlayerGame-获取玩家信息', [$e->getMessage()]);
                }
            }
        }

        return jsonSuccessResponse('success', [
            'total_pressure' => $playerGameLog['total_pressure'] ? floatval($playerGameLog['total_pressure']) : 0,
            'total_score' => $playerGameLog['total_score'] ? floatval($playerGameLog['total_score']) : 0,
            'total_turn_point' => $playerGameLog['total_turn_point'] ? floatval($playerGameLog['total_turn_point']) : 0,
        ]);
    }

    #[RateLimiter(limit: 5)]
    /**
     * 获取玩家信息
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function getPlayerInfo(Request $request): Response
    {
        $data = $request->post();
        $validator = v::key('talk_user_id', v::notEmpty()->intVal()->setName(trans('talk_user_id', [], 'message')));

        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e), [], 0);
        }
        /** @var Player $player */
        $player = Player::whereNull('deleted_at')->where('talk_user_id', $data['talk_user_id'])->where('status',
            1)->first();
        if (empty($player)) {
            return jsonSuccessResponse('success', [
                'talk_user_id' => $data['talk_user_id'],
                'id' => 0,
                'money' => 0,
                'name' => '',
            ]);
        }

        return jsonSuccessResponse('success', [
            'talk_user_id' => $player->talk_user_id,
            'id' => $player->id,
            'money' => \app\service\WalletService::getBalance($player->id), // ✅ Redis 实时余额
            'name' => $player->name,
        ]);
    }

    #[RateLimiter(limit: 5)]
    /**
     * 获取渠道信息
     * @param Request $request
     * @return Response
     */
    public function channelInfo(Request $request): Response
    {
        $siteId = $request->header('Site-Id');
        if (empty($siteId)) {
            return response('fail', 400);
        }
        $cacheKey = "channel_" . $siteId;
        /** @var Channel $channel */
        $channel = Cache::get($cacheKey);
        if (empty($channel)) {
            $channel = Channel::where('site_id', $siteId)->whereNull('deleted_at')->where('status', 1)->first();
            if (!empty($channel)) {
                $cacheKey = "channel_" . $channel->site_id;
                Cache::set($cacheKey, $channel->toArray());
            } else {
                return response('fail', 400);
            }
        }
        if (empty($channel)) {
            return response('fail', 400);
        }
        if ($channel['status'] == 0 || !empty($channel['deleted_at'])) {
            return response('fail', 400);
        }
        $newVersion = '';
        if (!empty($channel['client_version'])) {
            $versionNumbers = explode('.', $channel['client_version']);
            $newVersion = implode('', $versionNumbers);
        }

        $channel['domain_ext'] ??= [];

        array_unshift($channel['domain_ext'], ['domain' => $channel['domain']]);

        return jsonSuccessResponse('success', [
            'id' => $channel['id'],
            'name' => $channel['name'],
            'domain' => $channel['domain'],
            'domain_ext' => $channel['domain_ext'],
            'client_version' => $newVersion,
        ]);
    }

    /**
     * 获取游戏王域名密码
     * @param Request $request
     * @return Response
     */
    public function getPassword(Request $request): Response
    {
        return jsonSuccessResponse('success', [
            'domain' => Channel::query()->where('id','0000000018')->value('domain'),
            'password' => SystemSetting::query()->where('feature','app_password')->orderBy('id')->value('content')
        ]);
    }

    #[RateLimiter(limit: 5)]
    /**
     * 玩家绑定购宝钱包
     * @param Request $request
     * @return string
     */
    public function callbackFastBind(Request $request): string
    {
        $data = $request->post();
        $log = Log::channel('gb_pay_server');
        $log->info('callbackFastBind:返回数据', [$data]);
        $player = Player::query()->find($data['mid']);
        if (empty($player)) {
            return '';
        }
        if (PlayerBank::query()->where('player_id', $data['mid'])->where('type',
            ChannelRechargeMethod::TYPE_GB)->exists()) {
            return 'OK';
        }
        $playerBank = new PlayerBank();
        $playerBank->player_id = $data['mid'];
        $playerBank->type = ChannelRechargeMethod::TYPE_GB;
        $playerBank->bank_name = '购宝钱包';
        $playerBank->account_name = $data['bankName'] ?? '';
        $playerBank->account = $data['wallet'] ?? '';
        $playerBank->gb_nickname = $data['name'] ?? '';
        $playerBank->save();
        $log->info('callbackFastBind:绑定成功');
        return 'OK';
    }


    #[RateLimiter(limit: 5)]
    /**
     * 玩家绑定购宝钱包
     * @param Request $request
     * @return string
     */
    public function callbackWithdraw(Request $request): string
    {
        $data = $request->post();
        $log = Log::channel('gb_pay_server');
        $log->info('callbackWithdraw:返回数据', [$data]);
        /** @var PlayerWithdrawRecord $playerWithdrawRecord */
        $playerWithdrawRecord = PlayerWithdrawRecord::query()
            ->where('tradeno', $data['order_no'])
            ->where('money', $data['amount'])
            ->where('type', PlayerWithdrawRecord::TYPE_GB)
            ->where('status', PlayerWithdrawRecord::STATUS_PENDING_PAYMENT)
            ->first();
        if (empty($playerWithdrawRecord)) {
            $log->info('callbackWithdraw:充值订单不存在');
            return '';
        }

        if ($data['errorCode'] == 0) {
            DB::beginTransaction();
            try {
                // 更新订单
                $playerWithdrawRecord->status = PlayerWithdrawRecord::STATUS_SUCCESS;
                $playerWithdrawRecord->finish_time = date('Y-m-d H:i:s');
                if ($playerWithdrawRecord->save()) {
                    saveChannelFinancialRecord($playerWithdrawRecord, ChannelFinancialRecord::ACTION_WITHDRAW_PAYMENT);
                    // 更新渠道数据
                    $playerWithdrawRecord->channel->withdraw_amount = bcadd($playerWithdrawRecord->channel->withdraw_amount,
                        $playerWithdrawRecord->point, 2);
                    $playerWithdrawRecord->channel->push();
                }
                // 发送站内信
                $notice = new Notice();
                $notice->department_id = $playerWithdrawRecord->player->department_id;
                $notice->player_id = $playerWithdrawRecord->player_id;
                $notice->source_id = $playerWithdrawRecord->id;
                $notice->type = Notice::TYPE_WITHDRAW_COMPLETE;
                $notice->receiver = Notice::RECEIVER_PLAYER;
                $notice->is_private = 1;
                $notice->title = '提現打款成功';
                $notice->content = '恭喜您的提現訂單已打款成功，提現遊戲點 ' . $playerWithdrawRecord->point . '， 共提現金額 ' . $playerWithdrawRecord->inmoney;
                $notice->save();
                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                $log->info('callbackWithdraw:数据处理失败', [$e->getTrace()]);
            }
            $log->info('callbackWithdraw:提现成功');
        } else {
            try {
                $rejectReason = (new GBpayService($playerWithdrawRecord->player))->failCode[$data['errorCode']];
                if (withdrawBack($playerWithdrawRecord, $rejectReason)) {
                    saveChannelFinancialRecord($playerWithdrawRecord, ChannelFinancialRecord::ACTION_WITHDRAW_GB_ERROR);
                }
                // 发送站内信
                $notice = new Notice();
                $notice->department_id = $playerWithdrawRecord->player->department_id;
                $notice->player_id = $playerWithdrawRecord->player_id;
                $notice->source_id = $playerWithdrawRecord->id;
                $notice->type = Notice::TYPE_WITHDRAW_REJECT;
                $notice->receiver = Notice::RECEIVER_PLAYER;
                $notice->is_private = 1;
                $notice->title = '购宝提現支付不成功';
                $notice->content = '抱歉您的提現訂單未支付成功，原因是: ' . $rejectReason;
                $notice->save();
            } catch (Exception $e) {
                $log->info('callbackWithdraw:提现失败', [$e->getMessage()]);
            }
            $log->info('callbackWithdraw:提现失败');
        }

        return 'OK';
    }

    /**
     * EH支付回调
     * @param Request $request
     * @return string
     * @throws PushException
     */
    public function ehCallbackDeposit(Request $request): string
    {
        $data = $request->all();
        $log = Log::channel('eh_pay_server');
        $log->info('callbackDeposit:返回数据', [$data]);
        //返回数据验签
        if (!empty($data['data'])){
            $resSign = (new EHpayService())->verifySign($data['data']);
        } else {
            return 'fail';
        }
        if (in_array($data['http_status_code'],(new EHpayService())->successCode) && $resSign == $data['data']['sign'] && in_array($data['data']['status'], [4, 5])) {
            /** @var PlayerRechargeRecord $playerRechargeRecord */
            $playerRechargeRecord = PlayerRechargeRecord::query()
                ->where('tradeno', $data['data']['order_number'])
                ->where('status', PlayerRechargeRecord::STATUS_WAIT)
                ->first();
            if(empty($playerRechargeRecord) || $playerRechargeRecord->money != $data['data']['amount']){
                $log->info('callbackDeposit:充值订单不存在');
                return 'fail';
            }
            DB::beginTransaction();
            try {
                $firstRecharge = PlayerRechargeRecord::query()->where('status', PlayerRechargeRecord::STATUS_RECHARGED_SUCCESS)
                    ->where('player_id', $playerRechargeRecord->player_id)
                    ->where('type', '<>', PlayerRechargeRecord::TYPE_ARTIFICIAL)
                    ->first();
                // 生成订单
                $playerRechargeRecord->status = PlayerRechargeRecord::STATUS_RECHARGED_SUCCESS;
                $playerRechargeRecord->notify_result = json_encode($data);
                $playerRechargeRecord->remark = $remark??'';
                $playerRechargeRecord->finish_time = date('Y-m-d H:i:s');
                //使用 Lua 原子操作加款（Redis 作为唯一实时标准）
                $beforeGameAmount = \app\service\WalletService::getBalance($playerRechargeRecord->player_id, 1);
                $afterGameAmount = \app\service\WalletService::add($playerRechargeRecord->player_id, $playerRechargeRecord->point, 1);
                //全民代理首充返佣
                if (!isset($firstRecharge) && !empty($playerRechargeRecord->player->recommend_id) && $playerRechargeRecord->player->channel->national_promoter_status == 1) {
                    //玩家上级推广员信息
                    /** @var Player $recommendPlayer */
                    $recommendPlayer = Player::query()->find($playerRechargeRecord->player->recommend_id);
                    //首冲成功之后激活全民代理身份
                    /** @var NationalPromoter $nationalPromoter */
                    $nationalPromoter = NationalPromoter::query()->where('uid',$playerRechargeRecord->player_id)->first();
                    $nationalPromoter->created_at = $playerRechargeRecord->finish_time;
                    $nationalPromoter->status = 1;
                    $nationalPromoter->save();
                    //推广员为全民代理
                    if(!empty($recommendPlayer->national_promoter) && $recommendPlayer->is_promoter < 1){
                        //首充返佣金额
                        $rechargeRebate = $recommendPlayer->national_promoter->level_list->recharge_ratio;
                        //使用 Lua 原子操作加款（Redis 作为唯一实时标准）
                        $beforeRechargeAmount = \app\service\WalletService::getBalance($recommendPlayer->id, 1);
                        $afterRechargeAmount = \app\service\WalletService::add($recommendPlayer->id, $rechargeRebate, 1);

                        //寫入首充金流明細
                        $playerDeliveryRecord = new PlayerDeliveryRecord;
                        $playerDeliveryRecord->player_id = $recommendPlayer->id;
                        $playerDeliveryRecord->department_id = $recommendPlayer->department_id;
                        $playerDeliveryRecord->target = $playerRechargeRecord->getTable();
                        $playerDeliveryRecord->target_id = $playerRechargeRecord->id;
                        $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_RECHARGE_REWARD;
                        $playerDeliveryRecord->source = 'national_promoter';
                        $playerDeliveryRecord->amount = $rechargeRebate;
                        $playerDeliveryRecord->amount_before = $beforeRechargeAmount;
                        $playerDeliveryRecord->amount_after = $recommendPlayer->machine_wallet->money;
                        $playerDeliveryRecord->tradeno = $playerRechargeRecord->tradeno ?? '';
                        $playerDeliveryRecord->remark = $playerRechargeRecord->remark ?? '';
                        $playerDeliveryRecord->save();

                        //首冲成功之后全民代理邀请奖励
                        $recommendPlayer->national_promoter->invite_num = bcadd($recommendPlayer->national_promoter->invite_num, 1, 0);
                        $recommendPlayer->national_promoter->settlement_amount = bcadd($recommendPlayer->national_promoter->settlement_amount, $rechargeRebate, 2);
                        /** @var NationalInvite $national_invite */
                        $national_invite = NationalInvite::where('min', '<=',
                            $recommendPlayer->national_promoter->invite_num)
                            ->where('max', '>=', $recommendPlayer->national_promoter->invite_num)->first();

                        if (!empty($national_invite) && $national_invite->interval > 0 && $recommendPlayer->national_promoter->invite_num % $national_invite->interval == 0) {
                            $money = $national_invite->money;
                            //使用 Lua 原子操作加款（Redis 作为唯一实时标准）
                            $amount_before = \app\service\WalletService::getBalance($recommendPlayer->id, 1);
                            $amount_after = \app\service\WalletService::add($recommendPlayer->id, $money, 1);

                            // 寫入金流明細
                            $playerDeliveryRecord = new PlayerDeliveryRecord;
                            $playerDeliveryRecord->player_id = $recommendPlayer->id;
                            $playerDeliveryRecord->department_id = $recommendPlayer->department_id;
                            $playerDeliveryRecord->target = $national_invite->getTable();
                            $playerDeliveryRecord->target_id = $national_invite->id;
                            $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_NATIONAL_INVITE;
                            $playerDeliveryRecord->source = 'national_promoter';
                            $playerDeliveryRecord->amount = $money;
                            $playerDeliveryRecord->amount_before = $amount_before;
                            $playerDeliveryRecord->amount_after = $amount_after;
                            $playerDeliveryRecord->tradeno = '';
                            $playerDeliveryRecord->remark = '';
                            $playerDeliveryRecord->save();
                        }
                        $recommendPlayer->push();

                        $nationalProfitRecord = new NationalProfitRecord();
                        $nationalProfitRecord->uid = $playerRechargeRecord->player_id;
                        $nationalProfitRecord->recommend_id = $playerRechargeRecord->player->recommend_id ?? 0;
                        $nationalProfitRecord->money = $rechargeRebate;
                        $nationalProfitRecord->type = 0;
                        $nationalProfitRecord->status = 1;
                        $nationalProfitRecord->save();
                        $playerRechargeRecord->recharge_ratio = $rechargeRebate;
                    }
                }
                $playerRechargeRecord->player->player_extend->recharge_amount = bcadd($playerRechargeRecord->player->player_extend->recharge_amount,$playerRechargeRecord->point, 2);
                // 更新渠道信息
                $playerRechargeRecord->player->channel->recharge_amount = bcadd($playerRechargeRecord->player->channel->recharge_amount, $playerRechargeRecord->point, 2);
                $playerRechargeRecord->push();
                //寫入金流明細
                $playerDeliveryRecord = new PlayerDeliveryRecord;
                $playerDeliveryRecord->player_id = $playerRechargeRecord->player_id;
                $playerDeliveryRecord->department_id = $playerRechargeRecord->department_id;
                $playerDeliveryRecord->target = $playerRechargeRecord->getTable();
                $playerDeliveryRecord->target_id = $playerRechargeRecord->id;
                $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_RECHARGE;
                $playerDeliveryRecord->source = 'self_recharge';
                $playerDeliveryRecord->amount = $playerRechargeRecord->point;
                $playerDeliveryRecord->amount_before = $beforeGameAmount;
                $playerDeliveryRecord->amount_after = $afterGameAmount;
                $playerDeliveryRecord->tradeno = $playerRechargeRecord->tradeno ?? '';
                $playerDeliveryRecord->remark = $playerRechargeRecord->remark ?? '';
                $playerDeliveryRecord->save();
                saveChannelFinancialRecord($playerRechargeRecord, ChannelFinancialRecord::ACTION_RECHARGE_PASS);
                // 发送站内信
                $notice = new Notice();
                $notice->department_id = $playerRechargeRecord->player->channel->department_id;
                $notice->player_id = $playerRechargeRecord->player_id;
                $notice->source_id = $playerRechargeRecord->id;
                $notice->type = Notice::TYPE_RECHARGE_PASS;
                $notice->receiver = Notice::RECEIVER_PLAYER;
                $notice->is_private = 1;
                $notice->title = '充值稽核通過';
                $notice->content = '本次提交已通過審核，上分 ' . $playerRechargeRecord->point . ' ，請查收。';
                $notice->save();
                DB::commit();
                $log->info('callbackDeposit:充值成功');
                return 'success';
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('EH支付失败', [$e->getTrace()]);
                return message_error(admin_trans('player_recharge_record.action_error'));
            }
        } else {
            return 'fail';
        }
    }

    /**
     * EH代付回调
     * @param Request $request
     * @return string
     */
    public function ehCallbackWithdraws(Request $request): string
    {
        $data = $request->post();
        $log = Log::channel('eh_pay_server');
        $log->info('callbackWithdraw:返回数据', [$data]);
        //返回数据验签
        if (!empty($data['data'])){
            $resSign = (new EHpayService())->verifySign($data['data']);
        } else {
            return 'data miss';
        }
        if ($resSign == $data['data']['sign']) {
            /** @var PlayerWithdrawRecord $playerWithdrawRecord */
            $playerWithdrawRecord = PlayerWithdrawRecord::query()
                ->where('tradeno', $data['data']['order_number'])
                ->where('money', $data['data']['amount'])
                ->where('type', PlayerWithdrawRecord::TYPE_SELF)
                ->where('status', PlayerWithdrawRecord::STATUS_PENDING_PAYMENT)
                ->first();
            if (empty($playerWithdrawRecord)) {
                $log->info('callbackWithdraw:提现订单不存在');
                return 'fail';
            }

            if (in_array($data['http_status_code'],(new EHpayService())->successCode) && in_array($data['data']['status'], [4, 5])) {
                DB::beginTransaction();
                try {
                    // 更新订单
                    $playerWithdrawRecord->status = PlayerWithdrawRecord::STATUS_SUCCESS;
                    $playerWithdrawRecord->talk_tradeno = $data['data']['system_order_number'];
                    $playerWithdrawRecord->talk_result = json_encode($data);
                    $playerWithdrawRecord->finish_time = date('Y-m-d H:i:s');
                    if ($playerWithdrawRecord->save()) {
                        saveChannelFinancialRecord($playerWithdrawRecord, ChannelFinancialRecord::ACTION_WITHDRAW_PAYMENT);
                        // 更新渠道数据
                        $playerWithdrawRecord->channel->withdraw_amount = bcadd($playerWithdrawRecord->channel->withdraw_amount,
                            $playerWithdrawRecord->point, 2);
                        $playerWithdrawRecord->channel->push();
                    }
                    // 发送站内信
                    $notice = new Notice();
                    $notice->department_id = $playerWithdrawRecord->player->department_id;
                    $notice->player_id = $playerWithdrawRecord->player_id;
                    $notice->source_id = $playerWithdrawRecord->id;
                    $notice->type = Notice::TYPE_WITHDRAW_COMPLETE;
                    $notice->receiver = Notice::RECEIVER_PLAYER;
                    $notice->is_private = 1;
                    $notice->title = '提現打款成功';
                    $notice->content = '恭喜您的提現訂單已打款成功，提現遊戲點 ' . $playerWithdrawRecord->point . '， 共提現金額 ' . $playerWithdrawRecord->inmoney;
                    $notice->save();
                    DB::commit();
                } catch (\Exception $e) {
                    DB::rollBack();
                    $log->info('callbackWithdraw:数据处理失败', [$e->getTrace()]);
                    return 'fail';
                }
                $log->info('callbackWithdraw:提现成功');
            } elseif(in_array($data['http_status_code'],(new EHpayService())->successCode) && in_array($data['data']['status'], [1, 2, 3, 7, 11])){
                $log->info('callbackWithdraw:提现处理中');
                return 'fail';
            }  else {
                try {
                    if (in_array($data['data']['status'], [6, 8])) {
                        $rejectReason = '提现失败，请联系客服';
                    } else {
                        $rejectReason = (new EHpayService($playerWithdrawRecord->player))->failCode[$data['errorCode']];
                    }
                    if (withdrawBack($playerWithdrawRecord, $rejectReason)) {
                        saveChannelFinancialRecord($playerWithdrawRecord, ChannelFinancialRecord::ACTION_WITHDRAW_EH_ERROR);
                    }
                    // 发送站内信
                    $notice = new Notice();
                    $notice->department_id = $playerWithdrawRecord->player->department_id;
                    $notice->player_id = $playerWithdrawRecord->player_id;
                    $notice->source_id = $playerWithdrawRecord->id;
                    $notice->type = Notice::TYPE_WITHDRAW_REJECT;
                    $notice->receiver = Notice::RECEIVER_PLAYER;
                    $notice->is_private = 1;
                    $notice->title = '提現支付不成功';
                    $notice->content = '抱歉您的提現訂單未支付成功，原因是: ' . $rejectReason;
                    $notice->save();
                } catch (Exception $e) {
                    $log->info('callbackWithdraw:提现失败', [$e->getMessage()]);
                }
                $log->info('callbackWithdraw:提现失败');
                return 'fail';
            }

            return 'success';
        } else {
            return 'fail';
        }
    }

    /**
     * 获取彩金池和最新中奖记录
     * @param Request $request
     * @return Response
     */
    public function getLotteryPoolAndRecords(Request $request): Response
    {
        try {
            $data = $request->post();

            // 获取请求的类型参数（可选）
            // type: 1=斯洛(老虎机), 2=钢珠, 9=电子游戏
            // 如果不传type，则返回所有类型
            $type = $data['type'] ?? null;

            // 验证type参数
            if ($type !== null && !in_array($type, [1, 2, 9])) {
                return jsonFailResponse(trans('machine_type_param_error', [], 'message'), [], 0);
            }

            $lotteryPoolData = [];

            // 1. 根据type获取对应的彩金池数据
            if ($type === null || $type == 1) {
                // 获取老虎机彩金
                $lotteryServices = (new LotteryServices())->setSlotLotteryList();
                $lotteryPoolData['slot_amount'] = $this->formatLotteryList($lotteryServices->slotLotteryList);
            }

            if ($type === null || $type == 2) {
                // 获取钢珠机彩金
                $lotteryServices = (new LotteryServices())->setJackLotteryList();
                $lotteryPoolData['jack_amount'] = $this->formatLotteryList($lotteryServices->jackLotteryList);
            }

            if ($type === null || $type == 9) {
                // 获取电子游戏彩金
                $gameLotteryPool = GameLotteryServices::getLotteryPool();
                $lotteryPoolData['game_lottery_list'] = $this->formatGameLotteryPool($gameLotteryPool);
            }

            // 2. 获取最新中奖记录（每个彩池取4条）
            $recordsQuery = PlayerLotteryRecord::with(['player', 'lottery', 'channel'])
                ->where('status', PlayerLotteryRecord::STATUS_COMPLETE);

            // 根据type过滤中奖记录
            if ($type !== null) {
                if ($type == 1) {
                    // 老虎机
                    $recordsQuery->where('game_type', GameType::TYPE_SLOT);
                } elseif ($type == 2) {
                    // 钢珠机
                    $recordsQuery->where('game_type', GameType::TYPE_STEEL_BALL);
                } elseif ($type == 9) {
                    // 电子游戏
                    $recordsQuery->where('source', PlayerLotteryRecord::SOURCE_GAME);
                }
            }

            // 获取所有符合条件的彩池ID
            $lotteryIds = (clone $recordsQuery)
                ->distinct()
                ->pluck('lottery_id')
                ->toArray();

            // 每个彩池取4条最新记录，按彩池分组
            $lotteryRecords = [];
            foreach ($lotteryIds as $lotteryId) {
                $records = (clone $recordsQuery)
                    ->where('lottery_id', $lotteryId)
                    ->orderBy('id', 'desc')
                    ->limit(4)
                    ->get();

                if ($records->isEmpty()) {
                    continue;
                }

                // 获取彩池信息
                $firstRecord = $records->first();

                $lotteryRecords[] = [
                    'lottery_id' => $lotteryId,
                    'lottery_name' => $firstRecord->lottery_name,
                    'records' => $records->map(function (PlayerLotteryRecord $record) {
                        return [
                            'id' => $record->id,
                            'player_id' => $record->player_id,
                            'player_name' => $record->player_name,
                            'player_phone' => $record->player_phone,
                            'lottery_name' => $record->lottery_name,
                            'amount' => number_format($record->amount, 2, '.', ''),
                            'game_type' => $record->game_type,
                            'game_type_text' => $record->game_type,
                            'lottery_type' => $record->lottery_type,
                            'lottery_multiple' => $record->lottery_multiple,
                            'source' => $record->source,
                            'source_text' => $record->source == PlayerLotteryRecord::SOURCE_MACHINE ? '实体机台' : '电子游戏',
                            'created_at' => $record->created_at,
                        ];
                    })->toArray()
                ];
            }

            return jsonSuccessResponse('success', [
                'lottery_pool' => $lotteryPoolData,
                'latest_records' => $lotteryRecords,
            ]);

        } catch (\Exception $e) {
            Log::error('getLotteryPoolAndRecords error: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return jsonFailResponse(trans('get_data_failed', [], 'message'), [], 0);
        }
    }

    /**
     * 格式化彩金列表（老虎机/钢珠机）- 新版：使用独立彩池
     * @param $lotteryList
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

            // 获取爆彩状态（机台彩金使用不同的键前缀）
            $burstStatus = $this->getMachineBurstStatus($lottery->id, $lottery->burst_duration);
            $result[] = [
                'id' => $lottery->id,
                'name' => $lottery->name,
                'amount' => number_format($amount, 2, '.', ''),
                'lottery_multiple' => 1,
                'lottery_type' => $lottery->lottery_type,
                'lottery_type_text' => $lottery->lottery_type == Lottery::LOTTERY_TYPE_FIXED ? '固定' : '随机',
                'burst_status' => $burstStatus,
            ];
        }

        return $result;
    }

    /**
     * 格式化电子游戏彩金池数据
     * @param array $gameLotteryPool
     * @return array
     */
    private function formatGameLotteryPool(array $gameLotteryPool): array
    {
        $formattedGamePool = [];

        if (empty($gameLotteryPool)) {
            return $formattedGamePool;
        }

        foreach ($gameLotteryPool as $lottery) {
            // 获取爆彩状态
            $burstStatus = $this->getBurstStatus($lottery['id'], $lottery['burst_duration'] ?? 5);

            $formattedGamePool[] = [
                'id' => $lottery['id'],
                'name' => $lottery['name'],
                'amount' => number_format($lottery['amount'], 2, '.', ''),
                'lottery_type' => GameLottery::LOTTERY_TYPE_RANDOM,
                'lottery_type_text' => '随机',
                'burst_status' => $burstStatus,
            ];
        }

        return $formattedGamePool;
    }

    /**
     * 获取机台彩池的爆彩状态（老虎机/钢珠机）
     * @param int $lotteryId 彩池ID
     * @param int $burstDuration 爆彩持续时长（分钟）
     * @return array
     */
    private function getMachineBurstStatus(int $lotteryId, int $burstDuration): array
    {
        return $this->getGenericBurstStatus($lotteryId, $burstDuration, 'machine_lottery_burst:');
    }

    /**
     * 获取电子游戏彩池的爆彩状态
     * @param int $lotteryId 彩池ID
     * @param int $burstDuration 爆彩持续时长（分钟）
     * @return array
     */
    private function getBurstStatus(int $lotteryId, int $burstDuration): array
    {
        return $this->getGenericBurstStatus($lotteryId, $burstDuration, 'game_lottery_burst:');
    }

    /**
     * 通用爆彩状态获取方法
     * @param int $lotteryId 彩池ID
     * @param int $burstDuration 爆彩持续时长（分钟）
     * @param string $keyPrefix Redis键前缀
     * @return array
     */
    private function getGenericBurstStatus(int $lotteryId, int $burstDuration, string $keyPrefix): array
    {
        try {
            $redis = \support\Redis::connection();
            $burstKey = $keyPrefix . $lotteryId;
            $startTime = $redis->get($burstKey);

            // 如果没有爆彩记录，返回未爆彩状态
            if (!$startTime) {
                return [
                    'is_bursting' => false,
                    'burst_multiplier' => 1.0,
                    'burst_start_time' => null,
                    'burst_elapsed_seconds' => 0,
                    'burst_remaining_seconds' => 0,
                    'burst_elapsed_time' => '00:00:00',
                    'burst_remaining_time' => '00:00:00',
                ];
            }

            // 计算爆彩时长
            $startTime = intval($startTime);
            $currentTime = time();
            $elapsedSeconds = $currentTime - $startTime;
            $totalSeconds = $burstDuration * 60;
            $remainingSeconds = max(0, $totalSeconds - $elapsedSeconds);

            // 如果爆彩已经结束，清理Redis键并返回未爆彩状态
            if ($remainingSeconds <= 0) {
                // 删除已过期的爆彩键
                $redis->del($burstKey);

                Log::info('爆彩已过期并清理Redis键', [
                    'lottery_id' => $lotteryId,
                    'burst_key' => $burstKey,
                    'elapsed_seconds' => $elapsedSeconds,
                    'total_seconds' => $totalSeconds,
                ]);

                return [
                    'is_bursting' => false,
                    'burst_multiplier' => 1.0,
                    'burst_start_time' => null,
                    'burst_elapsed_seconds' => 0,
                    'burst_remaining_seconds' => 0,
                    'burst_elapsed_time' => '00:00:00',
                    'burst_remaining_time' => '00:00:00',
                ];
            }

            // 计算当前倍数（根据剩余时间百分比）
            $remainingPercentage = ($remainingSeconds / $totalSeconds) * 100;
            $multiplier = 1.0;

            // 简化的倍数计算逻辑（与 GameLottery::getBurstMultiplier 一致）
            if ($remainingPercentage <= 10) {
                $multiplier = 50;
            } elseif ($remainingPercentage <= 30) {
                $multiplier = 25;
            } elseif ($remainingPercentage <= 50) {
                $multiplier = 15;
            } elseif ($remainingPercentage <= 70) {
                $multiplier = 10;
            } else {
                $multiplier = 5;
            }

            return [
                'is_bursting' => true,
                'burst_multiplier' => $multiplier,
                'burst_start_time' => date('Y-m-d H:i:s', $startTime),
                'burst_elapsed_seconds' => $elapsedSeconds,
                'burst_remaining_seconds' => $remainingSeconds,
                'burst_elapsed_time' => gmdate('H:i:s', $elapsedSeconds),
                'burst_remaining_time' => gmdate('H:i:s', $remainingSeconds),
            ];

        } catch (\Exception $e) {
            // 如果 Redis 异常，返回未爆彩状态
            Log::error('getBurstStatus error: ' . $e->getMessage());
            return [
                'is_bursting' => false,
                'burst_multiplier' => 1.0,
                'burst_start_time' => null,
                'burst_elapsed_seconds' => 0,
                'burst_remaining_seconds' => 0,
                'burst_elapsed_time' => '00:00:00',
                'burst_remaining_time' => '00:00:00',
            ];
        }
    }

    /**
     * 测试接口：检查中奖
     * @param Request $request
     * @return Response
     */
    public function testCheckLottery(Request $request): Response
    {
        try {
            $data = $request->post();

            // 验证参数
            if (empty($data['player_id']) || empty($data['bet'])) {
                return jsonFailResponse(trans('player_bet_param_required', [], 'message'), [], 0);
            }

            $playerId = intval($data['player_id']);
            $bet = floatval($data['bet']);
            $playGameRecordId = intval($data['play_game_record_id'] ?? 0);

            // 获取玩家
            $player = Player::find($playerId);
            if (!$player) {
                return jsonFailResponse(trans('player_not_found', [], 'message'), [], 0);
            }

            // 调用中奖检测服务
            $gameLotteryServices = new GameLotteryServices();
            $gameLotteryServices->setPlayer($player)
                ->setLog()
                ->setLotteryList();

            // 执行中奖检测
            $result = $gameLotteryServices->checkLottery($bet, $playGameRecordId);

            // 查询是否真的中奖了
            $latestRecord = PlayerLotteryRecord::where('player_id', $playerId)
                ->where('source', PlayerLotteryRecord::SOURCE_GAME)
                ->where('play_game_record_id', $playGameRecordId)
                ->orderBy('id', 'desc')
                ->first();

            if ($latestRecord) {
                return jsonSuccessResponse(trans('lottery_check_completed', [], 'message'), [
                    'has_win' => true,
                    'lottery_id' => $latestRecord->lottery_id,
                    'lottery_name' => $latestRecord->lottery_name,
                    'amount' => $latestRecord->amount,
                    'lottery_multiple' => $latestRecord->lottery_multiple,
                    'record_id' => $latestRecord->id,
                    'created_at' => $latestRecord->created_at,
                ]);
            } else {
                return jsonSuccessResponse(trans('lottery_check_completed', [], 'message'), [
                    'has_win' => false,
                    'message' => '未中奖'
                ]);
            }

        } catch (\Exception $e) {
            Log::error('testCheckLottery error: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            return jsonFailResponse(trans('test_failed', [], 'message') . ': ' . $e->getMessage(), [], 0);
        }
    }

    /**
     * 测试接口：触发爆彩
     * @param Request $request
     * @return Response
     */
    public function testTriggerBurst(Request $request): Response
    {
        try {
            $data = $request->post();

            // 验证参数
            if (empty($data['lottery_id'])) {
                return jsonFailResponse(trans('lottery_id_required', [], 'message'), [], 0);
            }

            $lotteryId = intval($data['lottery_id']);
            $type = $data['type'] ?? 'game'; // game=电子游戏, machine=机台
            $duration = intval($data['duration'] ?? 5); // 默认5分钟

            // 验证类型参数
            if (!in_array($type, ['game', 'machine'])) {
                return jsonFailResponse(trans('lottery_type_param_error', [], 'message'), [], 0);
            }

            $redis = \support\Redis::connection();

            // 根据类型处理不同的彩金池
            if ($type === 'game') {
                // 电子游戏彩金
                return $this->triggerGameLotteryBurst($lotteryId, $duration, $redis);
            } else {
                // 机台彩金（老虎机/钢珠机）
                return $this->triggerMachineLotteryBurst($lotteryId, $duration, $redis);
            }

        } catch (\Exception $e) {
            Log::error('testTriggerBurst error: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return jsonFailResponse(trans('trigger_failed', [], 'message') . ': ' . $e->getMessage(), [], 0);
        }
    }

    /**
     * 触发电子游戏爆彩
     * @param int $lotteryId
     * @param int $duration
     * @param \Redis $redis
     * @return Response
     */
    private function triggerGameLotteryBurst(int $lotteryId, int $duration, $redis): Response
    {
        // 获取彩金池
        $lottery = \app\model\GameLottery::find($lotteryId);
        if (!$lottery) {
            return jsonFailResponse(trans('game_lottery_pool_not_found', [], 'message'), [], 0);
        }

        // 检查是否已经在爆彩中
        $burstKey = 'game_lottery_burst:' . $lotteryId;
        $existingBurst = $redis->get($burstKey);

        if ($existingBurst) {
            return jsonFailResponse(trans('lottery_pool_in_progress', [], 'message'), [], 0);
        }

        // 设置爆彩开始时间
        $currentTime = time();
        $expireSeconds = ($duration + GameLotteryServices::BURST_DURATION_BUFFER) * 60;
        $redis->setex($burstKey, $expireSeconds, $currentTime);

        // 发送爆彩开启 WebSocket 通知
        $this->sendBurstStartNotice($lottery->id, $lottery->name, $duration, 'game');

        Log::info('【测试】触发电子游戏爆彩', [
            'lottery_id' => $lotteryId,
            'lottery_name' => $lottery->name,
            'duration' => $duration,
            'start_time' => date('Y-m-d H:i:s', $currentTime),
            'expire_time' => date('Y-m-d H:i:s', $currentTime + $expireSeconds),
        ]);

        return jsonSuccessResponse(trans('game_lottery_trigger_success', [], 'message'), [
            'type' => 'game',
            'lottery_id' => $lotteryId,
            'lottery_name' => $lottery->name,
            'start_time' => date('Y-m-d H:i:s', $currentTime),
            'duration' => $duration,
            'expire_time' => date('Y-m-d H:i:s', $currentTime + $duration * 60),
            'redis_key' => $burstKey,
            'redis_ttl' => $expireSeconds,
        ]);
    }

    /**
     * 触发机台彩金爆彩
     * @param int $lotteryId
     * @param int $duration
     * @param \Redis $redis
     * @return Response
     */
    private function triggerMachineLotteryBurst(int $lotteryId, int $duration, $redis): Response
    {
        // 获取机台彩金池
        $lottery = Lottery::find($lotteryId);
        if (!$lottery) {
            return jsonFailResponse(trans('machine_lottery_pool_not_found', [], 'message'), [], 0);
        }

        // 检查是否已经在爆彩中
        $burstKey = 'machine_lottery_burst:' . $lotteryId;
        $existingBurst = $redis->get($burstKey);

        if ($existingBurst) {
            return jsonFailResponse(trans('lottery_pool_in_progress', [], 'message'), [], 0);
        }

        // 设置爆彩开始时间
        $currentTime = time();
        $expireSeconds = ($duration + LotteryServices::BURST_DURATION_BUFFER) * 60;
        $redis->setex($burstKey, $expireSeconds, $currentTime);

        // 获取游戏类型
        $gameTypeName = $lottery->game_type == 1 ? '老虎机' : '钢珠机';

        // 发送爆彩开启 WebSocket 通知
        $this->sendBurstStartNotice($lottery->id, $lottery->name, $duration, 'machine', $gameTypeName);

        Log::info('【测试】触发机台爆彩', [
            'lottery_id' => $lotteryId,
            'lottery_name' => $lottery->name,
            'game_type' => $lottery->game_type,
            'game_type_name' => $gameTypeName,
            'duration' => $duration,
            'start_time' => date('Y-m-d H:i:s', $currentTime),
            'expire_time' => date('Y-m-d H:i:s', $currentTime + $expireSeconds),
        ]);

        return jsonSuccessResponse(trans('machine_lottery_trigger_success', [], 'message'), [
            'type' => 'machine',
            'lottery_id' => $lotteryId,
            'lottery_name' => $lottery->name,
            'game_type' => $lottery->game_type,
            'game_type_name' => $gameTypeName,
            'start_time' => date('Y-m-d H:i:s', $currentTime),
            'duration' => $duration,
            'expire_time' => date('Y-m-d H:i:s', $currentTime + $duration * 60),
            'redis_key' => $burstKey,
            'redis_ttl' => $expireSeconds,
        ]);
    }

    /**
     * 发送爆彩开启通知（测试用）
     * @param int $lotteryId
     * @param string $lotteryName
     * @param int $duration
     * @param string $type game|machine
     * @param string|null $gameTypeName
     * @return void
     */
    private function sendBurstStartNotice(int $lotteryId, string $lotteryName, int $duration, string $type, ?string $gameTypeName = null): void
    {
        try {
            $message = [
                'msg_type' => $type === 'game' ? 'game_lottery_burst_notice' : 'machine_lottery_burst_notice',
                'lottery_id' => $lotteryId,
                'lottery_name' => $lotteryName,
                'burst_type' => 'start',
                'duration' => $duration,
                'title' => '🎉 【测试】彩金池爆彩开启！',
                'content' => sprintf(
                    '%s%s 爆彩活动正式开启！持续时间：%d分钟（测试模式）',
                    $type === 'machine' && $gameTypeName ? "【{$gameTypeName}】" : '',
                    $lotteryName,
                    $duration
                ),
                'test_mode' => true,
                'start_time' => date('Y-m-d H:i:s'),
            ];

            if ($type === 'machine' && $gameTypeName) {
                $message['game_type_name'] = $gameTypeName;
            }

            // 发送到全局广播频道
            sendSocketMessage('broadcast', $message);

            Log::info('发送测试爆彩开启通知', [
                'type' => $type,
                'lottery_id' => $lotteryId,
                'lottery_name' => $lotteryName,
                'message' => $message,
            ]);
        } catch (\Exception $e) {
            Log::error('发送测试爆彩开启通知失败', [
                'lottery_id' => $lotteryId,
                'lottery_name' => $lotteryName,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 测试接口：获取爆彩状态
     * @param Request $request
     * @return Response
     */
    public function testGetBurstStatus(Request $request): Response
    {
        try {
            $data = $request->post();

            // 验证参数
            if (empty($data['lottery_id'])) {
                return jsonFailResponse(trans('lottery_id_required', [], 'message'), [], 0);
            }

            $lotteryId = intval($data['lottery_id']);
            $type = $data['type'] ?? 'game'; // game=电子游戏, machine=机台

            // 验证类型参数
            if (!in_array($type, ['game', 'machine'])) {
                return jsonFailResponse(trans('lottery_type_param_error', [], 'message'), [], 0);
            }

            $redis = \support\Redis::connection();

            if ($type === 'game') {
                // 电子游戏彩金
                $lottery = \app\model\GameLottery::find($lotteryId);
                if (!$lottery) {
                    return jsonFailResponse(trans('game_lottery_pool_not_found', [], 'message'), [], 0);
                }
                $burstKey = 'game_lottery_burst:' . $lotteryId;
            } else {
                // 机台彩金
                $lottery = Lottery::find($lotteryId);
                if (!$lottery) {
                    return jsonFailResponse(trans('machine_lottery_pool_not_found', [], 'message'), [], 0);
                }
                $burstKey = 'machine_lottery_burst:' . $lotteryId;
            }

            // 检查爆彩状态
            $startTime = $redis->get($burstKey);

            if (!$startTime) {
                $result = [
                    'is_bursting' => false,
                    'type' => $type,
                    'lottery_id' => $lotteryId,
                    'lottery_name' => $lottery->name,
                ];

                if ($type === 'machine') {
                    $result['game_type'] = $lottery->game_type;
                    $result['game_type_name'] = $lottery->game_type == 1 ? '老虎机' : '钢珠机';
                }

                return jsonSuccessResponse(trans('query_success', [], 'message'), $result);
            }

            $startTime = intval($startTime);
            $currentTime = time();
            $elapsedSeconds = $currentTime - $startTime;
            $totalSeconds = $lottery->burst_duration * 60;
            $remainingSeconds = max(0, $totalSeconds - $elapsedSeconds);

            // 计算爆彩倍数
            $multiplier = 1.0;
            if ($remainingSeconds > 0) {
                $remainingPercentage = ($remainingSeconds / $totalSeconds) * 100;
                $multiplierConfig = $lottery->getBurstMultiplierConfig();

                if ($remainingPercentage <= 10) {
                    $multiplier = $multiplierConfig['final'];
                } elseif ($remainingPercentage <= 30) {
                    $multiplier = $multiplierConfig['stage_4'];
                } elseif ($remainingPercentage <= 50) {
                    $multiplier = $multiplierConfig['stage_3'];
                } elseif ($remainingPercentage <= 70) {
                    $multiplier = $multiplierConfig['stage_2'];
                } else {
                    $multiplier = $multiplierConfig['initial'];
                }
            }

            $result = [
                'is_bursting' => true,
                'type' => $type,
                'lottery_id' => $lotteryId,
                'lottery_name' => $lottery->name,
                'multiplier' => $multiplier,
                'start_time' => date('Y-m-d H:i:s', $startTime),
                'elapsed_time' => gmdate('H:i:s', $elapsedSeconds),
                'remaining_time' => gmdate('H:i:s', $remainingSeconds),
                'elapsed_seconds' => $elapsedSeconds,
                'remaining_seconds' => $remainingSeconds,
                'burst_duration' => $lottery->burst_duration,
            ];

            if ($type === 'machine') {
                $result['game_type'] = $lottery->game_type;
                $result['game_type_name'] = $lottery->game_type == 1 ? '老虎机' : '钢珠机';
            }

            return jsonSuccessResponse(trans('query_success', [], 'message'), $result);

        } catch (\Exception $e) {
            Log::error('testGetBurstStatus error: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return jsonFailResponse(trans('query_failed', [], 'message') . ': ' . $e->getMessage(), [], 0);
        }
    }

    /**
     * 测试接口：结束爆彩
     * @param Request $request
     * @return Response
     */
    public function testEndBurst(Request $request): Response
    {
        try {
            $data = $request->post();

            // 验证参数
            if (empty($data['lottery_id'])) {
                return jsonFailResponse(trans('lottery_id_required', [], 'message'), [], 0);
            }

            $lotteryId = intval($data['lottery_id']);
            $type = $data['type'] ?? 'game'; // game=电子游戏, machine=机台

            // 验证类型参数
            if (!in_array($type, ['game', 'machine'])) {
                return jsonFailResponse(trans('lottery_type_param_error', [], 'message'), [], 0);
            }

            $redis = \support\Redis::connection();

            if ($type === 'game') {
                // 电子游戏彩金
                $lottery = \app\model\GameLottery::find($lotteryId);
                if (!$lottery) {
                    return jsonFailResponse(trans('game_lottery_pool_not_found', [], 'message'), [], 0);
                }
                $burstKey = 'game_lottery_burst:' . $lotteryId;
            } else {
                // 机台彩金
                $lottery = Lottery::find($lotteryId);
                if (!$lottery) {
                    return jsonFailResponse(trans('machine_lottery_pool_not_found', [], 'message'), [], 0);
                }
                $burstKey = 'machine_lottery_burst:' . $lotteryId;
            }

            // 删除爆彩标记
            $deleted = $redis->del($burstKey);

            if ($deleted) {
                // 发送爆彩结束通知
                $this->sendBurstEndNoticeForTest($lottery->id, $lottery->name, $type, $lottery);

                $result = [
                    'type' => $type,
                    'lottery_id' => $lotteryId,
                    'lottery_name' => $lottery->name,
                ];

                if ($type === 'machine') {
                    $result['game_type'] = $lottery->game_type;
                    $result['game_type_name'] = $lottery->game_type == 1 ? '老虎机' : '钢珠机';
                }

                Log::info('【测试】结束爆彩', $result);

                return jsonSuccessResponse(trans('lottery_ended', [], 'message'), $result);
            } else {
                return jsonFailResponse(trans('lottery_pool_not_in_progress', [], 'message'), [], 0);
            }

        } catch (\Exception $e) {
            Log::error('testEndBurst error: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return jsonFailResponse(trans('end_failed', [], 'message') . ': ' . $e->getMessage(), [], 0);
        }
    }

    /**
     * 发送爆彩结束通知（测试用）
     * @param int $lotteryId
     * @param string $lotteryName
     * @param string $type
     * @param mixed $lottery
     * @return void
     */
    private function sendBurstEndNoticeForTest(int $lotteryId, string $lotteryName, string $type, $lottery): void
    {
        try {
            $message = [
                'msg_type' => $type === 'game' ? 'game_lottery_burst_notice' : 'machine_lottery_burst_notice',
                'lottery_id' => $lotteryId,
                'lottery_name' => $lotteryName,
                'burst_type' => 'end',
                'title' => '⏰ 【测试】爆彩活动结束',
                'content' => sprintf(
                    '%s%s 爆彩活动已结束（测试模式）',
                    $type === 'machine' ? "【" . ($lottery->game_type == 1 ? '老虎机' : '钢珠机') . "】" : '',
                    $lotteryName
                ),
                'test_mode' => true,
                'end_time' => date('Y-m-d H:i:s'),
            ];

            if ($type === 'machine') {
                $message['game_type'] = $lottery->game_type;
                $message['game_type_name'] = $lottery->game_type == 1 ? '老虎机' : '钢珠机';
            }

            // 发送到全局广播频道
            sendSocketMessage('broadcast', $message);

            Log::info('发送测试爆彩结束通知', [
                'type' => $type,
                'lottery_id' => $lotteryId,
                'lottery_name' => $lotteryName,
                'message' => $message,
            ]);
        } catch (\Exception $e) {
            Log::error('发送测试爆彩结束通知失败', [
                'lottery_id' => $lotteryId,
                'lottery_name' => $lotteryName,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 测试接口：概率服务测试
     * @param Request $request
     * @return Response
     */
    public function testProbability(Request $request): Response
    {
        try {
            $data = $request->post();

            // 验证参数
            $winRatio = floatval($data['win_ratio'] ?? 0);
            $testCount = intval($data['test_count'] ?? 100);
            $algorithm = $data['algorithm'] ?? 'checkSmart';

            if ($winRatio < 0 || $winRatio > 1) {
                return jsonFailResponse(trans('win_ratio_range_error', [], 'message'), [], 0);
            }

            if ($testCount <= 0 || $testCount > 100000) {
                return jsonFailResponse(trans('test_count_range_error', [], 'message'), [], 0);
            }

            $allowedAlgorithms = ['checkSmart', 'checkByBigInt', 'checkByHighPrecision', 'checkByProbabilityPool', 'checkBySimple'];
            if (!in_array($algorithm, $allowedAlgorithms)) {
                return jsonFailResponse(trans('unsupported_algorithm', [], 'message'), [], 0);
            }

            // 执行测试
            $service = new \app\service\LotteryProbabilityService();
            $winCount = 0;
            $startTime = microtime(true);

            for ($i = 0; $i < $testCount; $i++) {
                $result = false;

                switch ($algorithm) {
                    case 'checkSmart':
                        $result = $service->checkSmart($winRatio);
                        break;
                    case 'checkByBigInt':
                        $result = $service->checkByBigInt($winRatio);
                        break;
                    case 'checkByHighPrecision':
                        $result = $service->checkByHighPrecision($winRatio);
                        break;
                    case 'checkByProbabilityPool':
                        $result = $service->checkByProbabilityPool($winRatio);
                        break;
                    case 'checkBySimple':
                        $result = $service->checkBySimple($winRatio);
                        break;
                }

                if ($result) {
                    $winCount++;
                }
            }

            $endTime = microtime(true);
            $duration = $endTime - $startTime;

            // 计算统计信息
            $actualRate = $winCount / $testCount;
            $expectedRate = $winRatio;
            $deviation = $expectedRate > 0 ? abs($actualRate - $expectedRate) / $expectedRate * 100 : 0;

            Log::info('概率服务测试完成', [
                'algorithm' => $algorithm,
                'win_ratio' => $winRatio,
                'test_count' => $testCount,
                'win_count' => $winCount,
                'actual_rate' => $actualRate,
                'deviation' => $deviation,
                'duration' => $duration,
            ]);

            return jsonSuccessResponse(trans('test_completed', [], 'message'), [
                'algorithm' => $algorithm,
                'win_ratio' => $winRatio,
                'test_count' => $testCount,
                'win_count' => $winCount,
                'actual_rate' => $actualRate,
                'expected_rate' => $expectedRate,
                'deviation_percent' => $deviation,
                'duration_seconds' => round($duration, 4),
                'tests_per_second' => round($testCount / $duration, 2),
            ]);

        } catch (\Exception $e) {
            Log::error('testProbability error: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return jsonFailResponse(trans('test_failed', [], 'message') . ': ' . $e->getMessage(), [], 0);
        }
    }

    /**
     * 测试接口：模拟发送中奖消息
     * @param Request $request
     * @return Response
     */
    public function testSendWinMessage(Request $request): Response
    {
        try {
            $data = $request->post();

            // 验证参数
            if (empty($data['player_id'])) {
                return jsonFailResponse(trans('player_bet_param_required', [], 'message'), [], 0);
            }

            $playerId = intval($data['player_id']);
            $lotteryId = intval($data['lottery_id'] ?? 1);
            $lotteryName = $data['lottery_name'] ?? '测试彩金池';
            $amount = intval($data['amount'] ?? 10000);
            $lotteryMultiple = intval($data['lottery_multiple'] ?? 1);
            $isBurst = intval($data['is_burst'] ?? 0);
            $burstMultiplier = floatval($data['burst_multiplier'] ?? 1.0);
            $isDoubled = intval($data['is_doubled'] ?? 0);
            $messageType = $data['message_type'] ?? 'all'; // all/player/broadcast
            $gameType = intval($data['game_type'] ?? 3); // 1=老虎机, 2=钢珠机, 3=电子游戏

            // 获取玩家信息（如果存在）
            $playerName = '测试玩家';
            $playerUuid = 'TEST_' . $playerId;
            try {
                $player = \app\model\Player::find($playerId);
                if ($player) {
                    $playerName = $player->name ?? $player->uuid;
                    $playerUuid = $player->uuid;
                }
            } catch (\Exception $e) {
                // 玩家不存在，使用默认值
            }

            $gameTypeText = match($gameType) {
                1 => '老虎机',
                2 => '钢珠机',
                3 => '电子游戏',
                default => '未知',
            };

            // 1. 发送派彩消息（玩家私有频道）
            if (in_array($messageType, ['all', 'player'])) {
                // 根据游戏类型使用不同的消息格式
                if ($gameType == 3) {
                    // ===== 电子游戏 =====
                    $playerMessage = [
                        'msg_type' => 'game_player_lottery_allow',
                        'player_id' => $playerId,
                        'has_win' => 1,
                        'lottery_record_id' => time(), // 使用时间戳作为临时ID
                        'lottery_id' => $lotteryId,
                        'lottery_name' => $lotteryName,
                        'lottery_sort' => 1,
                        'lottery_type' => 2, // 随机
                        'amount' => $amount,
                        'lottery_pool_amount' => 50000, // 假设的池金额
                        'lottery_rate' => 100,
                        'is_doubled' => $isDoubled,
                        'lottery_multiple' => $lotteryMultiple,
                        'is_burst' => $isBurst,
                        'burst_multiplier' => $burstMultiplier,
                        'next_lottery' => [],
                        'test_mode' => true,
                    ];
                } else {
                    // ===== 实体机台（老虎机 / 钢珠机）=====
                    $playerMessage = [
                        'machine_id' => 999,
                        'msg_type' => 'player_lottery_allow',
                        'machine_name' => '测试机台',
                        'machine_code' => 'TEST001',
                        'machine_odds' => '1:1',
                        'machine_type' => $gameType,
                        'player_id' => $playerId,
                        'has_win' => 1,
                        'lottery_record_id' => time(), // 使用时间戳作为临时ID
                        'lottery_id' => $lotteryId,
                        'lottery_name' => $lotteryName,
                        'lottery_sort' => 1,
                        'lottery_type' => 2, // 随机
                        'amount' => $amount,
                        'lottery_pool_amount' => 50000, // 假设的池金额
                        'lottery_multiple' => $lotteryMultiple,
                        'is_burst' => $isBurst,
                        'burst_multiplier' => $burstMultiplier,
                        'is_doubled' => $isDoubled,
                        'lottery_rate' => 100,
                        'next_lottery' => [],
                        'test_mode' => true,
                    ];
                }

                sendSocketMessage('player-' . $playerId, $playerMessage);

                Log::info('【测试】发送派彩消息', [
                    'player_id' => $playerId,
                    'game_type' => $gameType,
                    'game_type_text' => $gameTypeText,
                    'msg_type' => $playerMessage['msg_type'],
                    'message' => $playerMessage,
                ]);
            }

            // 2. 发送站内消息（玩家私有频道）
            if (in_array($messageType, ['all', 'player'])) {
                $noticeMessage = [
                    'msg_type' => 'player_notice',
                    'player_id' => $playerId,
                    'notice_type' => \app\model\Notice::TYPE_LOTTERY, // 彩金派彩
                    'notice_title' => '【测试】彩金派彩',
                    'notice_content' => sprintf(
                        '恭喜您在%s%s中获得%s的彩金奖励（测试消息）',
                        $gameTypeText,
                        $gameType != 3 ? 'TEST001机台' : '',
                        $lotteryName
                    ),
                    'amount' => $amount,
                    'game_type' => $gameType,
                    'lottery_multiple' => $lotteryMultiple,
                    'is_burst' => $isBurst,
                    'is_doubled' => $isDoubled,
                    'lottery_rate' => 100,
                    'notice_num' => 1,
                    'test_mode' => true,
                ];

                // 实体机台需要添加机台信息
                if ($gameType != 3) {
                    $noticeMessage['machine_name'] = '测试机台';
                    $noticeMessage['machine_code'] = 'TEST001';
                    $noticeMessage['lottery_name'] = $lotteryName;
                    $noticeMessage['lottery_type'] = 2; // 随机
                }

                sendSocketMessage('player-' . $playerId, $noticeMessage);

                Log::info('【测试】发送站内消息', [
                    'player_id' => $playerId,
                    'game_type' => $gameType,
                    'message' => $noticeMessage,
                ]);
            }

            // 3. 发送全频道广播
            if (in_array($messageType, ['all', 'broadcast'])) {
                if ($gameType == 3) {
                    // ===== 电子游戏广播 =====
                    $broadcastMessage = [
                        'msg_type' => 'game_lottery_win_broadcast',
                        'lottery_id' => $lotteryId,
                        'lottery_name' => $lotteryName,
                        'lottery_type' => 2, // 随机
                        'player_id' => $playerId,
                        'player_name' => $playerName,
                        'player_uuid' => $playerUuid,
                        'amount' => $amount,
                        'lottery_pool_amount' => 50000,
                        'is_burst' => $isBurst,
                        'burst_multiplier' => $burstMultiplier,
                        'is_doubled' => $isDoubled,
                        'lottery_rate' => 100,
                        'title' => '🎊 【测试】恭喜玩家中奖！',
                        'content' => sprintf(
                            '恭喜玩家 %s 在电子游戏 %s 中赢得 %s%d 彩金！（测试消息）',
                            $playerName,
                            $lotteryName,
                            $isDoubled ? '【双倍】' : '',
                            $amount
                        ),
                        'test_mode' => true,
                    ];
                } else {
                    // ===== 实体机台广播 =====
                    $broadcastMessage = [
                        'msg_type' => 'machine_lottery_win_broadcast',
                        'lottery_id' => $lotteryId,
                        'lottery_name' => $lotteryName,
                        'lottery_type' => 2, // 随机
                        'player_id' => $playerId,
                        'player_name' => $playerName,
                        'player_uuid' => $playerUuid,
                        'amount' => $amount,
                        'machine_id' => 999,
                        'machine_code' => 'TEST001',
                        'machine_type' => $gameType,
                        'lottery_pool_amount' => 50000,
                        'is_burst' => $isBurst,
                        'burst_multiplier' => $burstMultiplier,
                        'is_doubled' => $isDoubled,
                        'lottery_rate' => 100,
                        'title' => '🎊 【测试】恭喜玩家中奖！',
                        'content' => sprintf(
                            '恭喜玩家 %s 在%s TEST001机台 中赢得 %s%d 彩金！（测试消息）',
                            $playerName,
                            $gameTypeText,
                            $isDoubled ? '【双倍】' : '',
                            $amount
                        ),
                        'test_mode' => true,
                    ];
                }

                sendSocketMessage('broadcast', $broadcastMessage);

                Log::info('【测试】发送全频道广播', [
                    'game_type' => $gameType,
                    'msg_type' => $broadcastMessage['msg_type'],
                    'message' => $broadcastMessage,
                ]);
            }

            // 根据游戏类型确定消息类型
            $playerMsgType = $gameType == 3 ? 'game_player_lottery_allow' : 'player_lottery_allow';
            $broadcastMsgType = $gameType == 3 ? 'game_lottery_win_broadcast' : 'machine_lottery_win_broadcast';

            $result = [
                'player_id' => $playerId,
                'player_name' => $playerName,
                'lottery_name' => $lotteryName,
                'amount' => $amount,
                'game_type' => $gameType,
                'game_type_text' => $gameTypeText,
                'lottery_multiple' => $lotteryMultiple,
                'is_burst' => $isBurst,
                'burst_multiplier' => $burstMultiplier,
                'is_doubled' => $isDoubled,
                'message_type' => $messageType,
                'message_types_used' => [
                    'player_lottery' => $playerMsgType,
                    'player_notice' => 'player_notice',
                    'broadcast' => $broadcastMsgType,
                ],
                'messages_sent' => match($messageType) {
                    'all' => ['player_lottery', 'player_notice', 'broadcast'],
                    'player' => ['player_lottery', 'player_notice'],
                    'broadcast' => ['broadcast'],
                    default => [],
                },
                'machine_info' => $gameType != 3 ? [
                    'machine_id' => 999,
                    'machine_code' => 'TEST001',
                    'machine_name' => '测试机台',
                ] : null,
            ];

            return jsonSuccessResponse(trans('lottery_message_sent', [], 'message'), $result);

        } catch (\Exception $e) {
            Log::error('testSendWinMessage error: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return jsonFailResponse(trans('send_failed', [], 'message') . ': ' . $e->getMessage(), [], 0);
        }
    }
}
