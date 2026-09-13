<?php

declare(strict_types=1);

namespace app\service\machine;

use app\model\Notice;
use Exception;
use Psr\Log\LoggerInterface;
use support\Log;

/**
 * SongOfflineSlot 线下版老虎机服务类（收账小卡协议 GD 2026-07-30）
 *
 * 职责：
 * - 从 Redis 读取机台状态
 * - 通过 HTTP 向 gk_work 发送机台指令
 * - 基于 Redis 状态变化进行实时推送
 *
 * 注意：TCP 连接和消息处理已迁移到 gk_work 项目
 *
 * 协议特点：
 * - 使用收账小卡协议（GD 2026-07-30）
 * - 必须登入才能进行上下分操作
 * - 上分固定100分/次
 * - 支持外部按钮开分/洗分（开分码表、洗分码表）
 *
 * @property int $auto 自动状态
 * @property int $reward_status 开奖状态
 * @property int $play_start_time 开始游戏时间
 * @property int $gaming_user_id 游戏中玩家
 * @property int $gaming 是否游戏中
 * @property int $point 当前分数（兼容字段，实际使用 machine_score）
 * @property int $score 当前得分（兼容字段，实际使用 card_score）
 * @property int $bet 机台压分
 * @property int $last_play_time 最后游戏时间
 * @property int $win 机台总得分
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
 * @property int $login_status 登入状态（线下版特有：0=未登入，1=已登入）
 * @property int $card_score 开分卡分数（线下版特有：心跳B1字段）
 * @property int $machine_score 机台分数（线下版特有：心跳B2字段）
 * @property int $total_bet 总押分数（线下版特有：心跳BA字段）
 * @property int $total_win 总得分数（线下版特有：心跳BB字段）
 * @property int $open_table 开分码表（线下版特有：外部开分累计）
 * @property int $wash_table 洗分码表（线下版特有：外部洗分累计）
 */
class SongOfflineSlot extends AbstractMachineService
{
    // ========================================
    // 查询指令（统一命名规范：READ_*）
    // ========================================
    public const READ_SCORE = 'eac4';              // 读取分数（查询账目：开分码表、洗分码表、开分卡分数、机台分数）
    public const READ_BET = 'ead8';                // 读取押分（查询总押分+总得分）
    public const READ_STATUS = 'ead4';             // 读取状态（查询机台情况：开分状态、洗分状态、转数）

    // ========================================
    // 登入/登出指令（线下版特有）
    // ========================================
    public const LOGIN = 'eac3';                   // 登入（機板回傳 A7 C3 表示登入中）
    public const LOGOUT = 'eac5';                  // 登出（機板回傳 A7 C5 表示登出中）

    // ========================================
    // 资金操作指令
    // ========================================
    public const OPEN_POINT = 'a5';                // 上分前缀（需拼接次数：A5 XX C0 SUM1 SUM2）
    public const WASH_POINT = 'a500c1';            // 下分（全部洗分：A5 00 C1 SUM1 SUM2）

    // ========================================
    // 管理指令（统一命名：ALL_DOWN/CHECK）
    // ========================================
    public const ALL_DOWN = 'eade';                // 清除历史记录（清除开洗分账+回补数）
    public const CHECK = 'a37005e0f8ce';           // 故排（归0机板，固定指令）
    public const SSR_SIGNAL = 'eaec';              // SSR讯号10秒（线下特有：预留给smart-slot移出按钮）

    // ========================================
    // 兼容性别名（统一接口）
    // ========================================
    public const ALL = 'all';                      // 获取所有机台数据
    public const OPEN_ANY_POINT = 'a5';            // 开任意分（映射到 OPEN_POINT）
    public const WASH_ZERO = 'a500c1';             // 洗分清零（映射到 WASH_POINT）

    /**
     * 初始化Redis缓存键名数组
     * 定义需要从Redis读取/写入的所有老虎机状态字段（线下版特有字段）
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
            // ========== 线下版特有字段 ==========
            $this->cacheDataKey . '_login_status',      // 登入状态
            $this->cacheDataKey . '_card_score',        // 开分卡分数
            $this->cacheDataKey . '_machine_score',     // 机台分数
            $this->cacheDataKey . '_total_bet',         // 总押分数
            $this->cacheDataKey . '_total_win',         // 总得分数
            $this->cacheDataKey . '_open_table',        // 开分码表
            $this->cacheDataKey . '_wash_table',        // 洗分码表
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
            'bet',
            'win',
            'has_lock',
            'login_status',      // 线下版特有：登入状态
        ];
    }

    /**
     * 初始化日志实例 - 使用专用的song_offline_slot_machine日志通道
     *
     * @return LoggerInterface 日志记录器实例
     */
    protected function initializeLogger(): LoggerInterface
    {
        return Log::channel('song_offline_slot_machine') ?? Log::channel('default');
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
        // 线下版特定指令失败时设置机台锁
        $lockCommands = [
            self::OPEN_POINT,
            self::WASH_POINT,
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
        $this->log->error('[线下版Slot] 发送指令异常', [
            'cmd' => $cmd,
            'machine_code' => $this->machine->code,
            'error' => $e->getMessage(),
            'protocol' => '收账小卡协议 GD 2026-07-30',
        ]);
    }
}
