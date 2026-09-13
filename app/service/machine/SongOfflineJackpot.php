<?php

declare(strict_types=1);

namespace app\service\machine;

use app\model\Notice;
use Exception;
use Psr\Log\LoggerInterface;
use support\Log;

/**
 * SongOfflineJackpot 线下版钢珠机服务类（Song 协议）
 *
 * 职责：
 * - 从 Redis 读取机台状态
 * - 通过 HTTP 向 gk_work 发送机台指令
 * - 基于 Redis 状态变化进行实时推送
 *
 * 注意：TCP 连接和消息处理已迁移到 gk_work 项目
 *
 * 协议特点：
 * - 使用 Song 协议（46 前缀）
 * - 支持外部按钮开分/洗分（开分码表、洗分码表）
 * - 支持转数、珠数、分数转换
 *
 * @property int $auto 自动状态
 * @property int $reward_status 开奖状态
 * @property int $rush_status rush状态
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
 * @property int $win_number 讀取中洞對獎次數
 * @property int $action_time 操作时间
 * @property int $push_auto push auto状态
 * @property int $change_point_card_status 开分卡状态
 * @property int $gift_bet 玩家开分增点时押注
 * @property int $now_turn 当前转数
 * @property int $has_lock 机台锁
 * @property int $pre_wash_point 预洗分点数
 * @property int $external_open_count 外部开分码表（线下版特有）
 * @property int $external_wash_count 洗分码表（线下版特有）
 */
class SongOfflineJackpot extends AbstractMachineService
{
    // ========================================
    // 查询指令
    // ========================================
    public const MACHINE_POINT = '46cea2';         // 查询机台目前分数
    public const MACHINE_SCORE = '46cea5';         // 查询机台目前得分WIN
    public const MACHINE_TURN = '46cea6';          // 查询机台目前剩余转数
    public const WIN_NUMBER = '46cea9';            // 查询机台累积转数
    public const EXTERNAL_BUTTON_QUERY = '46ceac'; // 查询外部开洗分码表（线下版特有）

    // ========================================
    // 管理指令
    // ========================================
    public const CHECK = '46ccb4';                 // 故障排除
    public const CLEAR_EXTERNAL_BUTTON = '46ccb3'; // 清除外部按钮码表（线下版特有）
    public const CLEAR_LOG = '46ccba';             // 清除押得数值
    public const MACHINE_OPEN = '46cebe';          // 开机
    public const MACHINE_CLOSE = '46cebc';         // 关机
    public const REWARD_SWITCH = '46ceb8';         // 查询大赏灯

    // ========================================
    // 机台控制指令
    // ========================================
    public const AUTO_UP_TURN = '46cecd';          // 启动机台
    public const AUTO_STOP = '46cece';             // 停止机台
    public const PUSH_THREE = '46ceb6';            // 连发PUSH
    public const PUSH_ONE = '46ceb2';              // 单发PUSH

    // ========================================
    // 转数/珠数/分数转换
    // ========================================
    public const POINT_TO_TURN = '46cec1';         // 分数变转数1次
    public const TURN_UP_ALL = '46cecb';           // 分数全变转数
    public const TURN_TO_POINT = '46ceca';         // 转数→分数-下转一次
    public const TURN_DOWN_ALL = '46cec9';         // 转数换回分数
    public const SCORE_TO_POINT = '46cec8';        // win换回分数

    // ========================================
    // 资金操作指令
    // ========================================
    public const OPEN_ANY_POINT = '46ca';          // 开任意分数-上分
    public const WASH_ZERO = '46cc';               // 洗分清零-下分

    // ========================================
    // 兼容性别名（统一接口）
    // ========================================
    public const ALL = 'all';                      // 获取所有机台数据
    public const TESTING = '46c0';                 // 测试心跳

    /**
     * 初始化Redis缓存键名数组
     * 定义需要从Redis读取/写入的所有钢珠机状态字段（线下版特有字段）
     */
    protected function initializeCacheKeys(): void
    {
        $this->cacheDataKeyArr = [
            $this->cacheDataKey . '_auto',
            $this->cacheDataKey . '_reward_status',
            $this->cacheDataKey . '_rush_status',
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
            $this->cacheDataKey . '_win_number',
            $this->cacheDataKey . '_action_time',
            $this->cacheDataKey . '_push_auto',
            $this->cacheDataKey . '_change_point_card_status',
            $this->cacheDataKey . '_gift_bet',
            $this->cacheDataKey . '_now_turn',
            $this->cacheDataKey . '_has_lock',
            $this->cacheDataKey . '_pre_wash_point',
            // ========== 线下版特有字段 ==========
            $this->cacheDataKey . '_external_open_count',   // 外部开分码表
            $this->cacheDataKey . '_external_wash_count',   // 洗分码表
        ];
    }

    /**
     * 初始化机台信息字段列表
     * 定义需要通过WebSocket实时推送给前端的字段（线下版特有字段）
     */
    protected function initializeMachineInfo(): void
    {
        $this->machineInfo = [
            'auto',
            'reward_status',
            'rush_status',
            'turn',
            'has_lock',
        ];
    }

    /**
     * 初始化日志实例 - 使用专用的song_offline_jackpot_machine日志通道
     *
     * @return LoggerInterface 日志记录器实例
     */
    protected function initializeLogger(): LoggerInterface
    {
        return Log::channel('song_offline_jackpot_machine') ?? Log::channel('default');
    }

    /**
     * 处理发送指令时的错误
     * 特定指令失败时设置机台锁并发送异常通知（线下版特定）
     *
     * @param string $cmd 指令代码
     * @param Exception $e 异常对象
     */
    protected function handleSendCmdError(string $cmd, Exception $e): void
    {
        // 线下版钢珠机特定指令失败时设置机台锁
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
        $this->log->error('[线下版钢珠机] 发送指令异常', [
            'cmd' => $cmd,
            'machine_code' => $this->machine->code,
            'error' => $e->getMessage(),
            'protocol' => 'Song 协议（46前缀）',
        ]);
    }
}
