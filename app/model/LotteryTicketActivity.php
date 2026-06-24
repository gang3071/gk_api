<?php

namespace app\model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Class LotteryTicketActivity 摸奖券活动模型
 *
 * @property int $id 主键ID
 * @property int $department_id 所属渠道部门ID
 * @property string $name 活动名称
 * @property string|null $description 活动说明
 * @property string|null $cover_image 活动封面图片URL
 * @property string|null $live_url 直播流地址
 * @property int $live_status 直播状态 0=未开播 1=直播中 2=已结束
 * @property string|null $status_history 状态变更历史(JSON)
 * @property string $start_time 活动开始时间
 * @property string $end_time 活动结束时间
 * @property string $draw_method 开奖方式：ball=摇球，manual=手动录入
 * @property string|null $draw_completed_at 开奖完成时间
 * @property string|null $prize_distributed_at 奖励发放完成时间
 * @property float $total_prize_amount 总奖金金额
 * @property float $distributed_prize_amount 已发放奖金金额
 * @property int $auto_draw 是否自动开奖 0=否 1=是
 * @property int $status 活动状态(0:未开始,1:进行中,2:已结束,3:已关闭,5:待开奖,6:开奖中)
 * @property int $total_tickets 总发放摸奖券数量
 * @property int $used_tickets 已使用摸奖券数量
 * @property int $current_ticket_no 当前已发券数
 * @property int $max_ticket_no 最大可发券数（默认100万张）
 * @property array|string $prize_config 奖品配置(JSON格式)
 * @property string $created_at 创建时间
 * @property string $updated_at 更新时间
 * @property string|null $deleted_at 删除时间
 *
 * @property LotteryTicketPrizeLevel[] $prizeLevels
 * @property LotteryTicket[] $tickets
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

    protected $casts = [
        'prize_config' => 'array',
        'status_history' => 'array',
        'total_prize_amount' => 'decimal:2',
        'distributed_prize_amount' => 'decimal:2',
    ];

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
