<?php

declare(strict_types=1);

namespace app\service\machine;

use app\model\Machine;
use Exception;
use Psr\Log\LoggerInterface;
use support\Cache;
use support\Log;

/**
 * 机台服务抽象基类
 * 提取公共功能，减少代码重复
 */
abstract class AbstractMachineService implements BaseMachine
{
    /** 缓存前缀 */
    protected const CACHE_PREFIX = 'machine_tcp_action_cache_';
    protected const MACHINE_DATA_PREFIX = 'machine_tcp_data_cache_';

    /** 机台对象 */
    protected Machine $machine;

    /** 缓存 Key */
    protected string $cacheKey;
    protected string $cacheDataKey;

    /** 缓存数据 Key 数组 */
    protected array $cacheDataKeyArr = [];

    /** 语言 */
    protected string $lang;

    /** 机台信息字段列表 */
    protected array $machineInfo = [];

    /** 缓存数据 */
    protected array $cacheData = [];

    /** 日志实例 */
    protected LoggerInterface $log;

    /** 操作超时时间（微秒） */
    protected int $expirationTime = 5000000;

    /**
     * 构造函数
     *
     * @param Machine $machine 机台对象
     * @param string $lang 语言代码
     */
    public function __construct(Machine $machine, string $lang = 'zh_CN')
    {
        $this->machine = $machine;
        $this->lang = $lang;
        $this->cacheKey = self::CACHE_PREFIX . $this->machine->id;
        $this->cacheDataKey = self::MACHINE_DATA_PREFIX . $this->machine->id;

        // 子类初始化缓存 Key 和机台信息字段
        $this->initializeCacheKeys();
        $this->initializeMachineInfo();

        // 加载缓存数据
        $this->cacheData = $this->getMachineCache();

        // 初始化日志
        $this->log = $this->initializeLogger();
    }

    /**
     * 初始化缓存 Key 数组
     * 子类必须实现此方法
     */
    abstract protected function initializeCacheKeys(): void;

    /**
     * 初始化机台信息字段列表
     * 子类必须实现此方法
     */
    abstract protected function initializeMachineInfo(): void;

    /**
     * 批量获取机台缓存数据
     *
     * @return array
     */
    protected function getMachineCache(): array
    {
        try {
            $values = Cache::getMultiple($this->cacheDataKeyArr, 0);
            return is_array($values) ? $values : [];
        } catch (Exception $e) {
            Log::error('批量获取机台缓存失败', [
                'machine_id' => $this->machine->id,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * 初始化日志实例
     * 子类可以覆盖此方法使用不同的日志通道
     *
     * @return LoggerInterface
     */
    protected function initializeLogger(): LoggerInterface
    {
        return Log::channel('default');
    }

    /**
     * 魔术方法 - 获取机台属性
     * 从 Redis 缓存读取机台实时状态
     *
     * @param string $name 属性名
     * @return mixed
     */
    public function __get(string $name): mixed
    {
        $key = $this->cacheDataKey . '_' . $name;

        if (!in_array($key, $this->cacheDataKeyArr)) {
            return null;
        }

        try {
            return Cache::get($key, 0);
        } catch (Exception $e) {
            // 失败后重试1次
            try {
                $value = Cache::get($key, 0);
                Log::warning('Redis缓存读取失败后重试成功', [
                    'machine_id' => $this->machine->id,
                    'field' => $name,
                    'error' => $e->getMessage()
                ]);
                return $value;
            } catch (Exception $e2) {
                // 重试仍失败，记录错误并返回默认值
                Log::error('Redis缓存读取失败（重试1次后仍失败）', [
                    'machine_id' => $this->machine->id,
                    'machine_code' => $this->machine->code,
                    'field' => $name,
                    'key' => $key,
                    'error' => $e2->getMessage()
                ]);
                return 0;
            }
        }
    }

    /**
     * 魔术方法 - 设置机台属性
     * 将机台状态写入 Redis 缓存
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
            // 捕获异常后重试1次
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
            $criticalFields = ['gaming', 'gaming_user_id', 'last_play_time', 'point', 'bet', 'keeping'];
            if (in_array($name, $criticalFields)) {
                Log::error('关键字段Redis保存失败', [
                    'machine_id' => $this->machine->id,
                    'machine_code' => $this->machine->code,
                    'field' => $name,
                    'value' => $value
                ]);
            }
        }

        // 推送机台信息更新（如果需要）
        $this->pushMachineUpdate();
    }

    /**
     * 推送机台信息更新到 WebSocket
     * 实时推送机台状态变化到前端
     */
    protected function pushMachineUpdate(): void
    {
        if (!function_exists('sendSocketMessage')) {
            return;
        }

        try {
            // 获取机台信息字段
            $machineInfo = [];
            foreach ($this->machineInfo as $field) {
                $machineInfo[$field] = $this->$field ?? null;
            }

            if (empty($machineInfo)) {
                return;
            }

            // 推送到机台频道
            sendSocketMessage("machine-{$this->machine->id}", [
                'msg_type' => 'machine_status_update',
                'machine_id' => $this->machine->id,
                'code' => $this->machine->code,
                'status' => $machineInfo,
                'timestamp' => time(),
            ]);

            // 如果有玩家在游戏，也推送给玩家
            if ($this->machine->gaming && $this->machine->gaming_user_id) {
                sendSocketMessage("player-{$this->machine->gaming_user_id}", [
                    'msg_type' => 'my_machine_status_update',
                    'machine_id' => $this->machine->id,
                    'status' => $machineInfo,
                    'timestamp' => time(),
                ]);
            }
        } catch (Exception $e) {
            Log::warning('推送机台状态失败', [
                'machine_id' => $this->machine->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 发送机台指令
     * 使用 HTTP 调用 gk_work 的机台操作接口
     *
     * @param string $cmd 指令代码
     * @param int $data 指令数据
     * @param string $source 来源 (player/admin)
     * @param int $source_id 来源ID
     * @param int $isSystem 是否系统调用
     * @return bool
     * @throws Exception
     */
    public function sendCmd(
        string $cmd,
        int $data = 0,
        string $source = 'player',
        int $source_id = 0,
        int $isSystem = 0
    ): bool {
        try {
            // 使用 MachineClient 调用 gk_work 的机台操作接口
            $client = new MachineClient();
            $playerId = $source === 'player' ? $source_id : null;

            $result = $client->sendCommand(
                $this->machine->id,
                $cmd,
                $data,
                $this->lang,
                $playerId
            );

            if (!$result['success']) {
                throw new Exception($result['message']);
            }

            // 注意：操作日志记录已迁移到 gk_work
            return true;

        } catch (Exception $e) {
            // 子类特定的错误处理
            $this->handleSendCmdError($cmd, $e);
            throw $e;
        }
    }

    /**
     * 处理发送指令时的错误
     * 子类可以覆盖此方法实现特定的错误处理逻辑
     *
     * @param string $cmd 指令代码
     * @param Exception $e 异常对象
     */
    protected function handleSendCmdError(string $cmd, Exception $e): void
    {
        $this->log->error('发送指令异常', [
            'cmd' => $cmd,
            'machine_code' => $this->machine->code,
            'error' => $e->getMessage()
        ]);
    }

    /**
     * 获取机台操作描述
     * 子类应该实现此方法
     *
     * @param string $cmd 操作指令
     * @return string
     */
    abstract public function getDescription(string $cmd = ''): string;

    /**
     * 获取所有缓存数据
     *
     * @return array
     */
    protected function getAllData(): array
    {
        try {
            $data = [];
            foreach ($this->cacheDataKeyArr as $key) {
                $data[$key] = Cache::get($key, 0);
            }
            return $data;
        } catch (Exception $e) {
            Log::error('获取所有缓存数据失败', [
                'machine_id' => $this->machine->id,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
}
