<?php

declare(strict_types=1);

namespace app\service\machine;

use app\model\Notice;
use Exception;
use Psr\Log\LoggerInterface;
use support\Log;

/**
 * SongSlot 老虎机服务类（Song 协议）
 *
 * 职责：
 * - 从 Redis 读取机台状态
 * - 通过 HTTP 向 gk_work 发送机台指令
 * - 基于 Redis 状态变化进行实时推送
 *
 * 注意：TCP 连接和消息处理已迁移到 gk_work 项目
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
    // ==================== 机台指令常量 ====================
    // 注意：这些常量定义了Song协议的老虎机指令代码，通过gk_work转发给机台硬件
    //      实际使用场景见：app/functions.php 和各个Controller

    // 通用指令
    public const ALL = 'all';                              // 全部数据

    // 操作指令 - 开分/洗分（使用中）
    public const OPEN_ANY_POINT = 'afca';                  // 任意分数开分（使用中）
    public const WASH_ZERO = 'afcc';                       // 洗分清零（使用中）

    // 查询指令 - 读取机台状态（使用中）
    public const READ_SCORE = 'afcbc5';                    // 读取当前得分（使用中）
    public const READ_WIN = 'afcbc9';                      // 读取总得分
    public const READ_BET = 'afcbc7';                      // 读取压分（使用中）

    // 控制指令 - 机台操作
    public const REWARD_SWITCH = 'afceb8';                 // 奖励开关
    public const CHECK = 'afcfb4';                         // 检查机台状态
    public const START = 'afceb2';                         // 开始游戏
    public const OUT_ON = 'afceb6';                        // 开启出分
    public const OUT_OFF = 'afceb2';                       // 关闭出分（使用中）
    public const STOP_ONE = 'afceb3';                      // 停止转轴1（使用中）
    public const STOP_TWO = 'afceb4';                      // 停止转轴2（使用中）
    public const STOP_THREE = 'afceb5';                    // 停止转轴3（使用中）
    public const MACHINE_OPEN = 'afcebe';                  // 打开机台
    public const MACHINE_CLOSE = 'afcebc';                 // 关闭机台
    public const ALL_DOWN = 'afcfba';                      // 全部下分（使用中）

    /**
     * 初始化Redis缓存键名数组
     * 定义需要从Redis读取/写入的所有老虎机状态字段（Song协议特定）
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
     * 定义需要通过WebSocket实时推送给前端的字段（Song协议特定）
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
     * 初始化日志实例 - 使用专用的song_slot_machine日志通道
     *
     * @return LoggerInterface 日志记录器实例
     */
    protected function initializeLogger(): LoggerInterface
    {
        return Log::channel('song_slot_machine');
    }

    /**
     * 处理发送指令时的错误
     * 特定指令失败时设置机台锁并发送异常通知（Song协议特定）
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
}

