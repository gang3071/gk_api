# 钢珠机/老虎机洗分事务一致性严重Bug分析

## 🚨 P0级严重问题

### 问题描述

**数据库事务提交在机台清零指令之前，导致可能出现"玩家拿到钱，但机台没清零"的严重bug**

### 影响范围

- ⚠️ **gk_api** 和 **gk_work** 都存在此问题
- ⚠️ 影响钢珠机和老虎机的所有下分/弃台操作

### 代码位置

| 项目 | 文件 | 函数 | 行号 |
|------|------|------|------|
| gk_api | app/functions.php | machineWash() | 3233-3285 |
| gk_work | app/functions.php | machineWash() | 1273-1290 |

---

## 📋 错误流程分析

### 当前代码结构

```php
// 1️⃣ 查询机台分数
$money = $services->point;  // 假设 = 1000分

// 2️⃣ 数据库事务开始
DB::beginTransaction();
try {
    // 3️⃣ 给玩家加钱
    machineWashZero($player, $machine, $money, ...);
    // → player.balance += 1000 ✅
    
    // 4️⃣ 更新游戏记录
    if ($path == 'leave') {
        $machine->gaming = 0;
        $machine->save();
    }
    
    // 5️⃣ ⚠️ 提交数据库事务（关键问题点）
    DB::commit();
    // ← 此时玩家钱已经到账！无法回滚！
    
    // 6️⃣ 发送机台清零指令（在事务外！）
    switch ($machine->type) {
        case GameType::TYPE_STEEL_BALL:
            $services->sendCmd($services::WASH_ZERO, 0, ...);
            $services->sendCmd($services::CLEAR_LOG, 0, ...);
            break;
        case GameType::TYPE_SLOT:
            $services->sendCmd($services::WASH_ZERO, 0, ...);
            $services->sendCmd($services::ALL_DOWN, 0, ...);
            break;
    }
    
} catch (Exception $e) {
    DB::rollback();  // ← 但rollback在commit之前！
    throw $e;
}
```

### 问题根源

```
┌─────────────────────────────────────────────┐
│          数据库事务范围                      │
├─────────────────────────────────────────────┤
│ 1. machineWashZero() - 玩家+钱              │
│ 2. $machine->save() - 更新游戏状态          │
│ 3. DB::commit() ✅ 提交                     │
└─────────────────────────────────────────────┘
              ↓
┌─────────────────────────────────────────────┐
│    ⚠️ 事务外操作（无法回滚）                 │
├─────────────────────────────────────────────┤
│ 4. sendCmd(WASH_ZERO) - 清零机台分数        │
│ 5. sendCmd(CLEAR_LOG) - 清除日志            │
│    ↓                                        │
│    如果失败 → 抛异常 ❌                      │
│    但数据库已commit，无法rollback            │
└─────────────────────────────────────────────┘
```

---

## 💥 问题场景

### 场景1：机台离线/网络故障

```
玩家操作：点击"下分1000"

时间线：
┌─────────────────────────────────────────┐
│ T0: 读取机台分数 = 1000                 │
│ T1: DB::beginTransaction()              │
│ T2: 玩家余额 +1000 → 余额变成 5000      │
│ T3: DB::commit() ✅                     │
│     → 数据库已提交，余额确定是5000       │
├─────────────────────────────────────────┤
│ T4: 发送 WASH_ZERO 指令...             │
│ T5: ❌ 机台离线！指令发送失败            │
│ T6: throw new Exception('指令失败')     │
└─────────────────────────────────────────┘

结果：
✅ 玩家余额 = 5000（拿到了1000）
❌ 机台分数 = 1000（没清零）
💰 玩家可以再次点击"下分" → 又拿到1000！
```

### 场景2：gk_work服务重启/崩溃

```
玩家操作：弃台下分2000

时间线：
┌─────────────────────────────────────────┐
│ T0: 执行弃台指令（下转、下珠）           │
│ T1: 读取机台分数 = 2000                 │
│ T2: DB::commit() ✅ 玩家+2000           │
├─────────────────────────────────────────┤
│ T3: 准备发送清零指令...                 │
│ T4: ⚠️ gk_work进程崩溃/重启             │
│ T5: ❌ 清零指令未发送                   │
└─────────────────────────────────────────┘

结果：
✅ 玩家拿到2000
❌ 机台分数 = 2000（仍然保留）
💰 重启后，下一个玩家可以拿这2000！
```

### 场景3：网络延迟/超时

```
玩家操作：下分500

时间线：
┌─────────────────────────────────────────┐
│ T0: DB::commit() ✅ 玩家+500            │
│ T1: 发送清零指令...                     │
│ T2: 网络延迟30秒...                     │
│ T3: 超时，返回失败                      │
└─────────────────────────────────────────┘

结果：
✅ 玩家拿到500
❓ 机台状态不确定：
   - 可能已清零（指令其实到了，但响应超时）
   - 可能未清零（指令真的没到）
   
不确定状态 → 数据一致性无法保证
```

### 场景4：并发操作（更严重）

```
两个玩家同时操作同一台机器：

T0: 玩家A读取分数 = 1000
T1: 玩家B读取分数 = 1000
T2: 玩家A commit ✅ 余额+1000
T3: 玩家B commit ✅ 余额+1000
T4: 玩家A发送清零 ✅
T5: 玩家B发送清零 ✅

结果：
❌ 两个玩家都拿到1000
❌ 但机台只有1000分
💰 平台损失1000
```

---

## 📊 财务影响评估

### 假设数据

| 参数 | 数值 |
|------|------|
| 每天下分次数 | 1,000次 |
| 机台清零失败率 | 0.1% ~ 1%（网络/离线/重启/超时）|
| 平均下分金额 | 500元 |

### 损失计算

**保守估计（0.1%失败率）**：
```
1,000次/天 × 0.1% × 500元 = 500元/天
500元/天 × 30天 = 15,000元/月
15,000元/月 × 12月 = 180,000元/年
```

**实际可能（1%失败率）**：
```
1,000次/天 × 1% × 500元 = 5,000元/天
5,000元/天 × 30天 = 150,000元/月
150,000元/月 × 12月 = 1,800,000元/年
```

**如果玩家发现可以重复下分（恶意利用）**：
```
损失可能 × 10 倍或更多
```

---

## 🔍 为什么会有这个设计？

### 可能的原因

1. **历史遗留**：
   - 最初可能没有考虑机台离线的情况
   - 假设机台指令永远成功

2. **误解事务边界**：
   - 开发者可能认为 try-catch 可以回滚一切
   - 忽略了 commit 后无法回滚

3. **性能考虑**：
   - 机台指令较慢（网络IO）
   - 不想阻塞数据库事务

4. **逐步演进**：
   - 一开始可能先有数据库操作
   - 后来加了机台清零，但没调整事务边界

---

## ✅ 修复方案

### 方案A：先清零，再加钱（推荐）

#### 优点
- ✅ 简单直接
- ✅ 不会出现"玩家拿钱，机台没清零"
- ✅ 最坏情况：机台清零了，但玩家没拿到钱（可人工处理）

#### 缺点
- ⚠️ 可能出现"机台清零了，但加钱失败"（概率极低）
- ⚠️ 需要重构代码

#### 实现

```php
function machineWash(...) {
    // ... 前面的逻辑不变 ...
    
    // 1️⃣ 先读取机台分数
    $money = $services->point;
    
    // 2️⃣ 先执行机台清零（在事务外）
    Log::info('[MachineWash] 准备清零机台', ['money' => $money]);
    
    $client = new MachineClient();
    switch ($machine->type) {
        case GameType::TYPE_STEEL_BALL:
            $result = $client->batchSendCommands($machine->id, [
                ['cmd' => $services::WASH_ZERO, 'data' => 0],
                ['cmd' => $services::CLEAR_LOG, 'data' => 0],
            ], $lang, $player->id, $washId ?? null);
            break;
        case GameType::TYPE_SLOT:
            $result = $client->batchSendCommands($machine->id, [
                ['cmd' => $services::WASH_ZERO, 'data' => 0],
                ['cmd' => $services::ALL_DOWN, 'data' => 0],
            ], $lang, $player->id, $washId ?? null);
            break;
    }
    
    // ✅ 检查清零是否成功
    $failedCount = $result['data']['failed_count'] ?? 0;
    if (!$result['success'] || $failedCount > 0) {
        Log::error('[MachineWash] 机台清零失败，终止下分', [
            'machine_id' => $machine->id,
            'player_id' => $player->id,
            'money' => $money,
            'error' => $result['message'],
        ]);
        throw new Exception('机台清零失败，请稍后再试');
    }
    
    Log::info('[MachineWash] 机台清零成功，开始数据库事务');
    
    // 3️⃣ 清零成功后，再执行数据库事务
    DB::beginTransaction();
    try {
        if ($money >= 0) {
            $machine = machineWashZero($player, $machine, $money, $is_system, 
                max($gamingPressure, 0), max($gamingScore, 0), max($gamingTurnPoint, 0), $path);
        }
        
        if ($path == 'leave') {
            if ($services->keeping == 1) {
                updateKeepingLog($machine->id, $player->id);
            }
            $machine->gaming = 0;
            $machine->gaming_user_id = 0;
            $machine->save();
            
            if ($machine->type == GameType::TYPE_STEEL_BALL) {
                $activityServices = new ActivityServices($machine, $player);
                $activityServices->playerFinishActivity(true);
            }
        }
        
        // 彩金检查...
        if ($machine->type == GameType::TYPE_SLOT) {
            // ...
        }
        
        // 更新Redis状态
        if ($path == 'leave') {
            $services->keeping_user_id = 0;
            $services->keeping = 0;
            $services->last_keep_at = 0;
            $services->keep_seconds = 0;
        }
        
        // 4️⃣ 提交数据库事务
        DB::commit();
        
        Log::info('[MachineWash] 下分完成', [
            'machine_id' => $machine->id,
            'player_id' => $player->id,
            'money' => $money,
        ]);
        
    } catch (Exception $e) {
        DB::rollback();
        
        // ⚠️ 数据库失败，但机台已清零
        // 需要记录日志，人工处理
        Log::error('[MachineWash] 数据库事务失败，但机台已清零（需人工处理）', [
            'machine_id' => $machine->id,
            'player_id' => $player->id,
            'money' => $money,
            'error' => $e->getMessage(),
        ]);
        
        throw new Exception('下分失败，请联系客服处理');
    }
    
    // ... 后续逻辑 ...
}
```

#### 补偿机制

如果"机台清零成功，但数据库失败"：

1. **记录到专门的日志表**：
   ```php
   CompensationLog::create([
       'type' => 'machine_wash_compensation',
       'machine_id' => $machine->id,
       'player_id' => $player->id,
       'amount' => $money,
       'status' => 'pending',
       'created_at' => now(),
   ]);
   ```

2. **后台定时任务**：
   - 每5分钟扫描一次补偿日志
   - 自动给玩家补发金额
   - 或者推送到客服系统人工处理

---

### 方案B：两阶段提交（复杂但更安全）

#### 原理

```
阶段1：准备
  - 锁定机台（Redis分布式锁）
  - 读取机台分数
  - 预留清零标记

阶段2：提交
  - 发送清零指令
  - 如果成功 → 数据库事务commit
  - 如果失败 → 释放锁，终止操作
```

#### 实现

```php
function machineWash(...) {
    // 1️⃣ 获取分布式锁
    $lockKey = "machine_wash_lock_{$machine->id}";
    $lock = Locker::lock($lockKey, 30);
    
    if (!$lock->acquire()) {
        throw new Exception('机台正在处理其他操作');
    }
    
    try {
        // 2️⃣ 读取机台分数
        $money = $services->point;
        
        // 3️⃣ 在Redis标记"准备清零"
        Redis::set("machine_wash_prepare_{$machine->id}", json_encode([
            'player_id' => $player->id,
            'money' => $money,
            'status' => 'preparing',
            'started_at' => time(),
        ]), 'EX', 60);
        
        // 4️⃣ 发送清零指令
        $result = $client->batchSendCommands(...);
        
        if (!$result['success']) {
            // 清零失败 → 清除准备标记
            Redis::del("machine_wash_prepare_{$machine->id}");
            throw new Exception('机台清零失败');
        }
        
        // 5️⃣ 清零成功，更新标记
        Redis::set("machine_wash_prepare_{$machine->id}", json_encode([
            'status' => 'cleared',
            'cleared_at' => time(),
        ]), 'EX', 60);
        
        // 6️⃣ 执行数据库事务
        DB::beginTransaction();
        try {
            machineWashZero(...);
            $machine->save();
            DB::commit();
            
            // 7️⃣ 清除准备标记
            Redis::del("machine_wash_prepare_{$machine->id}");
            
        } catch (Exception $e) {
            DB::rollback();
            
            // 数据库失败，记录补偿日志
            CompensationLog::create(...);
            throw $e;
        }
        
    } finally {
        $lock->release();
    }
}
```

#### 优点

- ✅ 更安全
- ✅ 有准备状态追踪
- ✅ 可以实现自动补偿

#### 缺点

- ❌ 复杂度高
- ❌ 需要额外的补偿任务
- ❌ 性能可能稍差（多次Redis操作）

---

### 方案C：异步补偿（最小改动）

保持现有逻辑，增加补偿机制：

```php
DB::commit();

try {
    // 发送清零指令
    $result = $client->batchSendCommands(...);
    
    if (!$result['success']) {
        // ⚠️ 清零失败，记录补偿日志
        CompensationLog::create([
            'type' => 'machine_clear_failed',
            'machine_id' => $machine->id,
            'player_id' => $player->id,
            'money' => $money,
            'action' => $path,
            'status' => 'pending',
        ]);
        
        // 推送告警
        sendAlert('机台清零失败', [...]);
        
        throw new Exception('机台清零失败，已记录待处理');
    }
} catch (Exception $e) {
    Log::error('清零指令失败', [
        'machine_id' => $machine->id,
        'error' => $e->getMessage(),
    ]);
    
    // 不抛异常，避免影响用户体验
    // 后台定时任务会处理
}
```

#### 优点

- ✅ 改动最小
- ✅ 不影响现有流程

#### 缺点

- ❌ 无法阻止损失发生
- ❌ 只能事后补救
- ❌ 需要人工介入

---

## 📝 建议的修复步骤

### 立即执行（紧急）

1. **添加监控和告警**：
   ```php
   if (!$result['success']) {
       // 立即告警
       sendTelegramAlert('⚠️ 机台清零失败', [
           'machine_id' => $machine->id,
           'player_id' => $player->id,
           'money' => $money,
       ]);
       
       // 记录到单独的日志文件
       Log::channel('machine_wash_failed')->error(...);
   }
   ```

2. **统计失败率**：
   - 查看最近7天的清零失败记录
   - 评估实际损失

### 短期（1-2周）

3. **实施方案A（先清零，再加钱）**：
   - 重构 `machineWash()` 函数
   - 充分测试
   - 灰度发布

4. **添加补偿机制**：
   - 创建 `CompensationLog` 表
   - 实现自动补偿任务

### 长期（1-2月）

5. **实施方案B（两阶段提交）**：
   - 如果方案A仍有问题
   - 实现更完善的分布式事务

6. **优化机台通信**：
   - 提高机台指令成功率
   - 减少网络超时
   - 增加重试机制

---

## 🔔 总结

### 问题严重性

| 等级 | P0（最高） |
|------|-----------|
| **影响范围** | 所有钢珠机和老虎机的下分/弃台 |
| **财务风险** | 15万~180万/年 |
| **可利用性** | 高（玩家可能发现并重复利用）|
| **修复难度** | 中等 |

### 立即行动

1. ✅ **添加监控** - 今天完成
2. ✅ **评估损失** - 3天内完成
3. ✅ **制定修复计划** - 1周内完成
4. ✅ **实施修复** - 2周内完成

### 教训

- ❌ 不要在事务外执行关键操作
- ❌ commit后无法rollback
- ✅ 先执行不可逆操作（清零），再执行可逆操作（加钱）
- ✅ 添加补偿机制
- ✅ 完善监控和告警

---

**分析人**: Claude Sonnet 4.5  
**分析时间**: 2026-07-16  
**严重程度**: ⛔ P0级严重bug  
**建议**: 立即修复
