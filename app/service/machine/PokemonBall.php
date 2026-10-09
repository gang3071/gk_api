<?php

declare(strict_types=1);

namespace app\service\machine;

use app\model\Notice;
use Exception;
use Psr\Log\LoggerInterface;
use support\Log;

/**
 * 精灵球机台服务类
 *
 * 职责：
 * - 从 Redis 读取机台状态
 * - 通过 HTTP 向 gk_work 发送机台指令（协议帧由 gk_work 的 PokemonBall 组装）
 * - 基于 Redis 状态变化进行实时推送
 *
 * 注意：TCP 连接、协议帧组装和消息解析都在 gk_work 项目
 *      本类的指令常量必须与 gk_work 的 PokemonBall 逐字一致
 *      （gk_work 的 buildCommandFrame 是 match ($cmd) 精确匹配）
 *
 * @property int $point 当前分数
 * @property int $score 当前得分
 * @property int $bet 本次压分
 * @property int $win 游戏结果
 * @property int $open_point 总上分
 * @property int $wash_point 总洗分
 * @property int $insert_money 总投入
 * @property int $gaming 游戏状态
 * @property int $gaming_user_id 游戏中玩家
 * @property int $keep_seconds 保留时长
 * @property int $keeping 保留状态
 * @property int $keeping_user_id 保留玩家
 * @property int $last_keep_at 最后保留时间
 * @property int $has_lock 机台锁
 * @property string $light_holes 亮灯洞口
 * @property string $fall_holes 落入洞口
 * @property int $jp_level 当前JP等级(1-5)
 * @property int $jackpot_score 彩金分数
 * @property int $ball_count 球数设置
 * @property int $light_count 灯数设置
 * @property int $game_type 游戏类型(1=A, 2=B)
 * @property string $multiplier 关卡倍数
 * @property string $uid 设备UID
 * @property string $version 主板版本号
 * @property int $door_status 开关门状态(1=开, 0=关)
 * @property int $game_enabled 游戏使能状态
 * @property int $last_point_at 最后上分时间
 * @property int $action_time 操作时间
 */
class PokemonBall extends AbstractMachineService
{
    // ==================== 操作指令常量 ====================
    // 注意：这些常量是语义名，由 gk_work 的 buildCommandFrame 映射为协议帧
    //      帧格式：FAEA | 命令类别 | 具体命令 | 数据长度 | 数据域 | 异或 | 和 | FBEB

    const ALL = 'all';                      // 机台状态（查询帐目 0x23）
    const WASH_ZERO = 'wash_zero';          // 洗分&清零（0x11）
    const OPEN_ANY_POINT = 'open_any';      // 开任意分（0x10 + 3字节分数）
    const SCORE_UP = 'score_up';            // 上分（0x10 + 3字节分数）
    const SCORE_DOWN = 'score_down';        // 下分（0x11）
    const GAME_ENABLE_ON = 'game_on';       // 允许游戏（0x03 + 0x01）
    const GAME_ENABLE_OFF = 'game_off';     // 禁止游戏（0x03 + 0x00）
    const GAME_END = 'game_end';            // 游戏结束（0x0C）
    const ENTER_JP1 = 'enter_jp1';          // 进入JP1（0x12）
    const SET_JACKPOT = 'set_jackpot';      // 设置彩金分数（0x14 + 3字节分数）
    const QUERY_UID = 'query_uid';          // 查询UID（0x16）
    const QUERY_ACCOUNT = 'query_account';  // 查询帐目（0x23）
    const SET_MULTIPLIER = 'set_multiplier'; // 设置关卡倍数（0x22 + 10字节）
    const ADD_SCORE = 'add_score';          // 加分（0x15 + 0x01）
    const SUB_SCORE = 'sub_score';          // 减分（0x15 + 0x02）
    const START_GAME = 'start_game';        // 启动一次（0x15 + 0x03）
    const AUTO_START = 'auto_start';        // 自动启动（0x15 + 0x04）
    const SET_BALL_COUNT = 'set_ball';      // 设置球数（0x21 + 数量）
    const SET_LIGHT_COUNT = 'set_light';    // 设置灯数（0x21 + 数量）

    /**
     * 初始化Redis缓存键名数组
     * 定义需要从Redis读取/写入的所有精灵球状态字段
     */
    protected function initializeCacheKeys(): void
    {
        $this->cacheDataKeyArr = [
            $this->cacheDataKey . '_point',
            $this->cacheDataKey . '_score',
            $this->cacheDataKey . '_bet',
            $this->cacheDataKey . '_win',
            $this->cacheDataKey . '_open_point',
            $this->cacheDataKey . '_wash_point',
            $this->cacheDataKey . '_insert_money',
            $this->cacheDataKey . '_gaming',
            $this->cacheDataKey . '_gaming_user_id',
            $this->cacheDataKey . '_keep_seconds',
            $this->cacheDataKey . '_keeping',
            $this->cacheDataKey . '_keeping_user_id',
            $this->cacheDataKey . '_last_keep_at',
            $this->cacheDataKey . '_has_lock',
            $this->cacheDataKey . '_light_holes',
            $this->cacheDataKey . '_fall_holes',
            $this->cacheDataKey . '_jp_level',
            $this->cacheDataKey . '_jackpot_score',
            $this->cacheDataKey . '_ball_count',
            $this->cacheDataKey . '_light_count',
            $this->cacheDataKey . '_game_type',
            $this->cacheDataKey . '_multiplier',
            $this->cacheDataKey . '_uid',
            $this->cacheDataKey . '_version',
            $this->cacheDataKey . '_door_status',
            $this->cacheDataKey . '_game_enabled',
            $this->cacheDataKey . '_last_point_at',
            $this->cacheDataKey . '_action_time',
        ];
    }

    /**
     * 初始化机台信息字段列表
     * 定义需要通过WebSocket实时推送给前端的字段
     */
    protected function initializeMachineInfo(): void
    {
        $this->machineInfo = [
            'point',
            'score',
            'bet',
            'win',
            'gaming',
            'has_lock',
            'jp_level',
            'jackpot_score',
            'game_enabled',
            'door_status',
        ];
    }

    /**
     * 初始化日志实例 - 使用专用的pokemon_ball_machine日志通道
     *
     * @return LoggerInterface 日志记录器实例
     */
    protected function initializeLogger(): LoggerInterface
    {
        return Log::channel('pokemon_ball_machine');
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
        // 上下分指令失败时设置机台锁
        $lockCommands = [
            self::OPEN_ANY_POINT,
            self::SCORE_UP,
            self::WASH_ZERO,
            self::SCORE_DOWN,
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
