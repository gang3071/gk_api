# 日志通道迁移说明

## 新增的独立日志通道

### gk_api 项目

| 通道名 | 文件路径 | 保留天数 | 用途 |
|--------|---------|---------|------|
| `machine_wash` | `runtime/logs/machine_wash.log` | 7天 | 洗分操作的所有日志 |
| `machine_batch_cmd` | `runtime/logs/machine_batch_cmd.log` | 5天 | 批量指令发送日志 |
| `machine_single_cmd` | `runtime/logs/machine_single_cmd.log` | 3天 | 单个指令发送日志 |

### 使用方法

#### 方法 1: 直接使用 Log::channel()

```php
// 旧代码
Log::info('[MachineWash] 开始洗分', $data);

// 新代码
Log::channel('machine_wash')->info('[MachineWash] 开始洗分', $data);
```

#### 方法 2: 使用简化函数（已添加到 app/functions.php）

```php
// 洗分日志
washLog('info', '开始洗分', $data);
washLog('warning', '洗分间隔不足5秒', $data);
washLog('error', '洗分失败', $data);

// 批量指令日志
batchCmdLog('info', '批量发送机台指令 - 开始', $data);

// 单个指令日志
singleCmdLog('info', '发送机台指令 - 请求', $data);
```

## 日志查询

### 查看洗分日志
```bash
# 实时监控
tail -f runtime/logs/machine_wash.log

# 查找特定 wash_id
grep "wash_675a1234" runtime/logs/machine_wash.log

# 查看今天的洗分失败
grep "洗分失败" runtime/logs/machine_wash-$(date +%Y-%m-%d).log
```

### 查看批量指令日志
```bash
# 实时监控
tail -f runtime/logs/machine_batch_cmd.log

# 查找特定 batch_id
grep "batch_675a5678" runtime/logs/machine_batch_cmd.log
```

### 查看单个指令日志
```bash
# 实时监控（日志量大）
tail -f runtime/logs/machine_single_cmd.log

# 查找特定指令
grep "WASH_ZERO" runtime/logs/machine_single_cmd.log
```

## 优势

1. **日志分离** - 不同类型的日志写入不同文件，互不干扰
2. **快速定位** - 直接查看对应日志文件，不需要 grep 过滤
3. **性能优化** - 减少单个日志文件的大小，提高查询速度
4. **保留策略** - 不同日志有不同的保留天数，节省磁盘空间
5. **并发友好** - 多个日志文件可以并发写入，降低锁竞争

## 日志文件说明

### machine_wash.log
包含所有洗分相关的日志：
- 洗分开始/完成/失败
- 机台状态读取
- 赠点信息
- 数据库操作
- 清零指令执行

### machine_batch_cmd.log
包含批量指令的日志：
- 批量指令开始/完成
- 每个指令的发送情况
- 指令成功/失败统计
- 性能警告

### machine_single_cmd.log  
包含单个指令的详细日志：
- HTTP 请求/响应
- 指令执行结果
- 耗时统计

## 迁移计划

### 阶段 1: 添加日志通道配置 ✅
已完成 - 在 `config/log.php` 添加三个新通道

### 阶段 2: 创建辅助函数
在 `app/functions.php` 添加：
```php
function washLog(string $level, string $message, array $context = []): void
{
    Log::channel('machine_wash')->{$level}('[MachineWash] ' . $message, $context);
}

function batchCmdLog(string $level, string $message, array $context = []): void
{
    Log::channel('machine_batch_cmd')->{$level}('[MachineClient-Batch] ' . $message, $context);
}

function singleCmdLog(string $level, string $message, array $context = []): void
{
    Log::channel('machine_single_cmd')->{$level}('[MachineClient] ' . $message, $context);
}
```

### 阶段 3: 逐步迁移代码
1. `app/functions.php` - machineWash() 函数
2. `app/service/machine/MachineClient.php` - 批量和单个指令
3. `app/api/controller/v1/MachineController.php` - 控制器日志

### 阶段 4: 更新文档
更新所有文档中的日志查询命令

## 示例对比

### 旧方式（所有日志混在一起）
```bash
tail -f runtime/logs/webman.log | grep "MachineWash"
```
问题：还会看到其他模块的日志，干扰诊断

### 新方式（独立日志文件）
```bash
tail -f runtime/logs/machine_wash.log
```
优势：只有洗分日志，清晰明了

## gk_work 端

同样可以添加独立日志通道：

```php
// config/log.php
'player_machine_cmd' => [
    'handlers' => [
        [
            'class' => Monolog\Handler\RotatingFileHandler::class,
            'constructor' => [
                runtime_path() . '/logs/player_machine_cmd.log',
                5,
                Monolog\Logger::DEBUG,
            ],
            'formatter' => [
                'class' => Monolog\Formatter\LineFormatter::class,
                'constructor' => [null, 'Y-m-d H:i:s', true],
            ],
        ]
    ],
],
```

使用：
```php
Log::channel('player_machine_cmd')->info('[PlayerMachine-SendCmd] 准备执行指令', $data);
```

---

**创建日期**: 2026-06-05  
**状态**: 配置已完成，代码迁移待进行
