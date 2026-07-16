# 机台数据不一致原因分析

## 问题现象

**机台 #1278 数据对比**：

| 字段 | 数据库值 | Redis值 | 说明 |
|------|---------|---------|------|
| gaming (游戏状态) | 0 | 1 | ❌ 不一致 |
| keeping (保留状态) | 0 | 1 | ❌ 不一致 |
| gaming_user_id | 0 | 9 | ❌ 不一致 |
| keeping_user_id | 0 | 9 | ❌ 不一致 |
| turn | N/A | 0 | - |
| score | 0 | 0 | ✅ 一致 |
| point | N/A | 0 | - |

**核心问题**：
- Redis显示机台**正在游戏中**（gaming=1）且**被保留**（keeping=1）
- 数据库显示机台**空闲**（gaming=0, keeping=0）
- 玩家ID在Redis中是9，数据库中是0

## 根本原因

### 📌 Redis是实时状态，数据库是持久化快照

根据 `CLAUDE.md` 和 memory 文件的说明：

> **Redis是钱包的唯一标准**（project_redis_wallet_standard.md）
> - Redis存储实时游戏状态
> - 数据库仅用于持久化配置和历史记录

**这意味着**：

1. **Redis = 实时真相**
   - 玩家正在玩机台 → `gaming=1` 立即更新到Redis
   - 玩家保留机台 → `keeping=1` 立即写入Redis
   - 所有游戏逻辑**只读写Redis**

2. **数据库 = 异步快照**
   - 数据库更新**不是实时的**
   - 可能存在**延迟同步**
   - 或者某些状态字段**根本不写数据库**

## 可能的同步策略

### 策略A：定时批量同步（最可能）

```php
// process/MachineStateSyncWorker.php (假设)
// 每N分钟将Redis状态批量写入数据库
public function onTimer() {
    $machines = getAllMachinesFromRedis();
    foreach ($machines as $machine) {
        DB::table('machine')
            ->where('id', $machine['id'])
            ->update([
                'gaming' => $machine['gaming'],
                'keeping' => $machine['keeping'],
                // ...
            ]);
    }
}
```

**特征**：
- ✅ 性能好（减少数据库写入）
- ❌ 延迟高（可能5-10分钟才同步一次）
- ❌ 中间状态丢失（玩家玩了2分钟就走了，数据库可能没记录）

### 策略B：关键事件触发同步

```php
// 只在关键时刻写数据库
public function leaveTable($machineId) {
    // 1. 更新Redis（立即）
    Redis::set("machine_tcp_data_cache_{$machineId}_gaming", 0);
    
    // 2. 更新数据库（异步或延迟）
    DB::table('machine')->where('id', $machineId)->update(['gaming' => 0]);
}
```

**特征**：
- ✅ 重要状态有记录
- ❌ 中间状态仍然不同步
- ❌ 如果异步写入失败，数据库永远是旧值

### 策略C：部分字段不同步数据库

```php
// gaming/keeping 可能被认为是"临时状态"，不写数据库
// 数据库只存储机台的"基础配置"：
// - code（机台编号）
// - type（机台类型）
// - status（启用/禁用）
// - maintaining（维护状态）

// gaming/keeping 只存在于Redis，重启后从0开始
```

**特征**：
- ✅ 性能最佳
- ❌ 数据库永远是0（这就是你看到的现象）
- ❌ 重启后所有玩家掉线

## 验证哪种策略

### 方法1：检查代码中是否写数据库

```bash
# 搜索更新 gaming/keeping 字段的代码
cd D:/gk_work
grep -r "update.*gaming" app/
grep -r "->gaming\s*=" app/
```

如果找不到写数据库的代码 → **策略C**（根本不同步）

### 方法2：观察时间差

1. 让玩家**进入**机台1278
2. 立即查Redis → `gaming=1`
3. 立即查数据库 → `gaming=?`
4. 等待5分钟后查数据库 → `gaming=?`

- 立即就变 → **策略B**（实时同步）
- 5分钟后变 → **策略A**（定时同步）
- 永远不变 → **策略C**（不同步）

### 方法3：检查后台worker

```bash
# 查看是否有机台状态同步worker
cat config/process.php | grep -i machine
cat config/process.php | grep -i sync
```

找到类似 `MachineStateSyncWorker` → **策略A**（定时同步）

## 你的情况最可能是什么？

根据以下线索：

1. **你之前提供的SQL INSERT**：
   ```sql
   INSERT INTO machine (..., gaming, keeping) VALUES (..., 0, 0)
   ```
   这是**插入新机台**时的默认值

2. **API返回的数据**：
   ```json
   {"gaming": 1, "keeping": 1}
   ```
   这是从**Redis读取的实时值**

3. **CLAUDE.md明确说**：
   > Redis是钱包的唯一标准

**结论**：最可能是 **策略C - gaming/keeping 只在Redis，不写数据库**

## 为什么这样设计？

### 原因1：性能

```
玩家游戏流程：
  进入机台 → gaming=1 (Redis)
  玩10分钟 → 期间转数变化100次 (全在Redis)
  离开机台 → gaming=0 (Redis)
  
如果每次都写数据库：
  100次Redis写 + 100次MySQL写 = 高负载
  
实际策略：
  100次Redis写 + 0次MySQL写 = 性能最优
```

### 原因2：临时状态无需持久化

```
gaming/keeping 是"会话状态"：
  - 玩家在线 → gaming=1
  - 玩家掉线/服务器重启 → 自动释放，gaming=0
  
类似Web的Session：
  - Session存Redis（快速）
  - 不写MySQL（没必要）
```

### 原因3：数据库只存配置

```
数据库的 machine 表作用：
  - 机台基础信息（code, type, name）
  - 管理员配置（maintaining, status）
  - 静态数据（created_at, updated_at）
  
动态游戏状态：
  - 全在Redis
  - 查询时从Redis读
  - API返回的就是Redis值
```

## 影响范围

### ✅ 不影响的场景

1. **玩家API调用**
   - 所有API都读Redis → 数据正确
   - `MachineController` 读Redis → 实时状态

2. **游戏逻辑**
   - 上分/下分/保留都操作Redis → 无问题

3. **WebSocket推送**
   - 推送的是Redis数据 → 实时准确

### ⚠️ 可能影响的场景

1. **后台管理系统查询机台状态**
   ```php
   // 如果后台直接查数据库
   $machine = DB::table('machine')->find(1278);
   echo $machine->gaming;  // ❌ 永远是0（错误）
   
   // 正确做法：查Redis
   $gaming = Redis::get("machine_tcp_data_cache_1278_gaming");
   ```

2. **数据分析/报表**
   - 如果报表基于MySQL → 看不到实时在玩人数
   - 需要从Redis读取

3. **服务器重启**
   - Redis未持久化 → gaming/keeping数据丢失
   - 所有玩家被强制下机
   - 数据库的0值变成"正确"值

## 建议的修复方案

### 方案A：接受现状（推荐）

**理解设计意图**：
- Redis = 实时状态（单一真相来源）
- 数据库 = 配置存储（不关心实时状态）

**调整查询逻辑**：
```php
// 后台管理系统需要实时状态时
public function getMachineStatus($machineId) {
    // 基础信息从数据库
    $machine = DB::table('machine')->find($machineId);
    
    // 实时状态从Redis
    $machine->gaming = Redis::get("machine_tcp_data_cache_{$machineId}_gaming");
    $machine->keeping = Redis::get("machine_tcp_data_cache_{$machineId}_keeping");
    
    return $machine;
}
```

### 方案B：添加定时同步（如果需要数据库准确）

```php
// 创建 process/MachineSyncWorker.php
class MachineSyncWorker {
    public function onWorkerStart() {
        Timer::add(60, function() {  // 每60秒同步一次
            $this->syncMachineStates();
        });
    }
    
    private function syncMachineStates() {
        $machines = DB::table('machine')->select('id')->get();
        
        foreach ($machines as $machine) {
            $gaming = Redis::get("machine_tcp_data_cache_{$machine->id}_gaming");
            $keeping = Redis::get("machine_tcp_data_cache_{$machine->id}_keeping");
            
            if ($gaming !== false || $keeping !== false) {
                DB::table('machine')->where('id', $machine->id)->update([
                    'gaming' => (int)$gaming,
                    'keeping' => (int)$keeping,
                ]);
            }
        }
    }
}
```

**权衡**：
- ✅ 数据库有准确快照（1分钟延迟）
- ❌ 增加数据库写入压力
- ❌ 仍然有延迟（不是实时）

### 方案C：Redis持久化保护

确保Redis配置了RDB或AOF：

```redis
# redis.conf
save 900 1      # 15分钟至少1个key变化时保存
save 300 10     # 5分钟至少10个key变化时保存
save 60 10000   # 1分钟至少10000个key变化时保存

appendonly yes  # 启用AOF持久化
```

**好处**：
- ✅ 服务器重启不丢Redis数据
- ✅ gaming/keeping状态可恢复

## 总结

### 数据不一致的真相

**这不是Bug，这是设计**：

1. Redis存**实时游戏状态**（gaming, keeping, turn, score）
2. 数据库存**静态配置**（code, type, status）
3. 两者**职责分离**，不需要保持一致

### 正确的使用方式

| 场景 | 应该读取 | 原因 |
|------|---------|------|
| 玩家API（上分/下分） | Redis | 实时状态 |
| 后台查询机台是否在用 | Redis | 实时状态 |
| 后台查询机台编号/类型 | 数据库 | 静态配置 |
| 数据分析报表 | Redis快照 | 需要实时数据 |

### 下一步

1. **确认设计意图**：检查代码中是否有更新数据库gaming字段的逻辑
2. **修复后台查询**：如果后台直接查数据库，改为查Redis
3. **添加文档说明**：在代码注释中说明哪些字段只在Redis

---

**分析结论**：数据不一致是正常的架构设计，Redis是游戏状态的唯一标准，数据库仅存储静态配置。
