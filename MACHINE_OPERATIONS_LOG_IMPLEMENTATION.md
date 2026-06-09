# 机台开分/洗分操作日志实现总结

## 实现目标 ✅

将开分和洗分操作的完整流程记录在**单个独立日志文件**中，便于排查问题。

## 核心设计

### 单一日志文件策略
- **文件名**: `machine_operations.log`
- **位置**: 
  - gk_api: `runtime/logs/machine_operations.log`
  - gk_work: `runtime/logs/machine_operations.log`
- **保留**: 7天自动轮转
- **优势**: 所有相关日志集中在一个文件，无需在多个文件间切换

## 记录的完整流程

### 1. Redis 数据读取
记录机台当前状态：
- 分数 (point)
- 下注金额 (bet)
- 赢分金额 (win)
- 自动状态 (auto_status)

**日志标记**: `[MachineWash] 机台状态读取`

### 2. 关键检查
- 洗分间隔检查（5秒限制）
- 赠点信息（give_point, lottery_give_point）

**日志标记**: 
- `[MachineWash] 洗分间隔不足5秒`
- `[MachineWash] 赠点信息`

### 3. 指令发送（gk_api → gk_work）
记录批量指令的发送过程：
- batch_id（批次唯一标识）
- wash_id（洗分操作唯一标识）
- 指令列表（WASH_ZERO, MOVE_POINT_OFF 等）
- 发送结果统计

**日志标记**: 
- `[MachineClient-Batch] 批量发送机台指令 - 开始`
- `[MachineClient-Batch] 批量指令执行完成`

### 4. 指令执行（gk_work）
记录每个指令的执行情况：
- 指令接收
- 执行结果（成功/失败）
- 执行耗时
- trace_context（wash_id, batch_id, command_index）

**日志标记**: 
- `[PlayerMachine-SendCmd] 准备执行指令`
- `[PlayerMachine-SendCmd] 指令执行完成`

### 5. 机台最终状态
记录洗分完成后的状态：
- 总耗时
- 彩票中奖信息（如果有）
- 性能警告（耗时 > 3秒）

**日志标记**: 
- `[MachineWash-Slot] 洗分完成`
- `[MachineWash-Slot] 洗分失败`

## 代码修改清单

### 1. 配置文件

#### gk_api/config/log.php
```php
'machine_operations' => [
    'handlers' => [
        [
            'class' => Monolog\Handler\RotatingFileHandler::class,
            'constructor' => [
                runtime_path() . '/logs/machine_operations.log',
                7, // 保留7天
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

#### gk_work/config/log.php
```php
'machine_operations' => [
    'handlers' => [
        [
            'class' => Monolog\Handler\RotatingFileHandler::class,
            'constructor' => [
                runtime_path() . '/logs/machine_operations.log',
                7, // 保留7天
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

### 2. 日志调用修改

#### gk_api/app/functions.php
所有 `[MachineWash]` 相关日志：
```php
// 修改前
Log::info('[MachineWash] 开始洗分', $data);

// 修改后
Log::channel('machine_operations')->info('[MachineWash] 开始洗分', $data);
```

#### gk_api/app/service/machine/MachineClient.php
所有批量和单个指令日志：
```php
// 批量指令
Log::channel('machine_operations')->info('[MachineClient-Batch] 批量发送机台指令 - 开始', $data);

// 单个指令（保留在 machine_operations 中以便追踪完整流程）
Log::channel('machine_operations')->info('[MachineClient] 发送机台指令 - 请求', $data);
```

#### gk_work/app/api/v1/PlayerMachineController.php
指令执行相关日志：
```php
// 准备执行
Log::channel('machine_operations')->info('[PlayerMachine-SendCmd] 准备执行指令', $data);

// 执行完成
Log::channel('machine_operations')->info('[PlayerMachine-SendCmd] 指令执行完成', $data);

// 执行失败
Log::channel('machine_operations')->error('[PlayerMachine-SendCmd] 指令执行失败', $data);
```

### 3. 工具脚本

#### trace_machine_operation.sh
完整流程追踪脚本，自动解析和展示：
- gk_api 洗分流程
- Redis 数据读取
- 指令发送情况
- gk_work 指令执行
- 最终结果统计

使用方法：
```bash
./trace_machine_operation.sh wash_675f12345
```

## 使用场景

### 场景1: 实时监控
```bash
# 监控 gk_api 端
tail -f D:/gk_api/runtime/logs/machine_operations.log

# 监控 gk_work 端
tail -f D:/gk_work/runtime/logs/machine_operations.log

# 同时监控两端（推荐）
tail -f D:/gk_api/runtime/logs/machine_operations.log & \
tail -f D:/gk_work/runtime/logs/machine_operations.log
```

### 场景2: 问题排查
```bash
# 1. 获取最新的 wash_id
grep "开始洗分" D:/gk_api/runtime/logs/machine_operations.log | tail -1

# 2. 使用追踪脚本
./trace_machine_operation.sh wash_675f12345

# 3. 分析输出结果，定位问题环节
```

### 场景3: 统计分析
```bash
# 今天的洗分次数
grep "开始洗分" runtime/logs/machine_operations-$(date +%Y-%m-%d).log | wc -l

# 成功率
TOTAL=$(grep "开始洗分" runtime/logs/machine_operations.log | wc -l)
SUCCESS=$(grep "洗分完成" runtime/logs/machine_operations.log | wc -l)
echo "scale=2; $SUCCESS * 100 / $TOTAL" | bc

# 平均耗时
grep "total_duration_ms" runtime/logs/machine_operations.log | \
  grep -oP 'total_duration_ms":\K[0-9.]+' | \
  awk '{sum+=$1; count++} END {print "平均:", sum/count, "ms"}'
```

## 日志示例

```
[2026-06-05 14:30:25] INFO [MachineWash] 开始洗分 {"wash_id":"wash_675f12345","player_id":123,"machine_id":456}
[2026-06-05 14:30:25] INFO [MachineWash] 机台状态读取 {"point":1000,"bet":0,"win":0,"auto_status":0}
[2026-06-05 14:30:25] INFO [MachineWash] 赠点信息 {"give_point":100,"lottery_give_point":0}
[2026-06-05 14:30:25] INFO [MachineClient-Batch] 批量发送机台指令 - 开始 {"batch_id":"batch_675f67890","wash_id":"wash_675f12345","commands_count":3,"commands_list":["WASH_ZERO","MOVE_POINT_OFF","OUT_OFF"]}
[2026-06-05 14:30:26] INFO [PlayerMachine-SendCmd] 准备执行指令 {"batch_id":"batch_675f67890","command_index":0,"cmd":"WASH_ZERO","trace_context":{"wash_id":"wash_675f12345"}}
[2026-06-05 14:30:26] INFO [PlayerMachine-SendCmd] 指令执行完成 {"batch_id":"batch_675f67890","command_index":0,"success":true,"exec_duration_ms":50}
[2026-06-05 14:30:26] INFO [MachineClient-Batch] 批量指令执行完成 {"batch_id":"batch_675f67890","success_count":3,"failed_count":0,"total_duration_ms":180}
[2026-06-05 14:30:26] INFO [MachineWash-Slot] 洗分完成 {"wash_id":"wash_675f12345","total_duration_ms":1200}
```

## 跨项目关联

通过 **wash_id** 和 **batch_id** 实现跨项目日志关联：

```
gk_api (开始洗分)
  └─> wash_id: wash_675f12345
      └─> batch_id: batch_675f67890
          └─> gk_work (接收指令)
              └─> trace_context: {wash_id, batch_id, command_index}
                  └─> 执行结果返回
                      └─> gk_api (洗分完成)
```

## 优势

1. **单文件集中管理** - 无需在多个日志文件间切换
2. **完整流程追踪** - 从开始到结束的每一步都有记录
3. **跨项目关联** - 通过 wash_id 和 batch_id 串联两个项目
4. **便于排查问题** - 快速定位是哪个环节出问题
5. **性能监控** - 自动记录耗时，发现性能瓶颈
6. **自动化工具** - 提供追踪脚本，一键查看完整流程

## 后续优化建议

1. **添加更多关键检查点**
   - 数据库写入前后的状态
   - 锁的获取和释放
   - 网络重试机制

2. **性能优化监控**
   - 记录每个环节的耗时
   - 识别慢查询和慢操作
   - 添加性能告警阈值

3. **日志分析工具**
   - 开发 Web 界面查看日志
   - 实时监控面板
   - 异常自动告警

4. **日志归档**
   - 长期保存重要操作记录
   - 数据分析和统计
   - 审计追踪

---

**实现日期**: 2026-06-05  
**状态**: ✅ 已完成  
**测试**: 待生产环境验证  
**文档**: MACHINE_OPERATIONS_LOG_GUIDE.md
