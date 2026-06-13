<?php

namespace app\model;

use Illuminate\Database\Eloquent\Model;

/**
 * Class LotteryTicketBetProgress 摸奖券打码进度模型
 *
 * @property int $id
 * @property int $activity_id
 * @property int $player_id
 * @property int $vip_level_id
 * @property float $bet_amount_required 所需打码量
 * @property float $current_bet_amount 当前打码量
 * @property float $progress_percent 完成百分比
 * @property float $remaining_bet_amount 剩余打码量
 * @property int $cycles_completed 已完成周期数
 * @property int $total_tickets_issued 已发券总数
 * @property int $ticket_count_per_cycle 每周期发券数
 * @property int $status 状态
 * @property string $created_at
 * @property string $updated_at
 */
class LotteryTicketBetProgress extends Model
{
    protected $table = 'lottery_ticket_bet_progress';

    protected $guarded = [];
}
