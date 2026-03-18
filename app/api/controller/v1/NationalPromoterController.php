<?php

namespace app\api\controller\v1;

use app\model\LevelList;
use app\model\NationalInvite;
use app\model\NationalProfitRecord;
use app\model\NationalPromoter;
use app\exception\GameException;
use app\exception\PlayerCheckException;
use Respect\Validation\Exceptions\AllOfException;
use Respect\Validation\Validator as v;
use support\Cache;
use support\Request;
use support\Response;
use think\Exception;
use Webman\RateLimiter\Annotation\RateLimiter;

class  NationalPromoterController
{
    #[RateLimiter(limit: 5)]
    /**
     * 账号等级
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public function nationalLevel(): Response
    {
        $player = checkPlayer();
        if (isset($player->national_promoter)) {
            $national_promoter = '等级' . $player->national_promoter->level_list->national_level->name;
            $national_level = $player->national_promoter->level_list->level;
        } else {
            $national_promoter = '';
            $national_level = '';
        }
        $nextLevel = LevelList::query()->where('department_id', $player->department_id)->where('must_chip_amount', '>',
            $player->national_promoter->chip_amount)->orderBy('must_chip_amount', 'asc')->first();
        
        return jsonSuccessResponse('success', [
            'national_promoter' => $national_promoter,
            'national_level' => $national_level,
            'chip_amount' => $player->national_promoter->chip_amount,
            'next_chip_amount' => isset($nextLevel->must_chip_amount) ? $nextLevel->must_chip_amount : 0,
            'next_national_promoter' => isset($nextLevel->national_level->name) ? '等级' . $nextLevel->national_level->name : '满级',
            'next_national_level' => isset($nextLevel->level) ? $nextLevel->level : '',
            'next_level_need' => isset($nextLevel) ? bcmul(bcdiv(bcsub($nextLevel->must_chip_amount,
                    $player->national_promoter->chip_amount, 2), $nextLevel->must_chip_amount, 2), 100,
                    0) . '%' : '0%',
        ]);
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 推广数据
     * @param Request $request
     * @return Response
     * @throws Exception
     * @throws PlayerCheckException
     * @throws GameException
     */
    public function promoterData(Request $request): Response
    {
        $player = checkPlayer();
        if (isset($player->national_promoter)) {
            $national_promoter = '等级' . $player->national_promoter->level_list->national_level->name;
            $national_level = $player->national_promoter->level_list->level;
        } else {
            $national_promoter = '';
            $national_level = '';
        }
        $channel = Cache::get("channel_" . $request->site_id);
        return jsonSuccessResponse('success', [
            'recommend_code' => $player->recommend_code,
            'pending_amount' => (float)$player->national_promoter->pending_amount,
            'settlement_amount' => (float)$player->national_promoter->settlement_amount,
            'national_promoter' => $national_promoter,
            'national_level' => $national_level,
            'damage_rebate_ratio' => $player->national_promoter->level_list->damage_rebate_ratio . '%',
            'recharge_ratio' => $player->national_promoter->level_list->recharge_ratio,
            'recommend_url' => $channel['domain'] . '/?promoter_code=' . $player->recommend_code,
        ]);
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 收益历史
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public function profitRecord(Request $request): Response
    {
        $player = checkPlayer();
        $data = $request->all();
        $validator = v::key('date', v::stringType()->setName(trans('date', [], 'message')), false)
            ->key('page', v::intVal()->setName(trans('page', [], 'message')))
            ->key('size', v::intVal()->setName(trans('size', [], 'message')));
        
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        $record = NationalProfitRecord::query()->with(['player:id,name'])
            ->where('recommend_id', $player->id)
            ->select(['id', 'uid', 'money', 'type', 'updated_at', 'status'])
            ->orderBy('updated_at', 'desc')
            ->forPage($data['page'], $data['size']);
        if (!empty($data['date'])) {
            $record->whereDate('updated_at', $data['date']);
        }
        $list = $record->get()->toArray();
        foreach ($list as &$item) {
            if($item['status'] == 0){
                $item['updated_at'] = '未结算';
            }
        }
        return jsonSuccessResponse('success', $list);
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 全民代理规则
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public function rulesList(): Response
    {
        $player = checkPlayer();
        $list = LevelList::query()->where('department_id', $player->department_id)
            ->with('national_level:id,name')
            ->select(['level_id', 'level', 'damage_rebate_ratio', 'recharge_ratio'])
            ->orderByDesc('id')
            ->get()->toArray();
        foreach ($list as &$item) {
            $item['national_level']['name'] = '等级' . $item['national_level']['name'];
        }
        
        return jsonSuccessResponse('success', $list);
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 我的用户
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public function subPlayersList(Request $request): Response
    {
        $player = checkPlayer();
        $data = $request->all();
        $validator = v::key('name', v::stringType()->setName(trans('date', [], 'message')), false)
            ->key('page', v::intVal()->setName(trans('page', [], 'message')))
            ->key('size', v::intVal()->setName(trans('size', [], 'message')));
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        $record = NationalPromoter::query()->with(['player:id,name,uuid,avatar'])
            ->leftjoin('national_profit_record', 'national_profit_record.uid', '=', 'national_promoter.uid')
            ->where('national_promoter.recommend_id', $player->id)
            ->where('national_promoter.status', 1)
            ->selectRaw("national_promoter.uid,national_promoter.created_at,IFNULL(sum(national_profit_record.money), 0) as money")
            ->orderBy('national_promoter.created_at', 'desc')
            ->groupBy('national_promoter.uid', 'national_promoter.created_at')
            ->forPage($data['page'], $data['size']);
        if (!empty($data['name'])) {
            $record->whereHas('player', function ($query) use ($data) {
                $query->where('name', 'like', '%' . $data['name'] . '%');
            });
        }
        
        return jsonSuccessResponse('success', [
            'invite_num' => $player->national_promoter->invite_num,
            'list' => $record->get()->toArray()
        ]);
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 全民代理邀请规则
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public function inviteRules(): Response
    {
        checkPlayer();
        return jsonSuccessResponse('success', NationalInvite::query()->where('status', '1')
            ->select(['min', 'max', 'interval', 'money'])
            ->get()->toArray());
    }
}
