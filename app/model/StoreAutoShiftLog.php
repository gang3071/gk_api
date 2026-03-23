<?php

namespace app\model;

use support\Model;

/**
 * 自动交班执行日志模型
 *
 * @property int $id 主键ID
 * @property int $config_id 配置ID
 * @property int $department_id 部门/渠道ID
 * @property int $bind_admin_user_id 绑定的管理员用户ID（代理/店家）
 * @property int|null $shift_record_id 关联的交班记录ID
 * @property string $start_time 统计开始时间
 * @property string $end_time 统计结束时间
 * @property string $execute_time 执行时间
 * @property int $status 执行状态（1=成功，2=失败，3=部分成功）
 * @property float $machine_amount 机台投钞金额
 * @property int $machine_point 机台投钞点数
 * @property float $total_in 总收入（送分）
 * @property float $total_out 总支出（取分）
 * @property float $lottery_amount 彩金金额
 * @property float $total_profit 总利润
 * @property int $execution_duration 执行耗时（毫秒）
 * @property string|null $error_message 错误信息
 * @property string $created_at 创建时间
 * @property string $updated_at 更新时间
 */
class StoreAutoShiftLog extends Model
{
    protected $table = 'store_auto_shift_log';
    protected $pk = 'id';

    // 执行状态常量
    const STATUS_SUCCESS = 1;          // 成功
    const STATUS_FAILED = 2;           // 失败
    const STATUS_PARTIAL_SUCCESS = 3;  // 部分成功

    /**
     * 关联配置
     */
    public function config()
    {
        return $this->belongsTo(StoreAutoShiftConfig::class, 'config_id', 'id');
    }

    /**
     * 关联店家
     */
    public function department()
    {
        return $this->belongsTo(Department::class, 'department_id', 'id');
    }

    /**
     * 关联绑定的代理/店家
     */
    public function bindAdminUser()
    {
        return $this->belongsTo(\app\model\AdminUser::class, 'bind_admin_user_id', 'id');
    }

    /**
     * 关联交班记录
     */
    public function shiftRecord()
    {
        return $this->belongsTo(\app\model\StoreAgentShiftHandoverRecord::class, 'shift_record_id', 'id');
    }

    /**
     * 获取状态文本
     */
    public function getStatusTextAttr($value, $data)
    {
        $statuses = [
            self::STATUS_SUCCESS => '成功',
            self::STATUS_FAILED => '失败',
            self::STATUS_PARTIAL_SUCCESS => '部分成功'
        ];
        return $statuses[$data['status']] ?? '未知';
    }

    /**
     * 获取状态标签
     */
    public function getStatusBadgeAttr($value, $data)
    {
        $badges = [
            self::STATUS_SUCCESS => '<span class="badge badge-success">成功</span>',
            self::STATUS_FAILED => '<span class="badge badge-danger">失败</span>',
            self::STATUS_PARTIAL_SUCCESS => '<span class="badge badge-warning">部分成功</span>'
        ];
        return $badges[$data['status']] ?? '<span class="badge badge-secondary">未知</span>';
    }

    /**
     * 获取执行耗时文本（秒）
     */
    public function getExecutionDurationTextAttr($value, $data)
    {
        if (empty($data['execution_duration'])) {
            return '-';
        }
        $seconds = round($data['execution_duration'] / 1000, 2);
        return $seconds . 's';
    }

    /**
     * 获取时间范围
     */
    public function getTimeRangeAttr($value, $data)
    {
        return $data['start_time'] . ' ~ ' . $data['end_time'];
    }

    /**
     * 是否成功
     */
    public function getIsSuccessAttr($value, $data)
    {
        return $data['status'] == self::STATUS_SUCCESS;
    }

    /**
     * 是否失败
     */
    public function getIsFailedAttr($value, $data)
    {
        return $data['status'] == self::STATUS_FAILED;
    }
}
