<?php

namespace app\model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 玩家积分主表模型
 *
 * @property int $id 主键ID
 * @property int $player_id 玩家ID
 * @property int $department_id 渠道ID
 * @property int $total_points 总积分（历史累计）
 * @property int $available_points 可用积分（当前余额）
 * @property int $frozen_points 冻结积分
 * @property int $used_points 已使用积分
 * @property int $version 乐观锁版本号
 * @property string $created_at 创建时间
 * @property string $updated_at 更新时间
 *
 * @property-read Player $player 关联玩家
 * @property-read PlayerPointsRecord[] $records 积分变动记录
 *
 * @package app\model
 */
class PlayerPoints extends Model
{
    protected $table = 'player_points';

    protected $fillable = [
        'player_id',
        'department_id',
        'total_points',
        'available_points',
        'frozen_points',
        'used_points',
        'version',
    ];

    protected $casts = [
        'player_id' => 'integer',
        'department_id' => 'integer',
        'total_points' => 'integer',
        'available_points' => 'integer',
        'frozen_points' => 'integer',
        'used_points' => 'integer',
        'version' => 'integer',
    ];

    /**
     * 关联玩家
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'player_id', 'id');
    }

    /**
     * 关联积分变动记录
     */
    public function records(): HasMany
    {
        return $this->hasMany(PlayerPointsRecord::class, 'player_id', 'player_id');
    }

    /**
     * 获取或创建玩家积分记录
     *
     * @param int $playerId 玩家ID
     * @param int $departmentId 渠道ID
     * @return PlayerPoints
     */
    public static function getOrCreate(int $playerId, int $departmentId = 0): PlayerPoints
    {
        return static::firstOrCreate(
            ['player_id' => $playerId],
            [
                'department_id' => $departmentId,
                'total_points' => 0,
                'available_points' => 0,
                'frozen_points' => 0,
                'used_points' => 0,
                'version' => 0,
            ]
        );
    }

    // ========================================
    // 说明：积分增加/扣除逻辑统一在 PlayerPointsService 中实现
    // 不在模型层提供业务方法，避免逻辑分散
    // ========================================
}
