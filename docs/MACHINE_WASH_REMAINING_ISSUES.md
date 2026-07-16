# 机台洗分逻辑剩余问题审查

## 审查时间
2026-07-16

## 已修复的问题

✅ **问题6**：清零指令改为单个发送  
✅ **问题7**：Redis更新时机统一到事务后  
✅ **问题8**：确认10秒超时足够（内网通信）

---

## ❌ 发现的新问题

### 🚨 问题A：弃台指令仍然是批量发送（遗漏）

**代码位置**: 行2979-3024

```php
// 弃台需要下转,下珠
if ($path == 'leave') {
    $leaveCommands = [];
    
    // ... 收集指令到数组 ...
    
    // ❌ 还是批量发送
    $result = $client->batchSendCommands($machine->id, $leaveCommands, ...);
    
    $failedCount = $result['data']['failed_count'] ?? 0;
    if (!$result['success'] || $failedCount > 0) {
        throw new Exception('批量发送弃台指令失败（部分指令失败）');
    }
}
```

**问题**：
- 如果 SCORE_TO_POINT 成功，但 TURN_DOWN_ALL 失败
- 得分已转为分数，但转数没转
- 机台处于不一致状态

**是否需要改为单个发送？**
- 如果需要，按你说的"关键指令失败立即终止"
- 关键指令：SCORE_TO_POINT, TURN_DOWN_ALL

---

### 🚨 问题B：查询指令是批量发送

**代码位置**: 行3026-3037

```php
// 批量查询机台状态
$result = $client->batchSendCommands($machine->id, [
    ['cmd' => $services::MACHINE_POINT, 'data' => 0],
    ['cmd' => $services::WIN_NUMBER, 'data' => 0],
], ...);

$failedCount = $result['data']['failed_count'] ?? 0;
if (!$result['success'] || $failedCount > 0) {
    throw new Exception('批量查询机台状态失败');
}
```

**问题**：
- 如果 MACHINE_POINT 成功，WIN_NUMBER 失败
- $services->point 有值，但 $services->player_win_number 没更新
- 可能导致数据不准确

**是否需要改为单个发送？**
- 查询指令都是关键的
- MACHINE_POINT 失败 → 无法知道洗分金额
- WIN_NUMBER 失败 → 无法记录转数

---

### 🚨 问题C：老虎机的批量指令

**代码位置**: 行3054-3162

```php
// 批量发送老虎机洗分指令
$slotCommands = [];

// 收集 MOVE_POINT_OFF, OUT_OFF, STOP_ONE, STOP_TWO, STOP_THREE, MACHINE_POINT

$result = $client->batchSendCommands($machine->id, $slotCommands, ...);

if (!$result['success'] || $failedCount > 0) {
    throw new Exception('批量发送老虎机洗分指令失败');
}
```

**是否需要改为单个发送？**
- STOP_ONE/TWO/THREE 可能不是关键指令（停止转轮）
- MACHINE_POINT 是关键指令（读取分数）

---

## 🤔 需要确认的问题

### 问题1：哪些指令是"关键指令"？

根据你说的"关键指令失败立即终止"，需要明确哪些指令是关键的：

#### 弃台指令（钢珠机）

| 指令 | 作用 | 是否关键？ |
|------|------|-----------|
| PUSH_STOP | 停止push | ❓ |
| AUTO_UP_TURN | 关闭自动上转 | ❓ |
| SCORE_TO_POINT | 得分→分数 | ❓ 应该是关键 |
| TURN_DOWN_ALL | 转数→分数 | ❓ 应该是关键 |
| MACHINE_TURN（小淞） | ❓ 作用未知 | ❓ |
| MACHINE_SCORE（小淞） | ❓ 作用未知 | ❓ |

#### 查询指令（钢珠机）

| 指令 | 作用 | 是否关键？ |
|------|------|-----------|
| MACHINE_POINT | 读取分数 | ✅ 肯定是关键 |
| WIN_NUMBER | 读取对奖次数 | ✅ 肯定是关键 |

#### 清零指令

| 指令 | 作用 | 是否关键？ |
|------|------|-----------|
| WASH_ZERO | 清零分数 | ✅ 已改为单个（关键） |
| CLEAR_LOG | 清除日志 | ✅ 已改为单个（关键） |
| ALL_DOWN（老虎机） | 全部下分 | ✅ 已改为单个（关键） |

#### 老虎机洗分指令

| 指令 | 作用 | 是否关键？ |
|------|------|-----------|
| MOVE_POINT_OFF | 关闭移分 | ❓ |
| OUT_OFF | 关闭自动 | ❓ |
| STOP_ONE/TWO/THREE | 停止转轮 | ❓ |
| MACHINE_POINT | 读取分数 | ✅ 肯定是关键 |
| READ_BET | 读取压分 | ✅ 可能是关键 |

---

### 问题2：非关键指令失败如何处理？

如果某些指令不是关键的（比如 PUSH_STOP, AUTO_UP_TURN），失败了应该：
- **选项A**：终止操作（最安全）
- **选项B**：记录日志，继续执行（可能有风险）
- **选项C**：重试1次，还失败就终止

---

### 问题3：批量发送 vs 单个发送的权衡

#### 批量发送的优点
- ✅ 减少网络往返次数（性能更好）
- ✅ 一次HTTP请求完成多个指令

#### 批量发送的缺点
- ❌ 可能部分成功、部分失败
- ❌ 机台可能处于不一致状态
- ❌ 难以精确知道是哪个指令失败

#### 单个发送的优点
- ✅ 每个指令立即检查
- ✅ 失败立即终止，不会部分成功
- ✅ 精确知道哪个指令失败

#### 单个发送的缺点
- ❌ 网络往返次数增加（性能稍差）
- ❌ 多次HTTP请求

#### 你的决定："关键指令改为单个发送"

这是一个很好的折中方案：
- 关键指令：单个发送（安全第一）
- 非关键指令：可以批量发送（性能优先）

但需要明确**哪些是关键指令**。

---

## 🎯 建议的修复方案

### 方案A：全部改为单个发送（最安全）

```php
// 弃台指令（钢珠机双美）
if ($path == 'leave') {
    // 1. PUSH_STOP
    $result = $client->sendCommand($machine->id, PUSH_STOP, ...);
    if (!$result['success']) {
        throw new Exception('PUSH_STOP失败');
    }
    
    // 2. AUTO_UP_TURN（如果开着）
    if ($services->auto == 1) {
        $result = $client->sendCommand($machine->id, AUTO_UP_TURN, ...);
        if (!$result['success']) {
            throw new Exception('AUTO_UP_TURN失败');
        }
    }
    
    // 3. SCORE_TO_POINT（如果有得分）
    if ($services->score > 0) {
        $result = $client->sendCommand($machine->id, SCORE_TO_POINT, ...);
        if (!$result['success']) {
            throw new Exception('SCORE_TO_POINT失败');
        }
    }
    
    // 4. TURN_DOWN_ALL（如果有转数）
    if ($services->turn > 0) {
        $result = $client->sendCommand($machine->id, TURN_DOWN_ALL, ...);
        if (!$result['success']) {
            throw new Exception('TURN_DOWN_ALL失败');
        }
    }
}

// 查询指令
$result = $client->sendCommand($machine->id, MACHINE_POINT, ...);
if (!$result['success']) {
    throw new Exception('读取分数失败');
}

$result = $client->sendCommand($machine->id, WIN_NUMBER, ...);
if (!$result['success']) {
    throw new Exception('读取转数失败');
}

$gamingTurnPoint = $services->player_win_number;
$money = $services->point;
```

**优点**：
- ✅ 最安全
- ✅ 任何指令失败都能精确知道

**缺点**：
- ❌ 网络请求次数最多（钢珠机弃台：最多4+2+2=8次HTTP）

---

### 方案B：只改关键指令为单个（推荐）

**定义关键指令**（需要你确认）：
- ✅ SCORE_TO_POINT（得分转分数）
- ✅ TURN_DOWN_ALL（转数转分数）
- ✅ MACHINE_POINT（读取分数）
- ✅ WIN_NUMBER（读取转数）
- ✅ WASH_ZERO（清零）
- ✅ CLEAR_LOG（清除日志）

**非关键指令可以批量**：
- PUSH_STOP
- AUTO_UP_TURN

```php
// 弃台指令：非关键的批量发送
if ($path == 'leave') {
    $nonCriticalCommands = [];
    
    // 非关键
    $nonCriticalCommands[] = ['cmd' => PUSH_STOP];
    if ($services->auto == 1) {
        $nonCriticalCommands[] = ['cmd' => AUTO_UP_TURN];
    }
    
    if (!empty($nonCriticalCommands)) {
        $result = $client->batchSendCommands(...);
        // 失败只记录日志，不终止
        if (!$result['success']) {
            Log::warning('非关键指令失败', [...]);
        }
    }
    
    // 关键指令：单个发送
    if ($services->score > 0) {
        $result = $client->sendCommand($machine->id, SCORE_TO_POINT, ...);
        if (!$result['success']) {
            throw new Exception('得分转换失败');
        }
    }
    
    if ($services->turn > 0) {
        $result = $client->sendCommand($machine->id, TURN_DOWN_ALL, ...);
        if (!$result['success']) {
            throw new Exception('转数转换失败');
        }
    }
}

// 查询指令：都是关键的，单个发送
$result = $client->sendCommand($machine->id, MACHINE_POINT, ...);
if (!$result['success']) {
    throw new Exception('读取分数失败');
}

$result = $client->sendCommand($machine->id, WIN_NUMBER, ...);
if (!$result['success']) {
    throw new Exception('读取转数失败');
}
```

**优点**：
- ✅ 安全（关键指令不会部分成功）
- ✅ 性能还可以（非关键指令批量）

**缺点**：
- ⚠️ 需要明确定义哪些是关键指令

---

### 方案C：保持批量，增强错误处理

```php
// 批量发送
$result = $client->batchSendCommands($machine->id, $commands, ...);

// 检查每个指令的结果
$results = $result['data']['results'] ?? [];
foreach ($results as $index => $cmdResult) {
    if (!($cmdResult['success'] ?? false)) {
        $failedCmd = $commands[$index]['cmd'] ?? 'unknown';
        
        // 根据指令类型决定是否终止
        if (in_array($failedCmd, ['SCORE_TO_POINT', 'TURN_DOWN_ALL', 'MACHINE_POINT', 'WIN_NUMBER'])) {
            // 关键指令失败 → 终止
            throw new Exception("关键指令 {$failedCmd} 失败");
        } else {
            // 非关键指令失败 → 记录日志
            Log::warning("非关键指令 {$failedCmd} 失败", [...]);
        }
    }
}
```

**优点**：
- ✅ 保持性能（批量发送）
- ✅ 区分关键/非关键指令

**缺点**：
- ❌ 关键指令失败时，前面的指令已经执行了（可能部分成功）
- ❌ 比方案A/B复杂

---

## 📋 需要你回答的问题

### 必须回答（决定修复方案）

1. **哪些指令是关键指令？**
   - PUSH_STOP 是否关键？
   - AUTO_UP_TURN 是否关键？
   - SCORE_TO_POINT 是否关键？（我认为是）
   - TURN_DOWN_ALL 是否关键？（我认为是）
   - MACHINE_TURN（小淞）是否关键？
   - MACHINE_SCORE（小淞）是否关键？

2. **采用哪个方案？**
   - 方案A：全部单个发送（最安全，性能稍差）
   - 方案B：关键指令单个，非关键批量（推荐）
   - 方案C：保持批量，增强检查（复杂）

3. **非关键指令失败如何处理？**
   - 终止操作（安全）
   - 记录日志继续（可能有风险）
   - 重试1次（折中）

---

## 总结

### 当前状态

✅ **已修复**：
- 清零指令：单个发送
- Redis更新：统一时机

❌ **未修复**：
- 弃台指令：仍然批量
- 查询指令：仍然批量
- 老虎机指令：仍然批量

### 建议

**我的建议**：采用**方案B**
- 关键指令（SCORE_TO_POINT, TURN_DOWN_ALL, MACHINE_POINT, WIN_NUMBER）改为单个
- 非关键指令（PUSH_STOP, AUTO_UP_TURN）可以批量或单个
- 查询指令全部单个（都是关键的）

**等待你的决定**。

---

**审查人**: Claude Sonnet 4.5  
**审查时间**: 2026-07-16  
**状态**: 等待用户反馈
