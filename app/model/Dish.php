<?php

namespace app\model;

use Illuminate\Database\Eloquent\Model;

/**
 * Class Dish
 * @property int id
 * @property int department_id 部門ID
 * @property int category_id 類別ID
 * @property string title 餐點名稱
 * @property string content 餐點描述
 * @property string picture 餐點圖片
 * @property float price 金額(積分)
 * @property int status 狀態（1=啟用 2=停用 3=售完）
 * @property int top 置頂（1=置頂 0=沒置頂）
 * @property int sort 排序
 * @property string remark 備註
 * @property int daily_limit 每人每天限量（0=不限量）
 * @property string created_at
 * @property string updated_at
 */
class Dish extends Model
{
    protected $table = 'dish';

    protected $guarded = [];

    const STATUS_ACTIVE = 1;    // 啟用
    const STATUS_DISABLED = 2;  // 停用
    const STATUS_SOLD_OUT = 3;  // 售完

    const TOP_NO = 0;   // 沒置頂
    const TOP_YES = 1;  // 置頂
}
