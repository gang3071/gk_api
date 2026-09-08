<?php

namespace app\model;

use Illuminate\Database\Eloquent\Model;

/**
 * Class Dish
 * @property int id
 * @property int department_id 渠道ID
 * @property int admin_user_id 門店ID
 * @property int category_id 類別ID
 * @property string title 餐點名稱
 * @property string content 餐點描述
 * @property string picture 餐點圖片
 * @property float price 金額(積分)
 * @property int status 狀態（1=啟用 0=停用）
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
    const STATUS_DISABLED = 0;  // 停用

    const TOP_NO = 0;   // 沒置頂
    const TOP_YES = 1;  // 置頂
}
