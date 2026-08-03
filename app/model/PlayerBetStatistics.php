<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 玩家打码量统计模型
 *
 * @property int $id ID
 * @property int $player_id 玩家ID
 * @property string $stat_type 统计类型：machine=实体机台, game=电子游戏
 * @property string $dimension 维度：daily=日, weekly=周, monthly=月
 * @property string $stat_date 统计日期：2026-07-31, 2026-W31, 2026-07
 * @property float $bet_amount 打码量（元）
 * @property int $bet_count 投注次数
 * @property string $created_at 创建时间
 * @property string $updated_at 更新时间
 *
 * @property Player $player 玩家
 * @package app\model
 */
class PlayerBetStatistics extends Model
{
    use HasDateTimeFormatter;

    /**
     * 表名
     * @var string
     */
    protected $table = 'player_bet_statistics';

    /**
     * 可批量赋值字段
     * @var array
     */
    protected $fillable = [
        'player_id',
        'stat_type',
        'dimension',
        'stat_date',
        'bet_amount',
        'bet_count',
    ];

    /**
     * 字段类型转换
     * @var array
     */
    protected $casts = [
        'player_id' => 'int',
        'bet_amount' => 'float',
        'bet_count' => 'int',
    ];

    /**
     * 启用时间戳
     * @var bool
     */
    public $timestamps = true;

    /**
     * 统计类型常量
     */
    const TYPE_MACHINE = 'machine';  // 实体机台
    const TYPE_GAME = 'game';        // 电子游戏

    /**
     * 统计维度常量
     */
    const DIMENSION_DAILY = 'daily';      // 日
    const DIMENSION_WEEKLY = 'weekly';    // 周
    const DIMENSION_MONTHLY = 'monthly';  // 月

    /**
     * 关联玩家
     *
     * @return BelongsTo
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'player_id', 'id');
    }
}
