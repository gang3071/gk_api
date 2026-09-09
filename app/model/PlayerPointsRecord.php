<?php

namespace app\model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 玩家积分变动记录模型
 *
 * ⚠️ 重要：此表仅记录重要操作，不记录每次打码明细
 *
 * @property int $id 主键ID
 * @property int $player_id 玩家ID
 * @property int $department_id 渠道ID
 * @property int $type 类型
 * @property string $source 来源
 * @property int $points 积分变动
 * @property int $points_before 变动前积分
 * @property int $points_after 变动后积分
 * @property float|null $bet_amount 汇总打码量（仅打码汇总时填写）
 * @property string|null $summary_period 汇总周期
 * @property string|null $summary_date 汇总日期
 * @property int|null $game_count 游戏局数
 * @property int|null $exchange_order_id 兑换订单ID
 * @property string|null $exchange_item 兑换物品
 * @property string|null $rule_code 规则代码
 * @property float|null $rate 转换比率
 * @property string|null $remark 备注
 * @property string|null $batch_id 批次ID
 * @property int|null $admin_id 操作员ID（后台调整时填写）
 * @property string|null $admin_name 操作员名称（后台调整时填写）
 * @property string|null $admin_ip 操作IP地址（后台调整时填写）
 * @property string $created_at 创建时间
 *
 * @property-read Player $player 关联玩家
 *
 * @package app\model
 */
class PlayerPointsRecord extends Model
{
    protected $table = 'player_points_record';

    // 禁用 updated_at
    const UPDATED_AT = null;

    protected $fillable = [
        'player_id',
        'department_id',
        'type',
        'source',
        'points',
        'points_before',
        'points_after',
        'bet_amount',
        'summary_period',
        'summary_date',
        'game_count',
        'exchange_order_id',
        'exchange_item',
        'rule_code',
        'rate',
        'remark',
        'batch_id',
        'admin_id',
        'admin_name',
        'admin_ip',
    ];

    protected $casts = [
        'player_id' => 'integer',
        'department_id' => 'integer',
        'type' => 'integer',
        'points' => 'integer',
        'points_before' => 'integer',
        'points_after' => 'integer',
        'bet_amount' => 'decimal:2',
        'game_count' => 'integer',
        'exchange_order_id' => 'integer',
        'rate' => 'decimal:4',
        'admin_id' => 'integer',
    ];

    // 积分变动类型常量
    const TYPE_BETTING_SUMMARY = 1;  // 打码汇总（每日/每周/每月）
    const TYPE_EXCHANGE = 2;         // 兑换消耗
    const TYPE_EXPIRE = 3;           // 过期扣除
    const TYPE_ADMIN_ADJUST = 4;     // 后台调整
    const TYPE_ACTIVITY = 5;         // 活动奖励
    const TYPE_REFUND = 6;           // 订单退款

    // 来源常量
    const SOURCE_BETTING_SUMMARY = 'betting_summary'; // 打码汇总
    const SOURCE_EXCHANGE = 'exchange';               // 兑换
    const SOURCE_EXPIRE = 'expire';                   // 过期
    const SOURCE_ADMIN = 'admin';                     // 后台
    const SOURCE_ACTIVITY = 'activity';               // 活动
    const SOURCE_REFUND = 'refund';                   // 退款

    // 汇总周期常量
    const PERIOD_DAILY = 'daily';      // 每日
    const PERIOD_WEEKLY = 'weekly';    // 每周
    const PERIOD_MONTHLY = 'monthly';  // 每月

    /**
     * 类型描述映射
     */
    public static $typeMap = [
        self::TYPE_BETTING_SUMMARY => '打码汇总',
        self::TYPE_EXCHANGE => '兑换消耗',
        self::TYPE_EXPIRE => '过期扣除',
        self::TYPE_ADMIN_ADJUST => '后台调整',
        self::TYPE_ACTIVITY => '活动奖励',
        self::TYPE_REFUND => '订单退款',
    ];

    /**
     * 关联玩家
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'player_id', 'id');
    }

    /**
     * 获取类型描述
     */
    public function getTypeDescAttribute(): string
    {
        return self::$typeMap[$this->type] ?? '未知';
    }

    /**
     * 创建打码汇总记录
     *
     * @param array $data
     * @return PlayerPointsRecord
     */
    public static function createBettingSummaryRecord(array $data): PlayerPointsRecord
    {
        return static::create(array_merge([
            'type' => self::TYPE_BETTING_SUMMARY,
            'source' => self::SOURCE_BETTING_SUMMARY,
        ], $data));
    }

    /**
     * 创建兑换消耗记录
     *
     * @param array $data
     * @return PlayerPointsRecord
     */
    public static function createExchangeRecord(array $data): PlayerPointsRecord
    {
        return static::create(array_merge([
            'type' => self::TYPE_EXCHANGE,
            'source' => self::SOURCE_EXCHANGE,
        ], $data));
    }

    /**
     * 创建过期扣除记录
     *
     * @param array $data
     * @return PlayerPointsRecord
     */
    public static function createExpireRecord(array $data): PlayerPointsRecord
    {
        return static::create(array_merge([
            'type' => self::TYPE_EXPIRE,
            'source' => self::SOURCE_EXPIRE,
        ], $data));
    }

    /**
     * 创建订单退款记录
     *
     * @param array $data
     * @return PlayerPointsRecord
     */
    public static function createRefundRecord(array $data): PlayerPointsRecord
    {
        return static::create(array_merge([
            'type' => self::TYPE_REFUND,
            'source' => self::SOURCE_REFUND,
        ], $data));
    }
}
