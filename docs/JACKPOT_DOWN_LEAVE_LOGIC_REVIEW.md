# 钢珠机下分/弃台逻辑审查报告

## 审查时间
2026-07-16

## 审查对象
- **控制器**: `MachineController::jackPotAction` (行839-879)
- **核心函数**: `machineWash()` (app/functions.php 行2902+)

---

## 1. 控制器层面 (MachineController.php)

### case 'down' / case 'leave' (行839-879)

```php
case 'leave': // 下分弃台
case 'down': // 下分
    // 幂等性保护：request_id 为可选参数
    $requestId = $request->input('request_id');

    // 如果传了 request_id，则进行幂等性检查
    if (!empty($requestId)) {
        // 第一阶段：检查并预留幂等性（在业务校验之前）
        $existingResponse = $this->checkIdempotent($requestId, 'jackpot_action_' . $action, $player->id);
        if ($existingResponse) {
            return $existingResponse;
        }
        if (!$this->reserveIdempotent($requestId, 'jackpot_action_' . $action, $player->id)) {
            return jsonFailResponse('请求正在处理中，请稍后');
        }
    }

    // 业务校验和逻辑执行（包裹在 try-catch 中）
    try {
        if ($machine->gaming_user_id == 0) {
            throw new Exception(trans('no_open_point', [], 'message'));
        }
        if ($services->reward_status == 1) {
            throw new Exception(trans('machine_reward_drawing', ['{code}' => $machine->code], 'message'));
        }
        machineWash($player, $machine, $action, 0, $hasLottery);

        // 第三阶段：保存幂等性记录（如果有 request_id）
        $response = jsonSuccessResponse('success', []);
        if (!empty($requestId)) {
            $this->saveIdempotent($requestId, $response, 'jackpot_action_' . $action, $player->id);
        }
        return $response;
    } catch (Exception $e) {
        // 业务失败，释放预留（如果有 request_id）
        if (!empty($requestId)) {
            $this->releaseIdempotent($requestId);
        }
        throw $e;
    }
    break;
```

### ✅ 控制器层检查结果

| 项目 | 状态 | 说明 |
|------|------|------|
| **幂等性保护** | ✅ 正确 | request_id 可选，检查在业务逻辑前 |
| **业务校验** | ✅ 正确 | gaming_user_id==0 和 reward_status==1 |
| **异常处理** | ✅ 正确 | try-catch + releaseIdempotent |
| **参数传递** | ✅ 正确 | $action 正确传递给 machineWash |

---

## 2. machineWash 函数层面

### 钢珠机分支 (行2977-3044)

```php
case GameType::TYPE_STEEL_BALL:
    // 弃台需要下转,下珠
    if ($path == 'leave') {
        $leaveCommands = [];

        if ($machine->control_type == Machine::CONTROL_TYPE_MEI) {
            // 双美机器弃台指令
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
            // 小淞机器弃台指令
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

        // 批量发送所有弃台指令
        if (!empty($leaveCommands)) {
            $client = new MachineClient();
            $result = $client->batchSendCommands($machine->id, $leaveCommands, $lang, $player->id, $washId ?? null);

            // ✅ 检查批量指令是否全部成功
            $failedCount = $result['data']['failed_count'] ?? 0;
            if (!$result['success'] || $failedCount > 0) {
                throw new Exception('批量发送弃台指令失败（部分指令失败）: ' . $result['message']);
            }
        }
    }
    // 批量查询机台状态（优化：2次HTTP调用 → 1次）
    $client = new MachineClient();
    $result = $client->batchSendCommands($machine->id, [
        ['cmd' => $services::MACHINE_POINT, 'data' => 0],
        ['cmd' => $services::WIN_NUMBER, 'data' => 0],
    ], $lang, $player->id, $washId ?? null);

    // ✅ 检查批量指令是否全部成功
    $failedCount = $result['data']['failed_count'] ?? 0;
    if (!$result['success'] || $failedCount > 0) {
        throw new Exception('批量查询机台状态失败（部分指令失败）: ' . $result['message']);
    }

    $gamingTurnPoint = $services->player_win_number;
    $money = $services->point;
    if (!empty($giftPoint) && $path == 'leave') {
        $money = max($money - $giftPoint['gift_point'], 0);
    }
    break;
```

### 🔍 发现的问题

#### ❌ **问题1：down 和 leave 没有区分逻辑**

**现象**：
```php
case 'leave': // 下分弃台
case 'down': // 下分
    // ... 完全相同的代码
```

在 `machineWash` 中：
```php
if ($path == 'leave') {
    // 发送弃台指令（下转、下珠）
}
// 后面都一样：查询机台状态、计算洗分金额
```

**问题**：
- `down`（仅下分）也会执行 `$path == 'leave'` 的弃台指令 ❌
- 因为控制器传递的是 `$action`，而 `$action` 可能是 `'down'` 或 `'leave'`

**验证**：
```php
// MachineController.php 行864
machineWash($player, $machine, $action, 0, $hasLottery);
//                            ^^^^^^ 这里传的是 $action ('down' 或 'leave')

// machineWash 函数签名
function machineWash(
    Player  $player,
    Machine $machine,
    string  $path = 'leave',  // ← 参数名是 $path
    int     $is_system = 0,
    bool    $hasLottery = false
)

// 函数内判断
if ($path == 'leave') {  // ✅ 正确：只有 $path == 'leave' 才执行弃台指令
```

**结论**：经过验证，**逻辑正确**！
- `$action = 'down'` → `$path = 'down'` → 跳过弃台指令
- `$action = 'leave'` → `$path = 'leave'` → 执行弃台指令

#### ✅ **问题1修正：实际是正确的**

重新审查后发现，逻辑是对的：
- `down`：只查询机台状态 → 计算洗分金额 → 加钱
- `leave`：先执行弃台指令（下转、下珠）→ 查询机台状态 → 计算洗分金额 → 加钱

---

#### ⚠️ **问题2：下分后没有更新 gaming 状态**

**现象**：
```php
if ($path == 'leave') {
    if ($services->keeping == 1) {
        // 更新保留日志
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
```

**问题**：
- `down`（仅下分）**不会重置** `gaming` 和 `gaming_user_id` ❌
- 这意味着玩家下分后，机台仍然显示 `gaming=1`, `gaming_user_id=玩家ID`

**影响**：
1. 玩家下分 → 钱到账了
2. 但机台状态还是"游戏中" → 别人无法上分
3. 只有"弃台"才会释放机台

**判断**：这是**设计问题**还是**Bug**？

需要明确：
- **down（仅下分）**：玩家继续玩，机台不释放 → gaming=1 ✅
- **leave（弃台下分）**：玩家离开，机台释放 → gaming=0 ✅

**结论**：如果业务需求是"仅下分不离开"，则逻辑正确。

---

#### ⚠️ **问题3：Redis 状态未同步**

**现象**：
```php
$machine->gaming = 0;
$machine->gaming_user_id = 0;
$machine->save();  // ← 只写了数据库
```

**问题**：
- 只更新了**数据库**的 `gaming` 字段
- 没有更新 **Redis** 中的 `machine_tcp_data_cache_{id}_gaming`

**影响**：
- API 读取的是 Redis → 仍然显示 gaming=1 ❌
- 数据库显示 gaming=0
- 这就是你之前看到的"数据库和Redis不一致"的原因！

**修复建议**：
```php
if ($path == 'leave') {
    if ($services->keeping == 1) {
        updateKeepingLog($machine->id, $player->id);
    }
    
    // ✅ 同时更新数据库和Redis
    $machine->gaming = 0;
    $machine->gaming_user_id = 0;
    $machine->save();
    
    // ✅ 同步到 Redis
    $services->gaming = 0;
    $services->gaming_user_id = 0;
    // 或者直接调用 Redis::set()
    
    if ($machine->type == GameType::TYPE_STEEL_BALL) {
        $activityServices = new ActivityServices($machine, $player);
        $activityServices->playerFinishActivity(true);
    }
}
```

但是！根据前面的分析，**Redis 才是实时状态的标准**，数据库只是配置存储。

那么正确的做法应该是：
```php
if ($path == 'leave') {
    // ✅ 只更新 Redis（才是实时标准）
    $services->gaming = 0;
    $services->gaming_user_id = 0;
    
    // 数据库可以不更新（或者异步同步）
    // $machine->gaming = 0;  // ← 可选
    // $machine->save();
}
```

**但实际代码中更新了数据库，没更新Redis** → 这是设计不一致的地方。

---

## 3. 清零指令 (行3240-3278)

```php
case GameType::TYPE_STEEL_BALL:
    Log::channel('machine_operations')->info('[MachineWash-SteelBall] 准备发送清零指令', [
        'wash_id' => $washId,
        'machine_id' => $machine->id,
        'commands' => ['WASH_ZERO', 'CLEAR_LOG'],
    ]);

    $clearStartTime = microtime(true);
    $result = $client->batchSendCommands($machine->id, [
        ['cmd' => $services::WASH_ZERO, 'data' => 0],
        ['cmd' => $services::CLEAR_LOG, 'data' => 0],
    ], $lang, $player->id, $washId ?? null);
    $clearDuration = round((microtime(true) - $clearStartTime) * 1000, 2);

    Log::channel('machine_operations')->info('[MachineWash-SteelBall] 清零指令执行完成', [
        'wash_id' => $washId,
        'machine_id' => $machine->id,
        'success' => $result['success'],
        'duration_ms' => $clearDuration,
        'result' => $result,
    ]);

    // ✅ 检查批量指令是否全部成功（修复：检查 failed_count）
    $failedCount = $result['data']['failed_count'] ?? 0;
    if (!$result['success'] || $failedCount > 0) {
        Log::channel('machine_operations')->error('[MachineWash-SteelBall] 清零指令发送失败', [
            'wash_id' => $washId,
            'machine_id' => $machine->id,
            'error' => $result['message'] ?? 'Unknown error',
            'failed_count' => $failedCount,
            'failed_commands' => array_filter($result['data']['results'] ?? [], function($r) {
                return !($r['success'] ?? false);
            }),
        ]);
        throw new Exception('批量发送洗分清零指令失败（部分指令失败）: ' . $result['message']);
    }

    $services->player_win_number = 0;
    break;
```

### ✅ 清零逻辑检查结果

| 项目 | 状态 | 说明 |
|------|------|------|
| **批量指令** | ✅ 正确 | WASH_ZERO + CLEAR_LOG 批量发送 |
| **失败检查** | ✅ 正确 | 检查 failed_count，任何失败都抛异常 |
| **日志完善** | ✅ 优秀 | 详细记录执行时间和结果 |
| **Redis 更新** | ✅ 正确 | player_win_number = 0 |

---

## 4. 整体流程图

### down（仅下分）

```
客户端请求 down
    ↓
幂等性检查（可选）
    ↓
业务校验：
  - gaming_user_id != 0
  - reward_status != 1
    ↓
machineWash($player, $machine, 'down', ...)
    ↓
【不执行弃台指令】（因为 $path != 'leave'）
    ↓
批量查询机台状态：
  - MACHINE_POINT
  - WIN_NUMBER
    ↓
计算洗分金额：
  - money = $services->point
  - gamingTurnPoint = $services->player_win_number
    ↓
数据库事务：
  - machineWashZero() → 玩家加钱
  - 【不更新 gaming 状态】
    ↓
发送清零指令：
  - WASH_ZERO
  - CLEAR_LOG
    ↓
返回成功
```

**关键点**：
- ✅ 玩家拿到钱
- ❌ 机台仍然是 gaming=1（玩家继续占用）
- ✅ 机台 point 清零

### leave（弃台下分）

```
客户端请求 leave
    ↓
幂等性检查（可选）
    ↓
业务校验：
  - gaming_user_id != 0
  - reward_status != 1
    ↓
machineWash($player, $machine, 'leave', ...)
    ↓
【执行弃台指令】（$path == 'leave'）：
  双美机器：
    - PUSH_STOP（停止push）
    - AUTO_UP_TURN（关自动上转）
    - SCORE_TO_POINT（得分转分数）
    - TURN_DOWN_ALL（全部下转）
  小淞机器：
    - AUTO_UP_TURN
    - MACHINE_TURN
    - MACHINE_SCORE
    - SCORE_TO_POINT
    - TURN_DOWN_ALL
    ↓
批量查询机台状态：
  - MACHINE_POINT
  - WIN_NUMBER
    ↓
计算洗分金额：
  - money = $services->point
  - 如果有赠点 → money -= gift_point
    ↓
数据库事务：
  - machineWashZero() → 玩家加钱
  - ✅ 更新 gaming = 0, gaming_user_id = 0
  - ✅ 结束活动（playerFinishActivity）
    ↓
发送清零指令：
  - WASH_ZERO
  - CLEAR_LOG
    ↓
返回成功
```

**关键点**：
- ✅ 玩家拿到钱
- ✅ 机台释放（但**只更新了数据库**，没更新Redis）
- ✅ 机台 point 清零
- ✅ 活动结束

---

## 5. 问题总结

### ❌ 严重问题

#### 问题1：弃台时 Redis 状态未同步

**文件**: `app/functions.php` 行3197-3211

**问题**:
```php
if ($path == 'leave') {
    // ... 省略
    $machine->gaming = 0;           // ✅ 更新数据库
    $machine->gaming_user_id = 0;   // ✅ 更新数据库
    $machine->save();
    
    // ❌ 但没有更新 Redis！
}
```

**影响**:
- API 读取 Redis → 仍然显示 gaming=1
- 其他玩家看到机台"使用中"，无法上分
- 这是**数据库与Redis不一致的根本原因**

**修复建议**:
```php
if ($path == 'leave') {
    if ($services->keeping == 1) {
        updateKeepingLog($machine->id, $player->id);
    }
    
    // ✅ 同时更新 Redis 和数据库
    $services->gaming = 0;
    $services->gaming_user_id = 0;
    // MachineServices 应该有方法更新 Redis
    
    $machine->gaming = 0;
    $machine->gaming_user_id = 0;
    $machine->save();
    
    if ($machine->type == GameType::TYPE_STEEL_BALL) {
        $activityServices = new ActivityServices($machine, $player);
        $activityServices->playerFinishActivity(true);
    }
}
```

或者根据"Redis是唯一标准"的设计：
```php
if ($path == 'leave') {
    if ($services->keeping == 1) {
        updateKeepingLog($machine->id, $player->id);
    }
    
    // ✅ 只更新 Redis（才是实时标准）
    $services->gaming = 0;
    $services->gaming_user_id = 0;
    
    // 数据库可以异步同步或不更新
    // $machine->gaming = 0;  // ← 可选
    // $machine->save();
    
    if ($machine->type == GameType::TYPE_STEEL_BALL) {
        $activityServices = new ActivityServices($machine, $player);
        $activityServices->playerFinishActivity(true);
    }
}
```

---

### ✅ 正确的部分

| 项目 | 状态 |
|------|------|
| 幂等性保护 | ✅ 正确实现 |
| 业务校验 | ✅ gaming_user_id 和 reward_status 检查完善 |
| 分布式锁 | ✅ 使用 Locker 防止并发 |
| 批量指令 | ✅ 优化网络调用 |
| 异常处理 | ✅ try-catch + 释放幂等占位 |
| 日志记录 | ✅ 非常详细 |
| down/leave 区分 | ✅ 通过 $path 正确区分 |

---

## 6. 建议的修复方案

### 方案A：同时更新 Redis 和数据库（推荐）

```php
if ($path == 'leave') {
    if ($services->keeping == 1) {
        updateKeepingLog($machine->id, $player->id);
    }
    
    // 1. 更新 Redis（实时状态）
    \support\Redis::set("machine_tcp_data_cache_{$machine->id}_gaming", pack('C6', 0, 0, 0, 2, 6, 0));
    \support\Redis::set("machine_tcp_data_cache_{$machine->id}_gaming_user_id", pack('C6', 0, 0, 0, 2, 6, 0));
    
    // 2. 更新数据库（持久化）
    $machine->gaming = 0;
    $machine->gaming_user_id = 0;
    $machine->save();
    
    if ($machine->type == GameType::TYPE_STEEL_BALL) {
        $activityServices = new ActivityServices($machine, $player);
        $activityServices->playerFinishActivity(true);
    }
}
```

### 方案B：通过 MachineServices 封装

```php
if ($path == 'leave') {
    if ($services->keeping == 1) {
        updateKeepingLog($machine->id, $player->id);
    }
    
    // ✅ 通过 services 更新（内部同步 Redis）
    $services->setGaming(0);
    $services->setGamingUserId(0);
    
    // 数据库（可选异步）
    $machine->gaming = 0;
    $machine->gaming_user_id = 0;
    $machine->save();
    
    if ($machine->type == GameType::TYPE_STEEL_BALL) {
        $activityServices = new ActivityServices($machine, $player);
        $activityServices->playerFinishActivity(true);
    }
}
```

---

## 7. 总结

### 审查结论

✅ **下分/弃台逻辑整体正确**，但有1个严重问题：

**问题**：弃台时只更新数据库，未同步Redis
- **影响**：机台状态不一致，其他玩家无法上分
- **优先级**：P0（严重bug）
- **修复难度**：低（添加2行Redis更新代码）

### 其他发现

1. **幂等性保护**：✅ 实现完美
2. **批量指令优化**：✅ 性能优化到位
3. **日志记录**：✅ 非常详细，便于排查
4. **异常处理**：✅ 完善

### 建议

1. **立即修复**：添加 Redis 状态同步（方案A或B）
2. **测试验证**：
   - 玩家弃台 → 立即查询 Redis gaming 应为 0
   - 其他玩家应能立即上分该机台
3. **长期优化**：统一数据更新策略（是否需要同步数据库？）

---

**审查人**: Claude Sonnet 4.5  
**审查时间**: 2026-07-16  
**审查结论**: ⚠️ 发现1个严重问题，需要立即修复
