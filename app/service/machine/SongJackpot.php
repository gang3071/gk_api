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
 * Song Jackpot 机台服务类（钢珠机 - Song协议）
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
 * @property int $player_turn_base 玩家转数基准点（缓存）
 * @property int $handle_status 圖柄確認状态
 * @property int $win_number 讀取中洞對獎次數
 * @property int $action_time 操作时间
 * @property int $push_auto push auto状态
 * @property int $change_point_card_status 开分卡状态
 * @property int $gift_bet 玩家开分增点时押注
 * @property int $now_turn 当前转数
 * @property int $has_lock 机台锁
 * @property int $pre_wash_point 预洗分点数
 */
class SongJackpot extends AbstractMachineService
{
    // ==================== 指令常量定义 ====================

    // 基础指令
    public const ALL = 'all';
    public const MACHINE_POINT = '46cea2';
    public const MACHINE_SCORE = '46cea5';
    public const MACHINE_TURN = '46cea6';
    public const WIN_NUMBER = '46cea9';

    // 读取指令
    public const GET_MACHINE_POINT = '46c0';
    public const AUTO_MACHINE_POINT = '46c6';
    public const GET_MACHINE_SCORE = '46da';
    public const FAULT1_MACHINE_SCORE = '46db';
    public const FAULT_MACHINE_SCORE = '46dc';
    public const GET_MACHINE_TURN = '46de';
    public const GET_WIN_NUMBER = '46d0';
    public const REWARD_WIN_NUMBER = '46d5';

    // 控制指令
    public const CHECK = '46cfb4';
    public const MACHINE_OPEN = '46cebe';
    public const MACHINE_CLOSE = '46cebc';
    public const REWARD_SWITCH = '46ceb8';
    public const PUSH_THREE = '46ceb6';
    public const PUSH_ONE = '46ceb2';
    public const TURN_DOWN_ALL = '46cec9';
    public const TURN_UP_ALL = '46cecb';
    public const SCORE_TO_POINT = '46cec8';
    public const OPEN_ANY_POINT = '46ca';
    public const CLEAR_LOG = '46ccba';
    public const WASH_ZERO = '46cc';
    public const AUTO_UP_TURN = '46cecd';
    public const AUTO_STOP = '46cece';
    public const TURN_TO_POINT = '46ceca';
    public const POINT_TO_TURN = '46cec1';

    // 心跳
    public const TESTING = '46c0';
    public const TESTING2 = '46c6';

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
            $this->cacheDataKey . '_player_turn_base',
            $this->cacheDataKey . '_action_time',
            $this->cacheDataKey . '_win_number',
            $this->cacheDataKey . '_push_auto',
            $this->cacheDataKey . '_change_point_card_status',
            $this->cacheDataKey . '_gift_bet',
            $this->cacheDataKey . '_now_turn',
            $this->cacheDataKey . '_rush_status',
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
            'turn',
            'point',
            'score',
            'win_number',
            'push_auto',
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
        return Log::channel('song_jackpot_machine');
    }

    /**
     * 覆盖父类的 __set 方法，增加 SongJackpot 特定的推送逻辑
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

        // SongJackpot 特定的推送逻辑
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
            'player_turn_base' => $machineCacheInfo[$this->cacheDataKey . '_player_turn_base'] ?? 0,
            'action_time' => $machineCacheInfo[$this->cacheDataKey . '_action_time'] ?? 0,
            'win_number' => $machineCacheInfo[$this->cacheDataKey . '_win_number'] ?? 0,
            'push_auto' => $machineCacheInfo[$this->cacheDataKey . '_push_auto'] ?? 0,
            'change_point_card_status' => $machineCacheInfo[$this->cacheDataKey . '_change_point_card_status'] ?? 0,
            'now_turn' => $machineCacheInfo[$this->cacheDataKey . '_now_turn'] ?? 0,
            'rush_status' => $machineCacheInfo[$this->cacheDataKey . '_rush_status'] ?? 0,
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
            if ($name === 'gaming_user_id' && !empty($this->machine->gamingPlayer)) {
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
     * 钢珠消息处理
     *
     * @param string $msg 消息内容
     * @return bool
     */
    public function jackPotCmd(string $msg): bool
    {
        $domain = $this->machine->domain;
        $port = $this->machine->port;

        try {
            $len = mb_strlen($msg);
            if (!in_array($len, [10, 12, 14, 16, 30, 36])) {
                throw new Exception('指令错误');
            }

            // 校验
            $this->validateCommand($msg);

            $fun = substr($msg, 0, 6);
            $fun1 = substr($msg, 0, 4);
            $gamingUserId = $this->machine->gaming_user_id;

            // 保存原始状态
            $orgRewardStatus = $this->reward_status;
            $orgAuto = $this->auto;
            $orgPoint = $this->point;
            $orgTurn = $this->turn;
            $orgScore = $this->score;
            $orgNowTurn = $this->now_turn;
            $orgWinNumber = $this->win_number;

            // 处理心跳指令（36位）
            if ($len == 36 && ($fun1 == self::TESTING || $fun1 == self::TESTING2)) {
                return $this->handleHeartbeat($msg, $gamingUserId, $orgRewardStatus, $orgWinNumber);
            }

            // 处理其他指令
            $this->handleOtherCommands($fun, $fun1, $msg, $domain, $port);

        } catch (Exception $e) {
            $this->log->error('消息处理错误', [
                $e->getMessage(),
                [
                    'msg' => $msg,
                    'action' => $fun ?? '',
                    'machine_code' => $this->machine->code,
                ]
            ]);
            return false;
        }

        return true;
    }

    /**
     * 校验指令
     *
     * @param string $msg 指令内容
     * @throws Exception
     */
    private function validateCommand(string $msg): void
    {
        $s1 = substr($msg, -4, 2);
        $s2 = substr($msg, -2, 2);
        $data = substr($msg, 0, -4);

        $calculatedS1 = self::calculateS1($data);
        if ($s1 != $calculatedS1) {
            throw new Exception('指令s1校验失败');
        }

        $calculatedS2 = self::calculateS2($data, $calculatedS1);
        if ($s2 != $calculatedS2) {
            throw new Exception('指令s2校验失败');
        }
    }

    /**
     * 处理心跳指令
     *
     * @param string $msg 消息内容
     * @param int|null $gamingUserId 游戏中的玩家ID
     * @param int $orgRewardStatus 原始开奖状态
     * @param int $orgWinNumber 原始中洞对奖次数
     * @return bool
     */
    private function handleHeartbeat(
        string $msg,
        ?int $gamingUserId,
        int $orgRewardStatus,
        int $orgWinNumber
    ): bool {
        // 检查机台故障
        if (substr($msg, 18, 2) != 'da') {
            $this->has_lock = 1;
            if (function_exists('sendMachineException')) {
                sendMachineException($this->machine, Notice::TYPE_MACHINE_LOCK, $gamingUserId);
            }
            throw new Exception('机台故障');
        }

        [$nowPoint, $nowRatio, $nowWinNumber, $nowScore, $nowTurn] = self::parseHeartbeat($msg);
        $nowAuto = substr($msg, 2, 2) == 'c6' ? 1 : 0;
        $nowRewardStatus = substr($msg, 10, 2) == 'd0' ? 0 : 1;

        $this->log->info('机台当前数据', [
            'msg' => $msg,
            'machine_code' => $this->machine->code,
            'nowRewardStatus' => $nowRewardStatus,
            'nowAuto' => $nowAuto,
            'nowPoint' => $nowPoint,
            'nowRatio' => $nowRatio,
            'nowWinNumber' => $nowWinNumber,
            'nowTurn' => $nowTurn,
            'nowScore' => $nowScore,
        ]);

        // 更新当前状态
        $this->point = $nowPoint;
        $this->auto = $nowAuto;
        $this->win_number = $nowWinNumber;
        $this->score = $nowScore;
        $this->turn = $nowTurn;
        $this->reward_status = $nowRewardStatus;
        $this->now_turn = $nowWinNumber;

        // 处理开奖记录
        $this->handleHeartbeatLotteryRecord($nowRewardStatus, $orgRewardStatus);

        // 处理开奖结束
        if ($nowRewardStatus == 0 && $orgRewardStatus == 1) {
            $this->handleHeartbeatRewardEnd($nowScore);
        }

        // 处理异常中洞对奖次数
        if ($this->handleAbnormalWinNumber($orgWinNumber, $nowWinNumber, $nowRewardStatus, $orgRewardStatus, $gamingUserId)) {
            return true;
        }

        // 处理玩家转数累加
        $this->handlePlayerTurnAccumulation($nowRewardStatus, $nowTurn, $gamingUserId);

        // 清理礼物缓存
        if ($nowTurn <= 0 && !empty($gamingUserId)) {
            Cache::delete('gift_cache_' . $this->machine->id . '_' . $gamingUserId);
        }

        // 处理中洞对奖次数变化
        $this->handleWinNumberChange($orgWinNumber, $nowWinNumber, $nowRewardStatus, $gamingUserId);

        // 注意：机台状态推送已迁移到 gk_work
        return true;
    }

    /**
     * 处理心跳开奖记录
     *
     * @param int $nowRewardStatus 当前开奖状态
     * @param int $orgRewardStatus 原始开奖状态
     */
    private function handleHeartbeatLotteryRecord(int $nowRewardStatus, int $orgRewardStatus): void
    {
        if ($nowRewardStatus == 1 && $orgRewardStatus == 0) {
            $machineLotteryRecord = new MachineLotteryRecord();
            $machineLotteryRecord->machine_id = $this->machine->id;
            $machineLotteryRecord->player_id = $this->machine->gaming_user_id ?? 0;
            $machineLotteryRecord->department_id = $this->machine->gamingPlayer->department_id ?? 0;
            $machineLotteryRecord->draw_bet = $this->win_number;
            $machineLotteryRecord->use_turn = $this->now_turn;
            $machineLotteryRecord->save();
        }
    }

    /**
     * 处理心跳开奖结束
     *
     * @param int $nowScore 当前得分
     */
    private function handleHeartbeatRewardEnd(int $nowScore): void
    {
        if (!empty($this->machine->gamingPlayer)) {
            (new LotteryServices())
                ->setMachine($this->machine)
                ->setPlayer($this->machine->gamingPlayer)
                ->fixedPotCheckLottery($nowScore);
        }

        if ($nowScore > 0 && !empty($this->machine->gaming_user_id)) {
            Client::send('play-activity', [
                'machine_id' => $this->machine->id,
                'player_id' => $this->machine->gaming_user_id,
                'point' => $nowScore,
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
     * 处理异常的中洞对奖次数
     *
     * @param int $orgWinNumber 原始中洞对奖次数
     * @param int $nowWinNumber 当前中洞对奖次数
     * @param int $nowRewardStatus 当前开奖状态
     * @param int $orgRewardStatus 原始开奖状态
     * @param int|null $gamingUserId 游戏中的玩家ID
     * @return bool 是否提前返回
     */
    private function handleAbnormalWinNumber(
        int $orgWinNumber,
        int $nowWinNumber,
        int $nowRewardStatus,
        int $orgRewardStatus,
        ?int $gamingUserId
    ): bool {
        if ($orgWinNumber > 0 && $orgWinNumber > $nowWinNumber
            && $this->change_point_card_status == 0 && $nowRewardStatus == 0 && $orgRewardStatus == 0) {
            if (function_exists('sendMachineException')) {
                sendMachineException($this->machine, Notice::TYPE_MACHINE_WIN_NUMBER);
            }
            $this->win_number = $nowWinNumber;
            return true;
        }
        return false;
    }

    /**
     * 处理玩家转数累加
     *
     * @param int $nowRewardStatus 当前开奖状态
     * @param int $nowTurn 当前转数
     * @param int|null $gamingUserId 游戏中的玩家ID
     */
    private function handlePlayerTurnAccumulation(int $nowRewardStatus, int $nowTurn, ?int $gamingUserId): void
    {
        if ($nowRewardStatus != 0 || empty($gamingUserId)) {
            return;
        }

        $orgTurn = $this->turn;
        $turnDelta = bcsub((string)$nowTurn, (string)$orgTurn, 2);

        // 检查是否刚执行过上转下转操作
        $isTurnAction = Cache::get('turn_action_flag_' . $this->machine->id);

        $this->log->info('心跳turn变化检测', [
            'machine_code' => $this->machine->code,
            'now_turn' => $nowTurn,
            'org_turn' => $orgTurn,
            'turn_delta' => $turnDelta,
            'current_player_win_number' => $this->player_win_number,
            'is_turn_action' => $isTurnAction ? 'yes' : 'no'
        ]);

        // 如果检测到上转下转标记，跳过本次累加
        if ($isTurnAction) {
            $this->log->info('检测到上转/下转操作标记，跳过本次累加', [
                'machine_code' => $this->machine->code,
                'turn_delta' => $turnDelta
            ]);
            return;
        }

        // turn是剩余转数，负增量说明玩家消耗了转数（正常游玩）
        // 但需要过滤大幅减少（可能是下转操作）
        if (bccomp($turnDelta, '0', 2) < 0 && bccomp($turnDelta, '-10', 2) >= 0) {
            $playerNumber = $this->player_win_number;
            $consumed = abs((float)$turnDelta);
            $this->player_win_number = (int)bcadd((string)$playerNumber, (string)$consumed, 2);

            $this->log->info('累加玩家使用转数', [
                'machine_code' => $this->machine->code,
                'turn_delta' => $turnDelta,
                'consumed' => $consumed,
                'player_win_number' => $this->player_win_number
            ]);
        } elseif (bccomp($turnDelta, '-10', 2) < 0) {
            $this->log->info('turn大幅减少，可能是下转操作，不累加', [
                'machine_code' => $this->machine->code,
                'turn_delta' => $turnDelta
            ]);
        } elseif (bccomp($turnDelta, '0', 2) > 0) {
            $this->log->info('turn增加，可能是上转操作，不累加', [
                'machine_code' => $this->machine->code,
                'turn_delta' => $turnDelta
            ]);
        }
    }

    /**
     * 处理中洞对奖次数变化
     *
     * @param int $orgWinNumber 原始中洞对奖次数
     * @param int $nowWinNumber 当前中洞对奖次数
     * @param int $nowRewardStatus 当前开奖状态
     * @param int|null $gamingUserId 游戏中的玩家ID
     */
    private function handleWinNumberChange(
        int $orgWinNumber,
        int $nowWinNumber,
        int $nowRewardStatus,
        ?int $gamingUserId
    ): void {
        if ($orgWinNumber > 0 && $orgWinNumber < $nowWinNumber
            && !empty($gamingUserId) && $this->change_point_card_status == 0 && $nowRewardStatus == 0) {
            $this->last_play_time = time();

            Client::send('play-keep-machine', [
                'change_amount' => abs($nowWinNumber - $orgWinNumber),
                'machine_id' => $this->machine->id,
                'player_id' => $gamingUserId,
            ]);

            Client::send('lottery-machine', [
                'num' => $nowWinNumber,
                'last_num' => $orgWinNumber,
                'machine_id' => $this->machine->id,
                'player_id' => $gamingUserId,
            ]);
        }
    }

    /**
     * 处理其他指令
     *
     * @param string $fun 完整指令
     * @param string $fun1 指令前缀
     * @param string $msg 原始消息
     * @param string $domain 域名
     * @param int $port 端口
     * @throws Exception
     */
    private function handleOtherCommands(string $fun, string $fun1, string $msg, string $domain, int $port): void
    {
        switch ($fun) {
            case self::REWARD_SWITCH:
            case self::MACHINE_OPEN:
            case self::MACHINE_CLOSE:
            case self::TURN_DOWN_ALL:
            case self::TURN_UP_ALL:
            case self::PUSH_THREE:
            case self::PUSH_ONE:
            case self::CLEAR_LOG:
            case self::CHECK:
            case self::POINT_TO_TURN:
            case self::TURN_TO_POINT:
                $this->setActionVersion($fun);
                break;

            case self::AUTO_UP_TURN:
                $this->auto = 1;
                $this->setActionVersion($fun);
                break;

            case self::AUTO_STOP:
                $this->auto = 0;
                $this->setActionVersion($fun);
                break;

            default:
                $this->handleShortCommands($fun1, $msg);
                break;
        }
    }

    /**
     * 处理短指令
     *
     * @param string $action 指令代码
     * @param string $msg 原始消息
     * @throws Exception
     */
    private function handleShortCommands(string $action, string $msg): void
    {
        $gamingUserId = $this->machine->gaming_user_id;

        switch ($action) {
            case self::OPEN_ANY_POINT:
            case self::WASH_ZERO:
                $this->setActionVersion(substr($msg, 0, 6));
                break;

            case self::FAULT1_MACHINE_SCORE:
            case self::FAULT_MACHINE_SCORE:
                $this->has_lock = 1;
                if (function_exists('sendMachineException')) {
                    sendMachineException($this->machine, Notice::TYPE_MACHINE_LOCK, $gamingUserId);
                }
                throw new Exception('机台故障');

            case self::GET_MACHINE_POINT:
            case self::AUTO_MACHINE_POINT:
                $point = self::parseScore(substr($msg, 4, 6));
                $this->point = $point;
                $this->log->info('当前分数', [$point]);
                $this->setActionVersion(self::MACHINE_POINT);
                break;

            case self::GET_MACHINE_SCORE:
                $score = self::parseScore(substr($msg, 4, 6));
                $this->score = $score;
                $this->log->info('当前得分', [$score]);
                $this->setActionVersion(self::MACHINE_SCORE);
                break;

            case self::GET_MACHINE_TURN:
                $turn = self::parseScore('00' . substr($msg, 4, 4));
                $this->turn = $turn;
                $this->log->info('当前转数', [$turn]);

                // 检查是否是上转/下转后的主动获取
                $isTurnAction = Cache::get('turn_action_flag_' . $this->machine->id);
                if ($isTurnAction && !empty($gamingUserId)) {
                    $this->player_turn_base = $turn;
                    Cache::delete('turn_action_flag_' . $this->machine->id);

                    $this->log->info('更新玩家转数基准点', [
                        'machine_code' => $this->machine->code,
                        'new_base' => $turn,
                        'player_win_number' => $this->player_win_number
                    ]);
                }

                $this->setActionVersion(self::MACHINE_TURN);
                break;

            case self::GET_WIN_NUMBER:
            case self::REWARD_WIN_NUMBER:
                $winNumber = self::parseScore('00' . substr($msg, 6, 4));
                $this->validateWinNumber($winNumber, $msg, $action);
                break;

            case self::SCORE_TO_POINT:
                $this->setActionVersion(self::SCORE_TO_POINT);
                break;

            default:
                throw new Exception('不存在的指令');
        }
    }

    /**
     * 验证并更新中洞对奖次数
     *
     * @param int $winNumber 中洞对奖次数
     * @param string $msg 原始消息
     * @param string $action 指令代码
     */
    private function validateWinNumber(int $winNumber, string $msg, string $action): void
    {
        $oldWinNumber = $this->win_number;
        $delta = $winNumber - $oldWinNumber;

        // winNumber 在正常游戏中不应该突然变化超过100
        if (abs($delta) > 100) {
            $this->log->error('检测到异常的winNumber值，拒绝更新', [
                'machine_code' => $this->machine->code,
                'old_win_number' => $oldWinNumber,
                'new_win_number' => $winNumber,
                'delta' => $delta,
                'raw_msg' => $msg,
                'extracted_hex' => substr($msg, 4, 6),
                'command' => $action
            ]);
        } else {
            $this->win_number = $winNumber;
        }

        $this->setActionVersion(self::WIN_NUMBER);
    }

    /**
     * 计算S1校验位 (XOR异或校验)
     *
     * @param string $data 指令数据
     * @return string
     */
    public static function calculateS1(string $data): string
    {
        $bytes = str_split($data, 2);
        $xor = 0;
        foreach ($bytes as $byte) {
            $xor ^= hexdec($byte);
        }
        return str_pad(dechex($xor), 2, '0', STR_PAD_LEFT);
    }

    /**
     * 计算S2校验位 (ADD累加校验)
     *
     * @param string $data 指令数据
     * @param string $s1 S1值
     * @return string
     */
    public static function calculateS2(string $data, string $s1): string
    {
        $bytes = str_split($data, 2);
        $add = 0;
        foreach ($bytes as $byte) {
            $add += hexdec($byte);
        }
        $add += hexdec($s1);
        $result = $add & 0xFF;
        return str_pad(dechex($result), 2, '0', STR_PAD_LEFT);
    }

    /**
     * 解析心跳指令中的压分数据
     *
     * @param string $command 心跳指令
     * @return array [point, ratio, win_number, score, turn]
     */
    public static function parseHeartbeat(string $command): array
    {
        $cleanCommand = str_replace(' ', '', strtoupper(trim($command)));

        $parts = [
            'point_section' => substr($cleanCommand, 4, 6),
            'ratio_section' => substr($cleanCommand, 12, 2),
            'win_number_section' => substr($cleanCommand, 14, 4),
            'score_section' => substr($cleanCommand, 20, 6),
            'turn_section' => substr($cleanCommand, 28, 4)
        ];

        $ratioArr = [
            '00' => '10',
            '01' => '11',
            '02' => '12',
            '03' => '13',
            '04' => '14',
            '05' => '15',
        ];

        return [
            self::parseScore($parts['point_section']),
            $ratioArr[$parts['ratio_section']] ?? '10',
            self::parseScore('00' . $parts['win_number_section']),
            self::parseScore($parts['score_section']),
            self::parseScore('00' . $parts['turn_section']),
        ];
    }

    /**
     * 解析当前分数
     * 格式: xx yy zz (BCD码)
     *
     * @param string $scoreSection 分数段
     * @return int
     */
    private static function parseScore(string $scoreSection): int
    {
        $bytes = str_split($scoreSection, 2);
        $bcd2 = $bytes[0];
        $bcd1 = $bytes[1];
        $bcd0 = $bytes[2];

        return (hexdec($bcd2) * 10000) + (hexdec($bcd1) * 100) + hexdec($bcd0);
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
        $lines[] = trans('machine_point', [], 'machine_action') . ($this->point ?? 0);
        $lines[] = trans('machine_score', [], 'machine_action') . ($this->score ?? 0);
        $lines[] = trans('machine_turn', [], 'machine_action') . ($this->turn ?? 0);
        $lines[] = trans('now_turn', [], 'machine_action') . ($nowTurn ?? 0);

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
