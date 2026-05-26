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
 * Song 老虎机服务类
 *
 * @property int $auto 自动状态
 * @property int $reward_status 开奖状态
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
 * @property int $pre_wash_point 预洗分点数
 */
class SongSlot extends AbstractMachineService
{
    // ==================== 指令常量定义 ====================

    // 基础指令
    public const ALL = 'all';
    public const OPEN_ANY_POINT = 'afca';
    public const WASH_ZERO = 'afcc';
    public const TESTING = 'afc0';
    public const TESTING2 = 'afc6';

    // 读取指令
    public const READ_SCORE = 'afcbc5';
    public const READ_WIN = 'afcbc9';
    public const READ_BET = 'afcbc7';
    public const READ_STATUS = 'afcbc3';

    // 快速读取指令
    public const GET_SCORE = 'afc5';
    public const GET_WIN = 'afc9';
    public const GET_BET = 'afc7';
    public const GET_STATUS = 'afc3';

    // 控制指令
    public const REWARD_SWITCH = 'afceb8';
    public const CHECK = 'afcfb4';
    public const START = 'afceb2';
    public const OUT_ON = 'afceb6';
    public const OUT_OFF = 'afceb2';
    public const STOP_ONE = 'afceb3';
    public const STOP_TWO = 'afceb4';
    public const STOP_THREE = 'afceb5';
    public const MACHINE_OPEN = 'afcebe';
    public const MACHINE_CLOSE = 'afcebc';
    public const ALL_DOWN = 'afcfba';

    /**
     * 初始化缓存 Key 数组
     */
    protected function initializeCacheKeys(): void
    {
        $this->cacheDataKeyArr = [
            $this->cacheDataKey . '_auto',
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
            $this->cacheDataKey . '_has_lock',
            $this->cacheDataKey . '_pre_wash_point',
        ];
    }

    /**
     * 初始化机台信息字段列表
     */
    protected function initializeMachineInfo(): void
    {
        $this->machineInfo = [
            'auto',
            'reward_status',
            'bet',
            'win',
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
        return Log::channel('song_slot_machine');
    }

    /**
     * 处理发送指令时的错误
     *
     * @param string $cmd 指令代码
     * @param Exception $e 异常对象
     */
    protected function handleSendCmdError(string $cmd, Exception $e): void
    {
        // Song 机台特定指令失败时设置机台锁
        $lockCommands = [
            self::OPEN_ANY_POINT,
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
     * @param int $data 指令数据（用于某些指令的描述）
     * @return string
     */
    public function getDescription(string $cmd = '', int $data = 0): string
    {
        locale(Str::replace('-', '_', $this->lang));

        if (empty($cmd)) {
            return $this->getFullStatusDescription();
        }

        return $this->getCommandDescription($cmd, $data);
    }

    /**
     * 获取完整状态描述
     *
     * @return string
     */
    private function getFullStatusDescription(): string
    {
        $lines = [];

        $lines[] = trans('machine_auto_status', [], 'machine_action') . $this->formatBoolStatus($this->auto);
        $lines[] = trans('machine_lottery_status', [], 'machine_action') . $this->formatBoolStatus($this->reward_status);
        $lines[] = trans('machine_point', [], 'machine_action') . ($this->point ?? 0);
        $lines[] = trans('machine_bet', [], 'machine_action') . ($this->bet ?? 0);
        $lines[] = trans('machine_win', [], 'machine_action') . ($this->win ?? 0);

        return implode(PHP_EOL, $lines);
    }

    /**
     * 获取指令描述
     *
     * @param string $cmd 指令代码
     * @param int $data 指令数据
     * @return string
     */
    private function getCommandDescription(string $cmd, int $data): string
    {
        $description = trans(
            'function.' . GameType::TYPE_SLOT . '_' . Machine::CONTROL_TYPE_SONG . '.' . $cmd,
            [],
            'machine_action'
        );

        // 根据指令类型附加数据
        $valueMap = [
            self::READ_SCORE => $this->point,
            self::READ_WIN => $this->win,
            self::READ_BET => $this->bet,
        ];

        // 特殊指令显示传入的数据
        if (in_array($cmd, [self::OPEN_ANY_POINT, self::WASH_ZERO])) {
            $description .= ': ' . $data;
        } elseif (isset($valueMap[$cmd])) {
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
