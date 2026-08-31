<?php

namespace app\model;

use Illuminate\Database\Eloquent\Model;

/**
 * Class DishCategory
 * @property int id
 * @property int department_id 部門ID
 * @property string title 類別名稱
 * @property string content 類別描述
 * @property string picture 類別圖片
 * @property int status 狀態（1=啟用 2=停用）
 * @property int top 置頂（1=置頂 0=沒置頂）
 * @property int sort 排序
 * @property string remark 備註
 * @property string created_at
 * @property string updated_at
 */
class DishCategory extends Model
{
    protected $table = 'dish_category';

    protected $guarded = [];

    const STATUS_ACTIVE = 1;    // 啟用
    const STATUS_DISABLED = 2;  // 停用

    const TOP_NO = 0;   // 沒置頂
    const TOP_YES = 1;  // 置頂
}
