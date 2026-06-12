<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class PlayerVipPeriod
 * @property int id 主键
 * @property int player_id 玩家ID
 * @property int vip_level_id VIP等级ID
 * @property string period_type 周期类型（upgrade=升级，retain=保级）
 * @property float start_bet_amount 周期开始时的总打码量
 * @property float period_bet_amount 周期内打码量总和
 * @property string started_at 周期开始时间
 * @property int status 状态（0=已过期，1=进行中，2=已完成）
 * @property string created_at 创建时间
 * @property string updated_at 更新时间
 * @property VipLevel vipLevel
 * @package app\model
 */
class PlayerVipPeriod extends Model
{
    use HasDateTimeFormatter;

    const STATUS_EXPIRED = 0;    // 已过期
    const STATUS_ONGOING = 1;    // 进行中
    const STATUS_COMPLETED = 2;  // 已完成

    const PERIOD_TYPE_UPGRADE = 'upgrade';  // 升级周期
    const PERIOD_TYPE_RETAIN = 'retain';    // 保级周期

    protected $table = 'player_vip_period';

    /**
     * 时间转换
     * @param DateTimeInterface $date
     * @return string
     */
    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }

    /**
     * VIP等级信息
     * @return BelongsTo
     */
    public function vipLevel(): BelongsTo
    {
        return $this->belongsTo(VipLevel::class, 'vip_level_id');
    }
}
