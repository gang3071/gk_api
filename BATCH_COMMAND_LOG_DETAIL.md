# 批量指令日志详细说明

## 概述

`MachineClient::batchSendCommands()` 方法现在提供了**完整的批量指令追踪日志**，可以精确定位每个指令的执行情况。

## 日志追踪 ID

### batch_id
每次批量指令调用生成唯一ID，格式：`batch_675a1234.56789abc`

通过 `batch_id` 可以追踪整个批次从开始到结束的所有日志。

## 日志层级结构

```
批量指令层 (MachineClient-Batch)
  ├─ 批量开始日志
  ├─ 单个指令层 (循环)
  │   ├─ 发送单个指令
  │   ├─ HTTP请求层 (MachineClient)
  │   │   ├─ 发送机台指令 - 请求
  │   │   └─ 指令执行成功/失败 - 响应
  │   └─ 指令执行成功/失败
  └─ 批量完成日志
```

## 关键日志点

### 1. 批量开始
```json
{
  "level": "INFO",
  "message": "[MachineClient-Batch] 批量发送机台指令 - 开始",
  "context": {
    "batch_id": "batch_675a1234.56789abc",
    "machine_id": 1274,
    "commands_count": 6,
    "commands_list": ["MOVE_POINT_OFF", "OUT_OFF", "STOP_ONE", "STOP_TWO", "STOP_THREE", "MACHINE_POINT"],
    "commands_detail": [
      {"cmd": "MOVE_POINT_OFF", "data": 0},
      {"cmd": "OUT_OFF", "data": 0},
      {"cmd": "STOP_ONE", "data": 0},
      {"cmd": "STOP_TWO", "data": 0},
      {"cmd": "STOP_THREE", "data": 0},
      {"cmd": "MACHINE_POINT", "data": 0}
    ],
    "player_id": 10,
    "lang": "zh-TW",
    "timestamp": "2026-06-05 16:43:22"
  }
}
```

**关键字段**:
- `batch_id`: 批次唯一ID
- `commands_count`: 指令总数
- `commands_list`: 指令名称列表（快速浏览）
- `commands_detail`: 指令详细信息（包含 data 参数）

### 2. 单个指令发送
```json
{
  "level": "INFO",
  "message": "[MachineClient-Batch] 发送单个指令",
  "context": {
    "batch_id": "batch_675a1234.56789abc",
    "index": 0,
    "cmd": "MOVE_POINT_OFF",
    "data": 0,
    "machine_id": 1274
  }
}
```

**关键字段**:
- `index`: 指令在批次中的索引（从0开始）
- `cmd`: 指令名称
- `data`: 指令数据

### 3. HTTP 请求（底层）
```json
{
  "level": "INFO",
  "message": "[MachineClient] 发送机台指令 - 请求",
  "context": {
    "url": "http://localhost:8788/api/v1/machine/send-cmd",
    "payload": {
      "machine_id": 1274,
      "cmd": "MOVE_POINT_OFF",
      "data": 0,
      "lang": "zh-TW"
    },
    "headers": {
      "Accept-Language": "zh-TW",
      "X-Player-Id": 10
    },
    "player_id": 10
  }
}
```

### 4. HTTP 响应（底层）
```json
{
  "level": "INFO",
  "message": "[MachineClient] 指令执行成功 - 响应",
  "context": {
    "machine_id": 1274,
    "cmd": "MOVE_POINT_OFF",
    "player_id": 10,
    "duration_ms": 123.45,
    "status_code": 200,
    "response_body": {
      "code": 200,
      "msg": "success",
      "data": {}
    }
  }
}
```

**关键字段**:
- `duration_ms`: HTTP 请求耗时（毫秒）
- `status_code`: HTTP 状态码
- `response_body`: gk_work 返回的完整响应

### 5. 单个指令完成
```json
{
  "level": "INFO",
  "message": "[MachineClient-Batch] 指令执行成功",
  "context": {
    "batch_id": "batch_675a1234.56789abc",
    "index": 0,
    "cmd": "MOVE_POINT_OFF",
    "duration_ms": 125.67
  }
}
```

或者失败：
```json
{
  "level": "WARNING",
  "message": "[MachineClient-Batch] 指令执行失败",
  "context": {
    "batch_id": "batch_675a1234.56789abc",
    "index": 2,
    "cmd": "STOP_ONE",
    "message": "Connection timeout",
    "duration_ms": 10005.23
  }
}
```

### 6. 批量完成
```json
{
  "level": "INFO",
  "message": "[MachineClient-Batch] 批量指令执行完成",
  "context": {
    "batch_id": "batch_675a1234.56789abc",
    "machine_id": 1274,
    "commands_count": 6,
    "success_count": 6,
    "failed_count": 0,
    "total_duration_ms": 678.92,
    "avg_duration_ms": 113.15,
    "results_summary": [
      {"cmd": "MOVE_POINT_OFF", "success": true, "duration_ms": 125.67},
      {"cmd": "OUT_OFF", "success": true, "duration_ms": 100.23},
      {"cmd": "STOP_ONE", "success": true, "duration_ms": 95.45},
      {"cmd": "STOP_TWO", "success": true, "duration_ms": 92.12},
      {"cmd": "STOP_THREE", "success": true, "duration_ms": 88.34},
      {"cmd": "MACHINE_POINT", "success": true, "duration_ms": 177.11}
    ],
    "timestamp": "2026-06-05 16:43:23"
  }
}
```

**关键字段**:
- `success_count`: 成功指令数
- `failed_count`: 失败指令数
- `total_duration_ms`: 批量指令总耗时
- `avg_duration_ms`: 平均每个指令耗时
- `results_summary`: 所有指令的摘要（快速查看哪个指令慢/失败）

## 完整日志示例

### 场景 1: 全部成功

```
[2026-06-05 16:43:22] INFO: [MachineClient-Batch] 批量发送机台指令 - 开始
  {"batch_id":"batch_675a1234","commands_count":6,"commands_list":["MOVE_POINT_OFF","OUT_OFF","STOP_ONE","STOP_TWO","STOP_THREE","MACHINE_POINT"]}

[2026-06-05 16:43:22] INFO: [MachineClient-Batch] 发送单个指令 {"batch_id":"batch_675a1234","index":0,"cmd":"MOVE_POINT_OFF"}
[2026-06-05 16:43:22] INFO: [MachineClient] 发送机台指令 - 请求 {"cmd":"MOVE_POINT_OFF"}
[2026-06-05 16:43:22] INFO: [MachineClient] 指令执行成功 - 响应 {"cmd":"MOVE_POINT_OFF","duration_ms":123}
[2026-06-05 16:43:22] INFO: [MachineClient-Batch] 指令执行成功 {"index":0,"cmd":"MOVE_POINT_OFF","duration_ms":125}

[2026-06-05 16:43:22] INFO: [MachineClient-Batch] 发送单个指令 {"batch_id":"batch_675a1234","index":1,"cmd":"OUT_OFF"}
[2026-06-05 16:43:22] INFO: [MachineClient] 发送机台指令 - 请求 {"cmd":"OUT_OFF"}
[2026-06-05 16:43:22] INFO: [MachineClient] 指令执行成功 - 响应 {"cmd":"OUT_OFF","duration_ms":98}
[2026-06-05 16:43:22] INFO: [MachineClient-Batch] 指令执行成功 {"index":1,"cmd":"OUT_OFF","duration_ms":100}

[2026-06-05 16:43:22] INFO: [MachineClient-Batch] 发送单个指令 {"index":2,"cmd":"STOP_ONE"}
[2026-06-05 16:43:22] INFO: [MachineClient] 指令执行成功 - 响应 {"cmd":"STOP_ONE","duration_ms":95}
[2026-06-05 16:43:22] INFO: [MachineClient-Batch] 指令执行成功 {"index":2,"cmd":"STOP_ONE","duration_ms":97}

[2026-06-05 16:43:22] INFO: [MachineClient-Batch] 发送单个指令 {"index":3,"cmd":"STOP_TWO"}
[2026-06-05 16:43:22] INFO: [MachineClient] 指令执行成功 - 响应 {"cmd":"STOP_TWO","duration_ms":92}
[2026-06-05 16:43:22] INFO: [MachineClient-Batch] 指令执行成功 {"index":3,"cmd":"STOP_TWO","duration_ms":94}

[2026-06-05 16:43:22] INFO: [MachineClient-Batch] 发送单个指令 {"index":4,"cmd":"STOP_THREE"}
[2026-06-05 16:43:22] INFO: [MachineClient] 指令执行成功 - 响应 {"cmd":"STOP_THREE","duration_ms":88}
[2026-06-05 16:43:22] INFO: [MachineClient-Batch] 指令执行成功 {"index":4,"cmd":"STOP_THREE","duration_ms":90}

[2026-06-05 16:43:22] INFO: [MachineClient-Batch] 发送单个指令 {"index":5,"cmd":"MACHINE_POINT"}
[2026-06-05 16:43:22] INFO: [MachineClient] 指令执行成功 - 响应 {"cmd":"MACHINE_POINT","duration_ms":175}
[2026-06-05 16:43:22] INFO: [MachineClient-Batch] 指令执行成功 {"index":5,"cmd":"MACHINE_POINT","duration_ms":177}

[2026-06-05 16:43:23] INFO: [MachineClient-Batch] 批量指令执行完成
  {"batch_id":"batch_675a1234","success_count":6,"failed_count":0,"total_duration_ms":683}
```

### 场景 2: 部分失败

```
[2026-06-05 16:43:22] INFO: [MachineClient-Batch] 批量发送机台指令 - 开始
  {"batch_id":"batch_675a5678","commands_count":6}

[2026-06-05 16:43:22] INFO: [MachineClient-Batch] 指令执行成功 {"index":0,"cmd":"MOVE_POINT_OFF"}
[2026-06-05 16:43:22] INFO: [MachineClient-Batch] 指令执行成功 {"index":1,"cmd":"OUT_OFF"}

[2026-06-05 16:43:22] INFO: [MachineClient-Batch] 发送单个指令 {"index":2,"cmd":"STOP_ONE"}
[2026-06-05 16:43:32] ERROR: [MachineClient] HTTP请求异常 {"cmd":"STOP_ONE","error":"Connection timeout"}
[2026-06-05 16:43:32] ERROR: [MachineClient-Batch] 指令执行异常 
  {"index":2,"cmd":"STOP_ONE","error":"机台指令失败: Connection timeout","duration_ms":10005}

[2026-06-05 16:43:32] INFO: [MachineClient-Batch] 指令执行成功 {"index":3,"cmd":"STOP_TWO"}
[2026-06-05 16:43:32] INFO: [MachineClient-Batch] 指令执行成功 {"index":4,"cmd":"STOP_THREE"}
[2026-06-05 16:43:32] INFO: [MachineClient-Batch] 指令执行成功 {"index":5,"cmd":"MACHINE_POINT"}

[2026-06-05 16:43:32] INFO: [MachineClient-Batch] 批量指令执行完成
  {"batch_id":"batch_675a5678","success_count":5,"failed_count":1,"total_duration_ms":10678}
```

### 场景 3: 性能问题

```
[2026-06-05 16:43:22] INFO: [MachineClient-Batch] 批量发送机台指令 - 开始
  {"batch_id":"batch_675a9999","commands_count":6}

... (指令执行) ...

[2026-06-05 16:43:25] INFO: [MachineClient-Batch] 批量指令执行完成
  {"total_duration_ms":3245,"avg_duration_ms":540}

[2026-06-05 16:43:25] WARNING: [MachineClient-Batch] 批量指令耗时过长
  {"batch_id":"batch_675a9999","duration_ms":3245,"threshold_ms":2000}
```

## 查询日志命令

### 1. 追踪特定批次
```bash
cd D:\gk_api

# 通过 batch_id 追踪完整批次
grep "batch_675a1234" runtime/logs/webman.log
```

### 2. 查看所有批量指令
```bash
# 实时监控
tail -f runtime/logs/webman.log | grep "\[MachineClient-Batch"

# 查看历史
grep "\[MachineClient-Batch" runtime/logs/webman.log | tail -100
```

### 3. 统计指令成功率
```bash
# 最近100次批量指令的统计
grep "批量指令执行完成" runtime/logs/webman.log | tail -100 | \
  grep -oP '(success_count|failed_count)":\K[0-9]+' | \
  paste - - | \
  awk '{success+=$1; failed+=$2; total+=$1+$2} 
       END {print "成功率:", (success/total*100)"%", "总指令:", total, "成功:", success, "失败:", failed}'
```

### 4. 找出最慢的指令
```bash
# 提取每个指令的耗时
grep "指令执行成功" runtime/logs/webman.log | tail -100 | \
  grep -oP '(cmd|duration_ms)":("|)\K[^",}]+' | \
  paste - - | \
  sort -k2 -nr | \
  head -10
```

输出示例：
```
MACHINE_POINT 178.23
MOVE_POINT_OFF 125.67
OUT_OFF 100.45
STOP_ONE 95.12
STOP_TWO 92.34
STOP_THREE 88.56
```

### 5. 查找失败的指令
```bash
# 失败的单个指令
grep "指令执行失败\|指令执行异常" runtime/logs/webman.log | tail -20

# 统计失败原因
grep "指令执行异常" runtime/logs/webman.log | \
  grep -oP 'error":"[^"]+' | \
  sort | uniq -c | \
  sort -nr
```

### 6. 性能分析
```bash
# 查找耗时超过2秒的批量指令
grep "批量指令耗时过长" runtime/logs/webman.log

# 统计平均批量耗时
grep "批量指令执行完成" runtime/logs/webman.log | tail -100 | \
  grep -oP 'total_duration_ms":\K[0-9.]+' | \
  awk '{sum+=$1; count++; if($1>max) max=$1; if(min=="" || $1<min) min=$1} 
       END {print "平均:", sum/count, "ms", "最大:", max, "ms", "最小:", min, "ms"}'
```

## 诊断场景

### 问题 1: 批量指令全部失败
```bash
# 1. 查找失败的批次
grep "批量指令执行完成.*failed_count\":[1-9]" runtime/logs/webman.log | tail -5

# 2. 提取 batch_id
BATCH_ID=$(grep "批量指令执行完成.*failed_count\":[1-9]" runtime/logs/webman.log | tail -1 | grep -oP 'batch_id":"batch_\K[^"]+')

# 3. 查看完整流程
grep "batch_$BATCH_ID" runtime/logs/webman.log

# 4. 检查 gk_work 是否在线
curl -s http://localhost:8788/api/v1/machine/check-online \
  -X POST \
  -H "Content-Type: application/json" \
  -d '{"machine_id":1274}'
```

### 问题 2: 某个特定指令总是失败
```bash
# 1. 统计各指令的失败次数
grep "指令执行失败\|指令执行异常" runtime/logs/webman.log | \
  grep -oP 'cmd":"[^"]+' | \
  sort | uniq -c | \
  sort -nr

# 输出示例：
#   45 cmd":"STOP_ONE       <-- STOP_ONE 失败45次
#    3 cmd":"MACHINE_POINT
#    1 cmd":"OUT_OFF

# 2. 查看 STOP_ONE 失败的详细原因
grep "cmd\":\"STOP_ONE" runtime/logs/webman.log | \
  grep "失败\|异常" | \
  tail -10
```

### 问题 3: 批量指令很慢
```bash
# 1. 查找耗时最长的批次
grep "批量指令执行完成" runtime/logs/webman.log | \
  grep -oP '(batch_id|total_duration_ms)":("|)\K[^",}]+' | \
  paste - - | \
  sort -k2 -nr | \
  head -5

# 输出示例：
# batch_675a9999 5678.45
# batch_675a8888 4234.12
# batch_675a7777 3456.78

# 2. 分析最慢批次中的每个指令
SLOW_BATCH="batch_675a9999"
grep "$SLOW_BATCH" runtime/logs/webman.log | \
  grep "指令执行成功\|指令执行失败" | \
  grep -oP '(cmd|duration_ms)":("|)\K[^",}]+' | \
  paste - -

# 输出示例：
# MOVE_POINT_OFF 1234.56  <-- 这个指令很慢！
# OUT_OFF 98.23
# STOP_ONE 92.12
```

## 相关文档

- [WASH_POINT_LOG_GUIDE.md](./WASH_POINT_LOG_GUIDE.md) - 完整洗分日志指南
- [WASH_POINT_FIX_SUMMARY.md](./WASH_POINT_FIX_SUMMARY.md) - 洗分功能修复总结

---

**文档版本**: 1.0  
**创建日期**: 2026-06-05  
**适用范围**: gk_api 项目 - MachineClient 批量指令
