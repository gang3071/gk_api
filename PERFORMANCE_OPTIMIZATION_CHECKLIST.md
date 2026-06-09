# 机台指令性能优化实施清单

## ✅ 已完成：方案1 - HTTP Keep-Alive连接池

### 实施内容

**文件**：`app/service/machine/MachineClient.php`

**改动**：
1. ✅ 新增静态变量 `$httpClientPool` 存储HTTP客户端实例
2. ✅ 新增 `getHttpClient()` 方法配置连接池和Keep-Alive
3. ✅ 修改 `sendCommand()` 使用连接池
4. ✅ 修改 `checkOnline()` 使用连接池
5. ✅ 修改 `batchCheckOnline()` 使用连接池

**连接池配置**：
```php
'curl' => [
    CURLOPT_FORBID_REUSE => false,    // 允许连接复用
    CURLOPT_FRESH_CONNECT => false,   // 不强制新连接
    CURLOPT_MAXCONNECTS => 50,        // 最多保持50个Keep-Alive连接
    CURLOPT_TCP_KEEPALIVE => 1,       // 启用TCP Keep-Alive
    CURLOPT_TCP_KEEPIDLE => 60,       // 空闲60秒后发送探测包
    CURLOPT_TCP_KEEPINTVL => 10,      // 探测包间隔10秒
]
```

**预期效果**：
- ✅ 单次调用延迟：24ms → 21ms（减少12.5%）
- ✅ 连续调用延迟：72ms → 36ms（减少50%）
- ✅ 连接建立开销：3ms → 0.1ms（复用连接）
- ✅ HTTP连接数：减少约50%（Keep-Alive复用）

---

## 🔜 待实施：方案2 - 批量指令接口

### 2.1 识别需要批量优化的场景

基于 `functions.php` 的分析，发现以下连续调用场景：

#### 场景1：查询机台状态（第946-948行）
**位置**：`isAllowClientGivePoint()` 函数  
**指令数**：3次连续调用  
**代码**：
```php
$services->sendCmd($services::MACHINE_POINT, 0, 'player', $player->id);
$services->sendCmd($services::MACHINE_SCORE, 0, 'player', $player->id);
$services->sendCmd($services::MACHINE_TURN, 0, 'player', $player->id);
```
**性能损失**：约 **+48ms**（相比批量接口）

---

#### 场景2：洗分 - 钢珠机Song协议（第2701-2707行）
**位置**：`machineWash()` 函数  
**指令数**：最多6次连续调用  
**代码**：
```php
$services->sendCmd($services::MACHINE_TURN, 0, 'player', $player->id, $is_system);
$services->sendCmd($services::MACHINE_SCORE, 0, 'player', $player->id, $is_system);
$services->sendCmd($services::SCORE_TO_POINT, 0, 'player', $player->id, $is_system);
$services->sendCmd($services::TURN_DOWN_ALL, 0, 'player', $player->id, $is_system);
$services->sendCmd($services::MACHINE_POINT, 0, 'player', $player->id, $is_system);
$services->sendCmd($services::WIN_NUMBER, 0, 'player', $player->id, $is_system);
```
**性能损失**：约 **+84ms**（相比批量接口）

---

#### 场景3：洗分 - 老虎机（第2726-2729行）
**位置**：`machineWash()` 函数  
**指令数**：4次连续调用  
**代码**：
```php
$services->sendCmd($services::STOP_ONE, 0, 'player', $player->id, $is_system);
$services->sendCmd($services::STOP_TWO, 0, 'player', $player->id, $is_system);
$services->sendCmd($services::STOP_THREE, 0, 'player', $player->id, $is_system);
$services->sendCmd($services::READ_SCORE, 0, 'player', $player->id, $is_system);
```
**性能损失**：约 **+56ms**（相比批量接口）

---

#### 场景4：洗分清零（第2800-2801行）
**位置**：`machineWash()` 函数  
**指令数**：2次连续调用  
**代码**：
```php
$services->sendCmd($services::WASH_ZERO, 0, 'player', $player->id, $is_system);
$services->sendCmd($services::CLEAR_LOG, 0, 'player', $player->id, $is_system);
```
**性能损失**：约 **+28ms**（相比批量接口）

---

#### 场景5：洗分清零（第2805-2806行）
**位置**：`machineWash()` 函数  
**指令数**：2次连续调用  
**代码**：
```php
$services->sendCmd($services::WASH_ZERO, 0, 'player', $player->id, $is_system);
$services->sendCmd($services::ALL_DOWN, 0, 'player', $player->id, $is_system);
```
**性能损失**：约 **+28ms**（相比批量接口）

---

### 2.2 批量接口设计

#### gk_work 新增接口

**接口路径**：`POST /api/admin/machine/batch-send-cmd`

**请求参数**：
```json
{
  "machine_id": 1,
  "commands": [
    {"cmd": "46cea2", "data": 0},
    {"cmd": "46cea5", "data": 0},
    {"cmd": "46cea6", "data": 0}
  ],
  "lang": "zh_TW",
  "player_id": 123  // 可选，用于日志追踪
}
```

**响应格式**：
```json
{
  "code": 200,
  "msg": "success",
  "data": {
    "results": [
      {"cmd": "46cea2", "success": true, "message": ""},
      {"cmd": "46cea5", "success": true, "message": ""},
      {"cmd": "46cea6", "success": true, "message": ""}
    ],
    "success_count": 3,
    "failed_count": 0
  }
}
```

**错误处理**：
- 如果某个指令失败，继续执行后续指令
- 在 `results` 数组中标记失败的指令
- 返回成功和失败的统计数量

---

#### MachineClient 新增方法

**方法签名**：
```php
/**
 * 批量发送机台指令
 *
 * @param int $machineId 机台ID
 * @param array $commands 指令数组 [['cmd' => 'xxx', 'data' => 0], ...]
 * @param string $lang 语言
 * @param int|null $playerId 玩家ID
 * @return array 返回格式: ['success' => bool, 'data' => array, 'message' => string]
 * @throws Exception
 */
public function batchSendCommands(
    int $machineId,
    array $commands,
    string $lang = 'zh_TW',
    ?int $playerId = null
): array {
    $startTime = microtime(true);
    
    Log::info('[MachineClient] 批量发送机台指令', [
        'machine_id' => $machineId,
        'commands_count' => count($commands),
        'player_id' => $playerId,
    ]);
    
    try {
        $response = $this->getHttpClient()
            ->withHeaders([
                'Accept-Language' => $lang,
                'X-Admin-Id' => 0,
                'X-Player-Id' => $playerId ?? 0,
            ])
            ->post($this->baseUrl . '/api/admin/machine/batch-send-cmd', [
                'machine_id' => $machineId,
                'commands' => $commands,
                'lang' => $lang,
            ]);
        
        $duration = round((microtime(true) - $startTime) * 1000, 2);
        $body = $response->json();
        
        if ($response->successful() && isset($body['code']) && $body['code'] === 200) {
            Log::info('[MachineClient] 批量指令执行成功', [
                'machine_id' => $machineId,
                'commands_count' => count($commands),
                'success_count' => $body['data']['success_count'] ?? 0,
                'failed_count' => $body['data']['failed_count'] ?? 0,
                'duration_ms' => $duration,
            ]);
            
            return [
                'success' => true,
                'data' => $body['data'] ?? [],
                'message' => $body['msg'] ?? 'success',
            ];
        }
        
        Log::warning('[MachineClient] 批量指令执行失败', [
            'machine_id' => $machineId,
            'commands_count' => count($commands),
            'duration_ms' => $duration,
            'response_code' => $body['code'] ?? null,
        ]);
        
        return [
            'success' => false,
            'data' => [],
            'message' => $body['msg'] ?? 'Unknown error',
        ];
        
    } catch (RequestException $e) {
        $duration = round((microtime(true) - $startTime) * 1000, 2);
        
        Log::error('[MachineClient] 批量指令HTTP请求异常', [
            'machine_id' => $machineId,
            'commands_count' => count($commands),
            'duration_ms' => $duration,
            'error' => $e->getMessage(),
        ]);
        
        throw new Exception(trans('machine_batch_command_failed', [], 'message') . ': ' . $e->getMessage());
    }
}
```

---

### 2.3 functions.php 改造计划

#### 改造1：查询机台状态（第946-948行）

**改造前**（3次HTTP调用）：
```php
case GameType::TYPE_STEEL_BALL:
    $services->sendCmd($services::MACHINE_POINT, 0, 'player', $player->id);
    $services->sendCmd($services::MACHINE_SCORE, 0, 'player', $player->id);
    $services->sendCmd($services::MACHINE_TURN, 0, 'player', $player->id);
    if ($services->point > 0 || $services->score > 0 || $services->turn > 0) {
        return false;
    }
    break;
```

**改造后**（1次HTTP调用）：
```php
case GameType::TYPE_STEEL_BALL:
    $client = new MachineClient();
    $result = $client->batchSendCommands($machine->id, [
        ['cmd' => $services::MACHINE_POINT, 'data' => 0],
        ['cmd' => $services::MACHINE_SCORE, 'data' => 0],
        ['cmd' => $services::MACHINE_TURN, 'data' => 0],
    ], locale(), $player->id);
    
    if (!$result['success']) {
        throw new Exception(trans('machine_command_failed', [], 'message'));
    }
    
    // 重新读取Redis缓存数据
    if ($services->point > 0 || $services->score > 0 || $services->turn > 0) {
        return false;
    }
    break;
```

**性能提升**：72ms → 24ms（减少67%）

---

#### 改造2：洗分操作（第2701-2707行）

**改造建议**：根据不同条件组合指令数组，最后一次批量发送

**改造前**（串行发送）：
```php
if ($machine->control_type == Machine::CONTROL_TYPE_SONG) {
    if ($services->auto == 1) {
        $services->sendCmd($services::AUTO_UP_TURN, 0, 'player', $player->id, $is_system);
    }
    $services->sendCmd($services::MACHINE_TURN, 0, 'player', $player->id, $is_system);
    $services->sendCmd($services::MACHINE_SCORE, 0, 'player', $player->id, $is_system);
    if ($services->score > 0) {
        $services->sendCmd($services::SCORE_TO_POINT, 0, 'player', $player->id, $is_system);
    }
    if ($services->turn > 0) {
        $services->sendCmd($services::TURN_DOWN_ALL, 0, 'player', $player->id, $is_system);
    }
}
```

**改造后**（批量发送）：
```php
if ($machine->control_type == Machine::CONTROL_TYPE_SONG) {
    $commands = [];
    
    if ($services->auto == 1) {
        $commands[] = ['cmd' => $services::AUTO_UP_TURN, 'data' => 0];
    }
    
    $commands[] = ['cmd' => $services::MACHINE_TURN, 'data' => 0];
    $commands[] = ['cmd' => $services::MACHINE_SCORE, 'data' => 0];
    
    if ($services->score > 0) {
        $commands[] = ['cmd' => $services::SCORE_TO_POINT, 'data' => 0];
    }
    
    if ($services->turn > 0) {
        $commands[] = ['cmd' => $services::TURN_DOWN_ALL, 'data' => 0];
    }
    
    $client = new MachineClient();
    $result = $client->batchSendCommands($machine->id, $commands, locale(), $player->id);
    
    if (!$result['success']) {
        throw new Exception(trans('machine_wash_command_failed', [], 'message'));
    }
}
```

**性能提升**：约 **120ms → 24ms（减少80%）**

---

### 2.4 实施步骤

#### 第1步：gk_work 实现批量接口（预计6小时）

- [ ] 新建控制器方法 `batchSendCmd`
- [ ] 验证请求参数（机台ID、指令数组）
- [ ] 循环调用TCP发送指令
- [ ] 收集每个指令的执行结果
- [ ] 返回统一格式响应
- [ ] 单元测试

#### 第2步：MachineClient 新增方法（预计2小时）

- [ ] 新增 `batchSendCommands()` 方法
- [ ] 添加详细日志记录
- [ ] 异常处理
- [ ] 单元测试

#### 第3步：functions.php 批量替换（预计8小时）

- [ ] 改造场景1：查询机台状态
- [ ] 改造场景2：洗分 - 钢珠机Song协议
- [ ] 改造场景3：洗分 - 老虎机
- [ ] 改造场景4：洗分清零1
- [ ] 改造场景5：洗分清零2
- [ ] 功能回归测试

#### 第4步：性能测试验证（预计4小时）

- [ ] 使用Apache Bench模拟高并发
- [ ] 对比优化前后的延迟（P50/P95/P99）
- [ ] 监控HTTP连接数变化
- [ ] 监控Redis QPS变化
- [ ] 生成性能测试报告

**总工作量**：约 **20小时**（3个工作日）

---

## 🔮 未来计划：方案3 - 异步指令队列

### 适用场景

仅限于**不需要立即获取执行结果**的操作：
- 机台状态推送
- 日志记录类指令
- 非关键路径的操作

### 不适用场景

- ❌ 开分/洗分操作（需要立即知道是否成功）
- ❌ 查询机台状态（需要返回结果）
- ❌ 任何玩家可见的操作（需要同步响应）

### 评估标准

- 如果 QPS < 500：当前方案足够，无需引入
- 如果 QPS 500-2000：考虑引入异步队列
- 如果 QPS > 2000：必须引入异步队列或gRPC

---

## 📊 监控指标

### 关键指标定义

| 指标 | 说明 | 数据源 |
|------|------|--------|
| HTTP调用延迟P50 | 50%请求的延迟 | MachineClient日志 |
| HTTP调用延迟P95 | 95%请求的延迟 | MachineClient日志 |
| HTTP调用延迟P99 | 99%请求的延迟 | MachineClient日志 |
| HTTP错误率 | 5xx响应 / 总请求 | MachineClient日志 |
| HTTP连接数 | 当前活跃连接数 | netstat统计 |
| Redis QPS | 每秒查询数 | Redis INFO stats |

### 监控命令

#### 查看HTTP连接数
```bash
# Windows
netstat -ano | findstr ":8788" | findstr "ESTABLISHED" | find /c ":"

# Linux
netstat -an | grep :8788 | grep ESTABLISHED | wc -l
```

#### 查看TIME_WAIT连接数
```bash
# Windows
netstat -ano | findstr ":8788" | findstr "TIME_WAIT" | find /c ":"

# Linux
netstat -an | grep :8788 | grep TIME_WAIT | wc -l
```

#### 分析日志延迟
```bash
# 提取MachineClient日志中的耗时
grep "\[MachineClient\]" storage/logs/webman.log | grep "duration_ms" | awk '{print $NF}' | sort -n
```

### 告警阈值

| 指标 | 警告 | 严重 | 处理建议 |
|------|------|------|---------|
| P95延迟 | >100ms | >200ms | 检查gk_work服务负载 |
| P99延迟 | >200ms | >500ms | 检查网络连接质量 |
| HTTP错误率 | >1% | >5% | 检查gk_work日志错误 |
| HTTP连接数 | >1000 | >2000 | 增大连接池 / 启用批量接口 |
| TIME_WAIT数 | >5000 | >10000 | 检查Keep-Alive配置 |

---

## 🎯 总结

### 已完成优化

- ✅ **HTTP Keep-Alive连接池**（方案1）
  - 预期单次调用延迟：**24ms → 21ms**（-12.5%）
  - 预期连续调用延迟：**72ms → 36ms**（-50%）
  - 实施时间：**已完成**
  - 风险：**低**

### 待实施优化

- 🔜 **批量指令接口**（方案2）
  - 预期连续3次调用：**72ms → 24ms**（-67%）
  - 预期连续5次调用：**120ms → 24ms**（-80%）
  - 实施时间：**2周内**
  - 风险：**中**（需要gk_work配合）

### 未来计划

- ⏳ **异步指令队列**（方案3）
  - 仅在QPS > 500时考虑
  - 适用于非关键路径操作
  - 风险：**中高**（异步化改造）

- ⏳ **gRPC替代HTTP**（方案4）
  - 仅在QPS > 2000时考虑
  - 二进制协议 + HTTP/2
  - 风险：**高**（技术栈变更）

---

**文档创建日期**：2026-05-28  
**更新日期**：2026-05-28  
**负责人**：Claude Code (Sonnet 4.5)  
**状态**：方案1已完成，方案2待实施
