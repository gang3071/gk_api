<?php

namespace app\model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Class LotteryTicketActivity 摸奖券活动模型
 *
 * @property int $id
 * @property int $department_id
 * @property string $name 活动名称
 * @property string $description 活动描述
 * @property string $cover_image 封面图片
 * @property string $start_time 开始时间
 * @property string $end_time 结束时间
 * @property int $status 状态
 * @property string $created_at
 * @property string $updated_at
 */
class LotteryTicketActivity extends Model
{
    // 状态常量（✅ 已统一 gk_admin）
    const STATUS_NOT_STARTED = 0; // 未开始
    const STATUS_ONGOING = 1;     // 进行中
    const STATUS_ENDED = 2;       // 已结束
    const STATUS_CLOSED = 3;      // 已关闭
    const STATUS_PREHEATING = 4;  // 预热期
    const STATUS_BETTING = 5;     // 打码中
    const STATUS_DRAWING = 6;     // 开奖中
    const STATUS_DRAWN = 7;       // 已开奖待发放

    // 直播状态常量
    const LIVE_STATUS_NOT_STARTED = 0; // 未开播
    const LIVE_STATUS_ONGOING = 1;      // 直播中
    const LIVE_STATUS_ENDED = 2;        // 已结束

    protected $table = 'lottery_ticket_activity';

    protected $guarded = [];

    /**
     * 奖品等级
     */
    public function prizeLevels(): HasMany
    {
        return $this->hasMany(LotteryTicketPrizeLevel::class, 'activity_id');
    }

    /**
     * 奖券
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(LotteryTicket::class, 'activity_id');
    }
}
