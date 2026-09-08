<?php

namespace app\model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class DishOrderItem
 * @property int id
 * @property int order_id 訂單ID
 * @property int dish_id 餐點ID
 * @property string dish_title 餐點名稱（快照）
 * @property string dish_picture 餐點圖片（快照）
 * @property int quantity 數量
 * @property float price 單價（快照）
 * @property float subtotal 小計
 * @property string remark 備註
 * @property string created_at
 * @property string updated_at
 */
class DishOrderItem extends Model
{
    protected $table = 'dish_order_item';

    protected $guarded = [];

    /**
     * 所屬訂單
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(DishOrder::class, 'order_id');
    }

    /**
     * 餐點
     */
    public function dish(): BelongsTo
    {
        return $this->belongsTo(Dish::class, 'dish_id');
    }
}
