# 斯洛机台洗分功能修复验证

## 问题描述
客户端调用 `/api/v1/slot-action?machine_id=1274&action=wash_point` 时返回"系统错误"

## 根本原因
在 `app/api/controller/v1/MachineController.php` 的 `slotAction()` 方法中，switch 语句缺少对 `wash_point` 操作的处理。当 action 为 `wash_point` 时，会进入 default 分支并抛出异常。

## 已修复的代码位置
**文件**: `D:\gk_api\app\api\controller\v1\MachineController.php`  
**方法**: `slotAction()`  
**行号**: 约 1134-1154 (新增的 case)

## 修复内容

在 `slotAction()` 方法的 switch 语句中，在 `pressure_score` case 之后、`default` 之前添加了 `wash_point` case：

```php
case 'wash_point':
    if ($machine->gaming_user_id == 0) {
        return jsonFailResponse(trans('no_open_point', [], 'message'));
    }
    if ($machine->gaming_user_id != 0 && $machine->gaming_user_id != $player->id) {
        return jsonFailResponse(trans('machine_is_using_msg1', [], 'message'));
    }
    // 增加业务锁
    $actionLockerKey = 'action_locker_key_machine_' . $machine->id . '_player_' . $player->id;
    $lock = Locker::lock($actionLockerKey, 5, true);
    if (!$lock->acquire()) {
        Log::error('业务锁异常--这里不处理异常');
    }

    $machineWashResult = machineWash($player, $machine, 'wash_point', 0, $hasLottery);
    if ($machineWashResult instanceof PlayerLotteryRecord) {
        $result = $machineWashResult->toArray();
        $result['has_lottery'] = false;
    } elseif ($machineWashResult != null) {
        $result = $machineWashResult;
    }
    break;
```

## 实现逻辑

### 1. 验证条件
- **机台未开分检查**: 如果 `gaming_user_id == 0`，返回"尚未开分"错误
- **玩家权限检查**: 如果机台正在被其他玩家使用，返回"机台使用中"错误

### 2. 业务锁
使用 `Locker::lock()` 创建分布式锁，防止并发洗分操作：
- **锁 Key**: `action_locker_key_machine_{机台ID}_player_{玩家ID}`
- **超时**: 5秒
- **锁获取失败**: 仅记录日志，不阻断流程（与 fishAction 保持一致）

### 3. 洗分处理
调用 `machineWash()` 全局函数执行洗分逻辑：
- **参数1**: 玩家对象
- **参数2**: 机台对象  
- **参数3**: 操作类型 `'wash_point'`（与 `leave`/`down` 不同）
- **参数4**: 是否系统操作 `0`
- **参数5**: 是否有彩金 `$hasLottery`

### 4. 结果处理
- 如果返回 `PlayerLotteryRecord` 实例：转为数组并设置 `has_lottery = false`
- 如果返回其他非 null 值：直接作为结果返回
- 最终通过 `jsonSuccessResponse()` 返回

## 与捕鱼机台洗分的对比

### 相似点
1. 都检查 `gaming_user_id`（是否开分 + 玩家权限）
2. 都使用业务锁防止并发
3. 都调用 `machineWash()` 或 `fishMachineWash()` 处理

### 差异点
| 项目 | 斯洛机台 (slotAction) | 捕鱼机台 (fishAction) |
|------|----------------------|----------------------|
| 洗分函数 | `machineWash()` | `fishMachineWash()` |
| 操作参数 | `'wash_point'` | `'wash_point'` (传递给服务) |
| 错误提示 | 使用 trans() 翻译 | 使用 trans() 翻译 |

## 验证要点

### 1. 基本功能测试
```bash
curl -X POST "http://api-test.5super9.com/api/v1/slot-action?machine_id=1274&action=wash_point" \
  -H "Authorization: Bearer {token}" \
  -H "Accept-Language: zh_CN"
```

**预期结果**: 不再返回"系统错误"，而是执行洗分逻辑或返回具体业务错误

### 2. 边界条件测试
- **未开分场景**: `gaming_user_id = 0` → 应返回"尚未开分"
- **他人使用**: `gaming_user_id != player->id` → 应返回"机台使用中"
- **机台维护**: `maintaining != 0` 或 `machineMaintaining() = true` → 应在 `checkAction()` 中拦截

### 3. 并发测试
同一玩家对同一机台快速发起多次洗分请求，验证业务锁是否有效

### 4. 日志检查
查看日志确认：
- `[MachineWash] 开始洗分` 日志出现
- 没有"业务锁异常"日志（或仅在极端并发下出现）
- SQL 执行正常（PlayerGameRecord 更新、WalletService 加款等）

## 相关文件

- **控制器**: `app/api/controller/v1/MachineController.php`
- **洗分逻辑**: `app/functions.php` → `machineWash()`
- **机台服务**: `app/service/machine/Slot.php`
- **翻译文件**: `resource/translations/{locale}/message.php`

## 注意事项

1. **不要混淆操作类型**:
   - `leave` / `down`: 弃台洗分（自动结束游戏）
   - `wash_point`: 中途洗分（游戏继续）

2. **锁超时处理**: 当前实现中，锁获取失败仅记录日志，不影响后续流程。如需严格保证互斥，应抛出异常。

3. **彩金处理**: `$hasLottery` 参数从 `checkAction()` 返回，需确保该方法正确设置。

4. **多语言支持**: 所有错误提示都使用 `trans()` 函数，确保支持 zh_CN、en、zh_TW、jp 四种语言。

## 后续优化建议

1. **统一洗分接口**: 考虑将 `machineWash()` 和 `fishMachineWash()` 合并或标准化
2. **锁失败处理**: 评估是否应在锁获取失败时直接返回错误而非继续执行
3. **日志增强**: 在 wash_point 操作成功后添加详细日志，包含洗分金额等信息
4. **性能监控**: 监控 `wash_point` 操作的平均响应时间，确保不超过 2 秒

---

**修复日期**: 2026-06-05  
**修复人**: Claude  
**影响范围**: 斯洛机台（GameType::TYPE_SLOT）洗分功能
