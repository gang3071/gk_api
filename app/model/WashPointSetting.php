<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class WashPointSetting
 * @property int id 主键
 * @property int admin_user_id 店家AdminUser ID
 * @property float wash_1 洗分选项1
 * @property float wash_2 洗分选项2
 * @property float wash_3 洗分选项3
 * @property float wash_4 洗分选项4
 * @property float wash_5 洗分选项5
 * @property float wash_6 洗分选项6
 * @property float default_wash_point 默认洗分基数
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 *
 * @property AdminUser adminUser 店家账号
 * @package app\model
 */
class WashPointSetting extends Model
{
    use HasDateTimeFormatter;

    protected $table = 'wash_point_setting';

    protected $fillable = [
        'admin_user_id',
        'wash_1',
        'wash_2',
        'wash_3',
        'wash_4',
        'wash_5',
        'wash_6',
        'default_wash_point',
    ];

    protected $casts = [
        'admin_user_id' => 'integer',
        'wash_1' => 'float',
        'wash_2' => 'float',
        'wash_3' => 'float',
        'wash_4' => 'float',
        'wash_5' => 'float',
        'wash_6' => 'float',
        'default_wash_point' => 'float',
    ];

    /**
     * 店家账号
     * @return BelongsTo
     */
    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }

    /**
     * 获取洗分配置数组
     * @return array
     */
    public function getWashPointsAttribute(): array
    {
        $washPoints = [];
        for ($i = 1; $i <= 6; $i++) {
            $key = 'wash_' . $i;
            if ($this->$key > 0) {
                $washPoints[] = $this->$key;
            }
        }
        return $washPoints;
    }

    /**
     * 获取有效的洗分基数
     *
     * 特殊规则：
     * - 配置为 0：表示只洗整数部分（小数保留）
     * - 配置为 正数：按配置值的倍数洗分
     * - 未配置：返回默认值 100
     *
     * @return float
     */
    public function getEffectiveWashPoint(): float
    {
        // 优先返回默认洗分基数（包括显式配置的0）
        if ($this->default_wash_point !== null) {
            return floatval($this->default_wash_point);
        }

        // 如果没有默认值，返回第一个设置的洗分选项（包括0）
        for ($i = 1; $i <= 6; $i++) {
            $key = 'wash_' . $i;
            if ($this->$key !== null && $this->$key >= 0) {
                return floatval($this->$key);
            }
        }

        // 如果都没有，返回100作为兜底值
        return 100.0;
    }
}
