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
    // 状态常量（6个核心状态，与 gk_admin 保持一致）
    const STATUS_NOT_STARTED = 0;      // 未开始
    const STATUS_ONGOING = 1;          // 进行中（玩家打码获券阶段）
    const STATUS_ENDED = 2;            // 已结束（完全结束，所有流程完成）
    const STATUS_CLOSED = 3;           // 已关闭（手动关闭，异常终止）
    const STATUS_PENDING_DRAW = 5;     // 待开奖（end_time 到达，等待管理员开奖）
    const STATUS_DRAWING = 6;          // 开奖中（管理员摇球阶段）

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
