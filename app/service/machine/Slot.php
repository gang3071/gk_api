<?php

declare(strict_types=1);

namespace app\service\machine;

use app\model\GameType;
use app\model\Machine;
use app\model\Notice;
use Exception;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use support\Log;

/**
 * 老虎机服务类
 *
 * @property int $auto 自动状态
 * @property int $move_point 移分状态
 * @property int $reward_status 开奖状态
 * @property int $rb_status RB状态
 * @property int $bb_status BB状态
 * @property int $play_start_time 开始游戏时间
 * @property int $gaming_user_id 游戏中玩家
 * @property int $gaming 是否游戏中
 * @property int $point 当前分数
 * @property int $score 当前得分
 * @property int $bet 机台压分
 * @property int $last_play_time 最后游戏时间
 * @property int $win 机台总得分
 * @property int $bb BB
 * @property int $rb RB
 * @property int $open_point 开分次数
 * @property int $wash_point 洗分次数
 * @property int $keep_seconds 保留时长
 * @property int $keeping 保留状态
 * @property int $keeping_user_id 保留玩家
 * @property int $last_keep_at 最后保留时间
 * @property int $player_pressure 玩家进入时原始压分
 * @property int $player_score 玩家进入时原始得分
 * @property int $player_open_point 玩家开分
 * @property int $player_wash_point 玩家洗分
 * @property int $last_point_at 玩家最后上下分时间
 * @property int $action_time 操作时间
 * @property int $change_point_card_status 开分卡状态
 * @property int $gift_bet 玩家开分增点时押注
 * @property int $gift_condition 增点完成条件
 * @property int $now_turn 当前转数
 * @property int $has_lock 机台锁
 */
class SlotOptimized extends AbstractMachineService
{
    // ==================== 指令常量定义 ====================

    /** 指令前缀 */
    public const PREFIX = 'A2';

    // 基础指令
    public const ALL = 'all';
    public const OPEN_ONE = '41';
    public const OPEN_TEN = '42';
    public const WASH_ZERO = '43';
    public const WASH_POINT = '44';
    public const MOVE_POINT_ON = '45';
    public const MOVE_POINT_OFF = '46';
    public const ALL_DOWN = '47';
    public const OPEN_FIVE = '49';
    public const OPEN_ANY_POINT = '4A';
    public const REWARD_SWITCH = '2D';
    public const REWARD_SWITCH_OPT = '64';
    public const MACHINE_BUSY = '1F';

    // 输出控制
    public const OUTPUT = '4B';
    public const ALL_OFF = '00';
    public const U1_ON = '01';
    public const U2_ON = '02';
    public const U3_ON = '03';
    public const U4_ON = '04';
    public const U5_ON = '05';
    public const U6_ON = '06';
    public const U7_ON = '07';
    public const U8_ON = '08';
    public const U1_PULSE = '21';
    public const U2_PULSE = '22';
    public const U3_PULSE = '23';
    public const U4_PULSE = '24';
    public const U5_PULSE = '25';
    public const U6_PULSE = '26';
    public const U7_PULSE = '27';
    public const U8_PULSE = '28';

    // 读取指令
    public const OPEN_TESTING = '20';
    public const READ_SCORE = '21';
    public const READ_CREDIT2 = '22';
    public const READ_BET = '23';
    public const READ_WIN = '24';
    public const READ_BB = '25';
    public const READ_RB = '26';
    public const OPEN_TABLE = '27';
    public const WASH_TABLE = '28';
    public const INSERT_COIN_TABLE = '29';
    public const OUT_COIN_TABLE = '2A';
    public const ALL_UP = '4C';

    // 自动卡指令
    public const OUT_ON = 'AA5708000001150D';
    public const OUT_OFF = 'AA5708000002F70D';
    public const PRESSURE = 'AA5708000003A90D';
    public const START = 'AA57080000042A0D';
    public const STOP_ONE = 'AA5708000005740D';
    public const STOP_TWO = 'AA5708000006960D';
    public const STOP_THREE = 'AA5708000007C80D';
    public const TESTING = 'AA57080000004B0D';
    public const GET_AUTO_STATUS = 'AA52082000000D0D';
    public const AUTO_START = 'AA5208200081DF0D';
    public const AUTO_STOP = 'AA5208200080810D';
    public const AUTO = 'AA520820';

    // 指令类型
    public const TYPE_OPEN_CARD = 1;
    public const TYPE_OUT_CARD = 2;

    /**
     * 初始化缓存 Key 数组
     */
    protected function initializeCacheKeys(): void
    {
        $this->cacheDataKeyArr = [
            $this->cacheDataKey . '_auto',
            $this->cacheDataKey . '_move_point',
            $this->cacheDataKey . '_reward_status',
            $this->cacheDataKey . '_play_start_time',
            $this->cacheDataKey . '_gaming_user_id',
            $this->cacheDataKey . '_gaming',
            $this->cacheDataKey . '_point',
            $this->cacheDataKey . '_score',
            $this->cacheDataKey . '_bet',
            $this->cacheDataKey . '_last_play_time',
            $this->cacheDataKey . '_win',
            $this->cacheDataKey . '_bb',
            $this->cacheDataKey . '_rb',
            $this->cacheDataKey . '_open_point',
            $this->cacheDataKey . '_wash_point',
            $this->cacheDataKey . '_keep_seconds',
            $this->cacheDataKey . '_keeping',
            $this->cacheDataKey . '_keeping_user_id',
            $this->cacheDataKey . '_last_keep_at',
            $this->cacheDataKey . '_player_pressure',
            $this->cacheDataKey . '_player_score',
            $this->cacheDataKey . '_player_open_point',
            $this->cacheDataKey . '_player_wash_point',
            $this->cacheDataKey . '_last_point_at',
            $this->cacheDataKey . '_action_time',
            $this->cacheDataKey . '_change_point_card_status',
            $this->cacheDataKey . '_gift_bet',
            $this->cacheDataKey . '_gift_condition',
            $this->cacheDataKey . '_now_turn',
            $this->cacheDataKey . '_rb_status',
            $this->cacheDataKey . '_bb_status',
            $this->cacheDataKey . '_has_lock',
        ];
    }

    /**
     * 初始化机台信息字段列表
     */
    protected function initializeMachineInfo(): void
    {
        $this->machineInfo = [
            'auto',
            'move_point',
            'reward_status',
            'bet',
            'win',
            'bb',
            'rb',
            'rb_status',
            'bb_status',
            'has_lock',
        ];
    }

    /**
     * 初始化日志实例
     *
     * @return LoggerInterface
     */
    protected function initializeLogger(): LoggerInterface
    {
        return Log::channel('slot_machine');
    }

    /**
     * 处理发送指令时的错误
     * 特定指令失败时设置机台锁并发送异常通知
     *
     * @param string $cmd 指令代码
     * @param Exception $e 异常对象
     */
    protected function handleSendCmdError(string $cmd, Exception $e): void
    {
        // 特定指令失败时设置机台锁
        $lockCommands = [
            self::OPEN_ANY_POINT,
            self::OPEN_ONE,
            self::OPEN_TEN,
            self::WASH_ZERO,
        ];

        if (in_array($cmd, $lockCommands)) {
            $this->has_lock = 1;
            if (function_exists('sendMachineException')) {
                sendMachineException(
                    $this->machine,
                    Notice::TYPE_MACHINE_LOCK,
                    $this->machine->gaming_user_id
                );
            }
        }

        // 记录错误日志
        $this->log->error('发送指令异常', [
            'cmd' => $cmd,
            'machine_code' => $this->machine->code,
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * 获取机台操作描述
     *
     * @param string $cmd 操作指令（空则返回完整状态）
     * @return string
     */
    public function getDescription(string $cmd = ''): string
    {
        locale(Str::replace('-', '_', $this->lang));

        if (empty($cmd)) {
            return $this->getFullStatusDescription();
        }

        return $this->getCommandDescription($cmd);
    }

    /**
     * 获取完整状态描述
     *
     * @return string
     */
    private function getFullStatusDescription(): string
    {
        $lines = [];
        $nowTurn = $this->now_turn;

        $lines[] = trans('machine_auto_status', [], 'machine_action') . $this->formatBoolStatus($this->auto);
        $lines[] = trans('machine_bb_status', [], 'machine_action') . $this->formatBoolStatus($this->bb_status);
        $lines[] = trans('machine_rb_status', [], 'machine_action') . $this->formatBoolStatus($this->rb_status);
        $lines[] = trans('machine_has_lock', [], 'machine_action') . $this->formatBoolStatus($this->has_lock);
        $lines[] = trans('machine_move_point_status', [], 'machine_action') . $this->formatBoolStatus($this->move_point);
        $lines[] = trans('machine_lottery_status', [], 'machine_action') . $this->formatBoolStatus($this->reward_status);
        $lines[] = trans('machine_point', [], 'machine_action') . ($this->point ?? 0);
        $lines[] = trans('machine_score', [], 'machine_action') . ($this->score ?? 0);
        $lines[] = trans('machine_bet', [], 'machine_action') . ($this->bet ?? 0);
        $lines[] = trans('machine_win', [], 'machine_action') . ($this->win ?? 0);
        $lines[] = trans('machine_bb', [], 'machine_action') . ($this->bb ?? 0);
        $lines[] = trans('machine_rb', [], 'machine_action') . ($this->rb ?? 0);
        $lines[] = trans('now_turn', [], 'machine_action') . ($nowTurn > 0 ? ceil($nowTurn / 3) : 0);
        $lines[] = trans('machine_open_point', [], 'machine_action') . ($this->open_point ?? 0);
        $lines[] = trans('machine_wash_point', [], 'machine_action') . ($this->wash_point ?? 0);

        return implode(PHP_EOL, $lines);
    }

    /**
     * 获取指令描述
     *
     * @param string $cmd 指令代码
     * @return string
     */
    private function getCommandDescription(string $cmd): string
    {
        $description = trans(
            'function.' . GameType::TYPE_SLOT . '_' . Machine::CONTROL_TYPE_MEI . '.' . $cmd,
            [],
            'machine_action'
        );

        // 根据指令类型附加数据
        $valueMap = [
            self::READ_SCORE => $this->point,
            self::READ_CREDIT2 => $this->score,
            self::READ_BET => $this->bet,
            self::READ_WIN => $this->win,
            self::READ_RB => $this->rb,
            self::OPEN_TABLE => $this->open_point,
            self::WASH_TABLE => $this->wash_point,
        ];

        if (isset($valueMap[$cmd])) {
            $description .= ': ' . $valueMap[$cmd];
        }

        return $description;
    }

    /**
     * 格式化布尔状态为翻译文本
     *
     * @param int|null $value 状态值
     * @return string
     */
    private function formatBoolStatus(?int $value): string
    {
        return $value == 1
            ? trans('machine_status_yes', [], 'machine_action')
            : trans('machine_status_no', [], 'machine_action');
    }
}
