<?php

namespace app\api\controller\external;

use app\model\GameType;
use app\model\MachineStrategy;
use Exception;
use Respect\Validation\Exceptions\AllOfException;
use Respect\Validation\Validator as v;
use support\Request;
use support\Response;
use Webman\RateLimiter\Annotation\RateLimiter;

class StrategyApiController
{
    #[RateLimiter(limit: 5)]
    /**
     * 攻略列表
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function strategyList(Request $request): Response
    {
        $data = $request->all();
        $validator = v::key('type', v::in([GameType::TYPE_SLOT, GameType::TYPE_STEEL_BALL])->notEmpty()->setName(trans('strategy_type', [], 'message')), false)
            ->key('page', v::intVal()->setName(trans('page', [], 'message')))
            ->key('name', v::stringType()->setName(trans('strategy_name', [], 'message')), false)
            ->key('size', v::intVal()->setName(trans('size', [], 'message')));

        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }
        $strategyModel = MachineStrategy::where('status', 1);
        if (isset($data['name']) && !empty($data['name'])) {
            $strategyModel = $strategyModel->where('name', 'LIKE', "%" . $data['name'] . "%");
        }
        $strategyList = $strategyModel->where('type', $data['type'] ?? GameType::TYPE_SLOT)
            ->orderBy('sort')
            ->orderBy('id', 'desc')
            ->select(['id', 'name', 'thumbnail'])
            ->forPage($data['page'], $data['size'])
            ->get()
            ->toArray();

        return jsonSuccessResponse('success', $strategyList);
    }
    
    #[RateLimiter(limit: 5)]
    /**
     * 获取玩家信息
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function strategyInfo(Request $request): Response
    {
        $data = $request->all();
        $validator = v::key('id', v::intVal()->notEmpty()->setName(trans('strategy_id', [], 'message')));

        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }

        return jsonSuccessResponse('success', MachineStrategy::where('id', $data['id'])->first()->toArray());
    }
}
