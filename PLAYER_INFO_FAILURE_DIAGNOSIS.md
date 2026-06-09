# 玩家信息获取失败问题诊断

## 问题描述

```
[MachineClient] 指令执行失败 - 响应
{
  "code": 401,
  "msg": "玩家信息获取失败",
  "data": []
}
```

## 问题发生流程

```
gk_api (8787端口)                     gk_work (8788端口)
      │                                      │
      │  1. 玩家上分请求                      │
      │  POST /api/v1/machine/slot           │
      │                                      │
      ├─> 2. 扣款成功 (数据库事务提交)         │
      │                                      │
      ├─> 3. 发送机台指令                      │
      │  POST http://127.0.0.1:8788/...     │
      │  Headers:                            │
      │    X-Player-Id: 10                   │
      │  Body:                               │
      │    machine_id: 1275                  │
      │    cmd: 4A                           │
      │    data: 51                          │
      │                                      │
      │                                  <───┤ 4. 返回 401 错误
      │                                      │    "玩家信息获取失败"
      │                                      │
      ├─> 5. 开始补偿事务                      │
          (尝试退款)                          
```

## 根本原因

**gk_work 无法根据 `X-Player-Id` header 获取到玩家信息。**

可能的原因：

### 1. 数据库连接配置问题

**检查项：**
- gk_work 的 `config/database.php` 是否正确配置
- gk_work 是否能连接到 gk_api 使用的同一个数据库
- gk_work 是否有读取 `player` 表的权限

**验证方法：**
```bash
cd D:\gk_work
php artisan db:query "SELECT id, username FROM player WHERE id = 10"
```

### 2. gk_work 接口未正确读取 header

**检查项：**
- gk_work 的 `MachineController` 是否正确读取 `X-Player-Id` header
- 中间件是否拦截或修改了 header

**验证方法：**
```bash
# 查看 gk_work 的机台控制器
cat D:\gk_work\app\api\controller\v1\MachineController.php | grep -A 20 "send-cmd"
```

### 3. Player 模型查询失败

**检查项：**
- gk_work 的 `Player` 模型是否与数据库表结构一致
- 是否有软删除或其他查询条件导致查询失败

**验证方法：**
```php
// 在 gk_work 中执行
$player = \app\model\Player::find(10);
var_dump($player);
```

### 4. 缺少必要的上下文信息

**可能需要的信息：**
- store_id（门店ID）
- session token
- 其他认证信息

## 诊断步骤

### 步骤1：运行测试脚本

```bash
cd D:\gk_api
php test_gk_work_connection.php
```

这会测试：
- gk_work 服务是否在线
- 不带 header 和带 header 的请求区别
- gk_work 的响应内容

### 步骤2：查看 gk_work 日志

```bash
# 实时查看 gk_work 日志
tail -f D:\gk_work\runtime\logs\webman.log

# 搜索相关错误
grep "玩家信息获取失败" D:\gk_work\runtime\logs\webman.log
```

### 步骤3：检查 gk_work 的接口实现

```bash
cd D:\gk_work

# 查找 "玩家信息获取失败" 的代码位置
grep -rn "玩家信息获取失败" app/
```

### 步骤4：对比 gk_api 和 gk_work 的数据库配置

```bash
# gk_api 的数据库配置
cat D:\gk_api\config\database.php | grep -A 10 "mysql"

# gk_work 的数据库配置
cat D:\gk_work\config\database.php | grep -A 10 "mysql"
```

## 临时解决方案

### 方案1：在请求 body 中传递完整玩家信息

修改 `MachineClient::sendCommand()` 方法，在 payload 中添加玩家信息：

```php
$requestPayload = [
    'machine_id' => $machineId,
    'cmd' => $cmd,
    'data' => $data,
    'lang' => $lang,
    'player_id' => $playerId,  // 添加到 body 中
];
```

### 方案2：修改 gk_work 的认证逻辑

在 gk_work 中添加降级方案：
- 优先从 header 读取 player_id
- 如果 header 不存在，从 body 读取
- 如果都不存在，返回明确的错误信息

### 方案3：添加更多上下文信息

修改 `AbstractMachineService::sendCmd()` 传递更多信息：

```php
public function sendCmd(
    string $cmd,
    int $data = 0,
    string $source = 'player',
    int $source_id = 0
): bool {
    $client = new MachineClient();
    $playerId = $source === 'player' ? $source_id : null;
    
    // 添加：传递 store_id 等额外信息
    $result = $client->sendCommand(
        $this->machine->id,
        $cmd,
        $data,
        $this->lang,
        $playerId,
        [
            'store_id' => $this->machine->store_id ?? 0,
            'machine_code' => $this->machine->code,
        ]
    );
    
    // ...
}
```

## 长期解决方案

### 1. 统一认证机制

在 gk_api 和 gk_work 之间建立统一的认证机制：
- 使用 JWT token 或 API key
- 在 header 中传递完整的认证信息
- gk_work 独立验证请求合法性

### 2. 使用服务间认证

为 gk_api -> gk_work 的调用添加服务认证：
```php
$headers = [
    'X-Service-Name' => 'gk_api',
    'X-Service-Token' => hash_hmac('sha256', $payload, $secret),
    'X-Player-Id' => $playerId,
];
```

### 3. 改进错误信息

gk_work 应该返回更详细的错误信息：
```json
{
  "code": 401,
  "msg": "玩家信息获取失败",
  "data": {
    "reason": "Player not found in database",
    "player_id": 10,
    "checked_table": "player",
    "suggestions": [
      "检查数据库连接",
      "检查 player_id 是否存在"
    ]
  }
}
```

## 下一步行动

1. **立即执行：** 运行 `test_gk_work_connection.php` 测试脚本
2. **检查日志：** 查看 gk_work 的日志文件
3. **定位代码：** 在 gk_work 中找到返回 "玩家信息获取失败" 的代码位置
4. **验证数据库：** 确认 gk_work 能否查询到 player_id=10 的玩家
5. **对比配置：** 确认 gk_api 和 gk_work 使用相同的数据库

---

**诊断时间：** 2026-06-04  
**问题类型：** 服务间通信 - 玩家信息验证失败  
**优先级：** 高（影响玩家上分功能）
