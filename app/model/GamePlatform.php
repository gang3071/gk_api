<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class GamePlatform
 * @property int id 主键
 * @property string $code 游戏平台code
 * @property string name 平台名称
 * @property string config 配置
 * @property float ratio 电子游戏平台比值
 * @property string logo logo
 * @property string picture picture
 * @property string cate_id 游戏类型
 * @property int display_mode 展示模式
 * @property int status 状态
 * @property int has_lobby 是否进入大厅
 * @property int maintenance_week 维护星期（1-7，1=周一，7=周日）
 * @property string maintenance_start_time 维护开始时间
 * @property string maintenance_end_time 维护结束时间
 * @property int maintenance_status 维护功能状态（0=关闭，1=开启）
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 * @property string deleted_at 删除时间
 *
 * @package app\model
 */
class GamePlatform extends Model
{
    use SoftDeletes, HasDateTimeFormatter;

    // 展示模式常量
    const DISPLAY_MODE_LANDSCAPE = 1; // 横版
    const DISPLAY_MODE_PORTRAIT = 2;  // 竖版
    const DISPLAY_MODE_ALL = 3;       // 全部支持
    protected $table = 'game_platform';

    /**
     * 判断平台当前是否处于维护中
     * @return int 0-未维护，1-维护中
     */
    public function getIsMaintenanceAttribute(): int
    {
        // 如果维护功能未开启，返回0
        if ($this->maintenance_status != 1) {
            return 0;
        }

        // 如果没有配置维护时间，返回0
        if (empty($this->maintenance_week) || empty($this->maintenance_start_time) || empty($this->maintenance_end_time)) {
            return 0;
        }

        // 获取当前时间
        $now = new \DateTime();
        $currentWeek = (int)$now->format('N'); // 1=周一，7=周日
        $currentTime = $now->format('H:i:s');

        // 检查是否是维护日
        if ($currentWeek != $this->maintenance_week) {
            return 0;
        }

        // 检查是否在维护时间段内
        if ($currentTime >= $this->maintenance_start_time && $currentTime <= $this->maintenance_end_time) {
            return 1;
        }

        return 0;
    }
}
