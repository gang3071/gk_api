<?php

namespace app\model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Class DishOrder
 * @property int id
 * @property string order_no 訂單編號
 * @property int player_id 玩家ID
 * @property int department_id 渠道ID
 * @property int admin_user_id 門店ID
 * @property float total_amount 訂單總金額(積分)
 * @property int status 狀態（0=待確認 1=已確認 2=製作中 3=已完成 4=已取消）
 * @property string remark 備註
 * @property string created_at
 * @property string updated_at
 */
class DishOrder extends Model
{
    protected $table = 'dish_order';

    protected $guarded = [];

    const STATUS_PENDING = 0;    // 待確認
    const STATUS_CONFIRMED = 1;  // 已確認
    const STATUS_COOKING = 2;    // 製作中
    const STATUS_COMPLETED = 3;  // 已完成
    const STATUS_CANCELLED = 4;  // 已取消

    /**
     * 玩家
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'player_id')->withTrashed();
    }

    /**
     * 訂單明細
     */
    public function items(): HasMany
    {
        return $this->hasMany(DishOrderItem::class, 'order_id');
    }

    /**
     * 生成訂單編號
     */
    public static function generateOrderNo(): string
    {
        return 'DO' . date('YmdHis') . str_pad(mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);
    }
}
