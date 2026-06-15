# gk_work 批量指令接口实现规范

## 接口概述

**接口路径**：`POST /api/admin/machine/batch-send-cmd`

**用途**：批量发送机台指令，减少HTTP往返次数，提升性能

**优先级**：高（gk_api已完成代码改造，等待此接口上线）

---

## 请求规范

### Headers

```http
Accept-Language: zh_TW
X-Admin-Id: 0
X-Player-Id: 123
Content-Type: application/json
```

### 请求体

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

**字段说明**：

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| machine_id | integer | 是 | 机台ID |
| commands | array | 是 | 指令数组，至少包含1个指令 |
| commands[].cmd | string | 是 | 指令代码（如"46cea2"） |
| commands[].data | integer | 是 | 指令数据（通常为0） |
| lang | string | 否 | 语言代码，默认zh_TW |

---

## 响应规范

### 成功响应

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

### 部分失败响应

```json
{
  "code": 200,
  "msg": "部分指令执行失败",
  "data": {
    "results": [
      {"cmd": "46cea2", "success": true, "message": ""},
      {"cmd": "46cea5", "success": false, "message": "机台连接超时"},
      {"cmd": "46cea6", "success": true, "message": ""}
    ],
    "success_count": 2,
    "failed_count": 1
  }
}
```

### 错误响应

```json
{
  "code": 400,
  "msg": "machine_id不能为空",
  "data": {}
}
```

```json
{
  "code": 404,
  "msg": "机台不存在",
  "data": {}
}
```

```json
{
  "code": 500,
  "msg": "TCP连接异常: Connection refused",
  "data": {}
}
```

---

## 实现逻辑

### 伪代码

```php
public function batchSendCmd(Request $request)
{
    // 1. 验证请求参数
    $validator = Validator::make($request->all(), [
        'machine_id' => 'required|integer',
        'commands' => 'required|array|min:1|max:20',
        'commands.*.cmd' => 'required|string',
        'commands.*.data' => 'required|integer',
        'lang' => 'nullable|string',
    ]);

    if ($validator->fails()) {
        return jsonFailResponse($validator->errors()->first(), [], 400);
    }

    $machineId = $request->input('machine_id');
    $commands = $request->input('commands');
    $lang = $request->input('lang', 'zh_TW');

    // 2. 查询机台
    $machine = Machine::find($machineId);
    if (!$machine) {
        return jsonFailResponse(trans('machine_not_found', [], 'message', $lang), [], 404);
    }

    // 3. 获取机台TCP连接（复用现有连接管理）
    $tcpClient = $this->getMachineTcpClient($machine);
    if (!$tcpClient || !$tcpClient->isConnected()) {
        return jsonFailResponse(trans('machine_offline', [], 'message', $lang), [], 503);
    }

    // 4. 循环发送指令
    $results = [];
    $successCount = 0;
    $failedCount = 0;

    foreach ($commands as $command) {
        $cmd = $command['cmd'];
        $data = $command['data'];

        try {
            // 调用现有的sendCommand方法
            $this->sendCommand($tcpClient, $machine, $cmd, $data);

            $results[] = [
                'cmd' => $cmd,
                'success' => true,
                'message' => '',
            ];
            $successCount++;

            // 记录操作日志（可选，根据现有逻辑决定）
            $this->logMachineOperation($machine, $cmd, $data, true);

        } catch (\Exception $e) {
            $results[] = [
                'cmd' => $cmd,
                'success' => false,
                'message' => $e->getMessage(),
            ];
            $failedCount++;

            // 记录错误日志
            Log::error('[BatchSendCmd] 指令执行失败', [
                'machine_id' => $machineId,
                'cmd' => $cmd,
                'error' => $e->getMessage(),
            ]);

            // 继续执行后续指令（不中断）
        }
    }

    // 5. 返回结果
    return jsonSuccessResponse(
        $failedCount > 0 ? trans('batch_cmd_partial_success', [], 'message', $lang) : 'success',
        [
            'results' => $results,
            'success_count' => $successCount,
            'failed_count' => $failedCount,
        ]
    );
}
```

---

## 重要注意事项

### 1. 复用现有TCP连接

- ✅ **必须复用**现有的TCP连接池机制
- ❌ **不要**为每个指令创建新连接
- ✅ 使用 `getMachineTcpClient($machine)` 获取已建立的TCP连接

### 2. 错误处理策略

- ✅ **单个指令失败不中断**：继续执行后续指令
- ✅ **记录每个指令的执行结果**：success + message
- ✅ **统计成功/失败数量**：便于监控
- ❌ **不要因为一个指令失败就返回HTTP 5xx**

### 3. 操作日志

- 是否记录每个指令的操作日志，取决于现有逻辑
- 如果单个`sendCommand`会记录日志，批量接口也应该记录
- 建议：记录一条批量操作的汇总日志 + 每个指令的详细日志

### 4. 性能考虑

- 批量指令不要超过**20个**（建议限制max:20）
- 每个指令的TCP超时时间保持与单个接口一致
- 总执行时间 = 单个指令耗时 × 指令数量（串行执行）

### 5. Redis状态更新

- 每个指令执行后，TCP消息解析应该**立即更新Redis**
- gk_api通过Redis读取最新状态，无需等待HTTP响应
- 批量接口只需确保指令按顺序执行即可

---

## 测试用例

### 测试1：正常批量发送（3个指令）

**请求**：
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

**预期响应**：
- HTTP 200
- code: 200
- success_count: 3
- failed_count: 0

---

### 测试2：部分失败（中间指令失败）

**模拟场景**：第2个指令发送时TCP连接断开

**预期响应**：
- HTTP 200
- code: 200
- results[1].success: false
- success_count: 2
- failed_count: 1

---

### 测试3：参数校验失败

**请求**：
```json
{
  "machine_id": 1,
  "commands": []
}
```

**预期响应**：
- HTTP 200
- code: 400
- msg: "commands数组不能为空"

---

### 测试4：机台不存在

**请求**：
```json
{
  "machine_id": 99999,
  "commands": [{"cmd": "46cea2", "data": 0}]
}
```

**预期响应**：
- HTTP 200
- code: 404
- msg: "机台不存在"

---

### 测试5：机台离线

**模拟场景**：机台TCP连接未建立或已断开

**预期响应**：
- HTTP 200
- code: 503
- msg: "机台离线"

---

## 性能基准

### 单机性能目标

| 指标 | 目标值 |
|------|--------|
| 3个指令的总耗时 | < 30ms（P95） |
| 5个指令的总耗时 | < 50ms（P95） |
| 10个指令的总耗时 | < 100ms（P95） |
| 并发支持（QPS） | > 100 |

### 与单个接口对比

| 场景 | 单个接口（3次调用） | 批量接口（1次调用） | 性能提升 |
|------|-------------------|-------------------|---------|
| 网络往返时间 | 6ms × 3 = 18ms | 6ms × 1 = 6ms | **-67%** |
| HTTP连接建立 | 0.1ms × 3（Keep-Alive） | 0.1ms × 1 | **-67%** |
| JSON序列化 | 1ms × 3 = 3ms | 1ms × 1 = 1ms | **-67%** |
| TCP指令发送 | 10ms × 3 = 30ms | 10ms × 3 = 30ms | 0% |
| **总耗时** | **51ms** | **37ms** | **-27%** |

---

## 监控指标

### 日志格式

```php
Log::info('[BatchSendCmd] 批量指令执行完成', [
    'machine_id' => $machineId,
    'commands_count' => count($commands),
    'success_count' => $successCount,
    'failed_count' => $failedCount,
    'duration_ms' => $duration,
    'player_id' => $playerId,
]);
```

### Prometheus指标（可选）

```
# 批量指令执行次数
machine_batch_cmd_total{status="success"} 1234
machine_batch_cmd_total{status="partial_failed"} 56
machine_batch_cmd_total{status="failed"} 12

# 批量指令执行耗时（毫秒）
machine_batch_cmd_duration_ms{quantile="0.5"} 25
machine_batch_cmd_duration_ms{quantile="0.95"} 45
machine_batch_cmd_duration_ms{quantile="0.99"} 80

# 单个指令成功/失败数
machine_batch_cmd_commands_total{status="success"} 5678
machine_batch_cmd_commands_total{status="failed"} 123
```

---

## 上线计划

### 开发阶段（预计6小时）

- [ ] 新建控制器方法 `batchSendCmd`（2小时）
- [ ] 参数验证和错误处理（1小时）
- [ ] 循环调用现有TCP发送逻辑（2小时）
- [ ] 单元测试（1小时）

### 测试阶段（预计4小时）

- [ ] 本地环境测试5个用例（1小时）
- [ ] 测试环境压力测试（2小时）
- [ ] 性能基准验证（1小时）

### 上线阶段

- [ ] gk_work部署批量接口
- [ ] gk_api部署批量调用代码
- [ ] 监控日志输出
- [ ] 观察HTTP连接数变化
- [ ] 观察Redis QPS变化
- [ ] 性能对比报告

---

## FAQ

### Q1：为什么不并发执行所有指令？

**A**：机台指令有顺序依赖，必须串行执行。例如：
- 先发送 `MACHINE_TURN` 查询转数
- 再根据结果决定是否发送 `TURN_DOWN_ALL`

并发执行会导致指令顺序错乱，引发机台状态异常。

---

### Q2：如果中间某个指令失败，是否继续执行后续指令？

**A**：**继续执行**。原因：
- gk_api的现有逻辑是"尽力而为"（try-catch后继续）
- 批量接口应保持一致的错误处理策略
- 返回详细的results数组，让调用方决定如何处理

---

### Q3：是否需要事务支持？

**A**：**不需要**。原因：
- TCP指令无法回滚（机台已执行）
- Redis更新也无需事务（每个指令独立）
- 保持与单个接口一致的语义

---

### Q4：最多支持多少个指令？

**A**：建议限制在**20个以内**。原因：
- 避免单次请求时间过长（20个指令 × 10ms = 200ms）
- 减少HTTP超时风险
- gk_api当前最多场景是6个指令（洗分操作）

---

## 附录：gk_api调用示例

```php
use app\service\machine\MachineClient;

// 创建客户端
$client = new MachineClient();

// 批量发送指令
$result = $client->batchSendCommands($machine->id, [
    ['cmd' => $services::MACHINE_POINT, 'data' => 0],
    ['cmd' => $services::MACHINE_SCORE, 'data' => 0],
    ['cmd' => $services::MACHINE_TURN, 'data' => 0],
], locale(), $player->id);

// 检查结果
if (!$result['success']) {
    throw new Exception('批量查询机台状态失败: ' . $result['message']);
}

// 读取Redis更新后的数据
$point = $services->point;
$score = $services->score;
$turn = $services->turn;
```

---

**文档版本**：1.0  
**创建日期**：2026-05-28  
**负责人**：Claude Code (Sonnet 4.5)  
**状态**：待gk_work团队实施
