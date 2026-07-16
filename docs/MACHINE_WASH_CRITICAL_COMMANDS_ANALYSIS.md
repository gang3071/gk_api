# 机台指令关键性分析（基于缓存理解）

## 重要前提

**用户确认**：读取的指令都是读取的缓存数据（Redis）

这意味着：
- ✅ 读取指令非常快（读Redis）
- ✅ 读取指令很可靠（不太可能失败）
- ✅ 批量读取是合理的（多个Redis GET操作）

---

## 指令分类

### 📖 读操作（从Redis读缓存）

| 指令 | 作用 | 失败概率 | 关键性 |
|------|------|---------|--------|
| MACHINE_POINT | 读取分数 | 极低（读Redis） | ⚠️ 数据关键，但操作安全 |
| WIN_NUMBER | 读取转数 | 极低（读Redis） | ⚠️ 数据关键，但操作安全 |
| READ_BET | 读取压分 | 极低（读Redis） | ⚠️ 数据关键，但操作安全 |
| READ_SCORE | 读取得分 | 极低（读Redis） | ⚠️ 数据关键，但操作安全 |
| MACHINE_TURN | 读取转数 | 极低（读Redis） | ⚠️ 数据关键，但操作安全 |
| MACHINE_SCORE | 读取得分 | 极低（读Redis） | ⚠️ 数据关键，但操作安全 |

**结论**：
- 读取指令**批量发送是合理的**
- 失败概率极低（除非Redis挂了）
- 如果Redis挂了，单个发送也会失败

---

### ✍️ 写操作（改变机台状态）

#### 关键写操作（必须成功）

| 指令 | 作用 | 失败后果 | 关键性 |
|------|------|---------|--------|
| SCORE_TO_POINT | 得分→分数 | 得分丢失 | 🔴 极关键 |
| TURN_DOWN_ALL | 转数→分数 | 转数丢失 | 🔴 极关键 |
| WASH_ZERO | 清零分数 | 玩家拿钱机台没清零 | 🔴 极关键 |
| CLEAR_LOG | 清除日志 | 日志残留 | 🟡 中等关键 |
| ALL_DOWN（老虎机） | 全部下分 | 分数残留 | 🔴 极关键 |

**结论**：
- 这些指令**必须单个发送**
- 任何一个失败都应该终止操作

---

#### 辅助写操作（失败影响小）

| 指令 | 作用 | 失败后果 | 关键性 |
|------|------|---------|--------|
| PUSH_STOP | 停止push | push还在运行 | 🟢 不太关键 |
| AUTO_UP_TURN | 关闭自动上转 | 自动上转还开着 | 🟢 不太关键 |
| MOVE_POINT_OFF | 关闭移分 | 移分还开着 | 🟢 不太关键 |
| OUT_OFF | 关闭自动 | 自动还开着 | 🟢 不太关键 |
| STOP_ONE/TWO/THREE | 停止转轮 | 转轮还在转 | 🟢 不太关键 |

**结论**：
- 这些指令失败**不影响资金安全**
- 可以批量发送，或者失败了只记录日志

---

## 🎯 修复策略

### 策略：区分关键写操作和非关键操作

#### ✅ 需要单个发送的指令

**关键写操作**：
- SCORE_TO_POINT
- TURN_DOWN_ALL
- WASH_ZERO（✅ 已改为单个）
- CLEAR_LOG（✅ 已改为单个）
- ALL_DOWN（✅ 已改为单个）

**失败处理**：立即终止操作，不给玩家钱

---

#### ✅ 可以批量发送的指令

**读操作**（读Redis缓存）：
- MACHINE_POINT
- WIN_NUMBER
- READ_BET
- READ_SCORE
- 等等...

**非关键写操作**：
- PUSH_STOP
- AUTO_UP_TURN
- MOVE_POINT_OFF
- OUT_OFF
- STOP_ONE/TWO/THREE

**失败处理**：
- 读操作失败 → 终止（数据不全，无法计算金额）
- 非关键写操作失败 → 记录日志，继续（不影响资金）

---

## 🔧 具体修复方案

### 钢珠机弃台流程

```php
if ($path == 'leave') {
    // 1️⃣ 非关键指令批量发送（可选）
    $auxiliaryCommands = [];
    $auxiliaryCommands[] = ['cmd' => $services::PUSH_STOP];
    if ($services->auto == 1) {
        $auxiliaryCommands[] = ['cmd' => $services::AUTO_UP_TURN];
    }
    
    if (!empty($auxiliaryCommands)) {
        $result = $client->batchSendCommands($machine->id, $auxiliaryCommands, ...);
        // 失败只记录日志
        if (!$result['success']) {
            Log::warning('[MachineWash] 辅助指令失败（不影响继续）', [
                'commands' => $auxiliaryCommands,
                'error' => $result['message'],
            ]);
        }
    }
    
    // 2️⃣ 关键指令单个发送
    // SCORE_TO_POINT（如果有得分）
    if ($services->score > 0) {
        $result = $client->sendCommand($machine->id, $services::SCORE_TO_POINT, 0, ...);
        if (!$result['success']) {
            Log::error('[MachineWash] 得分转换失败，终止操作');
            throw new Exception('得分转换失败，请稍后再试');
        }
    }
    
    // TURN_DOWN_ALL（如果有转数）
    if ($services->turn > 0) {
        $result = $client->sendCommand($machine->id, $services::TURN_DOWN_ALL, 0, ...);
        if (!$result['success']) {
            Log::error('[MachineWash] 转数转换失败，终止操作');
            throw new Exception('转数转换失败，请稍后再试');
        }
    }
}

// 3️⃣ 读取指令批量发送（读Redis，快速可靠）
$result = $client->batchSendCommands($machine->id, [
    ['cmd' => $services::MACHINE_POINT],
    ['cmd' => $services::WIN_NUMBER],
], ...);

if (!$result['success']) {
    // 读取失败才终止（虽然概率极低）
    Log::error('[MachineWash] 读取机台数据失败，终止操作');
    throw new Exception('读取机台数据失败，请稍后再试');
}

$gamingTurnPoint = $services->player_win_number;
$money = $services->point;
```

---

### 老虎机流程

```php
// 1️⃣ 非关键指令批量发送
$auxiliaryCommands = [];
if ($services->move_point == 1) {
    $auxiliaryCommands[] = ['cmd' => $services::MOVE_POINT_OFF];
}
if ($services->auto == 1) {
    $auxiliaryCommands[] = ['cmd' => $services::OUT_OFF];
}
$auxiliaryCommands[] = ['cmd' => $services::STOP_ONE];
$auxiliaryCommands[] = ['cmd' => $services::STOP_TWO];
$auxiliaryCommands[] = ['cmd' => $services::STOP_THREE];

if (!empty($auxiliaryCommands)) {
    $result = $client->batchSendCommands($machine->id, $auxiliaryCommands, ...);
    // 失败只记录日志
    if (!$result['success']) {
        Log::warning('[MachineWash-Slot] 辅助指令失败（不影响继续）');
    }
}

// 2️⃣ 读取指令（读Redis）
$result = $client->sendCommand($machine->id, $services::MACHINE_POINT, 0, ...);
if (!$result['success']) {
    throw new Exception('读取分数失败');
}

$result = $client->sendCommand($machine->id, $services::READ_BET, 0, ...);
if (!$result['success']) {
    throw new Exception('读取压分失败');
}

// 计算金额...
```

---

## 📊 性能对比

### 当前（全部批量）

**钢珠机弃台**：
- 弃台指令：1次HTTP（4个指令）
- 读取指令：1次HTTP（2个指令）
- 清零指令：1次HTTP（2个指令）✅ 已改为单个
- **总计**：3次HTTP → **改后5次**

### 修复后（关键指令单个）

**钢珠机弃台**：
- 辅助指令：1次HTTP（PUSH_STOP, AUTO_UP_TURN）
- SCORE_TO_POINT：1次HTTP
- TURN_DOWN_ALL：1次HTTP
- 读取指令：1次HTTP（MACHINE_POINT, WIN_NUMBER 批量）
- WASH_ZERO：1次HTTP
- CLEAR_LOG：1次HTTP
- **总计**：6次HTTP

**增加的开销**：
- 3次 → 6次HTTP
- 每次HTTP约10-50ms（内网）
- 总增加时间：约30-150ms（可接受）

---

## ✅ 总结

### 核心原则

1. **关键写操作**：单个发送，失败立即终止
   - SCORE_TO_POINT
   - TURN_DOWN_ALL

2. **读操作**：批量发送（读Redis，快速可靠）
   - MACHINE_POINT
   - WIN_NUMBER
   - 等等...

3. **辅助写操作**：批量发送，失败只记录日志
   - PUSH_STOP
   - AUTO_UP_TURN
   - 等等...

### 需要修复的地方

❌ **弃台指令**（行2979-3024）：
- SCORE_TO_POINT 改为单个
- TURN_DOWN_ALL 改为单个
- 其他可以批量

✅ **读取指令**（行3026-3037）：
- 保持批量（读Redis，可靠）
- 但要检查失败

✅ **清零指令**：
- 已改为单个（✅ 完成）

---

**建议**：立即修复弃台指令中的 SCORE_TO_POINT 和 TURN_DOWN_ALL，改为单个发送。

---

**分析人**: Claude Sonnet 4.5  
**分析时间**: 2026-07-16  
**状态**: 等待确认修复
