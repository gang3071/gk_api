<?php

namespace app\api\controller\v1;

use app\exception\PlayerCheckException;
use app\model\Dish;
use app\model\DishCategory;
use app\model\DishOrder;
use app\model\DishOrderItem;
use Carbon\Carbon;
use Exception;
use Respect\Validation\Exceptions\AllOfException;
use Respect\Validation\Validator as v;
use support\Db;
use support\Request;
use support\Response;
use Webman\RateLimiter\Annotation\RateLimiter;

class DishController
{
    #[RateLimiter(limit: 10)]
    /**
     * 菜品分類列表
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException
     */
    public function categoryList(Request $request): Response
    {
        checkPlayer();
        $departmentId = $request->department_id;

        $list = DishCategory::query()
            ->where('department_id', $departmentId)
            ->where('status', DishCategory::STATUS_ACTIVE)
            ->select(['id', 'title', 'content', 'picture', 'sort', 'top'])
            ->orderBy('top', 'desc')
            ->orderBy('sort', 'desc')
            ->orderBy('id', 'asc')
            ->get();

        return jsonSuccessResponse('success', [
            'list' => $list
        ]);
    }

    #[RateLimiter(limit: 10)]
    /**
     * 菜品列表
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException
     */
    public function dishList(Request $request): Response
    {
        checkPlayer();
        $data = $request->all();
        $departmentId = $request->department_id;

        $query = Dish::query()
            ->where('department_id', $departmentId)
            ->where('status', Dish::STATUS_ACTIVE)
            ->select(['id', 'title', 'content', 'picture', 'price', 'sort', 'top', 'remark', 'daily_limit']);

        // 可選：按分類篩選
        if (!empty($data['category_id'])) {
            $query->where('category_id', intval($data['category_id']));
        }

        $list = $query->orderBy('top', 'desc')
            ->orderBy('sort', 'desc')
            ->orderBy('id', 'asc')
            ->get();

        return jsonSuccessResponse('success', [
            'list' => $list
        ]);
    }

    #[RateLimiter(limit: 5)]
    /**
     * 客人下單
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException|Exception
     */
    public function dishOrder(Request $request): Response
    {
        $player = checkPlayer();
        $data = $request->all();
        $departmentId = $request->department_id;

        // 參數驗證
        $validator = v::key('dishes', v::arrayVal()->notEmpty()->setName(trans('dishes', [], 'message')))
            ->key('remark', v::stringType()->setName(trans('remark', [], 'message')), false);

        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }

        $dishes = $data['dishes'];
        if (empty($dishes)) {
            return jsonFailResponse(trans('dish_order_empty', [], 'message'));
        }

        // 驗證每道菜品
        $dishIds = array_column($dishes, 'dish_id');
        $dishList = Dish::query()
            ->whereIn('id', $dishIds)
            ->where('department_id', $departmentId)
            ->where('status', Dish::STATUS_ACTIVE)
            ->get()
            ->keyBy('id');

        $totalAmount = '0.00';
        $orderItems = [];

        foreach ($dishes as $item) {
            $dishId = $item['dish_id'] ?? 0;
            $quantity = max(1, intval($item['quantity'] ?? 1));

            if (!$dishList->has($dishId)) {
                return jsonFailResponse(trans('dish_not_found', [], 'message'), ['dish_id' => $dishId]);
            }

            $dish = $dishList->get($dishId);
            $subtotal = bcmul($dish->price, $quantity, 2);
            $totalAmount = bcadd($totalAmount, $subtotal, 2);

            $orderItems[] = [
                'dish_id' => $dish->id,
                'dish_title' => $dish->title,
                'dish_picture' => $dish->picture,
                'quantity' => $quantity,
                'price' => $dish->price,
                'subtotal' => $subtotal,
                'remark' => $item['remark'] ?? '',
            ];
        }

        // 每日限量校驗
        $today = Carbon::today()->toDateTimeString();
        $tomorrow = Carbon::tomorrow()->toDateTimeString();
        foreach ($dishes as $item) {
            $dishId = $item['dish_id'] ?? 0;
            $quantity = max(1, intval($item['quantity'] ?? 1));
            $dish = $dishList->get($dishId);

            if ($dish->daily_limit > 0) {
                // 查詢玩家今天已訂購該餐點的總數量
                $orderedToday = DishOrderItem::query()
                    ->where('dish_id', $dishId)
                    ->whereHas('order', function ($query) use ($player, $today, $tomorrow) {
                        $query->where('player_id', $player->id)
                            ->where('created_at', '>=', $today)
                            ->where('created_at', '<', $tomorrow)
                            ->where('status', '!=', DishOrder::STATUS_CANCELLED);
                    })
                    ->sum('quantity');

                if (bcadd($orderedToday, $quantity, 0) > $dish->daily_limit) {
                    return jsonFailResponse(trans('dish_daily_limit_exceeded', [], 'message'), [
                        'dish_id' => $dishId,
                        'dish_title' => $dish->title,
                        'daily_limit' => $dish->daily_limit,
                        'ordered_today' => intval($orderedToday),
                    ]);
                }
            }
        }

        Db::beginTransaction();
        try {
            $order = new DishOrder();
            $order->order_no = DishOrder::generateOrderNo();
            $order->player_id = $player->id;
            $order->department_id = $departmentId;
            $order->total_amount = $totalAmount;
            $order->status = DishOrder::STATUS_PENDING;
            $order->remark = $data['remark'] ?? '';
            $order->save();

            foreach ($orderItems as &$oi) {
                $oi['order_id'] = $order->id;
            }
            DishOrderItem::insert($orderItems);

            Db::commit();

            return jsonSuccessResponse('success', [
                'order_id' => $order->id,
                'order_no' => $order->order_no,
                'total_amount' => $order->total_amount,
            ]);
        } catch (Exception $e) {
            Db::rollBack();
            return jsonFailResponse(trans('system_error', [], 'message'));
        }
    }

    #[RateLimiter(limit: 10)]
    /**
     * 我的訂單列表
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException
     */
    public function myOrders(Request $request): Response
    {
        $player = checkPlayer();
        $data = $request->all();

        $page = max(1, intval($data['page'] ?? 1));
        $size = min(50, max(1, intval($data['size'] ?? 20)));

        $orders = DishOrder::query()
            ->with(['items'])
            ->where('player_id', $player->id)
            ->orderBy('id', 'desc')
            ->forPage($page, $size)
            ->get();

        return jsonSuccessResponse('success', [
            'list' => $orders
        ]);
    }

    #[RateLimiter(limit: 10)]
    /**
     * 訂單詳情
     * @param Request $request
     * @return Response
     * @throws PlayerCheckException
     */
    public function orderDetail(Request $request): Response
    {
        $player = checkPlayer();
        $data = $request->all();

        $validator = v::key('order_id', v::intVal()->setName(trans('order_id', [], 'message')));
        try {
            $validator->assert($data);
        } catch (AllOfException $e) {
            return jsonFailResponse(getValidationMessages($e));
        }

        $order = DishOrder::query()
            ->with(['items'])
            ->where('id', $data['order_id'])
            ->where('player_id', $player->id)
            ->first();

        if (empty($order)) {
            return jsonFailResponse(trans('dish_order_not_found', [], 'message'));
        }

        return jsonSuccessResponse('success', [
            'order' => $order
        ]);
    }
}
