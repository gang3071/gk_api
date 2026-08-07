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
    const TYPE_EXPERIENCE = 3; // 体验卷
    const TYPE_WELFARE = 4;    // 福利卷

    // 状态常量
    const STATUS_DISABLED = 0;       // 禁用
    const STATUS_NORMAL = 1;         // 正常
    const STATUS_BACKEND_USED = 2;   // 后台使用
    const STATUS_MACHINE_USED = 3;   // 机台使用

    /**
     * 获取票据类型名称
     */
    public function getTicketTypeNameAttribute(): string
    {
        return match ($this->ticket_type) {
            self::TYPE_RECHARGE => '开分',
            self::TYPE_WITHDRAW => '洗分',
            self::TYPE_EXPERIENCE => '体验卷',
            self::TYPE_WELFARE => '福利卷',
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
            self::STATUS_BACKEND_USED => '后台使用',
            self::STATUS_MACHINE_USED => '机台使用',
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
     * 格式：前缀 + 6位日期(YYMMDD) + 6位时间(HHMMSS) + 2位随机数 = 16字符
     *
     * 前缀规则：
     * - 福利卷 (TYPE_WELFARE): FL
     * - 体验卷 (TYPE_EXPERIENCE): TY
     * - 其他 (开分/洗分): TK
     *
     * @param int $ticketType 票据类型
     * @return string
     */
    public static function generateOrderId(int $ticketType = self::TYPE_RECHARGE): string
    {
        $prefix = match ($ticketType) {
            self::TYPE_WELFARE => 'FL',
            self::TYPE_EXPERIENCE => 'TY',
            default => 'TK',
        };

        return $prefix . date('ymdHis') . str_pad((string) mt_rand(1, 99), 2, '0', STR_PAD_LEFT);
    }

    /**
     * 生成二维码编号
     */
    public static function generateQrCodeNo(): string
    {
        return date('YmdHis') . str_pad((string) mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * 判断是否为福利卷或体验卷
     *
     * @return bool
     */
    public function isWelfareOrExperience(): bool
    {
        return in_array((int)$this->ticket_type, [self::TYPE_WELFARE, self::TYPE_EXPERIENCE]);
    }

    /**
     * 检查福利卷/体验卷是否已过期
     *
     * @return bool true=已过期, false=未过期
     */
    public function isExpired(): bool
    {
        if (!$this->isWelfareOrExperience()) {
            return false;
        }

        $expireHours = (int) config('welfare_ticket.expire_hours', 24);
        $createdAt = strtotime($this->created_at);
        $expireTime = $createdAt + ($expireHours * 60 * 60);

        return time() > $expireTime;
    }
}
