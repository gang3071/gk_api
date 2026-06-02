<?php

declare(strict_types=1);

namespace app\service\machine;

use app\model\Machine;
use app\model\Notice;
use Exception;
use Psr\Log\LoggerInterface;
use support\Log;

/**
 * Jackpot 机台服务类（钢珠机 - MEI 协议）
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
 * @property int $rush_status rush状态
 * @property int $bb_status bb状态
 * @property int $play_start_time 开始游戏时间
 * @property int $gaming_user_id 游戏中玩家
 * @property int $gaming 是否游戏中
 * @property int $turn 当前转数
 * @property int $point 当前分数
 * @property int $score 当前珠数
 * @property int $last_play_time 最后游戏时间
 * @property int $open_point 开分次数
 * @property int $wash_point 洗分次数
 * @property int $keep_seconds 保留时长
 * @property int $keeping 保留状态
 * @property int $keeping_user_id 保留玩家
 * @property int $last_keep_at 最后保留时间
 * @property int $player_win_number 玩家使用转数
 * @property int $player_open_point 玩家开分
 * @property int $player_wash_point 玩家洗分
 * @property int $last_point_at 玩家最后上下分时间
 * @property int $handle_status 圖柄確認状态
 * @property int $win_number 讀取中洞對獎次數
 * @property int $action_time 操作时间
 * @property int $push_auto push auto状态
 * @property int $change_point_card_status 开分卡状态
 * @property int $gift_bet 玩家开分增点时押注
 * @property int $now_turn 当前转数
 * @property int $has_lock 机台锁
 */
class Jackpot extends AbstractMachineService
{
    // ==================== 机台指令常量 (MEI 协议 - 钢珠机) ====================
    // 注意：这些常量定义了MEI协议的钢珠机指令代码，通过gk_work转发给机台硬件
    //      实际使用场景见：app/functions.php 和各个Controller

    // ========== 通用指令 ==========
    public const ALL = 'all';                          // 获取所有机台数据（用于初始化和全量刷新）

    // ========== 开分/洗分操作指令 ==========
    public const OPEN_ONE = '41';                      // 单次开分：玩家上分时按次数开分（1次）
    public const OPEN_TEN = '42';                      // 十次开分：玩家上分时按次数开分（10次）
    public const OPEN_ANY_POINT = '4A';                // 任意分数开分：玩家充值上分，指定具体分数
    public const WASH_ZERO = '43';                     // 洗分清零：玩家下分时将机台分数清零，返还给玩家
    public const WASH_ZERO_REMAINDER = '44';           // 洗分保留余数：洗分时保留部分余数不下分
    public const CLEAR_LOG = '4F';                     // 清除日志：清空机台操作日志记录（离台时调用）

    // ========== 转数/珠数/分数转换控制指令 ==========
    public const AUTO_UP_TURN = '45';                  // 自动上转：自动将得分转换为保转（离台时调用）
    public const RESET_READY_TURN = '46';              // 重置准备转数：清零准备转数计数器
    public const TURN_DOWN_ALL = '47';                 // 下所有转数：将所有保转下分返还给玩家（离台时调用）
    public const TURN_UP_ALL = '4C';                   // 上所有转数：将所有分数转换为保转
    public const TURN_TO_POINT = '48';                 // 转数转分数：手动将保转转换为可用分数
    public const POINT_TO_TURN = '49';                 // 分数转转数：手动将分数转换为保转
    public const SCORE_TO_POINT = '4B';                // 珠数转分数：将得分珠数转换为可用分数（离台前调用）
    public const OP_3 = '4D';                          // 开启OP_3保转：触发特殊保转模式

    // ========== 查询指令 - 读取机台状态 ==========
    public const TESTING = '20';                       // 测试心跳：检测机台连接状态
    public const MACHINE_POINT = '21';                 // 读取当前分数：查询机台当前剩余分数（统一接口）
    public const MACHINE_SCORE = '22';                 // 读取当前珠数：查询当前得分珠数（出珠计数）
    public const MACHINE_TURN = '23';                  // 读取当前转数：查询当前保转数量
    public const WIN_NUMBER = '24';                    // 读取中奖次数：查询累计中奖次数（用于活动统计）
    public const READ_OPEN_POINT = '25';               // 读取开分次数：查询累计开分次数
    public const READ_WASH_POINT = '26';               // 读取洗分次数：查询累计洗分次数
    public const REWARD_SWITCH = '2D';                 // 奖励开关：查询或切换开奖状态（0=关闭, 1=开启）
    public const REWARD_SWITCH_OPT = '64';             // 奖励开关选项：奖励开关的附加选项参数

    // ========== PUSH 推杆控制指令（组合使用：PUSH + 动作代码）==========
    public const PUSH = '2E';                          // PUSH基础指令：推杆控制前缀，需配合动作代码使用
    public const PUSH_STOP = '00';                     // 停止推杆：停止推杆动作（离台时强制停止）
    public const PUSH_ONE = '01';                      // 推杆动作1：执行第1档推杆动作
    public const PUSH_TWO = '02';                      // 推杆动作2：执行第2档推杆动作
    public const PUSH_THREE = '03';                    // 推杆动作3：执行第3档推杆动作

    /**
     * 构造函数 - 初始化Jackpot机台服务实例
     *
     * @param Machine $machine 机台对象（必须是钢珠机类型）
     * @param string $lang 语言代码（用于多语言翻译，默认zh_CN）
     */
    public function __construct(Machine $machine, string $lang = 'zh_CN')
    {
        parent::__construct($machine, $lang);
    }

    /**
     * 初始化Redis缓存键名数组
     * 定义需要从Redis读取/写入的所有机台状态字段
     */
    protected function initializeCacheKeys(): void
    {
        $this->cacheDataKeyArr = [
            $this->cacheDataKey . '_auto',
            $this->cacheDataKey . '_reward_status',
            $this->cacheDataKey . '_play_start_time',
            $this->cacheDataKey . '_gaming_user_id',
            $this->cacheDataKey . '_gaming',
            $this->cacheDataKey . '_turn',
            $this->cacheDataKey . '_point',
            $this->cacheDataKey . '_score',
            $this->cacheDataKey . '_last_play_time',
            $this->cacheDataKey . '_open_point',
            $this->cacheDataKey . '_wash_point',
            $this->cacheDataKey . '_keep_seconds',
            $this->cacheDataKey . '_keeping',
            $this->cacheDataKey . '_keeping_user_id',
            $this->cacheDataKey . '_last_keep_at',
            $this->cacheDataKey . '_player_win_number',
            $this->cacheDataKey . '_player_open_point',
            $this->cacheDataKey . '_player_wash_point',
            $this->cacheDataKey . '_last_point_at',
            $this->cacheDataKey . '_handle_status',
            $this->cacheDataKey . '_action_time',
            $this->cacheDataKey . '_win_number',
            $this->cacheDataKey . '_push_auto',
            $this->cacheDataKey . '_change_point_card_status',
            $this->cacheDataKey . '_gift_bet',
            $this->cacheDataKey . '_now_turn',
            $this->cacheDataKey . '_rush_status',
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
            'reward_status',
            'turn',
            'point',
            'score',
            'win_number',
            'push_auto',
            'rush_status',
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
        return Log::channel('jackpot_machine');
    }

    /**
     * 覆盖父类方法，返回 Jackpot 特定的关键字段
     *
     * @return array
     */
    protected function getCriticalFields(): array
    {
        return ['gaming', 'gaming_user_id', 'last_play_time', 'point', 'turn', 'keeping', 'win_number'];
    }

    /**
     * Jackpot 特定的字段更新推送逻辑
     *
     * @param string $name 字段名
     * @param mixed $value 字段值
     */
    protected function handleFieldUpdatePush(string $name, mixed $value): void
    {
        if (!function_exists('sendSocketMessage')) {
            return;
        }

        try {
            $machineCacheInfo = $this->getAllData(); // ✅ 使用带内存缓存的版本
            if (empty($machineCacheInfo)) {
                return;
            }

            $info = $this->buildMachineInfo($machineCacheInfo);

            // 玩家开始游戏
            if ($name === 'gaming_user_id' && !empty($value) && !empty($this->machine->gamingPlayer)) {
                sendSocketMessage("department-{$this->machine->gamingPlayer->department_id}", [
                    'msg_type' => 'game_start',
                    'data' => $info,
                    'timestamp' => time(),
                ]);
            }

            // 重要字段变化推送
            $importantFields = [
                'auto', 'turn', 'win_number', 'push_auto', 'reward_status',
                'last_point_at', 'wash_point', 'keep_seconds', 'score',
                'rush_status', 'bb_status'
            ];

            if (in_array($name, $importantFields) && !empty($this->machine->gamingPlayer)) {
                sendSocketMessage("department-{$this->machine->gamingPlayer->department_id}", [
                    'msg_type' => 'game_info_change',
                    'data' => $info,
                    'timestamp' => time(),
                ]);
            }

            // 推送给机台和玩家
            if (in_array($name, $this->machineInfo) && !empty($this->machine->gaming_user_id)) {
                sendSocketMessage("machine-{$this->machine->id}", [
                    'msg_type' => 'machine_field_update',
                    'machine_id' => $this->machine->id,
                    'field' => $name,
                    'value' => $value,
                    'info' => $info,
                    'timestamp' => time(),
                ]);

                sendSocketMessage("player-{$this->machine->gaming_user_id}", [
                    'msg_type' => 'my_machine_field_update',
                    'machine_id' => $this->machine->id,
                    'field' => $name,
                    'value' => $value,
                    'timestamp' => time(),
                ]);
            }
        } catch (Exception $e) {
            $this->log->warning('推送字段更新失败', [
                'machine_id' => $this->machine->id,
                'field' => $name,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 构建机台信息数组
     *
     * @param array $machineCacheInfo 缓存数据
     * @return array
     */
    private function buildMachineInfo(array $machineCacheInfo): array
    {
        return [
            'id' => $this->machine->id,
            'last_game_at' => $this->machine->last_game_at,
            'odds_x' => $this->machine->odds_x,
            'odds_y' => $this->machine->odds_y,
            'type' => $this->machine->type,
            'gaming_user_id' => $this->machine->gaming_user_id,
            'gaming' => $this->machine->gaming,
            'auto' => $machineCacheInfo[$this->cacheDataKey . '_auto'] ?? 0,
            'reward_status' => $machineCacheInfo[$this->cacheDataKey . '_reward_status'] ?? 0,
            'play_start_time' => $machineCacheInfo[$this->cacheDataKey . '_play_start_time'] ?? 0,
            'turn' => $machineCacheInfo[$this->cacheDataKey . '_turn'] ?? 0,
            'point' => $machineCacheInfo[$this->cacheDataKey . '_point'] ?? 0,
            'score' => $machineCacheInfo[$this->cacheDataKey . '_score'] ?? 0,
            'last_play_time' => $machineCacheInfo[$this->cacheDataKey . '_last_play_time'] ?? 0,
            'open_point' => $machineCacheInfo[$this->cacheDataKey . '_open_point'] ?? 0,
            'wash_point' => $machineCacheInfo[$this->cacheDataKey . '_wash_point'] ?? 0,
            'keep_seconds' => $machineCacheInfo[$this->cacheDataKey . '_keep_seconds'] ?? 0,
            'keeping' => $machineCacheInfo[$this->cacheDataKey . '_keeping'] ?? 0,
            'keeping_user_id' => $machineCacheInfo[$this->cacheDataKey . '_keeping_user_id'] ?? 0,
            'last_keep_at' => $machineCacheInfo[$this->cacheDataKey . '_last_keep_at'] ?? 0,
            'player_win_number' => $machineCacheInfo[$this->cacheDataKey . '_player_win_number'] ?? 0,
            'player_open_point' => $machineCacheInfo[$this->cacheDataKey . '_player_open_point'] ?? 0,
            'player_wash_point' => $machineCacheInfo[$this->cacheDataKey . '_player_wash_point'] ?? 0,
            'last_point_at' => $machineCacheInfo[$this->cacheDataKey . '_last_point_at'] ?? 0,
            'action_time' => $machineCacheInfo[$this->cacheDataKey . '_action_time'] ?? 0,
            'win_number' => $machineCacheInfo[$this->cacheDataKey . '_win_number'] ?? 0,
            'push_auto' => $machineCacheInfo[$this->cacheDataKey . '_push_auto'] ?? 0,
            'change_point_card_status' => $machineCacheInfo[$this->cacheDataKey . '_change_point_card_status'] ?? 0,
            'now_turn' => $machineCacheInfo[$this->cacheDataKey . '_now_turn'] ?? 0,
            'rush_status' => $machineCacheInfo[$this->cacheDataKey . '_rush_status'] ?? 0,
            'bb_status' => $machineCacheInfo[$this->cacheDataKey . '_bb_status'] ?? 0,
            'has_lock' => $machineCacheInfo[$this->cacheDataKey . '_has_lock'] ?? 0,
        ];
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
