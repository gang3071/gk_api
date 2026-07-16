# 钢珠机洗分弃台逻辑重大问题分析

## 审查时间
2026-07-16

## 审查范围
- **gk_api**: `app/functions.php` - `machineWash()` 函数（行2902-3400+）
- **gk_work**: `app/functions.php` - `machineWash()` 函数（行1145-1230）

---

## 🚨 发现的重大问题（gk_api 和 gk_work 都存在）

### ❌ **问题1：弃台指令执行顺序错误 - 玩家转数丢失（P0严重bug）**

#### 代码位置
- **gk_api**: 行2979-3043
- **gk_work**: 行1145-1185

#### 当前错误流程

```php
case GameType::TYPE_STEEL_BALL:
    // 1️⃣ 先执行弃台指令（清零转数）
    if ($path == 'leave') {
        if ($services->score > 0) {
            $services->sendCmd($services::SCORE_TO_POINT, ...);  // 得分→分数
        }
        if ($services->turn > 0) {
            $services->sendCmd($services::TURN_DOWN_ALL, ...);   // 转数→分数，同时清零player_win_number
        }
    }
    
    // 2️⃣ 再读取机台状态
    $services->sendCmd($services::MACHINE_POINT, ...);      // 读取分数 ✅
    $services->sendCmd($services::WIN_NUMBER, ...);         // 读取转数 ❌ 已被清零！
    
    // 3️⃣ 计算洗分金额
    $gamingTurnPoint = $services->player_win_number;  // ❌ = 0，错误！
    $money = $services->point;                        // ✅ 正确
```

#### 问题分析

**时间线示例**：

```
玩家游戏状态：
┌─────────────────────────────────────────────┐
│ score = 500（得分）                         │
│ turn = 1000（转数）                         │
│ point = 200（分数）                         │
│ player_win_number = 1000（玩家赢得的转数）   │
└─────────────────────────────────────────────┘
                    ↓
        【玩家点击弃台】
                    ↓
┌─────────────────────────────────────────────┐
│ T1: 执行弃台指令                            │
├─────────────────────────────────────────────┤
│ SCORE_TO_POINT 执行:                        │
│   → score 转入 point                        │
│   → point = 200 + 500 = 700                 │
│                                             │
│ TURN_DOWN_ALL 执行:                         │
│   → turn 转入 point                         │
│   → point = 700 + 1000 = 1700               │
│   → player_win_number 清零 = 0 ❌            │
└─────────────────────────────────────────────┘
                    ↓
┌─────────────────────────────────────────────┐
│ T2: 查询机台状态                            │
├─────────────────────────────────────────────┤
│ MACHINE_POINT 读取:                         │
│   → point = 1700 ✅                         │
│                                             │
│ WIN_NUMBER 读取:                            │
│   → player_win_number = 0 ❌                │
└─────────────────────────────────────────────┘
                    ↓
┌─────────────────────────────────────────────┐
│ T3: 计算洗分金额                            │
├─────────────────────────────────────────────┤
│ gamingTurnPoint = player_win_number = 0 ❌  │
│ money = point = 1700 ✅                     │
│                                             │
│ machineWashZero($money=1700, $turnPoint=0)  │
│   → 玩家拿到1700分 ✅                       │
│   → 但游戏记录显示转数=0 ❌                  │
└─────────────────────────────────────────────┘
```

#### 实际后果

1. **❌ 玩家转数丢失**：
   - 应该记录：`gamingTurnPoint = 1000`
   - 实际记录：`gamingTurnPoint = 0`

2. **❌ 游戏记录错误**：
   ```php
   // machineWashZero() 接收到的参数
   machineWashZero(
       $money = 1700,          // ✅ 正确
       $gamingTurnPoint = 0    // ❌ 错误，应该是1000
   );
   ```

3. **❌ 数据库记录错误**：
   ```sql
   -- player_game_record 表
   UPDATE player_game_record SET
       turn_point = 0,  -- ❌ 应该是1000
       ...
   ```

4. **❌ 统计报表错误**：
   - 玩家游戏历史显示转数为0
   - 机台收益统计不准确
   - 活动流水计算错误

#### 正确的执行顺序

**修复原则**：**先读取状态，再执行清零操作**

```php
case GameType::TYPE_STEEL_BALL:
    // ✅ 1️⃣ 先读取当前状态（在弃台指令执行前）
    $client = new MachineClient();
    $result = $client->batchSendCommands($machine->id, [
        ['cmd' => $services::MACHINE_POINT, 'data' => 0],
        ['cmd' => $services::WIN_NUMBER, 'data' => 0],
    ], $lang, $player->id, $washId ?? null);
    
    // ✅ 保存原始转数（弃台前的真实值）
    $gamingTurnPoint = $services->player_win_number;  // ✅ = 1000，正确！
    $originalPoint = $services->point;                // 保存原始分数
    
    // ✅ 2️⃣ 弃台时才执行下转/下珠
    if ($path == 'leave') {
        $leaveCommands = [];
        
        if ($machine->control_type == Machine::CONTROL_TYPE_MEI) {
            $leaveCommands[] = ['cmd' => $services::PUSH . $services::PUSH_STOP, 'data' => 0];
            if ($services->auto == 1) {
                $leaveCommands[] = ['cmd' => $services::AUTO_UP_TURN, 'data' => 0];
            }
            if ($services->score > 0) {
                $leaveCommands[] = ['cmd' => $services::SCORE_TO_POINT, 'data' => 0];
            }
            if ($services->turn > 0) {
                $leaveCommands[] = ['cmd' => $services::TURN_DOWN_ALL, 'data' => 0];
            }
        }
        
        if ($machine->control_type == Machine::CONTROL_TYPE_SONG) {
            if ($services->auto == 1) {
                $leaveCommands[] = ['cmd' => $services::AUTO_UP_TURN, 'data' => 0];
            }
            $leaveCommands[] = ['cmd' => $services::MACHINE_TURN, 'data' => 0];
            $leaveCommands[] = ['cmd' => $services::MACHINE_SCORE, 'data' => 0];
            if ($services->score > 0) {
                $leaveCommands[] = ['cmd' => $services::SCORE_TO_POINT, 'data' => 0];
            }
            if ($services->turn > 0) {
                $leaveCommands[] = ['cmd' => $services::TURN_DOWN_ALL, 'data' => 0];
            }
        }
        
        if (!empty($leaveCommands)) {
            $result = $client->batchSendCommands($machine->id, $leaveCommands, $lang, $player->id, $washId ?? null);
            
            if (!$result['success'] || ($result['data']['failed_count'] ?? 0) > 0) {
                throw new Exception('批量发送弃台指令失败（部分指令失败）: ' . $result['message']);
            }
        }
        
        // ✅ 3️⃣ 再次读取分数（转数已下到分数里了）
        $result = $client->batchSendCommands($machine->id, [
            ['cmd' => $services::MACHINE_POINT, 'data' => 0],
        ], $lang, $player->id, $washId ?? null);
    }
    
    // ✅ 4️⃣ 计算洗分金额
    $money = $services->point;  // ✅ 包含了转数下来的分数
    
    if (!empty($giftPoint)) {
        if ($money < $giftPoint['open_point'] * $giftPoint['condition']) {
            $money = max($money - $giftPoint['gift_point'], 0);
        }
    }
    
    break;
```

**修复后的效果**：

```
T1: 读取状态（弃台前）
    gamingTurnPoint = 1000 ✅
    originalPoint = 200

T2: 执行弃台指令
    SCORE_TO_POINT → point变成700
    TURN_DOWN_ALL → point变成1700，player_win_number变成0

T3: 再次读取分数
    money = 1700 ✅

T4: 保存记录
    machineWashZero($money=1700, $turnPoint=1000) ✅
    → 玩家拿到1700分 ✅
    → 游戏记录显示转数=1000 ✅
```

---

### ⚠️ **问题2：down（仅下分）也读取 WIN_NUMBER（性能浪费）**

#### 代码位置
- **gk_api**: 行3026-3037
- **gk_work**: 行1177-1178

```php
// ❌ 无论 down 还是 leave 都读取转数
$services->sendCmd($services::MACHINE_POINT, ...);
$services->sendCmd($services::WIN_NUMBER, ...);  // ❌ down时不需要
```

#### 问题
- `down`（仅下分）：不需要读取转数，只需要分数
- `leave`（弃台）：需要转数用于记录游戏历史

#### 影响
- 每次down操作多1次HTTP请求
- 高频下分时性能浪费

#### 修复
```php
// ✅ 根据操作类型读取不同数据
if ($path == 'leave') {
    // 弃台：需要转数和分数
    $commands = [
        ['cmd' => $services::MACHINE_POINT, 'data' => 0],
        ['cmd' => $services::WIN_NUMBER, 'data' => 0],
    ];
} else {
    // 仅下分：只需要分数
    $commands = [
        ['cmd' => $services::MACHINE_POINT, 'data' => 0],
    ];
    $gamingTurnPoint = 0;  // down操作不记录转数
}

$result = $client->batchSendCommands($machine->id, $commands, $lang, $player->id, $washId ?? null);
```

---

### ⚠️ **问题3：赠点扣除逻辑不完整（可能被利用）**

#### 代码位置
- **gk_api**: 行3041-3043
- **gk_work**: 行1181-1183

#### 当前代码
```php
$money = $services->point;
if (!empty($giftPoint) && $path == 'leave') {  // ❌ 只在leave时扣除
    $money = max($money - $giftPoint['gift_point'], 0);
}
```

#### 问题

1. **只在 leave 时扣除赠点**：
   ```
   玩家操作流程：
   1. 上分100（含赠点20）
   2. 多次 down 下分，每次拿走50（不扣赠点）
   3. 最后 leave 离开，扣除赠点20
   
   结果：玩家通过多次down套现了赠点
   ```

2. **没有检查流水条件**：
   - 老虎机有检查：`if ($money < $giftPoint['open_point'] * $giftPoint['condition'])`
   - 钢珠机没有检查

#### 对比老虎机的正确逻辑（行3149-3161）

```php
if (!empty($giftPoint)) {
    $originalMoney = $money;
    // ✅ 检查是否满足流水条件
    if ($money < $giftPoint['open_point'] * $giftPoint['condition']) {
        $money = max($money - $giftPoint['gift_point'], 0);
        Log::channel('machine_operations')->info('[MachineWash-Slot] 扣除赠点', [
            'original_money' => $originalMoney,
            'gift_point' => $giftPoint['gift_point'],
            'after_deduct' => $money,
        ]);
    }
}
```

#### 修复

```php
$money = $services->point;

// ✅ 统一的赠点扣除逻辑（down 和 leave 都检查）
if (!empty($giftPoint)) {
    $originalMoney = $money;
    
    // ✅ 检查是否满足流水条件
    // 流水不足 → 扣除赠点
    // 流水充足 → 赠点已转为真实金额，不扣除
    if ($money < $giftPoint['open_point'] * $giftPoint['condition']) {
        $money = max($money - $giftPoint['gift_point'], 0);
        
        Log::channel('machine_operations')->info('[MachineWash-SteelBall] 扣除赠点', [
            'wash_id' => $washId,
            'machine_id' => $machine->id,
            'path' => $path,
            'original_money' => $originalMoney,
            'gift_point' => $giftPoint['gift_point'],
            'open_point' => $giftPoint['open_point'],
            'condition' => $giftPoint['condition'],
            'required_flow' => $giftPoint['open_point'] * $giftPoint['condition'],
            'actual_flow' => $money,
            'after_deduct' => $money,
        ]);
    }
}
```

---

### ⚠️ **问题4：小淞机器的 MACHINE_TURN/MACHINE_SCORE 指令作用不明**

#### 代码位置
- **gk_api**: 行3003-3004
- **gk_work**: 行1167-1168

```php
if ($machine->control_type == Machine::CONTROL_TYPE_SONG) {
    // ...
    $services->sendCmd($services::MACHINE_TURN, 0, ...);
    $services->sendCmd($services::MACHINE_SCORE, 0, ...);
    // ...
}
```

#### 疑问
1. 这两个指令是**读取**还是**清零**？
2. 如果是读取，为什么在弃台时读取？
3. 如果是清零，为什么在 TURN_DOWN_ALL 和 SCORE_TO_POINT 之前清零？

#### 需要确认
- 查看 `SongJackpot` 类中这两个常量的定义
- 查看gk_work后台日志确认实际作用

---

## 📊 影响评估

### 问题1的影响（最严重）

| 影响项 | 严重程度 | 说明 |
|--------|---------|------|
| 数据准确性 | ⭐⭐⭐⭐⭐ | 所有钢珠机弃台的转数记录都是0 |
| 玩家体验 | ⭐⭐⭐ | 玩家看到游戏历史转数为0，疑似bug |
| 财务风险 | ⭐⭐ | 转数虽然丢失，但金额计算正确 |
| 活动流水 | ⭐⭐⭐⭐ | 活动流水统计可能不准确 |
| 数据分析 | ⭐⭐⭐⭐⭐ | 无法准确分析玩家游戏行为 |

### 问题2的影响（中等）

| 影响项 | 严重程度 | 说明 |
|--------|---------|------|
| 性能 | ⭐⭐ | 每次down多1次HTTP请求 |
| 成本 | ⭐ | 网络流量增加 |

### 问题3的影响（严重）

| 影响项 | 严重程度 | 说明 |
|--------|---------|------|
| 财务风险 | ⭐⭐⭐⭐ | 玩家可能套现赠点 |
| 公平性 | ⭐⭐⭐ | 钢珠机和老虎机赠点规则不一致 |

---

## 🔧 修复优先级

### P0 - 立即修复

1. **问题1：弃台指令执行顺序** - 数据准确性严重问题

### P1 - 近期修复

2. **问题3：赠点扣除逻辑** - 可能被利用

### P2 - 优化

3. **问题2：down时读取WIN_NUMBER** - 性能优化
4. **问题4：小淞机器指令确认** - 代码清晰度

---

## 📝 修复建议

### 方案A：最小修改（推荐先用）

只修复问题1，其他问题后续优化：

```php
case GameType::TYPE_STEEL_BALL:
    // ✅ 修复：先读取状态
    $client = new MachineClient();
    $result = $client->batchSendCommands($machine->id, [
        ['cmd' => $services::MACHINE_POINT, 'data' => 0],
        ['cmd' => $services::WIN_NUMBER, 'data' => 0],
    ], $lang, $player->id, $washId ?? null);
    
    $gamingTurnPoint = $services->player_win_number;  // ✅ 保存原始转数
    
    // ✅ 再执行弃台指令
    if ($path == 'leave') {
        // ... 弃台指令 ...
        
        // 弃台后再次读取分数
        $result = $client->batchSendCommands($machine->id, [
            ['cmd' => $services::MACHINE_POINT, 'data' => 0],
        ], $lang, $player->id, $washId ?? null);
    }
    
    $money = $services->point;
    // ... 后续逻辑不变 ...
```

### 方案B：完整修复（建议后续实施）

同时修复所有问题。

---

## ⚠️ 重要提醒

1. **两个项目都需要修复**：gk_api 和 gk_work
2. **数据迁移问题**：已有的错误记录无法修复
3. **需要测试**：
   - 弃台后检查游戏记录转数是否正确
   - 下分后检查赠点扣除是否正确
4. **监控**：修复后监控是否有新的异常

---

**审查人**: Claude Sonnet 4.5  
**审查时间**: 2026-07-16  
**审查结论**: ⛔ 发现P0级严重bug，建议立即修复
