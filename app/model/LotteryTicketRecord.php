<?php

namespace app\model;

use Illuminate\Database\Eloquent\Model;

/**
 * Class LotteryTicketRecord 摸奖券中奖记录模型
 *
 * @property int $id
 * @property int $activity_id
 * @property string $activity_name
 * @property int $player_id
 * @property string $ticket_no
 * @property int $prize_level
 * @property string $prize_level_name
 * @property float $prize_amount
 * @property int $status 状态
 * @property string|null $granted_at 发放时间
 * @property string $created_at
 * @property string $updated_at
 */
class LotteryTicketRecord extends Model
{
    // 状态常量（✅ 已统一 gk_admin）
    const STATUS_PENDING = 0;      // 待发放
    const STATUS_CLAIMED = 1;      // 已发放
    const STATUS_EXPIRED = 2;      // 已过期
    const STATUS_CANCELLED = 3;    // 已取消
    const STATUS_PROCESSING = 4;   // 发放中
    const STATUS_FAILED = 5;       // 发放失败

    // 兼容旧常量
    const STATUS_GRANTED = 1;      // 已发放（兼容旧代码）

    // 奖品类型常量
    const PRIZE_TYPE_CASH = 'cash';       // 现金
    const PRIZE_TYPE_BONUS = 'bonus';     // 红利
    const PRIZE_TYPE_ITEM = 'item';       // 实物
    const PRIZE_TYPE_POINTS = 'points';   // 积分
    const PRIZE_TYPE_EMPTY = 'empty';     // 未中奖

    protected $table = 'lottery_ticket_record';

    protected $guarded = [];
}
