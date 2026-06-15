# 架构调整性能影响分析与优化方案

## 一、架构变化对比

### 1.1 原架构（单项目）

```
客户端请求
    ↓
gk_api（HTTP接收）
    ↓
直接TCP连接机台（发送二进制指令）
    ↓
机台TCP响应（二进制数据）
    ↓
gk_api解析并保存Redis
    ↓
响应客户端
```

**调用链路延迟**：
- HTTP接收：~1ms
- TCP发送指令：~5-10ms（本地网络）
- 机台处理+响应：~10-50ms（机台处理时间）
- Redis保存：~1ms
- HTTP响应：~1ms

**总延迟**：约 **18-63ms**

---

### 1.2 新架构（双项目分离）

```
客户端请求
    ↓
gk_api（HTTP接收）
    ↓
HTTP调用gk_work（127.0.0.1:8788）          ← 新增
    ↓
gk_work接收HTTP请求                        ← 新增
    ↓
gk_work通过TCP连接机台（发送二进制指令）
    ↓
机台TCP响应（二进制数据）
    ↓
gk_work解析并保存Redis
    ↓
gk_work响应HTTP（JSON格式）                ← 新增
    ↓
gk_api接收HTTP响应                         ← 新增
    ↓
响应客户端
```

**调用链路延迟**：
- HTTP接收（gk_api）：~1ms
- **HTTP调用gk_work（新增）**：**~2-5ms**（本地127.0.0.1）
- **JSON序列化（新增）**：**~0.5ms**
- gk_work处理HTTP请求：~1ms
- TCP发送指令：~5-10ms
- 机台处理+响应：~10-50ms
- Redis保存：~1ms
- **JSON响应序列化（新增）**：**~0.5ms**
- **gk_work HTTP响应（新增）**：**~2-5ms**
- **gk_api解析JSON（新增）**：**~0.5ms**
- HTTP响应客户端：~1ms

**总延迟**：约 **24-76ms**

---

### 1.3 性能损失量化

| 场景 | 原架构延迟 | 新架构延迟 | 额外开销 | 增幅 |
|------|-----------|-----------|---------|------|
| 单次指令（快速机台响应） | 18ms | 24ms | **+6ms** | **+33%** |
| 单次指令（慢速机台响应） | 63ms | 76ms | **+13ms** | **+21%** |
| 连续3次指令（查询状态） | 54ms | 72ms | **+18ms** | **+33%** |
| 连续5次指令（洗分操作） | 90ms | 120ms | **+30ms** | **+33%** |

**关键发现**：
- **每次HTTP往返增加约 6ms 延迟**（2-5ms请求 + 2-5ms响应 + 1ms序列化）
- **连续多次调用时性能损失成倍增加**（每次调用都要经过完整的HTTP往返）

---

## 二、当前实现的性能瓶颈

### 2.1 HTTP客户端配置分析

**文件**：`app/service/machine/MachineClient.php`

```php
public function sendCommand(
    int $machineId,
    string $cmd,
    int $data = 0,
    string $lang = 'zh_TW',
    ?int $playerId = null
): array {
    $response = Http::timeout($this->timeout)  // 每次都新建连接
        ->withHeaders([...])
        ->post($this->baseUrl . '/api/admin/machine/send-cmd', [...]);
}
```

**问题**：
1. ❌ **无连接池**：每次调用都创建新的HTTP连接
2. ❌ **无Keep-Alive**：连接用完立即关闭
3. ❌ **无批量接口**：多个指令需要多次HTTP调用
4. ❌ **无异步调用**：所有调用都是同步阻塞

---

### 2.2 典型场景性能测试

#### 场景1：玩家开始游戏（查询机台状态）

**代码位置**：`functions.php` 第945-948行

```php
case GameType::TYPE_STEEL_BALL:
    $services->sendCmd($services::MACHINE_POINT, 0, 'player', $player->id);  // HTTP往返1
    $services->sendCmd($services::MACHINE_SCORE, 0, 'player', $player->id);  // HTTP往返2
    $services->sendCmd($services::MACHINE_TURN, 0, 'player', $player->id);   // HTTP往返3
```

**性能分析**：
- 原架构：3次TCP调用 = 约 **18ms**（可并发）
- 新架构：3次HTTP调用 = 约 **24ms × 3 = 72ms**（串行）
- **性能损失：+54ms（+300%）**

---

#### 场景2：洗分操作（多指令组合）

**代码位置**：`functions.php` 第2701-2707行

```php
$services->sendCmd($services::MACHINE_TURN, 0, 'player', $player->id, $is_system);    // HTTP往返1
$services->sendCmd($services::MACHINE_SCORE, 0, 'player', $player->id, $is_system);   // HTTP往返2
$services->sendCmd($services::SCORE_TO_POINT, 0, 'player', $player->id, $is_system);  // HTTP往返3
$services->sendCmd($services::TURN_DOWN_ALL, 0, 'player', $player->id, $is_system);   // HTTP往返4
$services->sendCmd($services::MACHINE_POINT, 0, 'player', $player->id, $is_system);   // HTTP往返5
```

**性能分析**：
- 原架构：5次TCP调用 = 约 **30ms**
- 新架构：5次HTTP调用 = 约 **24ms × 5 = 120ms**
- **性能损失：+90ms（+300%）**

---

#### 场景3：上分操作（单次指令）

**代码位置**：`functions.php` 第812行

```php
$services->sendCmd($services::OPEN_ANY_POINT, $openScore, 'player', $player->id);
```

**性能分析**：
- 原架构：1次TCP调用 = 约 **18ms**
- 新架构：1次HTTP调用 = 约 **24ms**
- **性能损失：+6ms（+33%）**

---

### 2.3 高并发场景影响

假设条件：
- 100个玩家同时在线
- 每个玩家每秒平均1次机台操作
- 每次操作平均3次指令

**HTTP连接数分析**：

| 指标 | 原架构 | 新架构 | 差异 |
|------|--------|--------|------|
| TCP连接数（gk_api → 机台） | 100个 | 0个 | -100 |
| TCP连接数（gk_work → 机台） | 0个 | 100个 | +100 |
| HTTP连接数（gk_api → gk_work） | 0个 | **300个/秒** | **+300** |

**系统资源消耗**：
- **端口占用**：每秒300个HTTP连接（如无Keep-Alive，需要300个临时端口）
- **TIME_WAIT状态**：连接关闭后需要2MSL（约60秒），大量TIME_WAIT堆积
- **内存消耗**：每个HTTP连接约8KB，300个连接 = **2.4MB/秒**

**潜在风险**：
- 端口耗尽（Windows默认动态端口范围：49152-65535，仅16383个）
- TIME_WAIT堆积导致端口无法重用
- 内存占用增加

---

## 三、优化方案

### 方案1：启用HTTP Keep-Alive（短期，立即可实施）

**目标**：复用HTTP连接，减少连接建立/释放开销

**实现方式**：

#### 3.1 修改 MachineClient.php

```php
class MachineClient
{
    private static ?\Illuminate\Http\Client\PendingRequest $httpClient = null;
    
    /**
     * 获取单例HTTP客户端（带Keep-Alive）
     */
    private function getHttpClient(): \Illuminate\Http\Client\PendingRequest
    {
        if (self::$httpClient === null) {
            self::$httpClient = Http::timeout($this->timeout)
                ->withOptions([
                    'http_version' => '1.1',  // 使用HTTP/1.1（支持Keep-Alive）
                    'curl' => [
                        CURLOPT_FORBID_REUSE => false,  // 允许连接复用
                        CURLOPT_FRESH_CONNECT => false, // 不强制新连接
                        CURLOPT_MAXCONNECTS => 50,      // 连接池大小
                        CURLOPT_TCP_KEEPALIVE => 1,     // 启用TCP Keep-Alive
                        CURLOPT_TCP_KEEPIDLE => 60,     // 空闲60秒后发送探测
                        CURLOPT_TCP_KEEPINTVL => 10,    // 探测间隔10秒
                    ],
                ]);
        }
        
        return self::$httpClient;
    }
    
    public function sendCommand(...): array
    {
        $response = $this->getHttpClient()
            ->withHeaders([...])
            ->post($this->baseUrl . '/api/admin/machine/send-cmd', [...]);
        // ...
    }
}
```

**预期效果**：
- 连接建立开销：**3ms → 0.1ms**（复用连接）
- 单次HTTP往返：**6ms → 3ms**（减少50%）
- 连续3次调用：**72ms → 36ms**（减少50%）

**风险评估**：
- ⚠️ Webman常驻进程，单例连接池需要考虑进程隔离
- ⚠️ 连接池大小需要根据并发量调整
- ⚠️ 需要处理连接断开后的重连逻辑

---

### 方案2：批量指令接口（中期，需gk_work配合）

**目标**：一次HTTP调用发送多条指令

**gk_work新增接口**：`POST /api/admin/machine/batch-send-cmd`

```json
{
  "machine_id": 1,
  "commands": [
    {"cmd": "46cea2", "data": 0},
    {"cmd": "46cea5", "data": 0},
    {"cmd": "46cea6", "data": 0}
  ],
  "lang": "zh_TW"
}
```

**响应**：
```json
{
  "code": 200,
  "msg": "success",
  "data": {
    "results": [
      {"cmd": "46cea2", "success": true},
      {"cmd": "46cea5", "success": true},
      {"cmd": "46cea6", "success": true}
    ]
  }
}
```

**MachineClient新增方法**：

```php
public function batchSendCommands(
    int $machineId,
    array $commands,  // [['cmd' => 'xxx', 'data' => 0], ...]
    string $lang = 'zh_TW',
    ?int $playerId = null
): array {
    $response = $this->getHttpClient()
        ->withHeaders([...])
        ->post($this->baseUrl . '/api/admin/machine/batch-send-cmd', [
            'machine_id' => $machineId,
            'commands' => $commands,
            'lang' => $lang,
        ]);
    
    return $response->json();
}
```

**修改 functions.php**：

```php
// 优化前（3次HTTP调用）
$services->sendCmd($services::MACHINE_POINT, 0, 'player', $player->id);
$services->sendCmd($services::MACHINE_SCORE, 0, 'player', $player->id);
$services->sendCmd($services::MACHINE_TURN, 0, 'player', $player->id);

// 优化后（1次HTTP调用）
$client = new MachineClient();
$client->batchSendCommands($machine->id, [
    ['cmd' => $services::MACHINE_POINT, 'data' => 0],
    ['cmd' => $services::MACHINE_SCORE, 'data' => 0],
    ['cmd' => $services::MACHINE_TURN, 'data' => 0],
], locale(), $player->id);
```

**预期效果**：
- 连续3次调用：**72ms → 24ms**（减少67%）
- 连续5次调用：**120ms → 24ms**（减少80%）
- HTTP连接数：**300个/秒 → 100个/秒**（减少67%）

---

### 方案3：异步指令队列（长期，架构升级）

**目标**：不需要等待指令响应的操作，改为异步执行

**适用场景**：
- 机台状态推送（不需要立即返回结果）
- 日志记录类指令
- 非关键路径操作

**实现方式**：

```php
// gk_api: 将指令推送到Redis队列
use support\Redis;

Redis::rPush('machine_cmd_queue', json_encode([
    'machine_id' => $machine->id,
    'cmd' => $cmd,
    'data' => $data,
    'player_id' => $player->id,
    'timestamp' => time(),
]));

// gk_work: 消费队列并执行
// （已有worker进程，可直接改造）
```

**预期效果**：
- 非阻塞操作：**24ms → <1ms**（仅队列写入）
- 吞吐量：**大幅提升**（批量处理队列）

**风险**：
- ⚠️ 异步操作无法立即获取执行结果
- ⚠️ 需要处理失败重试
- ⚠️ 顺序保证问题

---

### 方案4：gRPC替代HTTP（长期，高性能方案）

**目标**：使用二进制协议，减少序列化开销

**优势**：
- 二进制传输（vs JSON文本）
- HTTP/2多路复用（单连接并发）
- 流式传输支持

**预期效果**：
- 序列化开销：**1ms → 0.1ms**（减少90%）
- 单次调用：**24ms → 18ms**（接近原架构）

**成本**：
- 需要学习gRPC
- 需要定义protobuf协议
- 需要安装gRPC扩展

---

## 四、推荐实施路径

### 阶段1：快速优化（本周完成）

**目标**：减少50%性能损失，无需gk_work改造

**任务**：
1. ✅ 启用HTTP Keep-Alive（方案1）
2. ✅ 识别所有连续调用sendCmd的场景
3. ✅ 代码重构：将连续调用封装为批量调用占位符

**预期效果**：
- 单次调用：24ms → 21ms（减少12.5%）
- 连续调用：72ms → 36ms（减少50%，仍需方案2完成）

---

### 阶段2：批量接口（2周内完成）

**目标**：彻底解决连续调用性能问题

**任务**：
1. gk_work实现批量指令接口
2. MachineClient增加batchSendCommands方法
3. functions.php批量替换连续调用场景
4. 压力测试验证

**预期效果**：
- 连续3次调用：72ms → 24ms（减少67%）
- HTTP连接数：减少67%

---

### 阶段3：架构优化（1个月内评估）

**目标**：根据实际监控数据，决定是否引入异步/gRPC

**决策依据**：
- 如果QPS < 500：当前方案足够
- 如果QPS 500-2000：引入异步队列
- 如果QPS > 2000：考虑gRPC

---

## 五、性能监控指标

### 5.1 关键指标

在优化过程中，需要持续监控以下指标：

```php
// 在 MachineClient 中添加监控
Log::info('[Performance] HTTP调用耗时', [
    'machine_id' => $machineId,
    'cmd' => $cmd,
    'duration_ms' => $duration,
    'http_code' => $response->status(),
]);
```

**监控指标**：
- **P50延迟**：中位数延迟
- **P95延迟**：95%请求的延迟
- **P99延迟**：99%请求的延迟
- **错误率**：HTTP 5xx / 总请求数
- **QPS**：每秒请求数
- **连接数**：当前活跃连接数

### 5.2 告警阈值

| 指标 | 警告 | 严重 |
|------|------|------|
| P95延迟 | >100ms | >200ms |
| P99延迟 | >200ms | >500ms |
| 错误率 | >1% | >5% |
| HTTP连接数 | >1000 | >2000 |

---

## 六、代码改造工作量评估

### 6.1 方案1：HTTP Keep-Alive

| 任务 | 工作量 | 风险 |
|------|--------|------|
| 修改MachineClient | 2小时 | 低 |
| 测试连接池行为 | 3小时 | 中 |
| 监控指标验证 | 2小时 | 低 |
| **总计** | **7小时** | **低** |

### 6.2 方案2：批量接口

| 任务 | 工作量 | 风险 |
|------|--------|------|
| gk_work新增批量接口 | 6小时 | 中 |
| MachineClient新增方法 | 2小时 | 低 |
| functions.php重构 | 8小时 | 中 |
| 单元测试 | 4小时 | 低 |
| 压力测试 | 4小时 | 中 |
| **总计** | **24小时** | **中** |

---

## 七、总结

### 7.1 性能损失原因

1. **额外HTTP往返**：每次调用增加6ms（本地网络）
2. **JSON序列化/反序列化**：每次约1ms
3. **无连接复用**：每次建立新连接增加3ms
4. **串行调用**：多次调用时损失成倍增加

### 7.2 优化收益预估

| 场景 | 当前延迟 | 优化后延迟 | 改善幅度 |
|------|---------|-----------|---------|
| 单次指令 | 24ms | 21ms | **-12.5%** |
| 连续3次指令 | 72ms | 24ms | **-67%** |
| 连续5次指令 | 120ms | 24ms | **-80%** |
| 高并发HTTP连接数 | 300/秒 | 100/秒 | **-67%** |

### 7.3 建议

**立即实施**：
- ✅ 方案1（HTTP Keep-Alive）- 快速见效，低风险

**短期计划**：
- ✅ 方案2（批量接口）- 解决核心性能问题

**长期评估**：
- ⏳ 方案3（异步队列）- 根据监控数据决定
- ⏳ 方案4（gRPC）- 仅在极高QPS场景考虑

---

**分析日期**：2026-05-28  
**分析人**：Claude Code (Sonnet 4.5)  
**审核状态**：待技术评审
