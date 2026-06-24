<?php

declare(strict_types=1);

namespace app\model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 出票记录模型
 *
 * @property int $id
 * @property string $order_id 订单号
 * @property int $department_id 部门ID
 * @property int $store_admin_id 店家管理员ID
 * @property string $store_name 店名
 * @property int $machine_no 台号
 * @property int $machine_id 机台ID
 * @property int $player_id 玩家ID
 * @property string $player_name 玩家名称
 * @property float $score 分数/金额
 * @property string $qr_code 二维码信息
 * @property string $qr_code_no 二维码编号
 * @property string $encrypted_content 加密内容
 * @property int $ticket_type 票据类型: 1=开分 2=洗分
 * @property int $status 状态: 0=禁用 1=正常 2=已打印 3=已使用 4=待核销
 * @property int $scan_status 扫码状态: 0=待扫码 1=已扫码
 * @property string|null $scanned_at 扫码时间
 * @property string|null $scanned_by 扫码人
 * @property int $print_count 打印次数
 * @property string|null $last_print_time 最后打印时间
 * @property array|null $extra_data 扩展数据
 * @property string $remark 备注
 * @property string $created_at 创建时间
 * @property string $updated_at 更新时间
 * @property string|null $deleted_at 删除时间
 */
class TicketRecord extends Model
{
    use SoftDeletes;

    protected $table = 'qr_ticket_record';

    protected $guarded = [];

    protected $casts = [
        'extra_data' => 'json',
        'scanned_at' => 'datetime',
        'last_print_time' => 'datetime',
    ];

    // 票据类型常量
    const TYPE_RECHARGE = 1;   // 开分
    const TYPE_WITHDRAW = 2;   // 洗分

    // 状态常量
    const STATUS_DISABLED = 0;   // 禁用
    const STATUS_NORMAL = 1;     // 正常
    const STATUS_PRINTED = 2;    // 已打印
    const STATUS_USED = 3;       // 已使用
    const STATUS_PENDING = 4;    // 待核销

    // 扫码状态常量
    const SCAN_STATUS_PENDING = 0;  // 待扫码
    const SCAN_STATUS_SCANNED = 1;  // 已扫码

    /**
     * 获取票据类型名称
     */
    public function getTicketTypeNameAttribute(): string
    {
        return match ($this->ticket_type) {
            self::TYPE_RECHARGE => '开分',
            self::TYPE_WITHDRAW => '洗分',
            default => '未知',
        };
    }

    /**
     * 获取状态名称
     */
    public function getStatusNameAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_DISABLED => '禁用',
            self::STATUS_NORMAL => '正常',
            self::STATUS_PRINTED => '已打印',
            self::STATUS_USED => '已使用',
            self::STATUS_PENDING => '待核销',
            default => '未知',
        };
    }

    /**
     * 关联机台
     */
    public function machine()
    {
        return $this->belongsTo(Machine::class, 'machine_id');
    }

    /**
     * 关联玩家
     */
    public function player()
    {
        return $this->belongsTo(Player::class, 'player_id');
    }

    /**
     * 关联部门
     */
    public function department()
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    /**
     * 生成订单号
     */
    public static function generateOrderId(): string
    {
        return 'TK' . date('YmdHis') . str_pad((string) mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
    }

    /**
     * 生成二维码编号
     */
    public static function generateQrCodeNo(): string
    {
        return date('YmdHis') . str_pad((string) mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
    }
}
