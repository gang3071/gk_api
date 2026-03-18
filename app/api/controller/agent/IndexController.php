<?php

namespace app\api\controller\agent;

use app\model\AdminDepartment;
use app\model\AdminUser;
use app\model\AgentTransferOrder;
use app\model\Channel;
use app\model\ExternalApp;
use app\model\Player;
use app\model\PlayerActivityRecord;
use app\model\PlayerDeliveryRecord;
use app\model\PlayerGameRecord;
use app\model\PlayerLotteryRecord;
use app\model\PlayerRegisterRecord;
use app\model\PlayGameRecord;
use Exception;
use Respect\Validation\Exceptions\AllOfException;
use Respect\Validation\Validator as v;
use support\Db;
use support\Log;
use support\Request;
use support\Response;
use Tinywan\Jwt\JwtToken;
use Webman\RateLimiter\Annotation\RateLimiter;

class IndexController
{
    public array $failCode = array(
        '1000' => '参数错误',
        '1101' => '玩家账号',
        '1102' => '密码',
        '1103' => '玩家id',
        '1204' => '金额',
        '1205' => '开始时间',
        '1206' => '结束时间',
        '2001' => '渠道不存在',
        '2100' => '用户错误',
        '2102' => '用户已注册',
        '2103' => '玩家不存在',
        '2104' => '玩家已被停用',
        '2205' => '钱包余额不足',
        '2206' => '结束时间必须大于起始时间',
        '2207' => '查询时间不能超过1小时',
        '3000' => '系统错误',
        '3201' => '钱包转出失败',
        '3202' => '钱包转入失败',
        '3902' => 'JWT清除失败',
    );
    /** 排除  */
    protected $noNeedSign = [];

    #[RateLimiter(limit: 20)]
    /**
     * 获取请求令牌
     * @param Request $request
     * @return Response
     */
    public function getAccessToken(Request $request): Response
    {
        // 验证授权应用
        /** @var ExternalApp $externalApp */
        $externalApp = ExternalApp::query()->where('app_id',
            $request->header('appId'))->whereNull('deleted_at')->where('status', 1)->first();
        if (empty($externalApp)) {
            return jsonFailResponse('应用不存在', [], 0);
        }
        // 验证服务器ip
        if (!empty($externalApp->white_ip) && !in_array(request()->getRealIp(), explode(',', $externalApp->white_ip))) {
            return jsonFailResponse('IP认证不通过', [], 0);
        }
        $token = JwtToken::generateToken([
            'id' => $externalApp->app_id,
            'app_id' => $externalApp->app_id,
            'department_id' => $externalApp->department_id,
        ]);
        // 返回授权token
        return jsonSuccessResponse('success', [
            'token_type' => $token['token_type'],
            'expires_in' => $token['expires_in'],
            'access_token' => $token['access_token'],
        ]);
    }

    #[RateLimiter(limit: 20)]
    /**
     * 创建玩家
     * @param Request $request
     * @return Response
     */
    public function createPlayer(Request $request): Response
    {
        $data = $request->all();
        $validator = v::key('account', v::stringType()->alnum()->notEmpty()->length(8, 20)->setName('玩家账号'))
            ->key('password', v::stringType()->notEmpty()->alnum()->length(6, 12)->setName('账号密码'));

        try {
            $validator->assert($data);
        } catch (AllOfException) {
            return jsonFailResponse('参数错误', [], 1000);
        }
        /** @var Channel $channel */
        $channel = Channel::query()->where('department_id', \request()->department_id)->first();
        if (empty($channel)) {
            return jsonFailResponse($this->failCode['2001'], [], 2001);
        }
        if (Player::query()->where('account', $data['account'])->where('department_id',
            $channel->department_id)->exists()) {
            return jsonFailResponse($this->failCode['2102'], [], 2102);
        }
        //创建玩家
        DB::beginTransaction();
        try {
            // 查找当前部门的代理或店家管理员
            $adminUser = AdminUser::query()
                ->where('department_id', $channel->department_id)
                ->whereIn('type', [AdminDepartment::TYPE_AGENT, AdminDepartment::TYPE_STORE])
                ->where('is_super', 1)
                ->first();

            $player = new Player();
            $player->account = $data['account'] ?? '';
            $player->uuid = generate15DigitUniqueId();
            $player->name = $data['name'] ?? '';
            $player->currency = $channel->currency;
            $player->password = $data['password'];
            $player->department_id = $channel->department_id;
            $player->avatar = config('def_avatar.1');
            $player->recommend_code = createCode();

            // 设置代理或店家绑定
            if ($adminUser) {
                if ($adminUser->type == AdminDepartment::TYPE_AGENT) {
                    $player->agent_admin_id = $adminUser->id;
                } elseif ($adminUser->type == AdminDepartment::TYPE_STORE) {
                    $player->store_admin_id = $adminUser->id;
                }
            }

            $player->save();
            addPlayerExtend($player);
            addRegisterRecord($player->id, PlayerRegisterRecord::TYPE_CLIENT, $player->department_id);
            DB::commit();
        } catch (\Exception) {
            DB::rollBack();
            return jsonFailResponse($this->failCode['1000'], [], 1000);
        }

        return jsonSuccessResponse('success', [
            'player_id' => $player->id,
            'uuid' => $player->uuid,
            'account' => $player->account,
        ]);
    }

    #[RateLimiter(limit: 20)]
    /**
     * 获取玩家信息
     * @param Request $request
     * @return Response
     */
    public function getPlayerInfo(Request $request): Response
    {
        $data = $request->all();
        $validator = v::key('id', v::intVal()->setName('玩家id'));

        try {
            $validator->assert($data);
        } catch (AllOfException) {
            return jsonFailResponse('参数错误', [], 1000);
        }

        try {
            $player = $this->checkPlayer($data['id']);
        } catch (Exception $e) {
            return jsonFailResponse($e->getMessage(), [], $e->getCode());
        }

        return jsonSuccessResponse('success', [
            'player_id' => $player->id,
            'uuid' => $player->uuid,
            'account' => $player->account,
            'status_national' => $player->status_national,
            'status_reverse_water' => $player->status_reverse_water,
            'status_machine' => $player->status_machine,
            'switch_shop' => $player->switch_shop,
            'phone' => $player->phone,
            'name' => $player->name,
            'status_open_point' => $player->status_open_point,
            'status_transfer' => $player->status_transfer,
            'recommend_code' => $player->recommend_code,
            'machine_play_num' => $player->machine_play_num,
            'agent_admin_id' => $player->agent_admin_id ?? 0,
            'store_admin_id' => $player->store_admin_id ?? 0,
        ]);
    }

    /**
     * @param int $id
     * @return Player
     * @throws Exception
     */
    private function checkPlayer(int $id): Player
    {
        /** @var Player $player */
        $player = Player::query()->with(['machine_wallet'])->where('department_id',
            \request()->department_id)->find($id);

        if (empty($player)) {
            throw new Exception($this->failCode['2103'], 2103);
        }
        if ($player->status == Player::STATUS_STOP) {
            throw new Exception($this->failCode['2104'], 2104);
        }

        return $player;
    }

    #[RateLimiter(limit: 20)]
    /**
     * 钱包转出
     * @param Request $request
     * @return Response
     */
    public function transferOut(Request $request): Response
    {
        $data = $request->all();
        $validator = v::key('player_id', v::intVal()->notEmpty()->setName('玩家id'))
            ->key('amount', v::floatVal()->notEmpty()->between(1, 1000000000)->setName('金额'))
            ->key('tradeno', v::stringType()->notEmpty()->length(10, 12)->notEmpty()->setName('订单号'));
        try {
            $validator->assert($data);
        } catch (AllOfException) {
            return jsonFailResponse($this->failCode['1000'], [], 1000);
        }

        try {
            $player = $this->checkPlayer($data['player_id']);
        } catch (Exception $e) {
            return jsonFailResponse($e->getMessage(), [], $e->getCode());
        }

        if ($data['amount'] > $player->machine_wallet->money) {
            return jsonFailResponse($this->failCode['2205'], [], 2205);
        }
        DB::beginTransaction();
        try {
            $beforeGameAmount = $player->machine_wallet->money;
            $player->machine_wallet->money = $player->machine_wallet->money - $data['amount'];
            $player->push();
            $agentTransferOrder = new AgentTransferOrder();
            $agentTransferOrder->player_id = $player->id;
            $agentTransferOrder->department_id = $player->department_id;
            $agentTransferOrder->tradeno = createOrderNo();
            $agentTransferOrder->agent_tradeno = $data['tradeno'];
            $agentTransferOrder->status = AgentTransferOrder::STATUS_SUCCESS;
            $agentTransferOrder->type = AgentTransferOrder::TYPE_OUT;
            $agentTransferOrder->player_account = $player->account;
            $agentTransferOrder->money = $data['amount'];
            $agentTransferOrder->fee = 0;
            $agentTransferOrder->currency = 'CNY';
            $agentTransferOrder->finish_time = date('Y-m-d H:i:s', time());
            $agentTransferOrder->save();

            //寫入金流明細
            $playerDeliveryRecord = new PlayerDeliveryRecord;
            $playerDeliveryRecord->player_id = $agentTransferOrder->player_id;
            $playerDeliveryRecord->department_id = $agentTransferOrder->department_id;
            $playerDeliveryRecord->target = $agentTransferOrder->getTable();
            $playerDeliveryRecord->target_id = $agentTransferOrder->id;
            $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_AGENT_OUT;
            $playerDeliveryRecord->source = 'agent_out';
            $playerDeliveryRecord->amount = $agentTransferOrder->money;
            $playerDeliveryRecord->amount_before = $beforeGameAmount;
            $playerDeliveryRecord->amount_after = $player->machine_wallet->money;
            $playerDeliveryRecord->tradeno = $agentTransferOrder->tradeno ?? '';
            $playerDeliveryRecord->save();
            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            return jsonFailResponse($e, [], 3000);
        }

        return jsonSuccessResponse('success', [
            'player_id' => $agentTransferOrder->player_id,
            'tradeno' => $agentTransferOrder->tradeno,
            'player_account' => $agentTransferOrder->player_account,
            'money' => $agentTransferOrder->money,
            'currency' => $agentTransferOrder->currency,
            'finish_time' => $agentTransferOrder->finish_time,
        ]);
    }

    #[RateLimiter(limit: 20)]
    /**
     * 钱包转入
     * @param Request $request
     * @return Response
     */
    public function transferIn(Request $request): Response
    {
        $data = $request->all();
        $validator = v::key('player_id', v::intVal()->notEmpty()->setName('玩家id'))
            ->key('tradeno', v::stringType()->notEmpty()->length(10, 12)->notEmpty()->setName('订单号'))
            ->key('amount', v::floatVal()->between(1, 1000000000)->notEmpty()->setName('金额'));

        try {
            $validator->assert($data);
        } catch (AllOfException) {
            return jsonFailResponse($this->failCode['1000'], [], 1000);
        }

        //判断玩家状态
        try {
            $player = $this->checkPlayer($data['player_id']);
        } catch (Exception $e) {
            return jsonFailResponse($e->getMessage(), [], $e->getCode());
        }

        //操作钱包
        DB::beginTransaction();
        try {
            $beforeGameAmount = $player->machine_wallet->money;
            $player->machine_wallet->money = $player->machine_wallet->money + $data['amount'];
            $player->push();
            //保存账单
            $agentTransferOrder = new AgentTransferOrder();
            $agentTransferOrder->player_id = $player->id;
            $agentTransferOrder->department_id = $player->department_id;
            $agentTransferOrder->agent_tradeno = $data['tradeno'];
            $agentTransferOrder->tradeno = createOrderNo();
            $agentTransferOrder->status = AgentTransferOrder::STATUS_SUCCESS;
            $agentTransferOrder->type = AgentTransferOrder::TYPE_IN;
            $agentTransferOrder->player_account = $player->account;
            $agentTransferOrder->money = $data['amount'];
            $agentTransferOrder->fee = 0;   //当前设定默认为0
            $agentTransferOrder->currency = 'CNY';
            $agentTransferOrder->finish_time = date('Y-m-d H:i:s', time());
            $agentTransferOrder->save();

            //寫入金流明細
            $playerDeliveryRecord = new PlayerDeliveryRecord;
            $playerDeliveryRecord->player_id = $agentTransferOrder->player_id;
            $playerDeliveryRecord->department_id = $agentTransferOrder->department_id;
            $playerDeliveryRecord->target = $agentTransferOrder->getTable();
            $playerDeliveryRecord->target_id = $agentTransferOrder->id;
            $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_AGENT_IN;
            $playerDeliveryRecord->source = 'agent_int';
            $playerDeliveryRecord->amount = $agentTransferOrder->money;
            $playerDeliveryRecord->amount_before = $beforeGameAmount;
            $playerDeliveryRecord->amount_after = $player->machine_wallet->money;
            $playerDeliveryRecord->tradeno = $agentTransferOrder->tradeno ?? '';
            $playerDeliveryRecord->save();
            DB::commit();
        } catch (Exception) {
            DB::rollBack();
            return jsonFailResponse($this->failCode['3202'], [], 3202);
        }

        return jsonSuccessResponse('success', [
            'player_id' => $agentTransferOrder->player_id,
            'tradeno' => $agentTransferOrder->tradeno,
            'player_account' => $agentTransferOrder->player_account,
            'money' => $agentTransferOrder->money,
            'currency' => $agentTransferOrder->currency,
            'finish_time' => $agentTransferOrder->finish_time,
        ]);
    }

    #[RateLimiter(limit: 20)]
    /**
     * 进入游戏
     * @param Request $request
     * @return Response
     */
    public function enterGame(Request $request): Response
    {
        $url = [];
        $data = $request->all();
        $validator = v::key('id',
            v::stringType()->notEmpty()->length(1, 10)->setName('玩家id'));

        try {
            $validator->assert($data);
        } catch (AllOfException) {
            return jsonFailResponse($this->failCode['1000'], [], 1000);
        }

        /** @var Channel $channel */
        $channel = Channel::query()->where('department_id', \request()->department_id)->first();
        if (empty($channel)) {
            return jsonFailResponse($this->failCode['2001'], [], 2001);
        }

        try {
            $player = $this->checkPlayer($data['id']);
        } catch (Exception $e) {
            return jsonFailResponse($e->getMessage(), [], $e->getCode());
        }
        //记录登录信息
        addLoginRecord($player->id);
        $token = JwtToken::generateToken([
            'id' => $player->id,
            'avatar' => $player->avatar,
            'phone' => $player->phone,
            'type' => $player->type,
            'currency' => $player->currency,
            'recommended_code' => $player->recommended_code,
        ]);
        //首选域名
        $url[] = $channel->domain . '?token=' . $token['access_token'];
        //备用域名
        if (isset($channel->domain_ext[0]['domain'])) {
            $url[] = $channel->domain_ext[0]['domain'] . '?token=' . $token['access_token'];
        }
        Log::info('外接api进入游戏', [$url]);
        return jsonSuccessResponse('success', [
            'url' => $url,
        ]);
    }

    #[RateLimiter(limit: 20)]
    /**
     * 玩家登出
     * @param Request $request
     * @return Response
     */
    public function logout(Request $request): Response
    {
        if (JwtToken::clear()) {
            return jsonSuccessResponse('success');
        } else {
            return jsonFailResponse($this->failCode['3902'], [], 3902);
        }
    }

    #[RateLimiter(limit: 20)]
    /**
     * 已结算机台游戏记录
     * @param Request $request
     * @return Response
     */
    public function machineFinishRecord(Request $request): Response
    {
        $data = $request->all();
        $validator = v::key('start_time', v::dateTime('Y-m-d H:i:s')->notEmpty()->setName('起始时间'))
            ->key('end_time', v::dateTime('Y-m-d H:i:s')->notEmpty()->setName('结束时间'))
            ->key('player_id', v::intVal()->notEmpty()->setName('玩家id'), false);

        try {
            $validator->assert($data);
        } catch (AllOfException) {
            return jsonFailResponse($this->failCode['1000'], [], 1000);
        }

        //查询时间规则
        try {
            [$startTime, $endTime] = $this->timeRule($data['start_time'], $data['end_time']);
        } catch (Exception $e) {
            return jsonFailResponse($e->getMessage(), [], $e->getCode());
        }

        $machineRecordList = PlayerGameRecord::query()
            ->select([
                "player_game_record.id",
                "player.account",
                "player_game_record.game_id",
                "player_game_record.machine_id",
                "player_game_record.player_id",
                "player_game_record.type",
                "player_game_record.code",
                "player_game_record.status",
                "player_game_record.open_point",
                "player_game_record.wash_point",
                "player_game_record.open_amount",
                "player_game_record.wash_amount",
                "player_game_record.after_game_amount",
                "player_game_record.give_amount",
                "player_game_record.odds",
                "player_game_record.balance",
                "player_game_record.chip_amount",
                "player_game_record.created_at",
                "player_game_record.updated_at",
                "player_game_record.has_do",
                "player_game_record.national_damage_ratio",
            ])
            ->leftJoin('player', 'player_game_record.player_id', '=', 'player.id')
            ->where('player_game_record.status', PlayerGameRecord::STATUS_END)
            ->when(!empty($data['player_id']), function ($query) use ($data) {
                $query->where('player_game_record.player_id', $data['player_id']);
            })
            ->when($startTime, function ($query) use ($startTime) {
                $query->where('player_game_record.updated_at', '>=', $startTime);
            })
            ->when($endTime, function ($query) use ($endTime) {
                $query->where('player_game_record.updated_at', '<=', $endTime);
            })
            ->get();

        return jsonSuccessResponse('success', $machineRecordList->toArray());
    }

    #[RateLimiter(limit: 20)]
    /**
     * 上下分记录
     * @param Request $request
     * @return Response
     */
    public function machineRecord(Request $request): Response
    {
        $data = $request->all();
        $validator = v::key('start_time', v::dateTime('Y-m-d H:i:s')->notEmpty()->setName('起始时间'))
            ->key('end_time', v::dateTime('Y-m-d H:i:s')->notEmpty()->setName('结束时间'))
            ->key('player_id', v::intVal()->notEmpty()->setName('玩家id'), false);

        try {
            $validator->assert($data);
        } catch (AllOfException) {
            return jsonFailResponse($this->failCode['1000'], [], 1000);
        }

        //查询时间规则
        try {
            [$startTime, $endTime] = $this->timeRule($data['start_time'], $data['end_time']);
        } catch (Exception $e) {
            return jsonFailResponse($e->getMessage(), [], $e->getCode());
        }

        $machineRecordList = PlayerGameRecord::query()
            ->select([
                "id",
                "game_id",
                "machine_id",
                "player_id",
                "type",
                "code",
                "status",
                "open_point",
                "wash_point",
                "open_amount",
                "wash_amount",
                "after_game_amount",
                "give_amount",
                "odds",
                "balance",
                "chip_amount",
                "created_at",
                "updated_at",
                "has_do",
                "national_damage_ratio"
            ])
            ->when(!empty($data['player_id']), function ($query) use ($data) {
                $query->where('player_id', $data['player_id']);
            })
            ->when($startTime, function ($query) use ($startTime) {
                $query->where('created_at', '>=', $startTime);
            })
            ->when($endTime, function ($query) use ($endTime) {
                $query->where('created_at', '<=', $endTime);
            })
            ->get();

        return jsonSuccessResponse('success', $machineRecordList->toArray());
    }

    /**
     * 查询时间规则
     * @param string $startTime 起始时间
     * @param string $endTime 结束时间
     * @throws Exception
     */
    public function timeRule(string $startTime, string $endTime): array
    {
        if ($startTime > $endTime) {
            throw new Exception($this->failCode['2206'], 2206);
        }
        if (strtotime($endTime) - strtotime($startTime) > 3600) {
            throw new Exception($this->failCode['2207'], 2207);
        }

        return [$startTime, $endTime];
    }

    #[RateLimiter(limit: 20)]
    /**
     * 活动记录
     * @param Request $request
     * @return Response
     */
    public function activityRecord(Request $request): Response
    {
        $data = $request->all();
        $validator = v::key('start_time', v::notEmpty()->dateTime('Y-m-d H:i:s')->setName('起始时间'))
            ->key('end_time', v::notEmpty()->dateTime('Y-m-d H:i:s')->setName('结束时间'))
            ->key('player_id', v::intVal()->notEmpty()->setName('玩家id'), false);

        try {
            $validator->assert($data);
        } catch (AllOfException) {
            return jsonFailResponse($this->failCode['1000'], [], 1000);
        }

        try {
            [$startTime, $endTime] = $this->timeRule($data['start_time'], $data['end_time']);
        } catch (Exception $e) {
            return jsonFailResponse($e->getMessage(), [], $e->getCode());
        }

        $playerActivityRecord = PlayerActivityRecord::query()
            ->select([
                "id",
                "activity_id",
                "cate_id",
                "machine_id",
                "player_id",
                "department_id",
                "type",
                "code",
                "score",
                "bonus",
                "status",
                "created_at",
                "updated_at",
                "finish_at"
            ])
            ->where('department_id', request()->department_id)
            ->when(!empty($data['player_id']), function ($query) use ($data) {
                $query->where('player_id', $data['player_id']);
            })
            ->when($startTime, function ($query) use ($startTime) {
                $query->where('created_at', '>=', $startTime);
            })
            ->when($endTime, function ($query) use ($endTime) {
                $query->where('created_at', '<=', $endTime);
            })
            ->get();

        return jsonSuccessResponse('success', $playerActivityRecord->toArray());
    }

    #[RateLimiter(limit: 20)]
    /**
     * 电子游戏记录
     * @param Request $request
     * @return Response
     */
    public function gameRecord(Request $request): Response
    {
        $data = $request->all();
        $validator = v::key('start_time', v::notEmpty()->dateTime('Y-m-d H:i:s')->setName('起始时间'))
            ->key('end_time', v::notEmpty()->dateTime('Y-m-d H:i:s')->setName('结束时间'))
            ->key('player_id', v::intVal()->notEmpty()->setName('玩家id'), false);

        try {
            $validator->assert($data);
        } catch (AllOfException) {
            return jsonFailResponse($this->failCode['1000'], [], 1000);
        }

        //查询时间规则
        try {
            [$startTime, $endTime] = $this->timeRule($data['start_time'], $data['end_time']);
        } catch (Exception $e) {
            return jsonFailResponse($e->getMessage(), [], $e->getCode());
        }

        $playGameRecord = PlayGameRecord::query()
            ->select([
                "player.account",
                "play_game_record.id",
                "play_game_record.player_id",
                "play_game_record.parent_player_id",
                "play_game_record.player_uuid",
                "play_game_record.platform_id",
                "play_game_record.game_code",
                "play_game_record.department_id",
                "play_game_record.status",
                "play_game_record.bet",
                "play_game_record.win",
                "play_game_record.diff",
                "play_game_record.reward",
                "play_game_record.order_no",
                "play_game_record.platform_action_at",
                "play_game_record.created_at",
                "play_game_record.updated_at",
                "play_game_record.action_at",
                "play_game_record.national_promoter_action",
                "play_game_record.is_reverse",
                "play_game_record.national_damage_ratio"
            ])
            ->leftJoin('player', 'play_game_record.player_id', '=', 'player.id')
            ->where('play_game_record.department_id', request()->department_id)
            ->when(!empty($data['player_id']), function ($query) use ($data) {
                $query->where('play_game_record.player_id', $data['player_id']);
            })
            ->when($startTime, function ($query) use ($startTime) {
                $query->where('play_game_record.created_at', '>=', $startTime);
            })
            ->when($endTime, function ($query) use ($endTime) {
                $query->where('play_game_record.created_at', '<=', $endTime);
            })
            ->get();

        return jsonSuccessResponse('success', $playGameRecord->toArray());
    }

    #[RateLimiter(limit: 20)]
    /**
     * 彩金记录
     * @param Request $request
     * @return Response
     */
    public function lotteryRecord(Request $request): Response
    {
        $data = $request->all();
        $validator = v::key('start_time', v::notEmpty()->dateTime('Y-m-d H:i:s')->setName('起始时间'))
            ->key('end_time', v::notEmpty()->dateTime('Y-m-d H:i:s')->setName('结束时间'))
            ->key('player_id', v::intVal()->notEmpty()->setName('玩家id'), false);

        try {
            $validator->assert($data);
        } catch (AllOfException) {
            return jsonFailResponse($this->failCode['1000'], [], 1000);
        }

        //查询时间规则
        try {
            [$startTime, $endTime] = $this->timeRule($data['start_time'], $data['end_time']);
        } catch (Exception $e) {
            return jsonFailResponse($e->getMessage(), [], $e->getCode());
        }

        $machineRecordList = PlayerLotteryRecord::query()
            ->select([
                "id",
                "player_id",
                "uuid",
                "player_name",
                "department_id",
                "machine_id",
                "machine_name",
                "machine_code",
                "game_type",
                "odds",
                "amount",
                "is_max",
                "lottery_id",
                "lottery_name",
                "lottery_pool_amount",
                "lottery_rate",
                "lottery_type",
                "lottery_multiple",
                "lottery_sort",
                "cate_rate",
                "user_id",
                "user_name",
                "reject_reason",
                "status",
                "audit_at",
                "created_at",
                "updated_at"
            ])
            ->where('department_id', request()->department_id)
            ->when(!empty($data['player_id']), function ($query) use ($data) {
                $query->where('player_id', $data['player_id']);
            })
            ->when($startTime, function ($query) use ($startTime) {
                $query->where('created_at', '>=', $startTime);
            })
            ->when($endTime, function ($query) use ($endTime) {
                $query->where('created_at', '<=', $endTime);
            })
            ->get();

        return jsonSuccessResponse('success', $machineRecordList->toArray());
    }

    #[RateLimiter(limit: 20)]
    /**
     * 账变记录
     * @param Request $request
     * @return Response
     */
    public function deliveryRecord(Request $request): Response
    {
        $data = $request->all();
        $validator = v::key('start_time', v::notEmpty()->dateTime('Y-m-d H:i:s')->setName('起始时间'))
            ->key('end_time', v::notEmpty()->dateTime('Y-m-d H:i:s')->setName('结束时间'))
            ->key('player_id', v::intVal()->notEmpty()->setName('玩家id'), false);

        try {
            $validator->assert($data);
        } catch (AllOfException) {
            return jsonFailResponse($this->failCode['1000'], [], 1000);
        }

        //查询时间规则
        try {
            [$startTime, $endTime] = $this->timeRule($data['start_time'], $data['end_time']);
        } catch (Exception $e) {
            return jsonFailResponse($e->getMessage(), [], $e->getCode());
        }

        $machineRecordList = PlayerDeliveryRecord::query()
            ->where('department_id', request()->department_id)
            ->when(!empty($data['player_id']), function ($query) use ($data) {
                $query->where('player_id', $data['player_id']);
            })
            ->when($startTime, function ($query) use ($startTime) {
                $query->where('created_at', '>=', $startTime);
            })
            ->when($endTime, function ($query) use ($endTime) {
                $query->where('created_at', '<=', $endTime);
            })
            ->get();

        return jsonSuccessResponse('success', $machineRecordList->toArray());
    }

    #[RateLimiter(limit: 20)]
    /**
     * 玩家钱包余额
     * @param Request $request
     * @return Response
     */
    public function getBalance(Request $request): Response
    {
        $data = $request->all();
        $validator = v::key('id', v::intVal()->setName('玩家id'));

        try {
            $validator->assert($data);
        } catch (AllOfException) {
            return jsonFailResponse('参数错误', [], 1000);
        }

        try {
            $player = $this->checkPlayer($data['id']);
        } catch (Exception $e) {
            return jsonFailResponse($e->getMessage(), [], $e->getCode());
        }

        return jsonSuccessResponse('success', [
            'balance' => $player->machine_wallet->money,
        ]);
    }

    #[RateLimiter(limit: 20)]
    /**
     * 获取玩家信息
     * @param Request $request
     * @return Response
     */
    public function getPlayerInfoByAccount(Request $request): Response
    {
        $data = $request->all();
        $validator = v::key('account', v::stringVal()->setName('玩家账号'));

        try {
            $validator->assert($data);
        } catch (AllOfException) {
            return jsonFailResponse('参数错误', [], 1000);
        }

        try {
            /** @var Player $player */
            $player = Player::query()->with(['machine_wallet'])->where('department_id',
                $request->department_id)->where('account', $data['account'])->first();
            if (empty($player)) {
                throw new Exception($this->failCode['2103'], 2103);
            }
            if ($player->status == Player::STATUS_STOP) {
                throw new Exception($this->failCode['2104'], 2104);
            }
        } catch (Exception $e) {
            return jsonFailResponse($e->getMessage(), [], $e->getCode());
        }

        return jsonSuccessResponse('success', [
            'player_id' => $player->id,
            'uuid' => $player->uuid,
            'account' => $player->account,
            'status_national' => $player->status_national,
            'status_reverse_water' => $player->status_reverse_water,
            'status_machine' => $player->status_machine,
            'switch_shop' => $player->switch_shop,
            'phone' => $player->phone,
            'name' => $player->name,
            'status_open_point' => $player->status_open_point,
            'status_transfer' => $player->status_transfer,
            'recommend_code' => $player->recommend_code,
            'machine_play_num' => $player->machine_play_num,
            'agent_admin_id' => $player->agent_admin_id ?? 0,
            'store_admin_id' => $player->store_admin_id ?? 0,
        ]);
    }
}