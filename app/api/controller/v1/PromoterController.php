<?php

namespace app\api\controller\v1;

use app\model\Player;
use app\model\PlayerDeliveryRecord;
use app\model\PlayerPlatformCash;
use app\model\PlayerPromoter;
use app\model\PlayerRechargeRecord;
use app\model\PromoterProfitRecord;
use app\model\PromoterProfitSettlementRecord;
use app\model\SystemSetting;
use app\exception\PlayerCheckException;
use app\exception\PromoterCheckException;
use Carbon\Carbon;
use Respect\Validation\Exceptions\AllOfException;
use Respect\Validation\Validator as v;
use support\Cache;
use support\Db;
use support\Request;
use support\Response;
use think\Exception;
use Webman\RateLimiter\Annotation\RateLimiter;

class PromoterController
{
    /** 排除验签 */
    protected $noNeedSign = [];
    
    /** @var Player $player */
    protected $player;
    
    #[RateLimiter(limit: 5)]
    /**
     * 推广数据
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|PromoterCheckException
     */
    public function promotionData(Request $request): Response
    {
        $this->checkPromoter();
        $validator = v::key('page', v::intVal()->setName(trans('page', [], 'message')))
            ->key('size', v::intVal()->setName(trans('size', [], 'message')));
        $data = $request->all();
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        $content = SystemSetting::where('department_id', request()->department_id)->where('feature',
            'settlement_date')->where('status', 1)->value('content');
        if (empty($content)) {
            $content = SystemSetting::where('department_id', 0)->where('feature', 'settlement_date')->where('status',
                1)->value('content');
        }
        
        $channel = Cache::get("channel_" . $request->site_id);
        $settlementRecord = PromoterProfitSettlementRecord::where('promoter_player_id', $this->player->id)
            ->orderBy('created_at', 'desc')
            ->forPage($data['page'], $data['size'])
            ->select(['id', 'total_profit_amount', 'promoter_player_id', 'created_at', 'adjust_amount'])
            ->get();
        /** @var PromoterProfitSettlementRecord $promoterProfitSettlementRecord */
        foreach ($settlementRecord as $promoterProfitSettlementRecord) {
            $promoterProfitSettlementRecord->total_profit_amount = bcadd($promoterProfitSettlementRecord->total_profit_amount,
                $promoterProfitSettlementRecord->adjust_amount, 2);
        }
        $profitAmount = bcadd($this->player->player_promoter->profit_amount,
            $this->player->player_promoter->adjust_amount, 2);
        $systemCommissionRatio = SystemSetting::query()->where('department_id', request()->department_id)
            ->where('feature', 'commission')
            ->where('status', 1)
            ->value('num');
        return jsonSuccessResponse('success', [
            'profit_amount' => $profitAmount,
            'commission_ratio' => $systemCommissionRatio ?? 0,
            'total_profit_amount' => bcadd($profitAmount, $this->player->player_promoter->settlement_amount, 2),
            'settlement_amount' => $this->player->player_promoter->settlement_amount,
            'recommend_code' => $channel['domain'] . '/?promoter_code=' . $this->player->recommend_code,
            'settlement_date' => $content ?? '',
            'settlement_record' => $settlementRecord
        ]);
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 验证管理员
     * @throws PlayerCheckException|PromoterCheckException|Exception
     */
    public function checkPromoter()
    {
        $this->player = checkPlayer();
        if ($this->player->is_promoter == 0) {
            throw new PromoterCheckException(trans('player_not_promoter', [], 'message'), 100);
        }
        if ($this->player->player_promoter->status == 0) {
            throw new PromoterCheckException(trans('promoter_has_disabled', [], 'message'), 100);
        }
        $channel = getChannel(\request()->site_id);
        if (empty($channel)) {
            throw new PromoterCheckException(trans('channel_not_found', [], 'message'), 100);
        }
        if ($channel['promotion_status'] == 0) {
            throw new PromoterCheckException(trans('promotion_function_disabled', [], 'message'), 100);
        }
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 推广数据
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|PromoterCheckException|Exception
     */
    public function promotionDataPortrait(Request $request): Response
    {
        $this->checkPromoter();
        $validator = v::key('page', v::intVal()->setName(trans('page', [], 'message')))
            ->key('size', v::intVal()->setName(trans('size', [], 'message')));
        $data = $request->all();
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        $content = SystemSetting::where('department_id', request()->department_id)->where('feature',
            'settlement_date')->where('status', 1)->value('content');
        if (empty($content)) {
            $content = SystemSetting::where('department_id', 0)->where('feature', 'settlement_date')->where('status',
                1)->value('content');
        }
        
        $channel = Cache::get("channel_" . $request->site_id);
        $settlementRecord = PromoterProfitSettlementRecord::where('promoter_player_id', $this->player->id)
            ->orderBy('created_at', 'desc')
            ->forPage($data['page'], $data['size'])
            ->select([
                'id',
                'total_profit_amount',
                'promoter_player_id',
                'created_at',
                'adjust_amount',
                'total_player_profit_amount'
            ])
            ->get();
        /** @var PromoterProfitSettlementRecord $promoterProfitSettlementRecord */
        foreach ($settlementRecord as $promoterProfitSettlementRecord) {
            $promoterProfitSettlementRecord->total_profit_amount = $promoterProfitSettlementRecord->total_player_profit_amount;
        }
        
        $playerList = Player::query()
            ->select('player.*')
            ->where('recommend_id', $this->player->id)
            ->forPage($data['page'], $data['size'])
            ->get();
        $player = checkPlayer();
        
        $settlementTime = checkPlayer()->player_promoter->last_settlement_timestamp;
        
        $inType = implode(',', [
            PlayerDeliveryRecord::TYPE_PRESENT_IN,
        ]);
        $outType = implode(',', [
            PlayerDeliveryRecord::TYPE_PRESENT_OUT,
        ]);
        
        //判断用户是否最上级
        $top = $player->player_promoter->recommend_id == 0;
        $requestData = [];
        /** @var Player $player */
        foreach ($playerList as $player) {
            //判断是不是中级用户 中级不需要计算自己的数据 只展示下级  下级只展示自己的数据
            // 结算时间
            if ($top) {
                $childrenTotal = $this->childrenTotal([$player->id], $inType, $outType, $settlementTime);
                //顶级使用中级自身  下级使用中级
                $ratio = $player->player_promoter->ratio ?? 0;
                $presentInAmount = bcadd(0, $childrenTotal['total_in'] ?? 0, 2);
                $machinePutPoint = bcadd(0, $childrenTotal['total_point'] ?? 0, 2);
                $presentOutAmount = bcadd(0, $childrenTotal['total_out'] ?? 0, 2);
                $money = $childrenTotal['money'] ?? 0;
            } else {
                //中级使用下级总和  上级使用中级分润再乘比例
                //代理分成=店家的盈余 * (店家上缴比例-代理上缴比例)
                //官方分成=店家的盈余 * 代理上缴比例
                //代理上缴比例必须小于他下面所有店家的上缴比例
                $totalModel = PlayerDeliveryRecord::query()->where('player_id',
                    $player->id)->when(!empty($settlementTime), function ($query) use ($settlementTime) {
                    $query->where('created_at', '>=', $settlementTime);
                });
                
                $totalData = $totalModel->selectRaw('
                    sum(IF(type in (' . $inType . '), amount, 0)) as total_in,
                    sum(IF(type in (' . $outType . '), amount, 0)) as total_out,
                    sum(IF(type = ' . PlayerDeliveryRecord::TYPE_RECHARGE . ', amount, 0)) as total_recharge,
                    sum(IF(type = ' . PlayerDeliveryRecord::TYPE_MACHINE . ', amount, 0)) as total_point
                ')->first();
                
                $ratio = $this->player->player_promoter->ratio ?? 0;
                $ratio = $ratio - $this->player->recommend_promoter->ratio ?? 0;
                $presentInAmount = bcadd(0, $totalData['total_in'] ?? 0, 2);
                $machinePutPoint = bcadd(0, $totalData['total_point'] ?? 0, 2);
                $presentOutAmount = bcadd(0, $totalData['total_out'] ?? 0, 2);
                $money = $player->machine_wallet->money;
            }
            $totalPoint = round($machinePutPoint + $presentInAmount - $presentOutAmount, 2);
            $profitAmount = bcmul($totalPoint, $ratio / 100, 2);
            $requestData[] = [
                'id' => $player->id,
                'uuid' => $player->uuid,
                'name' => $player->name,
                'present_in_amount' => $presentInAmount,
                'present_out_amount' => $presentOutAmount,
                'machine_put_point' => $machinePutPoint,
                'money' => $money,
                'promoter_id' => $player->player_promoter->id ?? 0,
                'profit_amount' => $profitAmount,
                'is_promoter' => $player->is_promoter,
                'promoter_status' => $player->player_promoter->status ?? null,
                'ratio' => $ratio ?? 0,
                'total_point' => $totalPoint//总盈余
            ];
        }
        
        $totalPoint = round(array_sum(array_column($requestData, 'total_point')), 2);
        $portraitAmount = bcmul($totalPoint, $this->player->player_promoter->ratio / 100, 2);
        //顶级展示中级营收 和营收之后的比例拆账
        if (!$top) {
            $portraitAmount = round(array_sum(array_column($requestData, 'profit_amount')), 2);
        }
        return jsonSuccessResponse('success', [
            'profit_amount' => $portraitAmount,  //待结算金额
            'total_profit_amount' => $this->player->player_promoter->total_profit_amount, //结算金额
            'settlement_amount' => round(array_sum(array_column($settlementRecord->toArray(), 'total_profit_amount')),
                2), //总结算金额
            'recommend_code' => $channel['domain'] . '/?promoter_code=' . $this->player->recommend_code,
            'settlement_date' => $content ?? '',
            'settlement_record' => $settlementRecord
        ]);
    }
    
    /**
     * 获取下级的数据汇总
     * @param $playerId
     * @param $inType
     * @param $outType
     * @param $time
     * @return array
     */
    private function childrenTotal($playerId, $inType, $outType, $time): array
    {
        $children = Player::query()->whereIn('recommend_id',
            $playerId)->whereNull('deleted_at')->pluck('id')->toArray();
        if (empty($children)) {
            return [];
        }
        $totalModel = PlayerDeliveryRecord::query()->whereIn('player_id', $children)->when(!empty($time),
            function ($query) use ($time) {
                $query->where('created_at', '>=', $time);
            });
        $totalData = $totalModel->selectRaw('
            sum(IF(type in (' . $inType . '), amount, 0)) as total_in,
            sum(IF(type in (' . $outType . '), amount, 0)) as total_out,
            sum(IF(type = ' . PlayerDeliveryRecord::TYPE_WITHDRAWAL . ', amount, 0)) as total_withdrawal,
            sum(IF(type = ' . PlayerDeliveryRecord::TYPE_WITHDRAWAL_BACK . ', amount, 0)) as total_withdrawal_back,
            sum(IF(type = ' . PlayerDeliveryRecord::TYPE_RECHARGE . ', amount, 0)) as total_recharge,
            sum(IF(type = ' . PlayerDeliveryRecord::TYPE_MACHINE . ', amount, 0)) as total_point
            ')->first()->toArray();
        
        $totalData['money'] = PlayerPlatformCash::query()->whereIn('player_id', $children)->sum('money');
        
        $children = $this->childrenTotal($children, $inType, $outType, $time);
        
        foreach ($children as $key => $value) {
            $totalData[$key] = ($value ?? 0) + ($totalData[$key] ?? 0);
        }
        
        return $totalData;
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 推广员玩家
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|PromoterCheckException|Exception
     */
    public function promotionPlayer(Request $request): Response
    {
        $this->checkPromoter();
        $validator = v::key('page', v::intVal()->setName(trans('page', [], 'message')))
            ->key('size', v::intVal()->setName(trans('size', [], 'message')));
        $data = $request->all();
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        $playerList = Player::leftJoin('player_extend', 'player_extend.player_id', '=', 'player.id')
            ->select('player.*')
            ->where('recommend_id', $this->player->id)
            ->orderBy('player_extend.recharge_amount', 'desc')
            ->orderBy('player.id', 'desc')
            ->forPage($data['page'], $data['size'])
            ->get();
        
        $requestData = [];
        /** @var Player $player */
        foreach ($playerList as $player) {
            $profitAmount = PromoterProfitRecord::where('player_id', $player->id)
                ->where('status', PromoterProfitRecord::STATUS_UNCOMPLETED)
                ->where('promoter_player_id', $this->player->id)
                ->sum('profit_amount');
            if ($this->player->player_promoter->ratio == 0) {
                $promoterProfitRecord = PromoterProfitRecord::where('player_id', $player->id)
                    ->where('status', PromoterProfitRecord::STATUS_UNCOMPLETED)
                    ->where('promoter_player_id', $this->player->id)
                    ->first([
                        DB::raw("ifNull(sum(machine_up_amount - machine_down_amount - lottery_amount - present_amount - admin_add_amount + game_amount), 0) as score"),
                    ]);
                $totalScore = $promoterProfitRecord->score;
            } else {
                $totalScore = bcdiv(-$profitAmount, $this->player->player_promoter->ratio / 100, 2);
            }
            $requestData[] = [
                'id' => $player->id,
                'uuid' => $player->uuid,
                'name' => $player->name,
                'recharge_amount' => $player->player_extend->recharge_amount,
                'withdraw_amount' => $player->player_extend->withdraw_amount,
                'money' => $player->machine_wallet->money,
                'promoter_id' => $player->player_promoter->id ?? 0,
                'profit_amount' => $profitAmount,
                'is_promoter' => $player->is_promoter,
                'promoter_status' => $player->player_promoter->status ?? null,
                'ratio' => $player->player_promoter->ratio ?? 0,
                'remark' => $player->remark,
                'total_score' => $totalScore,//总输赢
            ];
        }
        $totalProfitAmount = PromoterProfitRecord::where('promoter_player_id', $this->player->id)
            ->where('source_player_id', $this->player->id)
            ->where('status', PromoterProfitRecord::STATUS_UNCOMPLETED)
            ->first([
                DB::raw(
                    "ifNull(sum(machine_up_amount - machine_down_amount - bonus_amount - lottery_amount - present_amount - admin_add_amount - water_amount + game_amount), 0) as score,
                    ifNull(sum(profit_amount), 0) as profit_amount,
                    ifNull(sum(commission), 0) as commission,
                    ifNull(sum(profit_amount - commission), 0) as real_profit_amount"
                ),
            ]);
        
        return jsonSuccessResponse('success', [
            'player_num' => $this->player->player_promoter->player_num,//拥有玩家数
            'total_player_score' => -$totalProfitAmount->score,//当期玩家输赢
            'profit_amount' => $totalProfitAmount->profit_amount,//当期分润
            'commission' => $totalProfitAmount->commission,//手续费
            'real_profit_amount' => $totalProfitAmount->real_profit_amount,//实际分润
            'max_ratio' => $this->player->player_promoter->ratio,//分润比例
            'player_list' => $requestData,
        ]);
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 推广员玩家（竖版）
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|PromoterCheckException|Exception
     */
    public function promotionPlayerPortrait(Request $request): Response
    {
        $player = checkPlayer();
        $this->checkPromoter();
        $validator = v::key('page', v::intVal()->setName(trans('page', [], 'message')))
            ->key('size', v::intVal()->setName(trans('size', [], 'message')));
        $data = $request->all();
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        $playerList = Player::leftJoin('player_extend', 'player_extend.player_id', '=', 'player.id')
            ->select('player.*')
            ->where('recommend_id', $this->player->id)
            ->orderBy('player_extend.recharge_amount', 'desc')
            ->forPage($data['page'], $data['size'])
            ->get();
        
        $settlementTime = checkPlayer()->player_promoter->last_settlement_timestamp;
        
        $inType = implode(',', [
            PlayerDeliveryRecord::TYPE_PRESENT_IN,
        ]);
        $outType = implode(',', [
            PlayerDeliveryRecord::TYPE_PRESENT_OUT,
        ]);
        
        //判断用户是否最上级
        $top = $player->player_promoter->recommend_id == 0;
        $requestData = [];
        /** @var Player $player */
        foreach ($playerList as $player) {
            //判断是不是中级用户 中级不需要计算自己的数据 只展示下级  下级只展示自己的数据
            // 结算时间
            if ($top) {
                $childrenTotal = $this->childrenTotal([$player->id], $inType, $outType, $settlementTime);
                //顶级使用中级自身  下级使用中级
                $ratio = $player->player_promoter->ratio ?? 0;
                $presentInAmount = bcadd(0, $childrenTotal['total_in'] ?? 0, 2);
                $machinePutPoint = bcadd(0, $childrenTotal['total_point'] ?? 0, 2);
                $presentOutAmount = bcadd(0, $childrenTotal['total_out'] ?? 0, 2);
                $money = $childrenTotal['money'] ?? 0;
            } else {
                //中级使用下级总和  上级使用中级分润再乘比例
                //代理分成=店家的盈余 * (店家上缴比例-代理上缴比例)
                //官方分成=店家的盈余 * 代理上缴比例
                //代理上缴比例必须小于他下面所有店家的上缴比例
                $totalModel = PlayerDeliveryRecord::query()->where('player_id',
                    $player->id)->when(!empty($settlementTime), function ($query) use ($settlementTime) {
                    $query->where('created_at', '>=', $settlementTime);
                });
                
                $totalData = $totalModel->selectRaw('
                    sum(IF(type in (' . $inType . '), amount, 0)) as total_in,
                    sum(IF(type in (' . $outType . '), amount, 0)) as total_out,
                    sum(IF(type = ' . PlayerDeliveryRecord::TYPE_RECHARGE . ', amount, 0)) as total_recharge,
                    sum(IF(type = ' . PlayerDeliveryRecord::TYPE_MACHINE . ', amount, 0)) as total_point
                ')->first();
                
                $ratio = $this->player->player_promoter->ratio ?? 0;
                $presentInAmount = bcadd(0, $totalData['total_in'] ?? 0, 2);
                $machinePutPoint = bcadd(0, $totalData['total_point'] ?? 0, 2);
                $presentOutAmount = bcadd(0, $totalData['total_out'] ?? 0, 2);
                $money = $player->machine_wallet->money;
            }
            $totalPoint = round($machinePutPoint + $presentInAmount - $presentOutAmount, 2);
            
            $profitAmount = bcmul($totalPoint, $ratio / 100, 2);
            $requestData[] = [
                'id' => $player->id,
                'uuid' => $player->uuid,
                'name' => $player->name,
                'present_in_amount' => $presentInAmount,
                'present_out_amount' => $presentOutAmount,
                'machine_put_point' => $machinePutPoint,
                'money' => $money,
                'promoter_id' => $player->player_promoter->id ?? 0,
                'profit_amount' => $profitAmount,
                'is_promoter' => $player->is_promoter,
                'promoter_status' => $player->player_promoter->status ?? null,
                'ratio' => $ratio ?? 0,
                'total_point' => $totalPoint//总盈余
            ];
        }
        
        $totalPoint = round(array_sum(array_column($requestData, 'total_point')), 2);
        $portraitAmount = bcmul($totalPoint, $this->player->player_promoter->ratio / 100, 2);
        
        //顶级展示中级营收 和营收之后的比例拆账
        if (!$top) {
            $portraitAmount = round(array_sum(array_column($requestData, 'profit_amount')), 2);
        }
        
        return jsonSuccessResponse('success', [
            'player_num' => $this->player->player_promoter->player_num,//拥有玩家数
            'max_ratio' => $this->player->player_promoter->ratio,//分润比例
            'portrait_score' => $totalPoint,
            'portrait_profit_amount' => $portraitAmount,
            'portrait_real_profit_amount' => $portraitAmount,
            'player_list' => $requestData,
        ]);
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 设置推广员
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|PromoterCheckException|Exception
     */
    public function setPromoter(Request $request): Response
    {
        $this->checkPromoter();
        $validator = v::key('id', v::intVal()->notEmpty()->setName(trans('player_id', [], 'message')))
            ->key('name', v::stringType()->notEmpty()->length(0, 30)->setName(trans('promoter_name', [], 'message')))
            ->key('ratio', v::intVal()->notEmpty()->between(0, 100)->setName(trans('ratio', [], 'message')));
        $data = $request->all();
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        if (!empty($this->player->recommend_id)) {
            return jsonFailResponse(trans('promoter_must_first', [], 'message'));
        }
        try {
            setPromoter($data['id'], $data['ratio'], $data['name']);
        } catch (\Exception $e) {
            return jsonFailResponse($e->getMessage());
        }
        
        return jsonSuccessResponse('success');
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 设置推广员
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|PromoterCheckException|Exception
     */
    public function setPromoterPortrait(Request $request): Response
    {
        $this->checkPromoter();
        $validator = v::key('id', v::intVal()->notEmpty()->setName(trans('player_id', [], 'message')))
            ->key('name', v::stringType()->notEmpty()->length(0, 30)->setName(trans('promoter_name', [], 'message')))
            ->key('ratio', v::intVal()->notEmpty()->between(0, 100)->setName(trans('ratio', [], 'message')));
        $data = $request->all();
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        if (!empty($this->player->recommend_id)) {
            return jsonFailResponse(trans('promoter_must_first', [], 'message'));
        }
        try {
            setPromoterPortrait($data['id'], $data['ratio'], $data['name']);
        } catch (\Exception $e) {
            return jsonFailResponse($e->getMessage());
        }
        
        return jsonSuccessResponse('success');
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 设置推广员备注名
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|PromoterCheckException|Exception
     */
    public function setPromoterName(Request $request): Response
    {
        $this->checkPromoter();
        $validator = v::key('id', v::intVal()->notEmpty()->setName(trans('player_id', [], 'message')))
            ->key('name', v::stringType()->notEmpty()->length(0, 30)->setName(trans('promoter_name', [], 'message')));
        $data = $request->all();
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        
        try {
            /** @var Player $player */
            $player = Player::find($data['id']);
            if (empty($player)) {
                throw new Exception(trans('player_not_fount', [], 'message'));
            }
            if ($player->status == Player::STATUS_STOP) {
                throw new Exception(trans('player_stop', [], 'message'));
            }
            if ($player->is_promoter == 0) {
                throw new Exception(trans('player_not_promoter', [], 'message'));
            }
            if ($player->player_promoter->status == 0) {
                throw new Exception(trans('promoter_has_disabled', [], 'message'));
            }
            $player->player_promoter->name = $data['name'];
            $player->player_promoter->save();
        } catch (\Exception $e) {
            return jsonFailResponse($e->getMessage());
        }
        
        return jsonSuccessResponse('success');
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 推广员团队
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|PromoterCheckException|Exception
     */
    public function promotionTeam(Request $request): Response
    {
        $this->checkPromoter();
        $validator = v::key('page', v::intVal()->setName(trans('page', [], 'message')))
            ->key('uuid', v::intVal()->setName(trans('uuid', [], 'message')), false)
            ->key('name', v::stringVal()->length(0, 30)->setName(trans('promoter_name', [], 'message')), false)
            ->key('size', v::intVal()->setName(trans('size', [], 'message')));
        $data = $request->all();
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        
        $teamModel = PlayerPromoter::where('recommend_id', $this->player->id);
        if (isset($data['uuid']) && !empty($data['uuid'])) {
            $teamModel->whereHas('player', function ($query) use ($data) {
                $query->where('uuid', $data['uuid'])->whereNull('deleted_at');
            });
        }
        if (isset($data['name']) && !empty($data['name'])) {
            $teamModel->where('name', 'like', '%' . $data['name'] . '%');
        }
        //下级推广员列表
        $teamList = $teamModel->forPage($data['page'], $data['size'])->get();
        $requestData = [];
        /** @var PlayerPromoter $playerPromoter */
        foreach ($teamList as $playerPromoter) {
            if ($playerPromoter->ratio == 0) {
                $profitAmount = PromoterProfitRecord::where('promoter_player_id', $playerPromoter->player_id)
                    ->where('source_player_id', $playerPromoter->player_id)
                    ->where('status', PromoterProfitRecord::STATUS_UNCOMPLETED)
                    ->first([
                        DB::raw("ifNull(sum(machine_up_amount - machine_down_amount - bonus_amount - lottery_amount - present_amount - admin_add_amount - water_amount + game_amount), 0) as score, ifNull(sum(profit_amount), 0) as profit_amount"),
                    ]);
                $profitAmount = $profitAmount->score;
            } else {
                $profitAmount = bcdiv(-$playerPromoter->profit_amount, $playerPromoter->ratio / 100, 2);
            }
            $requestData[] = [
                'id' => $playerPromoter->player_id,
                'promoter_id' => $playerPromoter->id,
                'uuid' => $playerPromoter->player->uuid,
                'name' => $playerPromoter->name,
                'team_num' => $playerPromoter->team_num,
                'team_withdraw_total_amount' => $playerPromoter->team_withdraw_total_amount,
                'team_recharge_total_amount' => $playerPromoter->team_recharge_total_amount,
                'machine_amount' => $playerPromoter->total_machine_amount,
                'machine_point' => $playerPromoter->total_machine_point,
                'ratio' => $playerPromoter->ratio ?? 0,
                'team_score' => -$profitAmount,//当期团队总输赢
                'actual_ratio' => bcsub(100, $playerPromoter->ratio, 2),//剩余分润比例
            ];
        }
        
        $totalProfitAmount = PromoterProfitRecord::where('promoter_player_id', $this->player->id)
            ->where('source_player_id', '<>', $this->player->id)
            ->where('status', PromoterProfitRecord::STATUS_UNCOMPLETED)
            ->first([
                DB::raw("ifNull(sum(machine_up_amount - machine_down_amount - bonus_amount - lottery_amount - present_amount - admin_add_amount - water_amount + game_amount), 0) as score, ifNull(sum(profit_amount), 0) as profit_amount"),
            ]);
        $totalTeamScore = $totalProfitAmount->score;
        $totalPoint = round(array_sum(array_column($requestData, 'team_score')), 2);
        
        $portraitAmount = bcmul($totalPoint, $this->player->player_promoter->ratio / 100, 2);
        return jsonSuccessResponse('success', [
            'id' => $this->player->id,
            'team_num' => $this->player->player_promoter->team_num,
            'team_profit_amount' => bcmul($totalTeamScore, $this->player->player_promoter->ratio / 100, 2), // 当期分润,
            'total_team_score' => -$totalTeamScore, // 当期团队总输赢,
            'max_ratio' => $this->player->player_promoter->ratio,//分润比例
            'portrait_score' => $totalPoint,//当期玩家输赢
            'portrait_profit_amount' => $portraitAmount,//当期玩家输赢
            'portrait_real_profit_amount' => $portraitAmount,//当期玩家输赢
            'player_list' => $requestData
        ]);
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 推广员团队
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|PromoterCheckException|Exception
     */
    public function promotionTeamPortrait(Request $request): Response
    {
        $this->checkPromoter();
        $validator = v::key('page', v::intVal()->setName(trans('page', [], 'message')))
            ->key('uuid', v::intVal()->setName(trans('uuid', [], 'message')), false)
            ->key('name', v::stringVal()->length(0, 30)->setName(trans('promoter_name', [], 'message')), false)
            ->key('size', v::intVal()->setName(trans('size', [], 'message')));
        $data = $request->all();
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        
        $teamModel = Player::where('recommend_id', $this->player->id)->where('is_promoter', 1);
        if (!empty($data['uuid'])) {
            $teamModel->where('uuid', $data['uuid'])->whereNull('deleted_at');
            
        }
        if (!empty($data['name'])) {
            $teamModel->where('name', 'like', '%' . $data['name'] . '%');
        }
        //下级推广员列表
        $teamList = $teamModel->forPage($data['page'], $data['size'])->get();
        $requestData = [];
        $playerRatio = $this->player?->player_promoter?->ratio ?? 0;
        $time = $this->player->player_promoter->last_settlement_timestamp;
        /** @var Player $player */
        foreach ($teamList as $player) {
            $children = Player::query()->where('recommend_id', $player->id)->pluck('id')->toArray();
            // 转入-开分 转出 细分
            $ratio = $player?->player_promoter?->ratio ?? 0;
            $ratio = $ratio - $playerRatio;
            $presentInAmount = PlayerDeliveryRecord::query()->whereIn('player_id', $children)->when(!empty($time),
                function ($query) use ($time) {
                    $query->where('created_at', '>=', $time);
                })->where('type', PlayerDeliveryRecord::TYPE_PRESENT_IN)->sum('amount') ?? 0;
            $presentOutAmount = PlayerDeliveryRecord::query()->whereIn('player_id', $children)->when(!empty($time),
                function ($query) use ($time) {
                    $query->where('created_at', '>=', $time);
                })->where('type', PlayerDeliveryRecord::TYPE_PRESENT_OUT)->sum('amount') ?? 0;
            $machinePutPoint = PlayerDeliveryRecord::query()->whereIn('player_id', $children)->when(!empty($time),
                function ($query) use ($time) {
                    $query->where('created_at', '>=', $time);
                })->where('type', PlayerDeliveryRecord::TYPE_MACHINE)->sum('amount') ?? 0;
            $teamScore = round($machinePutPoint + $presentInAmount - $presentOutAmount, 2) ?? 0;
            $teamProfit = bcmul($teamScore, $ratio / 100, 2);
            $requestData[] = [
                'id' => $player->id,
                'promoter_id' => $player->player_promoter->id,
                'uuid' => $player->uuid,
                'name' => $player->name,
                'team_num' => Player::where('recommend_id', $player->id)->count(),
                'present_in_amount' => $presentInAmount,
                'present_out_amount' => $presentOutAmount,
                'machine_amount' => $machinePutPoint * 4,
                'machine_point' => $machinePutPoint,
                'ratio' => $ratio,
                'team_score' => $teamScore,//总盈余
                'team_profit' => $teamProfit,//分润
                'actual_ratio' => bcsub(100, $ratio, 2),//剩余分润比例
                'my_bill' => round($teamScore - $teamProfit, 2),//我的拆账
            ];
        }
        $totalPoint = round(array_sum(array_column($requestData, 'team_score')), 2) ?? 0;
        
        $portraitAmount = bcmul($totalPoint, $playerRatio / 100, 2);
        return jsonSuccessResponse('success', [
            'id' => $this->player->id,
            'team_num' => $this->player->player_promoter->team_num,
            'team_profit_amount' => bcmul($totalPoint, $playerRatio / 100, 2), // 当期分润,
            'total_team_score' => -$totalPoint, // 当期团队总输赢,
            'max_ratio' => $playerRatio,//分润比例
            'portrait_score' => $totalPoint,//当期玩家输赢
            'portrait_profit_amount' => $portraitAmount,//当期玩家输赢
            'portrait_real_profit_amount' => $portraitAmount,//当期玩家输赢
            'player_list' => $requestData
        ]);
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 玩家账变记录
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|PromoterCheckException|Exception
     */
    public function playerDeliveryRecord(Request $request): Response
    {
        $this->checkPromoter();
        $validator = v::key('id', v::intVal()->notEmpty()->setName(trans('player_id', [], 'message')))
            ->key('page', v::intVal()->setName(trans('page', [], 'message')))
            ->key('type', v::stringVal()->setName(trans('date_type', [], 'message')))
            ->key('size', v::intVal()->setName(trans('size', [], 'message')));
        $data = $request->all();
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        try {
            /** @var Player $player */
            $player = Player::where('id', $data['id'])->first();
            if (empty($player)) {
                throw new Exception(trans('player_not_fount', [], 'message'));
            }
            if ($player->status == Player::STATUS_STOP) {
                throw new Exception(trans('player_stop', [], 'message'));
            }
            $playerDeliveryRecordModel = PlayerDeliveryRecord::where('player_id', $data['id']);
            switch ($data['type']) {
                case 'yesterday': // 今天
                    $playerDeliveryRecordModel->where('created_at', '>=',
                        Carbon::yesterday()->startOfDay())->where('created_at', '<=', Carbon::yesterday()->endOfDay());
                    break;
                case 'today': // 今天
                    $playerDeliveryRecordModel->whereDate('created_at', date('Y-m-d'));
                    break;
                case 'week': // 本周
                    $playerDeliveryRecordModel->where('created_at', '>=',
                        Carbon::today()->startOfWeek())->where('created_at', '<=', Carbon::today()->endOfWeek());
                    break;
                case 'month': // 本月
                    $playerDeliveryRecordModel->where('created_at', '>=',
                        Carbon::today()->firstOfMonth())->where('created_at', '<=', Carbon::today()->endOfMonth());
                    break;
                case 'sub_month': // 上月
                    $playerDeliveryRecordModel->where('created_at', '>=',
                        Carbon::today()->subMonth()->firstOfMonth())->where('created_at', '<=',
                        Carbon::today()->subMonth()->endOfMonth());
                    break;
                default:
                    $playerDeliveryRecordModel->whereDate('created_at', date('Y-m-d'));
                    break;
            }
            $inType = implode(',', [
                PlayerDeliveryRecord::TYPE_MODIFIED_AMOUNT_ADD,
                PlayerDeliveryRecord::TYPE_PRESENT_IN,
                PlayerDeliveryRecord::TYPE_MACHINE_DOWN,
                PlayerDeliveryRecord::TYPE_ACTIVITY_BONUS,
                PlayerDeliveryRecord::TYPE_REGISTER_PRESENT,
                PlayerDeliveryRecord::TYPE_PROFIT,
                PlayerDeliveryRecord::TYPE_LOTTERY,
            ]);
            $outType = implode(',', [
                PlayerDeliveryRecord::TYPE_PRESENT_OUT,
                PlayerDeliveryRecord::TYPE_MACHINE_UP,
                PlayerDeliveryRecord::TYPE_MODIFIED_AMOUNT_DEDUCT,
            ]);
            $totalModel = clone $playerDeliveryRecordModel;
            $totalData = $totalModel->selectRaw('
            sum(IF(type in (' . $inType . '), amount, 0)) as total_in,
            sum(IF(type in (' . $outType . '), amount, 0)) as total_out,
            sum(IF(type = ' . PlayerDeliveryRecord::TYPE_WITHDRAWAL . ', amount, 0)) as total_withdrawal,
            sum(IF(type = ' . PlayerDeliveryRecord::TYPE_WITHDRAWAL_BACK . ', amount, 0)) as total_withdrawal_back,
            sum(IF(type = ' . PlayerDeliveryRecord::TYPE_RECHARGE . ', amount, 0)) as total_recharge,
            sum(IF(type = ' . PlayerDeliveryRecord::TYPE_MACHINE . ', amount_after - amount_before, 0)) as total_point
            ')->first();
            
            $playerDeliveryRecord = $playerDeliveryRecordModel->forPage($data['page'], $data['size'])
                ->orderBy('id', 'desc')
                ->get();
            $list = [];
            /** @var PlayerDeliveryRecord $item */
            foreach ($playerDeliveryRecord as $item) {
                switch ($item->type) {
                    case PlayerDeliveryRecord::TYPE_MODIFIED_AMOUNT_ADD:
                        $item->target = trans('target.modified_amount_add', [], 'message');
                        break;
                    case PlayerDeliveryRecord::TYPE_PRESENT_IN:
                        $item->target = trans('target.present_in', [], 'message');
                        break;
                    case PlayerDeliveryRecord::TYPE_PRESENT_OUT:
                        $item->target = trans('target.present_out', [], 'message');
                        $item->amount = '-' . $item->amount;
                        break;
                    case PlayerDeliveryRecord::TYPE_MACHINE_UP:
                        $item->target = trans('target.machine_up', [], 'message');
                        $item->amount = '-' . $item->amount;
                        break;
                    case PlayerDeliveryRecord::TYPE_MACHINE_DOWN:
                        $item->target = trans('target.machine_down', [], 'message');
                        break;
                    case PlayerDeliveryRecord::TYPE_RECHARGE:
                        switch ($item->source) {
                            case 'artificial_recharge':
                                $item->target = trans('target.artificial_recharge', [], 'message');
                                break;
                            case 'self_recharge':
                                $item->target = trans('target.self_recharge', [], 'message');
                                break;
                            case 'talk_recharge':
                                $item->target = trans('target.talk_recharge', [], 'message');
                                break;
                            case 'coin_recharge':
                                $item->target = trans('target.coin_recharge', [], 'message');
                                break;
                        }
                        break;
                    case PlayerDeliveryRecord::TYPE_WITHDRAWAL:
                        switch ($item['source']) {
                            case 'artificial_withdrawal':
                                $item->target = trans('target.artificial_withdrawal', [], 'message');
                                break;
                            case 'talk_withdrawal':
                                $item->target = trans('target.talk_withdrawal', [], 'message');
                                break;
                            case 'channel_withdrawal':
                                $item->target = trans('target.channel_withdrawal', [], 'message');
                                break;
                        }
                        $item->amount = '-' . $item->amount;
                        break;
                    case PlayerDeliveryRecord::TYPE_MODIFIED_AMOUNT_DEDUCT:
                        $item->target = trans('target.modified_amount_deduct', [], 'message');
                        $item->amount = '-' . $item->amount;
                        break;
                    case PlayerDeliveryRecord::TYPE_WITHDRAWAL_BACK:
                        $item->target = trans('target.withdrawal_back', [], 'message');
                        break;
                    case PlayerDeliveryRecord::TYPE_ACTIVITY_BONUS:
                        $item->target = trans('target.activity_bonus', [], 'message');
                        break;
                    case PlayerDeliveryRecord::TYPE_REGISTER_PRESENT:
                        $item->target = trans('target.register_present', [], 'message');
                        break;
                    case PlayerDeliveryRecord::TYPE_PROFIT:
                        $item->target = trans('target.profit', [], 'message');
                        break;
                    case PlayerDeliveryRecord::TYPE_LOTTERY:
                        $item->target = trans('target.lottery', [], 'message');
                        break;
                    case PlayerDeliveryRecord::TYPE_GAME_PLATFORM_OUT:
                        $item->target = trans('target.wallet_transfer_out', ['{name}' => $item->gamePlatform->code],
                            'message');
                        $item->amount = '-' . $item->amount;
                        break;
                    case PlayerDeliveryRecord::TYPE_GAME_PLATFORM_IN:
                        $item->target = trans('target.wallet_transfer_in', ['{name}' => $item->gamePlatform->code],
                            'message');
                        break;
                    case PlayerDeliveryRecord::TYPE_NATIONAL_INVITE:
                        $item->target = trans('target.national_invite', [], 'message');
                        break;
                    case PlayerDeliveryRecord::TYPE_RECHARGE_REWARD:
                        $item->target = trans('target.player_recharge_record', [], 'message');
                        break;
                    case PlayerDeliveryRecord::TYPE_DAMAGE_REBATE:
                        $item->target = trans('target.national_promoter', [], 'message');
                        break;
                    case PlayerDeliveryRecord::TYPE_REVERSE_WATER:
                        $item->target = trans('target.reverse_water', [], 'message');
                        break;
                    case PlayerDeliveryRecord::COIN_ADD:
                        $item->target = trans('target.coin_add', [], 'message');
                        break;
                    case PlayerDeliveryRecord::COIN_DEDUCT:
                        $item->target = trans('target.coin_deduct', [], 'message');
                        $item->amount = '-' . $item->amount;
                        break;
                    case PlayerDeliveryRecord::TYPE_MACHINE:
                        $item->target = trans('target.machine_put_coins', [], 'message');
                        break;
                    default:
                        break;
                }
                $list[] = [
                    'id' => $item->id,
                    'amount' => $item->amount <= 0 ? $item->amount : '+' . $item->amount,
                    'source' => $item->target,
                    'amount_after' => $item->amount_after,
                    'created_at' => date('Y-m-d H:i:s', strtotime($item->created_at)),
                ];
            }
        } catch (\Exception $e) {
            return jsonFailResponse($e->getMessage());
        }
        
        return jsonSuccessResponse('success', [
            'list' => $list,
            'player' => [
                'uuid' => $player->uuid,
                'name' => $player->name,
                'promoter_uuid' => $this->player->uuid,
            ],
            'total_data' => [
                'total_in' => $totalData['total_in'] ?? 0,
                'total_out' => $totalData['total_out'] ?? 0,
                'total_withdrawal' => bcsub($totalData['total_withdrawal'] ?? 0,
                    $totalData['total_withdrawal_back'] ?? 0, 2),
                'total_recharge' => $totalData['total_recharge'] ?? 0,
            ],
            'date_type' => [
                'yesterday' => Carbon::yesterday()->format('Y-m-d'),
                'today' => Carbon::today()->format('Y-m-d'),
                'week' => Carbon::today()->startOfWeek()->format('Y-m-d') . '~' . Carbon::today()->endOfWeek()->format('Y-m-d'),
                'month' => Carbon::today()->firstOfMonth()->format('Y-m-d') . '~' . Carbon::today()->endOfMonth()->format('Y-m-d'),
                'sub_month' => Carbon::today()->subMonth()->firstOfMonth()->format('Y-m-d') . '~' . Carbon::today()->subMonth()->endOfMonth()->format('Y-m-d'),
            ]
        ]);
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 玩家账变记录
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|PromoterCheckException|Exception
     */
    public function playerDeliveryRecordPortrait(Request $request): Response
    {
        $this->checkPromoter();
        $validator = v::key('id', v::intVal()->notEmpty()->setName(trans('player_id', [], 'message')))
            ->key('page', v::intVal()->setName(trans('page', [], 'message')))
            ->key('type', v::stringVal()->setName(trans('date_type', [], 'message')))
            ->key('size', v::intVal()->setName(trans('size', [], 'message')));
        $data = $request->all();
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        try {
            /** @var Player $player */
            $player = Player::where('id', $data['id'])->first();
            if (empty($player)) {
                throw new Exception(trans('player_not_fount', [], 'message'));
            }
            if ($player->status == Player::STATUS_STOP) {
                throw new Exception(trans('player_stop', [], 'message'));
            }
            $settlementTime = null;
            $playerDeliveryRecordModel = PlayerDeliveryRecord::where('player_id', $data['id']);
            $playerRechargeRecordModel = PlayerRechargeRecord::where('player_id', $data['id']);
            switch ($data['type']) {
                case 'yesterday': // 今天
                    $playerDeliveryRecordModel->where('created_at', '>=',
                        Carbon::yesterday()->startOfDay())->where('created_at', '<=', Carbon::yesterday()->endOfDay());
                    $playerRechargeRecordModel->where('created_at', '>=',
                        Carbon::yesterday()->startOfDay())->where('created_at', '<=', Carbon::yesterday()->endOfDay());
                    break;
                case 'today': // 今天
                    $playerDeliveryRecordModel->whereDate('created_at', date('Y-m-d'));
                    $playerRechargeRecordModel->whereDate('created_at', date('Y-m-d'));
                    break;
                case 'week': // 本周
                    $playerDeliveryRecordModel->where('created_at', '>=',
                        Carbon::today()->startOfWeek())->where('created_at', '<=', Carbon::today()->endOfWeek());
                    $playerRechargeRecordModel->where('created_at', '>=',
                        Carbon::today()->startOfWeek())->where('created_at', '<=', Carbon::today()->endOfWeek());
                    break;
                case 'month': // 本月
                    $playerDeliveryRecordModel->where('created_at', '>=',
                        Carbon::today()->firstOfMonth())->where('created_at', '<=', Carbon::today()->endOfMonth());
                    $playerRechargeRecordModel->where('created_at', '>=',
                        Carbon::today()->firstOfMonth())->where('created_at', '<=', Carbon::today()->endOfMonth());
                    break;
                case 'sub_month': // 上月
                    $playerDeliveryRecordModel->where('created_at', '>=',
                        Carbon::today()->subMonth()->firstOfMonth())->where('created_at', '<=',
                        Carbon::today()->subMonth()->endOfMonth());
                    $playerRechargeRecordModel->where('created_at', '>=',
                        Carbon::today()->subMonth()->firstOfMonth())->where('created_at', '<=',
                        Carbon::today()->subMonth()->endOfMonth());
                    break;
                case 'unsettled': // 未结算
                    $settlementTime = $player->player_promoter->last_settlement_timestamp;
                    $playerDeliveryRecordModel->when(!empty($settlementTime), function ($query) use ($settlementTime) {
                        $query->where('created_at', '>=', $settlementTime);
                    });
                    $playerRechargeRecordModel->when(!empty($settlementTime), function ($query) use ($settlementTime) {
                        $query->where('created_at', '>=', $settlementTime);
                    });
                    break;
                case 'all': // 全部
                    break;
                default:
                    $playerDeliveryRecordModel->whereDate('created_at', date('Y-m-d'));
                    $playerRechargeRecordModel->whereDate('created_at', date('Y-m-d'));
                    break;
            }
            $inType = implode(',', [
                PlayerDeliveryRecord::TYPE_PRESENT_IN,
            ]);
            $outType = implode(',', [
                PlayerDeliveryRecord::TYPE_PRESENT_OUT,
            ]);
            $totalModel = clone $playerDeliveryRecordModel;
            $machineTotal = clone $playerRechargeRecordModel;
            $totalData = $totalModel->selectRaw('
            sum(IF(type in (' . $inType . '), amount, 0)) as total_in,
            sum(IF(type in (' . $outType . '), amount, 0)) as total_out,
            sum(IF(type = ' . PlayerDeliveryRecord::TYPE_WITHDRAWAL . ', amount, 0)) as total_withdrawal,
            sum(IF(type = ' . PlayerDeliveryRecord::TYPE_WITHDRAWAL_BACK . ', amount, 0)) as total_withdrawal_back,
            sum(IF(type = ' . PlayerDeliveryRecord::TYPE_RECHARGE . ', amount, 0)) as total_recharge,
            sum(IF(type = ' . PlayerDeliveryRecord::TYPE_MACHINE . ', amount_after - amount_before, 0)) as total_put_count
            ')->first();
            
            $totalData['total_point'] = $machineTotal->selectRaw('
            sum(IF(type = ' . PlayerRechargeRecord::TYPE_MACHINE . ', point, 0)) as total_point
            ')->value('total_point') ?? 0;
            
            
            //判断是不是中级用户 中级不需要计算自己的数据 只展示下级  下级只展示自己的数据
            $center = $player->id == checkPlayer()->id;
            //竖版增加所有下级汇总
            $childrenTotal = $this->playerDeliveryRecordChildren($data, $inType, $outType, $settlementTime);
            
            if ($center) {
                $totalIn = bcadd(0, $childrenTotal['total_in'] ?? 0, 2);
                $totalPutCount = bcadd(0, $childrenTotal['total_put_count'] ?? 0, 2);
                $totalPoint = bcadd(0, $childrenTotal['total_point'] ?? 0, 2);
                $totalOut = bcadd(0, $childrenTotal['total_out'] ?? 0, 2);
                $totalRecharge = bcadd(0, $childrenTotal['total_recharge'] ?? 0, 2);
            } else {
                $totalIn = bcadd($totalData['total_in'] ?? 0, 0, 2);
                $totalPutCount = bcadd($totalData['total_put_count'] ?? 0, 0, 2);
                $totalPoint = bcadd($totalData['total_point'] ?? 0, 0, 2);
                $totalOut = bcadd($totalData['total_out'] ?? 0, 0, 2);
                $totalRecharge = bcadd($totalData['total_recharge'] ?? 0, 0, 2);
            }
            
            $playerDeliveryRecord = $playerDeliveryRecordModel->forPage($data['page'], $data['size'])
                ->orderBy('id', 'desc')
                ->get();
            $list = [];
            /** @var PlayerDeliveryRecord $item */
            foreach ($playerDeliveryRecord as $item) {
                switch ($item->type) {
                    case PlayerDeliveryRecord::TYPE_MODIFIED_AMOUNT_ADD:
                        $item->target = trans('target.modified_amount_add', [], 'message');
                        break;
                    case PlayerDeliveryRecord::TYPE_PRESENT_IN:
                        $item->target = '開分';
                        break;
                    case PlayerDeliveryRecord::TYPE_PRESENT_OUT:
                        $item->target = '洗分';
                        $item->amount = '-' . $item->amount;
                        break;
                    case PlayerDeliveryRecord::TYPE_MACHINE_UP:
                        $item->target = trans('target.machine_up', [], 'message');
                        $item->amount = '-' . $item->amount;
                        break;
                    case PlayerDeliveryRecord::TYPE_MACHINE_DOWN:
                        $item->target = trans('target.machine_down', [], 'message');
                        break;
                    case PlayerDeliveryRecord::TYPE_RECHARGE:
                        switch ($item->source) {
                            case 'artificial_recharge':
                                $item->target = trans('target.artificial_recharge', [], 'message');
                                break;
                            case 'self_recharge':
                                $item->target = trans('target.self_recharge', [], 'message');
                                break;
                            case 'talk_recharge':
                                $item->target = trans('target.talk_recharge', [], 'message');
                                break;
                            case 'coin_recharge':
                                $item->target = trans('target.coin_recharge', [], 'message');
                                break;
                        }
                        break;
                    case PlayerDeliveryRecord::TYPE_WITHDRAWAL:
                        switch ($item['source']) {
                            case 'artificial_withdrawal':
                                $item->target = trans('target.artificial_withdrawal', [], 'message');
                                break;
                            case 'talk_withdrawal':
                                $item->target = trans('target.talk_withdrawal', [], 'message');
                                break;
                            case 'channel_withdrawal':
                                $item->target = trans('target.channel_withdrawal', [], 'message');
                                break;
                        }
                        $item->amount = '-' . $item->amount;
                        break;
                    case PlayerDeliveryRecord::TYPE_MODIFIED_AMOUNT_DEDUCT:
                        $item->target = trans('target.modified_amount_deduct', [], 'message');
                        $item->amount = '-' . $item->amount;
                        break;
                    case PlayerDeliveryRecord::TYPE_WITHDRAWAL_BACK:
                        $item->target = trans('target.withdrawal_back', [], 'message');
                        break;
                    case PlayerDeliveryRecord::TYPE_ACTIVITY_BONUS:
                        $item->target = trans('target.activity_bonus', [], 'message');
                        break;
                    case PlayerDeliveryRecord::TYPE_REGISTER_PRESENT:
                        $item->target = trans('target.register_present', [], 'message');
                        break;
                    case PlayerDeliveryRecord::TYPE_PROFIT:
                        $item->target = trans('target.profit', [], 'message');
                        break;
                    case PlayerDeliveryRecord::TYPE_LOTTERY:
                        $item->target = trans('target.lottery', [], 'message');
                        break;
                    case PlayerDeliveryRecord::TYPE_GAME_PLATFORM_OUT:
                        $item->target = trans('target.wallet_transfer_out', ['{name}' => $item->gamePlatform->code],
                            'message');
                        $item->amount = '-' . $item->amount;
                        break;
                    case PlayerDeliveryRecord::TYPE_GAME_PLATFORM_IN:
                        $item->target = trans('target.wallet_transfer_in', ['{name}' => $item->gamePlatform->code],
                            'message');
                        break;
                    case PlayerDeliveryRecord::TYPE_NATIONAL_INVITE:
                        $item->target = trans('target.national_invite', [], 'message');
                        break;
                    case PlayerDeliveryRecord::TYPE_RECHARGE_REWARD:
                        $item->target = trans('target.player_recharge_record', [], 'message');
                        break;
                    case PlayerDeliveryRecord::TYPE_DAMAGE_REBATE:
                        $item->target = trans('target.national_promoter', [], 'message');
                        break;
                    case PlayerDeliveryRecord::TYPE_REVERSE_WATER:
                        $item->target = trans('target.reverse_water', [], 'message');
                        break;
                    case PlayerDeliveryRecord::COIN_ADD:
                        $item->target = trans('target.coin_add', [], 'message');
                        break;
                    case PlayerDeliveryRecord::COIN_DEDUCT:
                        $item->target = trans('target.coin_deduct', [], 'message');
                        $item->amount = '-' . $item->amount;
                        break;
                    case PlayerDeliveryRecord::TYPE_MACHINE:
                        $item->target = trans('target.machine_put_coins', [], 'message');
                        break;
                    default:
                        break;
                }
                $list[] = [
                    'id' => $item->id,
                    'amount' => $item->amount <= 0 ? $item->amount : '+' . $item->amount,
                    'source' => $item->target,
                    'amount_after' => $item->amount_after,
                    'created_at' => date('Y-m-d H:i:s', strtotime($item->created_at)),
                ];
            }
        } catch (\Exception $e) {
            return jsonFailResponse($e->getMessage());
        }
        
        return jsonSuccessResponse('success', [
            
            'total_data' => [
                'total_in' => $totalIn,
                'total_put_count' => $totalPutCount,
                'total_point' => $totalPoint,
                'total_out' => $totalOut,
                'total_withdrawal' => bcsub($totalData['total_withdrawal'] ?? 0,
                    $totalData['total_withdrawal_back'] ?? 0, 2),
                'total_recharge' => $totalRecharge,
                'total_score' => round($totalPoint + $totalIn - $totalOut, 2)
            ],
            'date_type' => [
                'yesterday' => Carbon::yesterday()->format('Y-m-d'),
                'today' => Carbon::today()->format('Y-m-d'),
                'week' => Carbon::today()->startOfWeek()->format('Y-m-d') . '~' . Carbon::today()->endOfWeek()->format('Y-m-d'),
                'month' => Carbon::today()->firstOfMonth()->format('Y-m-d') . '~' . Carbon::today()->endOfMonth()->format('Y-m-d'),
                'sub_month' => Carbon::today()->subMonth()->firstOfMonth()->format('Y-m-d') . '~' . Carbon::today()->subMonth()->endOfMonth()->format('Y-m-d'),
            ],
            'list' => $list,
            'player' => [
                'uuid' => $player->uuid,
                'name' => $player->name,
                'promoter_uuid' => $this->player->uuid,
            ],
        ]);
    }
    
    /**
     * 获取下级的数据汇总
     * @param $data
     * @param $inType
     * @param $outType
     * @param null $settlementTime
     * @return array
     */
    private function playerDeliveryRecordChildren($data, $inType, $outType, $settlementTime = null): array
    {
        if (!is_array($data['id'])) {
            $data['id'] = [$data['id']];
        }
        
        $children = Player::query()->whereIn('recommend_id',
            $data['id'])->whereNull('deleted_at')->pluck('id')->toArray();
        if (empty($children)) {
            return [];
        }
        
        $data['id'] = $children;
        
        $playerDeliveryRecordModel = PlayerDeliveryRecord::query()->whereIn('player_id', $children);
        $playerRechargeRecordModel = PlayerRechargeRecord::query()->whereIn('player_id', $children);
        switch ($data['type']) {
            case 'yesterday': // 今天
                $playerDeliveryRecordModel->where('created_at', '>=',
                    Carbon::yesterday()->startOfDay())->where('created_at', '<=', Carbon::yesterday()->endOfDay());
                $playerRechargeRecordModel->where('created_at', '>=',
                    Carbon::yesterday()->startOfDay())->where('created_at', '<=', Carbon::yesterday()->endOfDay());
                break;
            case 'today': // 今天
                $playerDeliveryRecordModel->whereDate('created_at', date('Y-m-d'));
                $playerRechargeRecordModel->whereDate('created_at', date('Y-m-d'));
                break;
            case 'week': // 本周
                $playerDeliveryRecordModel->where('created_at', '>=',
                    Carbon::today()->startOfWeek())->where('created_at', '<=', Carbon::today()->endOfWeek());
                $playerRechargeRecordModel->where('created_at', '>=',
                    Carbon::today()->startOfWeek())->where('created_at', '<=', Carbon::today()->endOfWeek());
                break;
            case 'month': // 本月
                $playerDeliveryRecordModel->where('created_at', '>=',
                    Carbon::today()->firstOfMonth())->where('created_at', '<=', Carbon::today()->endOfMonth());
                $playerRechargeRecordModel->where('created_at', '>=',
                    Carbon::today()->firstOfMonth())->where('created_at', '<=', Carbon::today()->endOfMonth());
                break;
            case 'sub_month': // 上月
                $playerDeliveryRecordModel->where('created_at', '>=',
                    Carbon::today()->subMonth()->firstOfMonth())->where('created_at', '<=',
                    Carbon::today()->subMonth()->endOfMonth());
                $playerRechargeRecordModel->where('created_at', '>=',
                    Carbon::today()->subMonth()->firstOfMonth())->where('created_at', '<=',
                    Carbon::today()->subMonth()->endOfMonth());
                break;
            case 'unsettled': // 未结算
                $playerDeliveryRecordModel->when(!empty($settlementTime), function ($query) use ($settlementTime) {
                    $query->where('created_at', '>=', $settlementTime);
                });
                $playerRechargeRecordModel->when(!empty($settlementTime), function ($query) use ($settlementTime) {
                    $query->where('created_at', '>=', $settlementTime);
                });
                break;
            case 'all': // 全部
                break;
            default:
                $playerDeliveryRecordModel->whereDate('created_at', date('Y-m-d'));
                $playerRechargeRecordModel->whereDate('created_at', date('Y-m-d'));
                break;
        }
        
        $totalModel = clone $playerDeliveryRecordModel;
        $machineTotal = clone $playerRechargeRecordModel;
        $totalData = $totalModel->selectRaw('
            sum(IF(type in (' . $inType . '), amount, 0)) as total_in,
            sum(IF(type in (' . $outType . '), amount, 0)) as total_out,
            sum(IF(type = ' . PlayerDeliveryRecord::TYPE_WITHDRAWAL . ', amount, 0)) as total_withdrawal,
            sum(IF(type = ' . PlayerDeliveryRecord::TYPE_WITHDRAWAL_BACK . ', amount, 0)) as total_withdrawal_back,
            sum(IF(type = ' . PlayerDeliveryRecord::TYPE_RECHARGE . ', amount, 0)) as total_recharge,
            sum(IF(type = ' . PlayerDeliveryRecord::TYPE_MACHINE . ', amount_after - amount_before, 0)) as total_put_count
            ')->first()->toArray();
        
        $totalData['total_point'] = $machineTotal->selectRaw('
            sum(IF(type = ' . PlayerRechargeRecord::TYPE_MACHINE . ', point, 0)) as total_point
            ')->value('total_point') ?? 0;
        
        $children = $this->playerDeliveryRecordChildren($data, $inType, $outType, $settlementTime);
        
        foreach ($children as $key => $value) {
            $totalData[$key] = ($value ?? 0) + ($totalData[$key] ?? 0);
        }
        
        return $totalData;
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 设置玩家备注
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|PromoterCheckException|Exception
     */
    public function setPlayerRemark(Request $request): Response
    {
        $this->checkPromoter();
        $validator = v::key('id', v::intVal()->notEmpty()->setName(trans('player_id', [], 'message')))
            ->key('remark', v::stringType()->notEmpty()->length(0, 30)->setName(trans('promoter_name', [], 'message')));
        $data = $request->all();
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        
        try {
            /** @var Player $player */
            $player = Player::find($data['id']);
            if (empty($player)) {
                throw new Exception(trans('player_not_fount', [], 'message'));
            }
            if ($player->status == Player::STATUS_STOP) {
                throw new Exception(trans('player_stop', [], 'message'));
            }
            if ($player->recommend_id != $this->player->id) {
                throw new Exception(trans('player_not_found', [], 'message'));
            }
            $player->remark = $data['remark'];
            $player->save();
        } catch (\Exception $e) {
            return jsonFailResponse($e->getMessage());
        }
        return jsonSuccessResponse('success');
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 验证密码
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|PromoterCheckException|Exception
     */
    public function passCheck(Request $request): Response
    {
        $this->checkPromoter();
        $data = $request->post();
        $validator = v::key('password', v::stringType()->notEmpty()->setName(trans('password', [], 'message')));
        
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        if (empty($this->player->password)) {
            return jsonFailResponse(trans('must_set_password', [], 'message'));
        }
        if (!password_verify($data['password'], $this->player->password)) {
            return jsonFailResponse(trans('password_error', [], 'message'));
        }
        
        return jsonSuccessResponse('success');
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 团队明细
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|PromoterCheckException|Exception
     */
    public function promotionTeamPlayer(Request $request): Response
    {
        $this->checkPromoter();
        $validator = v::key('page', v::intVal()->setName(trans('page', [], 'message')))
            ->key('size', v::intVal()->setName(trans('size', [], 'message')))
            ->key('id', v::intVal()->setName(trans('player_id', [], 'message')));
        $data = $request->all();
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        $playerList = Player::where('recommend_id', $data['id'])
            ->forPage($data['page'], $data['size'])
            ->get();
        
        $requestData = [];
        
        /** @var Player $player */
        foreach ($playerList as $player) {
            $promoterProfitRecord = PromoterProfitRecord::where('player_id', $player->id)
                ->where('status', PromoterProfitRecord::STATUS_UNCOMPLETED)
                ->where('promoter_player_id', $data['id'])
                ->first([
                    DB::raw("sum(machine_up_amount - machine_down_amount - lottery_amount - present_amount - admin_add_amount - water_amount + game_amount) as score"),
                ]);
            $totalScore = $promoterProfitRecord->score;
            $requestData[] = [
                'id' => $player->id,
                'uuid' => $player->uuid,
                'name' => $player->name,
                'recharge_amount' => $player->player_extend->recharge_amount,
                'withdraw_amount' => $player->player_extend->withdraw_amount,
                'present_in_amount' => $player->player_extend->present_in_amount,
                'present_out_amount' => $player->player_extend->present_out_amount,
                'machine_put_amount' => $player->player_extend->machine_put_amount,
                'machine_put_point' => $player->player_extend->machine_put_point,
                'money' => $player->machine_wallet->money,
                'promoter_id' => $player->player_promoter->id ?? 0,
                'is_promoter' => $player->is_promoter,
                'promoter_status' => $player->player_promoter->status ?? null,
                'ratio' => $player->player_promoter->ratio ?? 0,
                'remark' => $player->remark,
                'total_score' => $totalScore ?? 0,
                //总输赢
                'total_point' => $player->player_extend->machine_put_point + $player->player_extend->present_in_amount - $player->player_extend->present_out_amount,
                //总盈亏
            ];
        }
        return jsonSuccessResponse('success', $requestData);
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 团队明细竖版
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|PromoterCheckException|Exception
     */
    public function promotionTeamPlayerPortrait(Request $request): Response
    {
        $this->checkPromoter();
        $validator = v::key('page', v::intVal()->setName(trans('page', [], 'message')))
            ->key('size', v::intVal()->setName(trans('size', [], 'message')))
            ->key('id', v::intVal()->setName(trans('player_id', [], 'message')));
        $data = $request->all();
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        $playerList = Player::where('recommend_id', $data['id'])
            ->forPage($data['page'], $data['size'])
            ->get();
        
        $requestData = [];
        
        /** @var Player $player */
        foreach ($playerList as $player) {
            $time = $this->player->player_promoter->last_settlement_timestamp;
            $presentInAmount = PlayerDeliveryRecord::query()->where('player_id', $player->id)
                ->when(!empty($time), function ($query) use ($time) {
                    $query->where('created_at', '>=', $time);
                })
                ->where('type', PlayerDeliveryRecord::TYPE_PRESENT_IN)
                ->sum('amount') ?? 0;
            $presentOutAmount = PlayerDeliveryRecord::query()->where('player_id', $player->id)
                ->when(!empty($time), function ($query) use ($time) {
                    $query->where('created_at', '>=', $time);
                })
                ->where('type', PlayerDeliveryRecord::TYPE_PRESENT_OUT)
                ->sum('amount') ?? 0;
            $machinePutPoint = PlayerDeliveryRecord::query()->where('player_id', $player->id)
                ->when(!empty($time), function ($query) use ($time) {
                    $query->where('created_at', '>=', $time);
                })
                ->where('type', PlayerDeliveryRecord::TYPE_MACHINE)
                ->sum('amount') ?? 0;
            $requestData[] = [
                'id' => $player->id,
                'uuid' => $player->uuid,
                'name' => $player->name,
                'present_in_amount' => $presentInAmount,
                'present_out_amount' => $presentOutAmount,
                'machine_put_amount' => $machinePutPoint * 4,
                'machine_put_point' => $machinePutPoint,
                'money' => $player->machine_wallet->money,
                'promoter_id' => $player->player_promoter->id ?? 0,
                'is_promoter' => $player->is_promoter,
                'promoter_status' => $player->player_promoter->status ?? null,
                'ratio' => $player->player_promoter->ratio ?? 0,
                'remark' => $player->remark,
                'total_point' => $machinePutPoint + $presentInAmount - $presentOutAmount,//总盈亏
            ];
        }
        return jsonSuccessResponse('success', $requestData);
    }
}
