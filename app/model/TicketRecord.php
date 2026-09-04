<?php

declare(strict_types=1);

namespace app\model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

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
 * @property int|null $source_ticket_id 来源原票ID
 * @property string|null $source_type 来源类型: split/merge/purchase
 * @property array|null $related_ticket_ids 关联的新票ID数组
 * @property int $operation_type 操作类型: 0=无操作, 1=拆分, 2=合并, 3=购票
 * @property string|null $operated_at 操作时间
 * @property int|null $operated_by 操作人ID
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
        'related_ticket_ids' => 'json',
        'scanned_at' => 'datetime',
        'last_print_time' => 'datetime',
        'operated_at' => 'datetime',
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
    const STATUS_SPLIT = 6;          // 已拆分
    const STATUS_MERGED = 7;         // 已合并

    // 操作类型常量
    const OPERATION_NONE = 0;        // 无操作
    const OPERATION_SPLIT = 1;       // 拆分
    const OPERATION_MERGE = 2;       // 合并
    const OPERATION_PURCHASE = 3;    // 购票

    // 来源类型常量
    const SOURCE_TYPE_SPLIT = 'split';       // 来源：拆分
    const SOURCE_TYPE_MERGE = 'merge';       // 来源：合并
    const SOURCE_TYPE_PURCHASE = 'purchase'; // 来源：购票

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
            self::STATUS_BACKEND_USED => '后台已使用',
            self::STATUS_MACHINE_USED => '机台已使用',
            self::STATUS_SPLIT => '已拆分',
            self::STATUS_MERGED => '已合并',
            default => '未知',
        };
    }

    /**
     * 获取操作类型名称
     */
    public function getOperationTypeNameAttribute(): string
    {
        return match ((int)$this->operation_type) {
            self::OPERATION_NONE => '无操作',
            self::OPERATION_SPLIT => '拆分',
            self::OPERATION_MERGE => '合并',
            self::OPERATION_PURCHASE => '购票',
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
        $createdAt = Carbon::parse($this->created_at);
        $expireTime = $createdAt->addHours($expireHours);

        return Carbon::now()->gt($expireTime);
    }
}
