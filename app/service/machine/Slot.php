<?php

declare(strict_types=1);

namespace app\service\machine;

use app\model\Notice;
use Exception;
use Psr\Log\LoggerInterface;
use support\Log;

/**
 * Slot 老虎机服务类（MEI 协议）
 *
 * 职责：
 * - 从 Redis 读取机台状态
 * - 通过 HTTP 向 gk_work 发送机台指令
 * - 基于 Redis 状态变化进行实时推送
 *
 * 注意：TCP 连接和消息处理已迁移到 gk_work 项目
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
class Slot extends AbstractMachineService
{
    // ==================== 机台指令常量 (MEI 协议) ====================
    // 注意：这些常量定义了MEI协议的老虎机指令代码，通过gk_work转发给机台硬件
    //      实际使用场景见：app/functions.php 和各个Controller

    // ========== 通用指令 ==========
    public const ALL = 'all';                                  // 获取所有机台数据（用于初始化和全量刷新）

    // ========== 开分/洗分操作指令 ==========
    public const OPEN_ONE = '41';                              // 单次开分：玩家上分时按次数开分（1次）
    public const OPEN_TEN = '42';                              // 十次开分：玩家上分时按次数开分（10次）
    public const OPEN_FIVE = '49';                             // 五次开分：玩家上分时按次数开分（5次）
    public const OPEN_ANY_POINT = '4A';                        // 任意分数开分：玩家充值上分，指定具体分数
    public const WASH_ZERO = '43';                             // 洗分清零：玩家下分时将机台分数清零，返还给玩家
    public const WASH_POINT = '44';                            // 洗分指定金额：洗出指定分数
    public const ALL_DOWN = '47';                              // 全部下分：老虎机玩家离开时，将所有剩余分数下分

    // ========== 移分控制指令 ==========
    public const MOVE_POINT_ON = '45';                         // 开启移分：允许机台间分数转移（长按ON状态）
    public const MOVE_POINT_OFF = '46';                        // 关闭移分：禁止机台间分数转移（玩家离台时调用）

    // ========== 奖励状态控制指令 ==========
    public const REWARD_SWITCH = '2D';                         // 奖励开关：查询或切换开奖状态（0=关闭, 1=开启）
    public const REWARD_SWITCH_OPT = '64';                     // 奖励开关选项：奖励开关的附加选项参数

    // ========== 输出控制指令 ==========
    public const OUTPUT = '4B';                                // 输出控制前缀：控制机台外设输出（灯光/音效等）
    public const U1_PULSE = '21';                              // U1脉冲输出：触发U1端口脉冲信号（常用于彩灯控制）

    // ========== 查询指令 - 读取机台状态 ==========
    public const OPEN_TESTING = '20';                          // 开分卡测试：检测开分卡是否正常工作
    public const MACHINE_POINT = '21';                         // 读取当前分数：查询机台当前剩余分数（统一接口）
    public const READ_CREDIT2 = '22';                          // 读取信用额度：查询机台信用值（Credit2字段）
    public const READ_BET = '23';                              // 读取当前压分：查询玩家当前押注金额
    public const READ_WIN = '24';                              // 读取总得分：查询玩家累计赢得的分数
    public const READ_RB = '26';                               // 读取RB次数：查询Regular Bonus触发次数
    public const OPEN_TABLE = '27';                            // 读取开分表：获取开分记录明细
    public const WASH_TABLE = '28';                            // 读取洗分表：获取洗分记录明细
    public const INSERT_COIN_TABLE = '29';                     // 读取投币表：获取投币记录明细
    public const OUT_COIN_TABLE = '2A';                        // 读取退币表：获取退币记录明细
    public const READ_STATUS = '2B';                           // 读取机台状态：查询机台当前运行状态（游戏中/空闲等）
    public const CHECK = '2F';                                 // 故障检查：诊断机台是否有硬件故障
    public const MACHINE_OPEN = '31';                          // 开机指令：启动机台电源
    public const MACHINE_CLOSE = '32';                         // 关机指令：关闭机台电源

    // ========== 自动卡指令 - 控制老虎机自动游戏 ==========
    public const OUT_ON = 'AA5708000001150D';                  // 开启出分：启用自动出分功能
    public const OUT_OFF = 'AA5708000002F70D';                 // 关闭出分：禁用自动出分（玩家离台时调用）
    public const PRESSURE = 'AA5708000003A90D';                // 自动压分：设置自动游戏的押注金额
    public const START = 'AA57080000042A0D';                   // 开始游戏：启动一次老虎机转轴旋转
    public const STOP_ONE = 'AA5708000005740D';                // 停止转轴1：停止第1个转轴（玩家离台时强制停止）
    public const STOP_TWO = 'AA5708000006960D';                // 停止转轴2：停止第2个转轴（玩家离台时强制停止）
    public const STOP_THREE = 'AA5708000007C80D';              // 停止转轴3：停止第3个转轴（玩家离台时强制停止）
    public const TESTING = 'AA57080000004B0D';                 // 测试心跳：检测自动卡连接状态
    public const GET_AUTO_STATUS = 'AA570800000A5D0D';         // 获取自动状态：查询自动游戏模式是否开启

    /**
     * 初始化Redis缓存键名数组
     * 定义需要从Redis读取/写入的所有老虎机状态字段
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
     * 定义需要通过WebSocket实时推送给前端的字段
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
     * 初始化日志实例 - 使用专用的slot_machine日志通道
     *
     * @return LoggerInterface 日志记录器实例
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
}
