<?php

namespace app\model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class LotteryTicket 摸奖券模型
 *
 * @property int $id
 * @property int $player_id
 * @property int $department_id
 * @property int $activity_id
 * @property string $ticket_no 奖券编号(6位数字)
 * @property int $status 状态
 * @property int $source 来源
 * @property string $issued_at 发放时间
 * @property string $expired_at 过期时间
 * @property int|null $prize_level 中奖等级
 * @property float|null $prize_amount 中奖金额
 * @property string $created_at
 * @property string $updated_at
 */
class LotteryTicket extends Model
{
    // 状态常量（✅ 已统一 gk_admin）
    const STATUS_UNUSED = 0;    // 未使用
    const STATUS_USED = 1;      // 已使用
    const STATUS_EXPIRED = 2;   // 已过期

    // 来源常量（✅ 已统一 gk_admin）
    const SOURCE_RECHARGE = 'recharge';  // 充值赠送
    const SOURCE_ACTIVITY = 'activity';  // 活动赠送
    const SOURCE_MANUAL = 'manual';      // 手动发放

    protected $table = 'lottery_ticket';

    protected $guarded = [];

    /**
     * 所属玩家
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'player_id');
    }

    /**
     * 所属活动
     */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(LotteryTicketActivity::class, 'activity_id');
    }
}
