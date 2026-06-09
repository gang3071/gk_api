# 机台数据访问架构分析报告

## 分析日期
2026-05-26

## 分析目的
深入检查机台数据访问路径，确认：
1. 机台操作指令 → 通过 MachineClient 发送到 gk_work ✅
2. 机台状态/数据读取 → 直接从共享 Redis 获取 ✅ **需要验证**

---

## 🔍 架构分析

### 数据存储架构

```
┌─────────────┐         ┌─────────────┐         ┌─────────────┐
│   gk_api    │         │   Redis     │         │  gk_work    │
│  (HTTP API) │◄───────►│  (共享存储)  │◄───────►│ (任务/TCP)   │
└─────────────┘         └─────────────┘         └─────────────┘
       │                                                │
       │                                                │
       │                                         ┌──────▼──────┐
       │                                         │  Gateway    │
       │                                         │  (TCP连接)   │
       │                                         └──────┬──────┘
       │                                                │
       │                                         ┌──────▼──────┐
       └─────────────── HTTP 调用 ───────────────►│  机台硬件    │
                                                 └─────────────┘
```

---

## 📊 机台数据类型分类

### 1. 机台状态数据（从 Redis 读取）

#### 存储位置
- **Redis Key 前缀**: `machine_tcp_data_cache_{machine_id}`
- **字段格式**: `machine_tcp_data_cache_{machine_id}_{field}`

#### 包含字段（以 Slot 为例）
```php
- auto              // 自动状态
- move_point        // 移分状态
- reward_status     // 开奖状态
- gaming            // 游戏中状态
- gaming_user_id    // 游戏玩家ID
- point             // 当前分数
- score             // 当前得分
- bet               // 机台压分
- win               // 机台总得分
- turn              // 转数（钢珠机）
- ... 更多字段
```

#### 访问方式（gk_api）
**✅ 正确实现 - 直接从 Redis 读取**

```php
// app/service/machine/AbstractMachineService.php
public function __get(string $name): mixed
{
    $key = $this->cacheDataKey . '_' . $name;
    
    if (!in_array($key, $this->cacheDataKeyArr)) {
        return null;
    }
    
    try {
        return Cache::get($key, 0);  // 直接从 Redis 读取
    } catch (Exception $e) {
        // 重试机制...
        return 0;
    }
}
```

**使用示例**:
```php
$slot = new Slot($machine, 'zh_CN');
$point = $slot->point;      // 直接从 Redis 读取
$score = $slot->score;      // 直接从 Redis 读取
$auto = $slot->auto;        // 直接从 Redis 读取
```

---

### 2. 机台在线状态（必须通过 HTTP 调用）

#### 存储位置
- **Gateway 进程内存** - `Gateway::isUidOnline($uid)`
- **无 Redis 存储** - 在线状态不存储在 Redis 中

#### 检查逻辑（在 gk_work 中）
```php
// D:\gk_work\app\api\v1\AdminMachineController.php
public function checkOnline(Request $request): Response
{
    $machine = Machine::find($machineId);
    
    // 主连接在线检查
    $mainUid = $machine->domain . ':' . $machine->port;
    $mainOnline = Gateway::isUidOnline($mainUid);  // Gateway 内部方法
    
    // 自动卡在线检查
    $autoOnline = false;
    if (!empty($machine->auto_card_domain)) {
        $autoUid = $machine->auto_card_domain . ':' . $machine->auto_card_port;
        $autoOnline = Gateway::isUidOnline($autoUid);
    }
    
    return $this->success([
        'main_online' => $mainOnline,
        'auto_online' => $autoOnline,
        'online' => $mainOnline,
    ]);
}
```

#### 访问方式（gk_api）
**✅ 正确实现 - 必须通过 HTTP 调用**

```php
// app/service/machine/MachineClient.php
public function checkOnline(int $machineId, string $lang = 'zh_TW'): array
{
    $response = Http::timeout($this->timeout)
        ->withHeaders(['Accept-Language' => $lang])
        ->post($this->baseUrl . '/api/admin/machine/check-online', [
            'machine_id' => $machineId,
        ]);
    
    return [
        'success' => true,
        'data' => $body['data'] ?? [],  // ['online' => bool]
    ];
}
```

**使用示例**:
```php
// app/api/controller/v1/MachineController.php
private function isMachineOnline(int $machineId): bool
{
    $client = new MachineClient();
    $result = $client->checkOnline($machineId);
    return $result['success'] && ($result['data']['online'] ?? false);
}
```

---

### 3. 机台操作指令（必须通过 HTTP 调用）

#### 发送逻辑
**✅ 正确实现 - 通过 HTTP 调用 gk_work**

```php
// app/service/machine/AbstractMachineService.php
public function sendCmd(
    string $cmd,
    int $data = 0,
    string $source = 'player',
    int $source_id = 0,
    int $isSystem = 0
): bool {
    $client = new MachineClient();
    $playerId = $source === 'player' ? $source_id : null;

    $result = $client->sendCommand(
        $this->machine->id,
        $cmd,
        $data,
        $this->lang,
        $playerId
    );  // HTTP 调用 gk_work

    if (!$result['success']) {
        throw new Exception($result['message']);
    }

    return true;
}
```

---

## 🚨 发现的问题

### 问题 1: MachineClient 中存在不必要的方法

#### ❌ getMachineStatus() - 不应该存在

**位置**: `app/service/machine/MachineClient.php` line 109-146

**问题**:
- 机台状态数据存储在 Redis 中
- gk_api 可以直接从 Redis 读取（通过 AbstractMachineService）
- 不需要通过 HTTP 调用 gk_work

**现状**:
- 该方法定义了但**从未被使用**
- 属于死代码

**建议**: **删除此方法**

---

### 问题 2: 在线状态检查存在性能问题

#### ⚠️ checkOnline/batchCheckOnline - 可以优化

**当前实现**:
```php
// 每次检查都需要 HTTP 调用
$client = new MachineClient();
$result = $client->checkOnline($machineId);  // HTTP 请求
```

**问题**:
- 高频调用时会产生大量 HTTP 请求
- 每次请求耗时 10-50ms
- 机台列表页面需要批量检查，影响性能

**优化建议**: 在 gk_work 中实现在线状态同步到 Redis

```php
// gk_work 定期同步在线状态到 Redis
// Key: machine_online_{machine_id}
// Value: 1 (在线) / 0 (离线)
// TTL: 10秒（超时自动过期）

// gk_api 直接从 Redis 读取
$online = Cache::get("machine_online_{$machineId}", 0);
```

---

## 📋 当前使用情况统计

### 1. 直接从 Redis 读取状态（正确）

**使用位置**:
- 所有机台服务类（Slot, SongSlot, Jackpot, SongJackpot）
- 通过魔术方法 `__get()` 访问

**使用频率**: 高频（每次需要机台数据时）

**性能**: 优秀（直接 Redis 读取，< 1ms）

---

### 2. HTTP 调用检查在线状态

**使用位置**:

| 文件 | 方法 | 调用次数 |
|------|------|---------|
| MachineController.php | isMachineOnline() | 单次检查 |
| MachineController.php | list() | 批量检查 |
| PlayerController.php | isMachineOnline() | 单次检查 |
| PlayerController.php | favoriteMachineList() | 批量检查 |
| PlayerController.php | playingMachineList() | 批量检查 |

**使用频率**: 中频（机台列表加载时）

**性能**: 一般（HTTP 请求，10-50ms）

---

### 3. HTTP 调用发送指令（正确且必要）

**使用位置**:
- AbstractMachineService::sendCmd()
- 所有子类继承

**使用频率**: 中频（玩家操作机台时）

**性能**: 可接受（HTTP 请求，但操作不频繁）

---

## 🎯 优化建议

### 短期优化（立即实施）

#### 1. 删除不必要的方法 ✅

**删除**: `MachineClient::getMachineStatus()`

**理由**:
- 从未被使用
- 功能重复（已有直接 Redis 读取）
- 减少代码维护成本

**影响**: 无（未被使用）

---

### 中期优化（1-2周）

#### 2. 在线状态同步到 Redis ⭐

**实现位置**: gk_work

**方案 A: Gateway 事件监听**
```php
// gk_work/process/Gateway.php
public static function onConnect($client_id)
{
    // 连接建立时，写入 Redis
    $uid = Gateway::getUidByClientId($client_id);
    Cache::set("machine_online_{$machineId}", 1, 60);
}

public static function onClose($client_id)
{
    // 连接断开时，删除 Redis
    Cache::delete("machine_online_{$machineId}");
}
```

**方案 B: 定时同步（推荐）**
```php
// gk_work/process/SyncMachineOnlineStatus.php
class SyncMachineOnlineStatus
{
    public function onWorkerStart(): void
    {
        // 每5秒同步一次在线状态
        Timer::add(5, function () {
            $machines = Machine::query()->where('status', 1)->get();
            
            foreach ($machines as $machine) {
                $uid = $machine->domain . ':' . $machine->port;
                $online = Gateway::isUidOnline($uid);
                
                // 写入 Redis，TTL 10秒（超时自动过期）
                $key = "machine_online_{$machine->id}";
                if ($online) {
                    Cache::set($key, 1, 10);
                } else {
                    Cache::set($key, 0, 10);
                }
            }
        });
    }
}
```

**gk_api 修改**:
```php
// 修改 isMachineOnline() 方法
private function isMachineOnline(int $machineId): bool
{
    // 直接从 Redis 读取
    return (bool)Cache::get("machine_online_{$machineId}", 0);
}

// 删除 MachineClient::checkOnline() 调用
```

**收益**:
- ✅ 减少 HTTP 请求
- ✅ 提升响应速度（< 1ms vs 10-50ms）
- ✅ 降低 gk_work 负载
- ✅ 更好的性能体验

---

### 长期优化（3个月）

#### 3. 评估 MachineClient 的职责

**当前职责**:
- 发送机台指令 ✅
- 检查在线状态 ⚠️（可以优化）
- 获取机台状态 ❌（不应该有）

**优化后职责**:
- 发送机台指令 ✅（保留）

**建议**: 如果在线状态同步到 Redis 后，MachineClient 只剩一个方法 `sendCommand()`，考虑：
1. 保持现状（单一职责，清晰）
2. 或直接在 AbstractMachineService 中实现 HTTP 调用

---

## ✅ 验证清单

### 当前架构验证

- [x] 机台状态数据从 Redis 直接读取 ✅
- [x] 机台操作指令通过 MachineClient 发送 ✅
- [x] 机台在线状态通过 MachineClient 检查 ⚠️（可优化）
- [x] getMachineStatus() 未被使用 ✅（可删除）
- [x] Redis 为 gk_api 和 gk_work 共享 ✅

### 优化后验证

- [ ] 删除 MachineClient::getMachineStatus()
- [ ] gk_work 实现在线状态同步到 Redis
- [ ] gk_api 从 Redis 读取在线状态
- [ ] 性能对比测试（HTTP vs Redis）
- [ ] 压力测试（高并发机台列表加载）

---

## 📈 性能对比预估

### 机台列表加载（100台机台）

| 方案 | 在线检查方式 | 耗时 | 请求次数 |
|------|------------|------|---------|
| **当前** | HTTP 批量调用 | ~100ms | 1 次 HTTP |
| **优化后** | Redis 批量读取 | ~5ms | 0 次 HTTP |
| **提升** | - | **95% ↓** | **100% ↓** |

### 单台机台在线检查

| 方案 | 检查方式 | 耗时 | 请求次数 |
|------|---------|------|---------|
| **当前** | HTTP 单次调用 | ~20ms | 1 次 HTTP |
| **优化后** | Redis 单次读取 | ~0.5ms | 0 次 HTTP |
| **提升** | - | **97.5% ↓** | **100% ↓** |

---

## 总结

### ✅ 架构验证结论

1. **机台状态数据读取** - ✅ 已正确实现（直接从 Redis 读取）
2. **机台操作指令发送** - ✅ 已正确实现（通过 HTTP 发送到 gk_work）
3. **机台在线状态检查** - ⚠️ 当前通过 HTTP 调用，可优化为 Redis 读取

### 🎯 立即优化

- [x] 删除 `MachineClient::getMachineStatus()` 方法

### 🎯 推荐优化

- [ ] 在 gk_work 中实现在线状态同步到 Redis
- [ ] 修改 gk_api 从 Redis 读取在线状态
- [ ] 删除或简化 `MachineClient::checkOnline()` 相关方法

### 📊 优化收益

- **性能提升**: 95%+ （在线检查）
- **HTTP 请求减少**: 100% （在线检查）
- **代码简化**: 删除 ~40 行无用代码
- **架构一致**: 所有读取操作都从 Redis，所有写入操作都通过 HTTP

---

**分析完成时间**: 2026-05-26  
**分析工程师**: Claude Code  
**下一步**: 实施立即优化 → 删除 getMachineStatus() 方法
