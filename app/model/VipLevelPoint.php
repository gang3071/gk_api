<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int id
 * @property int vip_level_id VIP等级ID
 * @property int platform_id 遊戲平台ID
 * @property int status 0=停用 1=啟用
 * @property float ratio_point 比例-積分
 * @property string created_at
 * @property string updated_at
 *
 * @property VipLevel vipLevel VIP等级
 * @property GamePlatform gamePlatform 遊戲平台
 * @package addons\webman\model
 */
class VipLevelPoint extends Model
{
    use HasDateTimeFormatter;

    protected $table = 'vip_level_point';

    /**
     * 时间转换
     * @param DateTimeInterface $date
     * @return string
     */
    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }
}
