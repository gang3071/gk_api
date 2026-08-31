<?php

namespace app\api\controller\v1;

use app\exception\PlayerCheckException;
use app\model\Dish;
use app\model\DishOrder;
use app\model\DishOrderItem;
use Exception;
use Respect\Validation\Exceptions\AllOfException;
use Respect\Validation\Validator as v;
use support\Db;
use support\Request;
use support\Response;
use Webman\RateLimiter\Annotation\RateLimiter;

class DishAdminController
{
    #[RateLimiter(limit: 10)]
    /**
     * 店家訂單列表（按門店 department_id 篩選）
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException
     */
    public function orderList(Request $request): Response
    {
        checkPlayer();
        $data = $request->all();
        $departmentId = $request->department_id;

        $page = max(1, intval($data['page'] ?? 1));
        $size = min(50, max(1, intval($data['size'] ?? 20)));

        $query = DishOrder::query()
            ->with(['player:id,name,phone', 'items'])
            ->where('department_id', $departmentId);

        // 可選：按狀態篩選
        if (isset($data['status']) && $data['status'] !== '') {
            $query->where('status', intval($data['status']));
        }

        $orders = $query->orderBy('id', 'desc')
            ->forPage($page, $size)
            ->get();

        return jsonSuccessResponse('success', [
            'list' => $orders
        ]);
    }

    #[RateLimiter(limit: 10)]
    /**
     * 訂單詳情（店家用）
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException
     */
    public function orderDetail(Request $request): Response
    {
        checkPlayer();
        $data = $request->all();
        $departmentId = $request->department_id;

        $validator = v::key('order_id', v::intVal()->setName(trans('order_id', [], 'message')));
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }

        $order = DishOrder::query()
            ->with(['player:id,name,phone', 'items'])
            ->where('id', $data['order_id'])
            ->where('department_id', $departmentId)
            ->first();

        if (empty($order)) {
            return jsonFailResponse(trans('dish_order_not_found', [], 'message'));
        }

        return jsonSuccessResponse('success', [
            'order' => $order
        ]);
    }

    #[RateLimiter(limit: 5)]
    /**
     * 更新訂單狀態（出餐流程）
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public function updateStatus(Request $request): Response
    {
        checkPlayer();
        $data = $request->all();
        $departmentId = $request->department_id;

        $validator = v::key('order_id', v::intVal()->setName(trans('order_id', [], 'message')))
            ->key('status', v::intVal()->setName(trans('status', [], 'message')));

        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }

        $newStatus = intval($data['status']);
        $validStatuses = [
            DishOrder::STATUS_CONFIRMED,
            DishOrder::STATUS_COOKING,
            DishOrder::STATUS_COMPLETED,
            DishOrder::STATUS_CANCELLED,
        ];

        if (!in_array($newStatus, $validStatuses)) {
            return jsonFailResponse(trans('dish_order_invalid_status', [], 'message'));
        }

        $order = DishOrder::query()
            ->where('id', $data['order_id'])
            ->where('department_id', $departmentId)
            ->first();

        if (empty($order)) {
            return jsonFailResponse(trans('dish_order_not_found', [], 'message'));
        }

        // 狀態流轉校驗
        $allowedTransitions = [
            DishOrder::STATUS_PENDING => [DishOrder::STATUS_CONFIRMED, DishOrder::STATUS_CANCELLED],
            DishOrder::STATUS_CONFIRMED => [DishOrder::STATUS_COOKING, DishOrder::STATUS_CANCELLED],
            DishOrder::STATUS_COOKING => [DishOrder::STATUS_COMPLETED],
        ];

        if (!in_array($newStatus, $allowedTransitions[$order->status] ?? [])) {
            return jsonFailResponse(trans('dish_order_status_transition_error', [], 'message'));
        }

        $order->status = $newStatus;
        $order->save();

        return jsonSuccessResponse('success', [
            'order_id' => $order->id,
            'status' => $order->status,
        ]);
    }
}
