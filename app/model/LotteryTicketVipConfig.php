<?php

namespace app\model;

use Illuminate\Database\Eloquent\Model;

/**
 * Class LotteryTicketVipConfig 摸奖券VIP配置模型
 *
 * @property int $id
 * @property int $activity_id
 * @property int $vip_level_id
 * @property float $bet_amount_required
 * @property int $ticket_count
 * @property int $status
 * @property string $created_at
 * @property string $updated_at
 */
class LotteryTicketVipConfig extends Model
{
/**
     * 状态常量
     */
    const STATUS_DISABLED = 0;
const STATUS_ENABLED = 1;
    protected $table = 'lottery_ticket_vip_config'; // 禁用
        protected $guarded = [];  // 启用

    /**
     * 关联VIP等级
     */
    public function vipLevel()
    {
        return $this->belongsTo(VipLevel::class, 'vip_level_id', 'id');
    }

    /**
     * 关联活动
     */
    public function activity()
    {
        return $this->belongsTo(LotteryTicketActivity::class, 'activity_id', 'id');
    }
}
