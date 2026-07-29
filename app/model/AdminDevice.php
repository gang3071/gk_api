<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class AdminDevice
 * @property int $id 主键ID
 * @property int $channel_id 所属渠道ID
 * @property int $department_id 所属部门ID
 * @property int $agent_admin_id 所属代理ID
 * @property int $store_admin_id 所属店家ID
 * @property string $device_name 设备名称
 * @property string $device_no 设备号（安卓设备唯一标识）
 * @property string $device_model 设备型号
 * @property string|null $voice_url 语音播报文件URL（Google TTS生成）
 * @property int $status 状态(0:禁用,1:启用)
 * @property string $remark 备注
 * @property string $created_at 创建时间
 * @property string $updated_at 更新时间
 * @property string|null $deleted_at 删除时间
 *
 * @package app\model
 */
class AdminDevice extends Model
{
    use HasDateTimeFormatter;

    protected $table = 'admin_device';

    protected $fillable = [
        'channel_id',
        'department_id',
        'agent_admin_id',
        'store_admin_id',
        'device_name',
        'device_no',
        'device_model',
        'voice_url',
        'status',
        'remark',
    ];
}
