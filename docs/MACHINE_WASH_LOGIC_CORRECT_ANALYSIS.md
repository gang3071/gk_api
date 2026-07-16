# 钢珠机洗分弃台逻辑正确分析（修正版）

## 重要前提理解

**用户澄清**：弃台是将转数转为分数

这意味着：
- 弃台时执行 `TURN_DOWN_ALL` → 将转数转换成分数
- 转换后，机台的 `turn = 0`，`point` 增加
- 这是**预期行为**，不是bug

## 重新审查代码逻辑

### 当前代码流程（gk_api 行2979-3043）

```php
case GameType::TYPE_STEEL_BALL:
    // 1️⃣ 弃台时：先执行转换指令
    if ($path == 'leave') {
        if ($services->score > 0) {
            sendCmd(SCORE_TO_POINT);  // 得分→分数
        }
        if ($services->turn > 0) {
            sendCmd(TURN_DOWN_ALL);   // 转数→分数
        }
    }
    
    // 2️⃣ 读取机台状态
    sendCmd(MACHINE_POINT);           // 读取分数（已包含转换后的分数）
    sendCmd(WIN_NUMBER);              // 读取对奖次数
    
    // 3️⃣ 计算洗分金额
    $gamingTurnPoint = $services->player_win_number;  
    $money = $services->point;
```

### ❓ 关键问题

**`player_win_number` 和 `turn` 的区别是什么？**

让我查看变量定义：

- `turn`：机台当前转数（会被TURN_DOWN_ALL清零）
- `win_number`：中洞对奖次数（机台返回的值）
- `player_win_number`：**玩家使用转数**（累积值？）

### 🤔 疑问点

#### 疑问1：`player_win_number` 会不会被 `TURN_DOWN_ALL` 清零？

**需要确认**：
- 如果 `player_win_number` 是累积值，不应该被清零
- 如果 `player_win_number` 就是 `turn`，会被清零

#### 疑问2：为什么要在转换后读取 `WIN_NUMBER`？

**可能原因A**：读取玩家本次游戏的转数记录（用于游戏历史）
- `player_win_number` 是Redis中存储的累积值
- 不受 `TURN_DOWN_ALL` 影响

**可能原因B**：读取是为了清零（错误）
- 转换后 `win_number = 0`
- `player_win_number` 也变成0

### 🔍 需要确认的逻辑

#### 场景A：`player_win_number` 是独立累积值

```
玩家游戏过程：
┌────────────────────────────────┐
│ 上分开始游戏                   │
│ player_win_number = 0          │
│ turn = 0                       │
└────────────────────────────────┘
          ↓
┌────────────────────────────────┐
│ 游戏中（转了100次）            │
│ player_win_number = 100  ← 累积│
│ turn = 50  ← 当前机台转数      │
└────────────────────────────────┘
          ↓
┌────────────────────────────────┐
│ 弃台：TURN_DOWN_ALL            │
│ player_win_number = 100  ← 不变│
│ turn = 0  ← 清零               │
│ point += 50转的分数            │
└────────────────────────────────┘
          ↓
┌────────────────────────────────┐
│ 读取 WIN_NUMBER                │
│ player_win_number = 100  ✅    │
│ 记录：本次游戏转数100           │
└────────────────────────────────┘
```

**这种情况：逻辑正确 ✅**

#### 场景B：`player_win_number` 就是 `turn`

```
玩家游戏过程：
┌────────────────────────────────┐
│ 上分开始游戏                   │
│ player_win_number = 0          │
│ turn = 0                       │
└────────────────────────────────┘
          ↓
┌────────────────────────────────┐
│ 游戏中（转了100次）            │
│ player_win_number = 100        │
│ turn = 100  ← 同步             │
└────────────────────────────────┘
          ↓
┌────────────────────────────────┐
│ 弃台：TURN_DOWN_ALL            │
│ player_win_number = 0  ← 清零❌│
│ turn = 0  ← 清零               │
│ point += 100转的分数           │
└────────────────────────────────┘
          ↓
┌────────────────────────────────┐
│ 读取 WIN_NUMBER                │
│ player_win_number = 0  ❌      │
│ 记录：本次游戏转数0  ← 错误    │
└────────────────────────────────┘
```

**这种情况：逻辑错误 ❌**

## 🧪 如何验证

### 方法1：查看Redis值

```bash
# 弃台前
redis-cli> GET machine_tcp_data_cache_1278_player_win_number
# 假设返回：1000（转了1000次）

redis-cli> GET machine_tcp_data_cache_1278_turn
# 假设返回：500（当前机台转数）

# 弃台后（执行TURN_DOWN_ALL）
redis-cli> GET machine_tcp_data_cache_1278_player_win_number
# 如果返回0 → 场景B（有bug）
# 如果返回1000 → 场景A（正确）

redis-cli> GET machine_tcp_data_cache_1278_turn
# 应该返回：0（转数已下到分数）
```

### 方法2：查看数据库记录

```sql
-- 查看最近弃台的游戏记录
SELECT 
    id,
    player_id,
    machine_id,
    turn_point,  -- 这个字段存的是什么值？
    created_at
FROM player_game_record
WHERE status = 2  -- 已结束
ORDER BY id DESC
LIMIT 10;
```

**如果 `turn_point` 大部分是0 → 场景B（有bug）**  
**如果 `turn_point` 有正常数值 → 场景A（正确）**

### 方法3：查看日志

```bash
# 查看洗分日志
tail -100 runtime/logs/machine_operations.log | grep -A 10 "MachineWash.*SteelBall"
```

看日志中 `gamingTurnPoint` 的值是否为0。

## 📊 我的判断

根据代码结构和命名：

1. **`player_win_number`** - "玩家使用转数"
   - 这个名字暗示是**累积值**，记录玩家总共转了多少次
   - 应该在每次转数变化时累加
   - 不应该在 `TURN_DOWN_ALL` 时清零

2. **`turn`** - "转数"
   - 机台当前的转数
   - 可以被 `TURN_DOWN_ALL` 清零

3. **`win_number`** - "中洞对奖次数"
   - 机台返回的对奖次数
   - 可能与 `player_win_number` 不同

### 如果我的判断正确

那么当前逻辑**应该是正确的**：
- `TURN_DOWN_ALL` 清零的是 `turn`，不是 `player_win_number`
- 读取 `WIN_NUMBER` 获取的是累积的对奖次数
- 记录到数据库的 `gamingTurnPoint` 是正确的

### 如果我的判断错误

那么确实存在bug：
- `TURN_DOWN_ALL` 同时清零了 `turn` 和 `player_win_number`
- 读取 `WIN_NUMBER` 得到的是0
- 记录到数据库的是错误值

## 🔧 建议的验证步骤

1. **立即检查**：
   ```bash
   # 在gk_work运行环境
   redis-cli
   > KEYS machine_tcp_data_cache_*_player_win_number
   > GET machine_tcp_data_cache_<某个ID>_player_win_number
   ```

2. **查看数据库**：
   ```sql
   SELECT turn_point, COUNT(*) as count
   FROM player_game_record
   WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
   GROUP BY turn_point
   ORDER BY count DESC;
   ```
   
   如果大部分是0 → 确认有bug

3. **测试验证**：
   - 找一台钢珠机
   - 上分后玩几转
   - 记录弃台前的 `player_win_number` 值
   - 弃台
   - 检查数据库中 `turn_point` 是否正确

## 🎯 结论

**需要你提供以下信息来确认**：

1. 数据库中 `player_game_record.turn_point` 字段的典型值
2. 弃台后，玩家游戏记录中的转数是否正确
3. Redis中 `player_win_number` 在弃台前后的值变化

根据这些信息，我可以：
- 如果有bug → 提供修复方案
- 如果没bug → 说明我之前的分析有误，逻辑是正确的

---

**等待用户反馈**：
- [ ] player_game_record 表中 turn_point 的实际数据
- [ ] 弃台前后 Redis player_win_number 的值
- [ ] 用户观察到的具体问题是什么

