<?php

declare(strict_types=1);

namespace app\service\machine;

use app\model\GameType;
use app\model\Machine;
use app\model\MachineLotteryRecord;
use app\model\Notice;
use app\service\LotteryServices;
use Exception;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use support\Cache;
use support\Log;
use Webman\RedisQueue\Client;

/**
 * Jackpot 机台服务类（钢珠机）
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
    // ==================== 指令常量定义 ====================

    /** 指令前缀 */
    public const PREFIX = 'A2';

    // 基础指令
    public const ALL = 'all';
    public const OPEN_ONE = '41';
    public const OPEN_TEN = '42';
    public const WASH_ZERO = '43';
    public const WASH_ZERO_REMAINDER = '44';
    public const AUTO_UP_TURN = '45';
    public const RESET_READY_TURN = '46';
    public const TURN_DOWN_ALL = '47';
    public const TURN_TO_POINT = '48';
    public const POINT_TO_TURN = '49';
    public const OPEN_ANY_POINT = '4A';
    public const SCORE_TO_POINT = '4B';
    public const TURN_UP_ALL = '4C';
    public const OP_3 = '4D';
    public const CLEAR_GIVE = '4E';
    public const CLEAR_LOG = '4F';

    // 读取指令
    public const TESTING = '20';
    public const MACHINE_POINT = '21';
    public const MACHINE_SCORE = '22';
    public const MACHINE_TURN = '23';
    public const WIN_NUMBER = '24';
    public const READ_OPEN_POINT = '25';
    public const READ_WASH_POINT = '26';
    public const BB_RUSH = '2B';
    public const REWARD_SWITCH = '2D';
    public const REWARD_SWITCH_OPT = '64';

    // PUSH 控制
    public const PUSH = '2E';
    public const PUSH_STOP = '00';
    public const PUSH_ONE = '01';
    public const PUSH_TWO = '02';
    public const PUSH_THREE = '03';

    // 保留
    public const KEEPING = '40';

    /**
     * 构造函数
     *
     * @param Machine $machine 机台对象
     * @param string $lang 语言代码
     */
    public function __construct(Machine $machine, string $lang = 'zh_CN')
    {
        parent::__construct($machine, $lang);
    }

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
     */
    protected function initializeMachineInfo(): void
    {
        $this->machineInfo = [
            'auto',
            'move_point',
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
     * 覆盖父类的 __set 方法，增加 Jackpot 特定的推送逻辑
     *
     * @param string $name 属性名
     * @param mixed $value 属性值
     */
    public function __set(string $name, mixed $value): void
    {
        $key = $this->cacheDataKey . '_' . $name;

        if (!in_array($key, $this->cacheDataKeyArr)) {
            return;
        }

        $saveResult = false;
        try {
            $saveResult = Cache::set($this->cacheDataKey . '_' . $name, $value);
            if (!$saveResult) {
                $saveResult = Cache::set($this->cacheDataKey . '_' . $name, $value);
            }
        } catch (Exception $e) {
            try {
                $saveResult = Cache::set($this->cacheDataKey . '_' . $name, $value);
                Log::warning('Redis缓存保存异常后重试成功', [
                    'machine_id' => $this->machine->id,
                    'field' => $name,
                    'error' => $e->getMessage()
                ]);
            } catch (Exception $e2) {
                $saveResult = false;
                Log::error('Redis缓存保存异常（重试1次后仍失败）', [
                    'machine_id' => $this->machine->id,
                    'machine_code' => $this->machine->code,
                    'field' => $name,
                    'value' => $value,
                    'error' => $e2->getMessage()
                ]);
            }
        }

        // 关键字段保存失败时记录额外日志
        if (!$saveResult) {
            $criticalFields = ['gaming', 'gaming_user_id', 'last_play_time', 'point', 'turn', 'keeping', 'win_number'];
            if (in_array($name, $criticalFields)) {
                Log::error('关键字段Redis保存失败', [
                    'machine_id' => $this->machine->id,
                    'machine_code' => $this->machine->code,
                    'field' => $name,
                    'value' => $value
                ]);
            }
        }

        // Jackpot 特定的推送逻辑
        $machineCacheInfo = $this->getAllData();
        if (!empty($machineCacheInfo)) {
            $info = $this->buildMachineInfo($machineCacheInfo);
            $this->handleFieldUpdate($name, $value, $info);
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
            'move_point' => $machineCacheInfo[$this->cacheDataKey . '_move_point'] ?? 0,
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
     * 处理字段更新的推送逻辑
     * 根据字段类型推送不同的实时消息
     *
     * @param string $name 字段名
     * @param mixed $value 字段值
     * @param array $info 机台信息
     */
    private function handleFieldUpdate(string $name, mixed $value, array $info): void
    {
        if (!function_exists('sendSocketMessage')) {
            return;
        }

        try {
            // 玩家开始游戏
            if ($name === 'gaming_user_id' && !empty($value) && !empty($this->machine->gamingPlayer)) {
                sendSocketMessage("department-{$this->machine->gamingPlayer->department_id}", [
                    'msg_type' => 'game_start',
                    'data' => $info,
                    'timestamp' => time(),
                ]);
            }

            // 重要字段变化
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
                // 推送到机台频道
                sendSocketMessage("machine-{$this->machine->id}", [
                    'msg_type' => 'machine_field_update',
                    'machine_id' => $this->machine->id,
                    'field' => $name,
                    'value' => $value,
                    'info' => $info,
                    'timestamp' => time(),
                ]);

                // 推送给当前玩家
                sendSocketMessage("player-{$this->machine->gaming_user_id}", [
                    'msg_type' => 'my_machine_field_update',
                    'machine_id' => $this->machine->id,
                    'field' => $name,
                    'value' => $value,
                    'timestamp' => time(),
                ]);
            }
        } catch (Exception $e) {
            Log::warning('推送字段更新失败', [
                'machine_id' => $this->machine->id,
                'field' => $name,
                'error' => $e->getMessage()
            ]);
        }
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
     * 钢珠消息处理
     *
     * @param string $message 消息内容
     * @return bool
     */
    public function jackPotCmd(string $message): bool
    {
        try {
            $msg = strtoupper(bin2hex($message));
            jackPotCheckCRC8($msg); // 检查crc8
            $fun = substr($msg, 2, 2);
            $data = jackpotDecodeData($msg);
            checkJackpotXor55($msg, $data);
            $status1 = decodeStatus(substr($msg, 4, 2));
            $orgBbStatus = $this->bb_status;
            $orgRushStatus = $this->rush_status;
            $this->bb_status = (int)substr($status1, 7, 1);
            $this->rush_status = (int)substr($status1, 6, 1);
            $this->handle_status = (int)substr($status1, 4, 1);
            $this->auto = (int)substr($status1, 5, 1);
            $this->action_time = getMillisecond();

            $this->log->info('机器接收指令日志', [
                'jackPot -> jackPotCmd',
                [
                    'code' => $this->machine->code,
                    'msg' => $msg,
                    'auto' => $this->auto,
                    'now_turn' => $this->now_turn,
                    'reward_status' => $this->reward_status,
                    'bb_status' => $this->bb_status,
                    'rush_status' => $this->rush_status,
                    'player_win_number' => $this->player_win_number,
                ]
            ]);

            // 开奖状态处理
            $this->handleRewardStatus($orgBbStatus, $orgRushStatus);

            // 开奖记录处理
            $this->handleLotteryRecord($orgBbStatus, $orgRushStatus);

            $gamingUserId = $this->machine->gaming_user_id;

            // 处理指令
            $this->handleCommand($fun, $data, $gamingUserId);

        } catch (Exception $e) {
            $this->log->error('消息处理错误', [
                $e->getMessage(),
                [
                    'msg' => $msg ?? '',
                    'action' => $fun ?? '',
                    'trace' => $e->getTraceAsString(),
                    'machineInfo' => $this->getMachineCache(),
                ]
            ]);
            return false;
        }

        return true;
    }

    /**
     * 处理开奖状态
     *
     * @param int $orgBbStatus 原始BB状态
     * @param int $orgRushStatus 原始Rush状态
     */
    private function handleRewardStatus(int $orgBbStatus, int $orgRushStatus): void
    {
        // 开奖状态和rush确变状态只要一个为1进入开奖状态
        if ($this->bb_status == 1 || $this->rush_status == 1) {
            $this->reward_status = 1;
            $this->last_play_time = time();
        }

        // 开奖状态和rush确变状态都为0并且当前状态为开奖中
        if ($this->bb_status == 0 && $this->rush_status == 0) {
            $rewardStatus = $this->reward_status;
            $this->reward_status = 0;
            if ($rewardStatus == 1) {
                $this->handleRewardEnd();
            }
        }
    }

    /**
     * 处理开奖结束
     */
    private function handleRewardEnd(): void
    {
        if (!empty($this->machine->gamingPlayer)) {
            (new LotteryServices())
                ->setMachine($this->machine)
                ->setPlayer($this->machine->gamingPlayer)
                ->fixedPotCheckLottery($this->score);
        }

        if ($this->score > 0 && !empty($this->machine->gaming_user_id)) {
            Client::send('play-activity', [
                'machine_id' => $this->machine->id,
                'player_id' => $this->machine->gaming_user_id,
                'point' => $this->score,
            ]);
        }

        // 开奖结束后需剔除其他观看中玩家
        if (function_exists('sendSocketMessage')) {
            sendSocketMessage('group-' . $this->machine->id, [
                'msg_type' => 'machine_reward_end',
                'machine_id' => $this->machine->id,
                'machine_code' => $this->machine->code,
                'gaming_user_id' => $this->machine->gaming_user_id,
            ]);
        }

        $this->sendCmd(self::SCORE_TO_POINT, 0, 'player', (int)$this->machine->gaming_user_id);
    }

    /**
     * 处理开奖记录
     *
     * @param int $orgBbStatus 原始BB状态
     * @param int $orgRushStatus 原始Rush状态
     */
    private function handleLotteryRecord(int $orgBbStatus, int $orgRushStatus): void
    {
        if ($orgBbStatus == 0 && $orgRushStatus == 0
            && $this->bb_status == 1 && $this->rush_status == 1 && $this->now_turn > 0) {
            $machineLotteryRecord = new MachineLotteryRecord();
            $machineLotteryRecord->machine_id = $this->machine->id;
            $machineLotteryRecord->player_id = $this->machine->gaming_user_id ?? 0;
            $machineLotteryRecord->department_id = $this->machine->gamingPlayer->department_id ?? 0;
            $machineLotteryRecord->draw_bet = $this->win_number;
            $machineLotteryRecord->use_turn = $this->now_turn;
            $machineLotteryRecord->save();
            $this->now_turn = 0;
        }

        if (($orgBbStatus == 0 && $orgRushStatus == 1 && $this->bb_status == 0
                && $this->rush_status == 0 && $this->now_turn > 0)
            || ($orgBbStatus == 1 && $this->bb_status == 0 && $this->rush_status == 1 && $this->now_turn > 0)) {
            $this->now_turn = 0;
        }
    }

    /**
     * 处理指令
     *
     * @param string $fun 指令代码
     * @param int $data 数据
     * @param int|null $gamingUserId 游戏中的玩家ID
     */
    private function handleCommand(string $fun, int $data, ?int $gamingUserId): void
    {
        switch ($fun) {
            case self::TURN_UP_ALL:
            case self::TURN_TO_POINT:
            case self::TURN_DOWN_ALL:
            case self::POINT_TO_TURN:
            case self::SCORE_TO_POINT:
            case self::OPEN_ONE:
            case self::OPEN_TEN:
            case self::OPEN_ANY_POINT:
            case self::WASH_ZERO:
            case self::WASH_ZERO_REMAINDER:
            case self::AUTO_UP_TURN:
            case self::BB_RUSH:
                $this->setActionVersion($fun);
                break;

            case self::MACHINE_POINT:
                $this->point = $data;
                $this->setActionVersion($fun);
                break;

            case self::CLEAR_LOG:
                $this->win_number = 0;
                $this->setActionVersion($fun);
                break;

            case self::MACHINE_SCORE:
                $this->score = $data;
                if ($data > 0) {
                    $this->reward_status = 1;
                }
                $this->setActionVersion($fun);
                break;

            case self::MACHINE_TURN:
                $this->turn = $data;
                if ($data <= 0 && !empty($gamingUserId)) {
                    Cache::delete('gift_cache_' . $this->machine->id . '_' . $gamingUserId);
                }
                $this->setActionVersion($fun);
                break;

            case self::WIN_NUMBER:
                $this->handleWinNumber($data, $gamingUserId);
                break;

            case self::READ_OPEN_POINT:
                $this->open_point = $data;
                $this->setActionVersion($fun);
                break;

            case self::READ_WASH_POINT:
                $this->wash_point = $data;
                $this->setActionVersion($fun);
                break;

            case self::PUSH:
                $this->handlePushCommand(substr(strtoupper(bin2hex('')), 4, 2));
                $this->setActionVersion($fun);
                break;

            case self::TESTING:
                // 状态消息推送已迁移到 gk_work
                break;

            default:
                // 不处理未知指令
                break;
        }
    }

    /**
     * 处理中洞对奖次数
     *
     * @param int $data 数据
     * @param int|null $gamingUserId 游戏中的玩家ID
     */
    private function handleWinNumber(int $data, ?int $gamingUserId): void
    {
        if ($this->win_number > 0 && $this->win_number > $data && $this->change_point_card_status == 0) {
            if (function_exists('sendMachineException')) {
                sendMachineException($this->machine, Notice::TYPE_MACHINE_WIN_NUMBER);
            }
            if (!empty($gamingUserId) && $this->auto == 1) {
                $this->sendCmd(self::AUTO_UP_TURN, 0, 'player', $gamingUserId, 1);
            }
            $this->win_number = $data;
            return;
        }

        if ($this->win_number > 0 && $this->win_number != $data
            && !empty($gamingUserId) && $this->change_point_card_status == 0) {
            $this->last_play_time = time();
            if ($this->reward_status == 0) {
                Client::send('play-keep-machine', [
                    'change_amount' => abs($data - $this->win_number),
                    'machine_id' => $this->machine->id,
                    'player_id' => $gamingUserId,
                ]);
                Client::send('lottery-machine', [
                    'num' => $data,
                    'last_num' => $this->win_number,
                    'machine_id' => $this->machine->id,
                    'player_id' => $gamingUserId,
                ]);
            }
        }

        if (($this->rush_status == 0 && $this->bb_status == 0)
            || ($this->rush_status == 1 && $this->bb_status == 0)) {
            $nowTurn = $this->now_turn;
            $bet = $this->win_number;
            $this->now_turn = (int)bcadd((string)$nowTurn, bcsub((string)$data, (string)$bet, 2), 2);
            if (!empty($gamingUserId)) {
                $playerNumber = $this->player_win_number;
                $this->player_win_number = (int)bcadd((string)$playerNumber, bcsub((string)$data, (string)$bet, 2), 2);
            }
        }

        $this->win_number = $data;
        $this->change_point_card_status = 0;
        $this->setActionVersion(self::WIN_NUMBER);
    }

    /**
     * 处理PUSH指令
     *
     * @param string $pushStatus PUSH状态
     */
    private function handlePushCommand(string $pushStatus): void
    {
        if ($pushStatus == self::PUSH_STOP) {
            $this->push_auto = 0;
        }
        if ($pushStatus == self::PUSH_THREE) {
            $this->push_auto = 1;
        }
    }

    /**
     * 设置操作版本号
     *
     * @param string $name 操作名称
     * @return float
     */
    public function setActionVersion(string $name): float
    {
        $version = getMillisecond();
        Cache::set($this->cacheDataKey . '_action_' . $name, $version, 60 * 60);
        return $version;
    }

    /**
     * 获取操作版本号
     *
     * @param string $name 操作名称
     * @return float
     */
    public function getActionVersion(string $name): float
    {
        return (float)Cache::get($this->cacheDataKey . '_action_' . $name, 0);
    }

    /**
     * 获取机台操作描述
     *
     * @param string $fun 操作指令（空则返回完整状态）
     * @return string
     */
    public function getDescription(string $fun = ''): string
    {
        locale(Str::replace('-', '_', $this->lang));

        if (empty($fun)) {
            return $this->getFullStatusDescription();
        }

        return $this->getCommandDescription($fun);
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
        $lines[] = trans('machine_lottery_status', [], 'machine_action') . $this->formatBoolStatus($this->reward_status);
        $lines[] = trans('machine_bb_status', [], 'machine_action') . $this->formatBoolStatus($this->bb_status);
        $lines[] = trans('machine_rush_status', [], 'machine_action') . $this->formatBoolStatus($this->rush_status);
        $lines[] = trans('machine_point', [], 'machine_action') . ($this->point ?? 0);
        $lines[] = trans('machine_score', [], 'machine_action') . ($this->score ?? 0);
        $lines[] = trans('machine_turn', [], 'machine_action') . ($this->turn ?? 0);
        $lines[] = trans('now_turn', [], 'machine_action') . ($nowTurn ?? 0);
        $lines[] = trans('machine_open_point', [], 'machine_action') . ($this->open_point ?? 0);
        $lines[] = trans('machine_wash_point', [], 'machine_action') . ($this->wash_point ?? 0);

        return implode(PHP_EOL, $lines);
    }

    /**
     * 获取指令描述
     *
     * @param string $fun 指令代码
     * @return string
     */
    private function getCommandDescription(string $fun): string
    {
        $description = trans(
            'function.' . GameType::TYPE_STEEL_BALL . '_' . Machine::CONTROL_TYPE_SONG . '.' . $fun,
            [],
            'machine_action'
        );

        // 根据指令类型附加数据
        $valueMap = [
            self::MACHINE_POINT => $this->point,
            self::MACHINE_SCORE => $this->score,
            self::MACHINE_TURN => $this->turn,
            self::WIN_NUMBER => $this->win_number,
            self::READ_OPEN_POINT => $this->open_point,
            self::READ_WASH_POINT => $this->wash_point,
        ];

        if (isset($valueMap[$fun])) {
            $description .= ': ' . $valueMap[$fun];
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
