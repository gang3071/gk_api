# 玩家ID传递检查报告

## 检查结果

### 代码层面的传递链路 ✅

#### 1. functions.php → AbstractMachineService::sendCmd()
```php
// app/functions.php:878
$services->sendCmd($services::OPEN_ANY_POINT, $openScore, 'player', $player->id);
```
✅ **传递了 `$player->id`**

#### 2. AbstractMachineService::sendCmd() → MachineClient::sendCommand()
```php
// app/service/machine/AbstractMachineService.php:374
$playerId = $source === 'player' ? $source_id : null;

$result = $client->sendCommand(
    $this->machine->id,
    $cmd,
    $data,
    $this->lang,
    $playerId  // ✅ 传递了 playerId
);
```
✅ **正确提取并传递 `$playerId`**

#### 3. MachineClient::sendCommand() → HTTP Request Header
```php
// app/service/machine/MachineClient.php:105-107
if ($playerId !== null && $playerId > 0) {
    $headers['X-Player-Id'] = $playerId;  // ✅ 添加到 header
}
```
✅ **条件判断正确，playerId = 10 满足条件**

### 实际日志中的数据

**请求日志：**
```json
{
  "url":"http://127.0.0.1:8788/api/v1/machine/send-cmd",
  "payload":{
    "machine_id":1275,
    "cmd":"4A",
    "data":51,
    "lang":"zh-TW"
  },
  "player_id":10
}
```

**响应日志：**
```json
{
  "code":401,
  "msg":"玩家信息获取失败"
}
```

## 问题分析

### 理论上应该发送的 HTTP 请求

```http
POST http://127.0.0.1:8788/api/v1/machine/send-cmd HTTP/1.1
Accept-Language: zh-TW
X-Player-Id: 10
Content-Type: application/json

{
  "machine_id": 1275,
  "cmd": "4A",
  "data": 51,
  "lang": "zh-TW"
}
```

### 为什么会失败？

#### 可能性1：Header 实际未发送（已排除概率低）
- 代码逻辑检查：✅ 正确
- 条件判断检查：✅ playerId=10 满足条件
- **但之前的日志没有记录 headers**

#### 可能性2：gk_work 无法读取 Header（概率高 ⭐）
- gk_work 的中间件可能未处理 `X-Player-Id`
- gk_work 的控制器可能未读取 header
- 需要检查 gk_work 的代码实现

#### 可能性3：gk_work 读取到 player_id 但查询失败（概率高 ⭐）
- gk_work 的数据库连接配置错误
- gk_work 无法查询到 player_id=10 的玩家
- Player 模型或表结构问题

## 已完成的改进

### 1. 增强日志记录 ✅
修改了 `MachineClient::sendCommand()` 的日志，现在会记录：
```php
Log::info('[MachineClient] 发送机台指令 - 请求', [
    'url' => $this->baseUrl . '/api/v1/machine/send-cmd',
    'payload' => $requestPayload,
    'headers' => $headers,  // 🆕 新增：记录发送的 headers
    'player_id' => $playerId,
]);
```

下次请求会在日志中看到完整的 headers 内容。

## 下一步诊断步骤

### 步骤1：运行调试脚本（推荐）
```bash
cd D:\gk_api
php debug_player_id_transmission.php
```

这个脚本会：
1. 验证 playerId 条件判断逻辑
2. 显示构建的 HTTP headers
3. 实际发送请求到 gk_work
4. 测试3种方式：
   - 带 X-Player-Id header
   - 不带 X-Player-Id header
   - player_id 放在 body 中

### 步骤2：再次测试并查看新日志
```bash
# 测试上分功能，触发一次请求
# 然后查看日志

tail -f runtime/logs/webman.log | grep MachineClient
```

新日志会显示：
```json
{
  "headers": {
    "Accept-Language": "zh-TW",
    "X-Player-Id": 10
  }
}
```

### 步骤3：检查 gk_work 的实现

切换到 gk_work 项目：
```bash
cd D:\gk_work

# 1. 查找接收 X-Player-Id 的代码
grep -rn "X-Player-Id" app/

# 2. 查找 "玩家信息获取失败" 的错误来源
grep -rn "玩家信息获取失败" app/

# 3. 查看机台控制器
cat app/api/controller/v1/MachineController.php
```

### 步骤4：验证 gk_work 的数据库连接

在 gk_work 中执行：
```php
// 测试查询
php -r "
require 'vendor/autoload.php';
\$player = \app\model\Player::find(10);
var_dump(\$player);
"
```

## 临时解决方案（如果 Header 不工作）

### 方案A：同时在 body 和 header 中发送 player_id

修改 `MachineClient::sendCommand()`：
```php
$requestPayload = [
    'machine_id' => $machineId,
    'cmd' => $cmd,
    'data' => $data,
    'lang' => $lang,
    'player_id' => $playerId,  // 🆕 添加到 body 中
];
```

### 方案B：修改 gk_work 读取方式

在 gk_work 的控制器中：
```php
// 优先从 header 读取，fallback 到 body
$playerId = $request->header('X-Player-Id') 
         ?? $request->input('player_id') 
         ?? 0;
```

## 总结

1. ✅ **gk_api 代码层面传递正确**
2. ❓ **需要确认 headers 是否真的发送到 gk_work**（新日志会显示）
3. ❓ **需要检查 gk_work 是否正确读取和处理 X-Player-Id**
4. ❓ **需要验证 gk_work 的数据库连接和 Player 查询**

---

**检查时间：** 2026-06-04  
**结论：** gk_api 代码正确，问题很可能在 gk_work 端  
**建议：** 运行 `debug_player_id_transmission.php` 脚本进行完整测试
