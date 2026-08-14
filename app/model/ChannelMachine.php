<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class ChannelMachine
 * @property int id 主键
 * @property int department_id 渠道id
 * @property int machine_id 机器id
 * @property int|null store_admin_id 绑定店家ID（仅线下机台使用）
 * @property int status 状态
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 *
 * @property Channel channel 渠道
 * @property Machine machine 机器
 * @property AdminUser|null storeAdmin 绑定店家（仅线下机台）
 * @package app\model
 */
class ChannelMachine extends Model
{
    use HasDateTimeFormatter;

    //数据权限字段
    protected $dataAuth = ['department_id' => 'department_id'];

    /**
     * 时间转换
     * @param DateTimeInterface $date
     * @return string
     */
    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }

    protected $table = 'channel_machine';

    /**
     * 渠道信息
     * @return BelongsTo
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'department_id',
            'department_id')->withTrashed();
    }

    /**
     * 机台信息
     * @return BelongsTo
     */
    public function machine(): BelongsTo
    {
        return $this->BelongsTo(Machine::class, 'machine_id')->withTrashed();
    }

    /**
     * 绑定店家信息（仅线下机台使用）
     * @return BelongsTo
     */
    public function storeAdmin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'store_admin_id')->withTrashed();
    }
}
