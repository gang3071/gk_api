<?php

namespace app\model;

use Illuminate\Database\Eloquent\Model;

/**
 * Class LotteryTicketPrizeLevel 摸奖券奖品等级模型
 *
 * @property int $id
 * @property int $activity_id
 * @property int $level_rank 等级排序
 * @property string $level_name 等级名称
 * @property string $prize_type 奖品类型
 * @property float $prize_amount 奖品金额
 * @property int $prize_count 奖品数量
 * @property string $created_at
 * @property string $updated_at
 */
class LotteryTicketPrizeLevel extends Model
{
    protected $table = 'lottery_ticket_prize_level';

    protected $guarded = [];
}
