# 设备服务铃 API 文档

## 📋 目录

- [概述](#概述)
- [API 接口](#api-接口)
- [防重复机制](#防重复机制)
- [WebSocket 推送](#websocket-推送)
- [消息记录系统](#消息记录系统)
- [H5 前端集成](#h5-前端集成)
- [测试指南](#测试指南)
- [安全机制](#安全机制)
- [配置要求](#配置要求)
- [常见问题](#常见问题)
- [更新日志](#更新日志)

---

## 概述

设备服务铃功能允许 H5 玩家端呼叫服务，店家后台实时收到通知并播放语音提醒。

### 业务流程

```
H5 玩家端
    ↓ 点击「呼叫服务」
调用 API
    ↓ POST /api/v1/call-service
服务端验证
    ↓ 验证设备 → 防重复检查 → 数据一致性校验
WebSocket 推送
    ↓ 推送到店家后台频道
店家后台
    ├─ 实时接收 → 桌面通知 + 语音播报
    └─ 保存消息记录 → 消息列表显示
店家前往处理
```

### 核心特性

✅ **实时推送** - WebSocket 实时通知店家后台  
✅ **语音播报** - 自动播放「{设备名称}呼叫服务」  
✅ **防重复** - 5 秒内同一设备只能请求一次  
✅ **消息记录** - 自动保存到数据库，可查看历史  
✅ **多语言** - 支持繁体中文、简体中文、英文、日文  
✅ **数据隔离** - 四重安全保障，防止跨店家串消息  

---

## API 接口

### 呼叫服务铃

**接口地址**: `POST /api/v1/call-service`

**Content-Type**: `application/json`

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| device_no | string | 是 | 设备号（Android 设备唯一标识） |

**请求示例**:

```json
{
  "device_no": "1234567890abcdef"
}
```

**成功响应** (HTTP 200, code: 200):

```json
{
  "code": 200,
  "msg": "服務鈴已呼叫，請稍等",
  "data": {
    "device_name": "3號桌",
    "retry_after": 5
  }
}
```

**响应字段说明**:

| 字段 | 类型 | 说明 |
|------|------|------|
| code | int | 状态码（200=成功，100=失败） |
| msg | string | 提示消息（支持多语言） |
| data.device_name | string | 设备名称 |
| data.retry_after | int | 下次可请求的等待时间（秒） |

### 错误响应

#### 1. 参数验证失败

```json
{
  "code": 100,
  "msg": "設備號 must not be empty",
  "data": null
}
```

**触发条件**：
- `device_no` 为空
- `device_no` 类型不正确

#### 2. 设备不存在或已禁用

```json
{
  "code": 100,
  "msg": "設備不存在或已禁用",
  "data": null
}
```

**触发条件**：
- `device_no` 在数据库中不存在
- 设备 `status = 0`（已禁用）

#### 3. 设备未绑定店家

```json
{
  "code": 100,
  "msg": "設備未綁定店家，無法呼叫服務",
  "data": null
}
```

**触发条件**：
- `store_admin_id` 为空或 0

#### 4. 请求过于频繁（防重复）

```json
{
  "code": 100,
  "msg": "已呼叫服務員，請耐心等待",
  "data": {
    "retry_after": 3
  }
}
```

**触发条件**：
- 5 秒内重复请求同一设备

**说明**：
- `retry_after` 显示还需等待的秒数
- 等待时间结束后可再次请求

#### 5. WebSocket 推送失败

```json
{
  "code": 100,
  "msg": "服務鈴推送失敗，請稍後重試",
  "data": null
}
```

**触发条件**：
- WebSocket Push 服务不可用
- 店家管理员不存在
- 数据一致性校验失败

**说明**：
- 推送失败会立即释放 Redis 锁
- 玩家可以立即重试

#### 6. 系统错误

```json
{
  "code": 100,
  "msg": "系統錯誤",
  "data": null
}
```

**触发条件**：
- 数据库连接失败
- 未知异常

---

## 防重复机制

### Redis 锁实现

**锁键名格式**:
```
service:call:device:{device_id}
```

**锁过期时间**: 5 秒（TTL）

### 工作流程

```
1. 玩家请求服务铃
   ↓
2. 尝试获取 Redis 锁
   ├─ 成功 → 继续处理
   └─ 失败 → 返回 429 错误
   ↓
3. 推送消息到店家后台
   ├─ 成功 → 保持锁 5 秒
   └─ 失败 → 立即释放锁（允许重试）
   ↓
4. 5 秒后锁自动过期
   ↓
5. 玩家可再次请求
```

### 为什么是 5 秒？

| 场景 | 说明 |
|------|------|
| **防止误操作** | 玩家连续点击不会产生多次推送 |
| **防止恶意刷接口** | 限制请求频率，保护服务器 |
| **合理的等待时间** | 店家通常在 5-10 秒内响应 |
| **允许再次呼叫** | 如果店家未响应，可以重新呼叫 |

### 代码实现

```php
// 尝试获取锁（非阻塞）
$lockKey = "service:call:device:{$device->id}";
$lockTtl = 5; // 5 秒

$lock = Locker::lock($lockKey, $lockTtl, false);

if (!$lock) {
    // 获取锁失败 = 5 秒内已请求过
    $remainingTtl = Cache::ttl($lockKey);
    
    return jsonFailResponse(trans('service_call_waiting', [], 'message'), [
        'retry_after' => $remainingTtl
    ]);
}

// 推送失败时立即释放锁
if ($pushFailed) {
    Locker::unlock($lock);
}
```

---

## WebSocket 推送

### 频道命名规则

店家后台 WebSocket 频道格式：

```
private-store-{department_id}-{store_admin_id}
```

**示例**：
```
private-store-1001-100
```

**说明**：
- `department_id` - 部门ID（渠道ID）
- `store_admin_id` - 店家管理员ID

### 推送数据格式（已优化）

```json
{
  "from_uid": "service_bell",
  "content": "{\"type\":\"service_call\",\"device_name\":\"3號桌\",\"voice_url\":\"https://storage.googleapis.com/...\"}"
}
```

**content 字段解析后**：

```json
{
  "type": "service_call",
  "device_name": "3號桌",
  "voice_url": "https://storage.googleapis.com/.../device_123.mp3"
}
```

**字段说明**：

| 字段 | 类型 | 说明 |
|------|------|------|
| type | string | 消息类型，固定为 `service_call` |
| device_name | string | 设备名称，用于通知和语音播报 |
| voice_url | string | Google TTS 生成的语音文件 URL |

**优化说明**：
- ✅ 精简数据：只保留播报必要的 3 个字段
- ✅ 数据量减少 50%（从 7 个字段优化到 3 个）
- ✅ 节省网络传输，提高推送速度

### 店家后台接收示例

```javascript
// socket.vue 接收逻辑
handleAdminMessage(data) {
  const content = JSON.parse(data.content);
  
  // 处理服务铃消息
  if (content.type === 'service_call') {
    this.handleServiceCall(content);
  }
}

handleServiceCall(content) {
  const lang = this.lang; // 当前语言
  
  // 1. 显示桌面通知
  this.$notification.warning({
    message: messages[lang].message.service_bell_call,  // "服務鈴呼叫"
    description: `${content.device_name}${messages[lang].message.device_call_service}`,  // "3號桌呼叫服務"
    duration: 5,
  });
  
  // 2. 添加到语音播报队列
  if (content.voice_url) {
    this.addToVoiceQueue(content.voice_url);
  }
}
```

### 语音播报队列

店家后台实现了语音播报队列系统：

```javascript
// 语音队列数据
data() {
  return {
    voiceQueue: [],        // 语音URL队列
    isPlayingVoice: false  // 是否正在播报
  }
}

// 添加到队列
addToVoiceQueue(voiceUrl) {
  this.voiceQueue.push(voiceUrl);
  
  if (!this.isPlayingVoice) {
    this.playNextVoice();
  }
}

// 播放下一条语音
playNextVoice() {
  if (this.voiceQueue.length === 0) {
    this.isPlayingVoice = false;
    return;
  }
  
  this.isPlayingVoice = true;
  const voiceUrl = this.voiceQueue.shift();
  const audio = new Audio(voiceUrl);
  
  // 播报完成后等待 3 秒
  audio.onended = () => {
    setTimeout(() => {
      this.playNextVoice();
    }, 3000);
  };
  
  // 播报失败后等待 1 秒重试
  audio.onerror = () => {
    setTimeout(() => {
      this.playNextVoice();
    }, 1000);
  };
  
  audio.play();
}
```

**队列机制优势**：
- ✅ 多个服务铃消息顺序播报
- ✅ 每条消息间隔 3 秒，避免重叠
- ✅ 播放失败自动跳过，不卡住
- ✅ 自动清空队列，不积压

---

## 消息记录系统

### Notice 表记录

每次服务铃请求成功后，会自动保存到 `yjb_notice` 表：

```php
Notice::create([
    'department_id' => $device->department_id,  // 设备所属部门
    'source_id' => $device->id,                 // 设备ID
    'type' => Notice::TYPE_SERVICE_CALL,        // 类型：25
    'receiver' => Notice::RECEIVER_DEPARTMENT,  // 接收方：子站（店家）
    'admin_id' => $storeAdmin->id,              // 店家管理员ID
    'admin_name' => $storeAdmin->nickname,      // 管理员昵称
    'status' => 0,                               // 未读
]);
```

### 数据库字段

| 字段 | 类型 | 说明 |
|------|------|------|
| id | int | 主键 |
| department_id | int | 部门ID（数据权限） |
| source_id | int | 设备ID（关联 admin_device） |
| type | int | 消息类型（25=服务铃） |
| receiver | int | 接收方（3=子站/店家后台） |
| admin_id | int | 店家管理员ID |
| admin_name | string | 管理员昵称 |
| status | int | 状态（0=未读，1=已读） |
| created_at | datetime | 创建时间 |

### 店家后台消息列表

**接口**: `GET /ex-admin/system/noticeList?page=1&size=10`

**查询条件**（四重安全校验）：

```php
Notice::where('admin_id', Admin::id())                     // 筛选当前店家
    ->where('department_id', Admin::user()->department_id) // 筛选当前部门
    ->whereIN('type', [25])                                 // 服务铃类型
    ->latest()
    ->get();
```

**响应示例**：

```json
{
  "code": 0,
  "data": [
    {
      "id": 123,
      "source_id": 456,
      "title": "設備服務鈴呼叫",
      "content": "設備 3號桌 呼叫服務",
      "type": 25,
      "created_at": "2026-07-29 15:30:25",
      "status": true,
      "url": ""
    },
    {
      "id": 122,
      "source_id": 458,
      "title": "設備服務鈴呼叫",
      "content": "設備 5號桌 呼叫服務",
      "type": 25,
      "created_at": "2026-07-29 15:28:12",
      "status": true,
      "url": ""
    }
  ]
}
```

### 多语言翻译

消息列表支持 4 种语言：

| 语言 | 标题 | 内容格式 |
|------|------|---------|
| **繁体中文** | 設備服務鈴呼叫 | 設備 {device_name} 呼叫服務 |
| **简体中文** | 设备服务铃呼叫 | 设备 {device_name} 呼叫服务 |
| **英文** | Device Service Bell Call | Device {device_name} call service |
| **日文** | デバイスサービスベル呼び出し | デバイス {device_name} がサービスを呼び出す |

---

## H5 前端集成

### 1. HTML 按钮

```html
<button id="callServiceBtn" 
        onclick="callService()" 
        class="service-btn">
  呼叫服務
</button>

<style>
.service-btn {
  width: 100%;
  padding: 15px;
  font-size: 18px;
  background: #ff6b6b;
  color: white;
  border: none;
  border-radius: 8px;
  cursor: pointer;
}

.service-btn:disabled {
  background: #ccc;
  cursor: not-allowed;
}
</style>
```

### 2. JavaScript 完整实现

```javascript
class ServiceBellClient {
  constructor() {
    this.isDisabled = false;
    this.countdownTimer = null;
  }
  
  /**
   * 呼叫服务
   */
  async callService() {
    // 防止重复点击
    if (this.isDisabled) {
      return;
    }
    
    const deviceNo = this.getDeviceNo();
    
    if (!deviceNo) {
      this.showToast('设备号不存在，请刷新页面');
      return;
    }
    
    try {
      const response = await fetch('/api/v1/call-service', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          device_no: deviceNo
        })
      });
      
      const result = await response.json();
      
      if (result.code === 200) {
        // 成功
        this.showToast(result.msg);
        this.disableButtonWithCountdown(result.data.retry_after);
      } else {
        // 错误（包括 429 频繁请求）
        this.showToast(result.msg);
        
        // 如果有 retry_after，显示倒计时
        if (result.data && result.data.retry_after) {
          this.disableButtonWithCountdown(result.data.retry_after);
        }
      }
    } catch (error) {
      console.error('Service bell error:', error);
      this.showToast('网络错误，请稍后重试');
    }
  }
  
  /**
   * 禁用按钮并显示倒计时
   */
  disableButtonWithCountdown(seconds) {
    const btn = document.getElementById('callServiceBtn');
    this.isDisabled = true;
    
    let remaining = seconds;
    btn.textContent = `請等待 ${remaining} 秒`;
    btn.disabled = true;
    
    this.countdownTimer = setInterval(() => {
      remaining--;
      if (remaining <= 0) {
        clearInterval(this.countdownTimer);
        btn.textContent = '呼叫服務';
        btn.disabled = false;
        this.isDisabled = false;
      } else {
        btn.textContent = `請等待 ${remaining} 秒`;
      }
    }, 1000);
  }
  
  /**
   * 显示提示消息
   */
  showToast(message) {
    // 使用 Toast 组件或 alert
    if (typeof Toast !== 'undefined') {
      Toast.info(message);
    } else {
      alert(message);
    }
  }
  
  /**
   * 获取设备号
   */
  getDeviceNo() {
    // 从 URL 参数获取
    const urlParams = new URLSearchParams(window.location.search);
    const deviceNo = urlParams.get('device_no');
    
    if (deviceNo) {
      // 保存到 localStorage
      localStorage.setItem('device_no', deviceNo);
      return deviceNo;
    }
    
    // 从 localStorage 获取
    return localStorage.getItem('device_no') || '';
  }
}

// 初始化
const serviceBell = new ServiceBellClient();

function callService() {
  serviceBell.callService();
}
```

### 3. 移动端优化

```javascript
// 添加触摸反馈
document.getElementById('callServiceBtn').addEventListener('touchstart', function() {
  this.style.transform = 'scale(0.95)';
});

document.getElementById('callServiceBtn').addEventListener('touchend', function() {
  this.style.transform = 'scale(1)';
});

// 防止双击放大
document.getElementById('callServiceBtn').addEventListener('touchend', function(e) {
  e.preventDefault();
  callService();
});
```

---

## 测试指南

### 使用 curl 测试

```bash
# 正常请求
curl -X POST http://localhost:8787/api/v1/call-service \
  -H "Content-Type: application/json" \
  -d '{"device_no": "1234567890abcdef"}'

# 预期响应
{
  "code": 200,
  "msg": "服務鈴已呼叫，請稍等",
  "data": {
    "device_name": "3號桌",
    "retry_after": 5
  }
}
```

### 测试场景

#### 场景 1：正常呼叫流程

**前置条件**：
- 设备存在且 `status = 1`
- 设备已绑定店家（`store_admin_id` 有效）
- 店家后台在线

**操作步骤**：
1. H5 端点击「呼叫服务」按钮
2. 调用 API

**预期结果**：
- ✅ API 返回 `code: 200`
- ✅ 按钮禁用 5 秒，显示倒计时
- ✅ 店家后台收到 WebSocket 推送
- ✅ 桌面通知显示「3號桌呼叫服務」
- ✅ 自动播放语音
- ✅ 消息保存到数据库
- ✅ 消息列表显示该记录

#### 场景 2：重复请求（防刷）

**操作步骤**：
1. 正常呼叫成功
2. 5 秒内再次点击按钮

**预期结果**：
- ✅ API 返回 `code: 100`
- ✅ 提示「已呼叫服務員，請耐心等待」
- ✅ 显示剩余等待时间
- ✅ 不产生新的 WebSocket 推送
- ✅ 不保存重复的数据库记录

#### 场景 3：设备不存在

**操作步骤**：
使用不存在的 `device_no` 调用 API

**预期结果**：
- ✅ API 返回 `code: 100`
- ✅ 提示「設備不存在或已禁用」
- ✅ 按钮不禁用，可立即重试

#### 场景 4：设备未绑定店家

**操作步骤**：
使用 `store_admin_id = 0` 的设备

**预期结果**：
- ✅ API 返回 `code: 100`
- ✅ 提示「設備未綁定店家，無法呼叫服務」

#### 场景 5：WebSocket 推送失败

**模拟方式**：
停止 WebSocket Push 服务

**预期结果**：
- ✅ API 返回 `code: 100`
- ✅ 提示「服務鈴推送失敗，請稍後重試」
- ✅ Redis 锁立即释放
- ✅ 玩家可立即重试

#### 场景 6：多设备同时呼叫

**操作步骤**：
不同设备（A、B、C）同时呼叫服务

**预期结果**：
- ✅ 每个设备都能成功呼叫
- ✅ 店家后台收到 3 条消息
- ✅ 语音播报排队播放，间隔 3 秒
- ✅ 消息列表显示 3 条记录

### 性能测试

```bash
# 使用 ab 进行压力测试
ab -n 1000 -c 10 \
  -p data.json \
  -T "application/json" \
  http://localhost:8787/api/v1/call-service

# data.json 内容
{"device_no": "1234567890abcdef"}
```

**预期指标**：
- 吞吐量：> 500 req/s
- 平均响应时间：< 20ms
- 错误率：0%（除重复请求外）

---

## 安全机制

### 1. 数据一致性校验

```php
// 验证设备和店家管理员必须属于同一部门
if ($device->department_id != $storeAdmin->department_id) {
    Log::error('设备和店家管理员部门不一致', [
        'device_id' => $device->id,
        'device_department_id' => $device->department_id,
        'store_admin_id' => $storeAdmin->id,
        'store_admin_department_id' => $storeAdmin->department_id,
    ]);
    throw new Exception('数据异常');
}
```

**防护目标**：防止数据不一致导致的串消息

### 2. 四重数据隔离

| 层级 | 机制 | 说明 |
|------|------|------|
| **写入端** | 数据源统一 | 使用 `device.department_id` |
| **写入端** | 一致性校验 | `device.dept` 必须等于 `admin.dept` |
| **读取端** | 查询双重筛选 | `admin_id` + `department_id` |
| **模型层** | 全局作用域 | DataPermissions 自动过滤 |

### 3. WebSocket 频道隔离

```
店家 A：private-store-1001-100
店家 B：private-store-1002-200

完全隔离，不会串推送！
```

### 4. 日志审计

```php
// 成功日志
Log::info('设备服务铃请求成功', [
    'device_id' => $device->id,
    'store_admin_id' => $storeAdmin->id,
    'channel' => $channelName,
    'ip' => $request->getRealIp(),
]);

// 错误日志
Log::error('设备服务铃推送失败', [
    'device_id' => $device->id,
    'error' => $e->getMessage(),
]);
```

---

## 配置要求

### 环境变量

`.env` 文件必须配置：

```env
# WebSocket Push 服务配置
PUSH_API_URL=http://127.0.0.1:3232
PUSH_APP_KEY=20f94408fc4c52845f162e92a253c7a3
PUSH_APP_SECRET=a8c092135db24c9695dcdb5bbd88cd18
WS_URL=ws://127.0.0.1:3131

# Google TTS API Key（可选）
GOOGLE_TTS_API_KEY=your_google_tts_api_key
```

### 数据库表

#### admin_device 表

```sql
CREATE TABLE `yjb_admin_device` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `device_no` varchar(255) NOT NULL COMMENT '设备号',
  `device_name` varchar(100) NOT NULL COMMENT '设备名称',
  `store_admin_id` int(11) DEFAULT NULL COMMENT '所属店家ID',
  `department_id` int(11) NOT NULL COMMENT '所属部门ID',
  `voice_url` varchar(500) DEFAULT NULL COMMENT '语音播报文件URL',
  `status` tinyint(1) DEFAULT 1 COMMENT '状态(1:启用 0:禁用)',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `device_no` (`device_no`),
  KEY `idx_store_admin_id` (`store_admin_id`),
  KEY `idx_department_id` (`department_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

#### yjb_notice 表

```sql
-- 服务铃消息类型
ALTER TABLE `yjb_notice` 
  ADD COMMENT ON COLUMN `type` = '25: 设备服务铃呼叫';

-- 索引优化
CREATE INDEX `idx_admin_dept_type` 
  ON `yjb_notice` (`admin_id`, `department_id`, `type`);
```

### WebSocket Push 服务

确保 WebSocket Push 服务正常运行：

```bash
# 检查端口
netstat -tlnp | grep 3131  # WebSocket 端口
netstat -tlnp | grep 3232  # Push API 端口

# 测试连接
curl http://127.0.0.1:3232/api/ping
```

---

## 常见问题

### Q1: 为什么设置 5 秒防重复？

**A**: 5 秒是一个平衡点：

| 优势 | 说明 |
|------|------|
| **防止误操作** | 玩家连续点击不会产生多次推送 |
| **防止恶意刷接口** | 限制请求频率，保护服务器资源 |
| **合理的响应时间** | 给店家 5-10 秒的响应窗口 |
| **允许再次呼叫** | 如果店家未响应，可以重新呼叫 |

### Q2: 推送失败会怎样？

**A**:
- ✅ API 返回错误 `code: 100`，提示「服務鈴推送失敗」
- ✅ 立即释放 Redis 锁
- ✅ 玩家可以立即重试
- ✅ 记录详细错误日志，便于排查
- ✅ 不保存 Notice 记录，避免脏数据

### Q3: 店家后台离线怎么办？

**A**:
- WebSocket 推送失败不影响 API 响应
- 消息会保存到 `yjb_notice` 表
- 店家重新上线后可以在消息列表查看
- 建议设置离线提醒机制

### Q4: 如何监控服务铃使用情况？

**A**:

**方法 1：查看日志**

```bash
# 统计今天的服务铃请求
grep "设备服务铃请求成功" runtime/logs/webman.log | \
  grep "$(date +%Y-%m-%d)" | wc -l
```

**方法 2：数据库统计**

```sql
-- 查看各店家的服务铃使用情况
SELECT 
  admin_name,
  COUNT(*) AS call_count,
  DATE(created_at) AS date
FROM yjb_notice
WHERE type = 25
  AND created_at >= CURDATE()
GROUP BY admin_id, DATE(created_at)
ORDER BY call_count DESC;

-- 查看最活跃的设备
SELECT 
  d.device_name,
  COUNT(*) AS call_count
FROM yjb_notice n
JOIN yjb_admin_device d ON n.source_id = d.id
WHERE n.type = 25
  AND n.created_at >= CURDATE() - INTERVAL 7 DAY
GROUP BY n.source_id
ORDER BY call_count DESC
LIMIT 10;
```

### Q5: 语音播报不工作怎么办？

**A**:

**检查清单**：

1. **检查 voice_url 字段**
   ```sql
   SELECT id, device_name, voice_url 
   FROM yjb_admin_device 
   WHERE id = 123;
   ```

2. **测试语音文件是否可访问**
   ```bash
   curl -I https://storage.googleapis.com/.../device_123.mp3
   ```

3. **检查浏览器控制台错误**
   ```
   F12 → Console → 查看是否有 CORS 错误
   ```

4. **检查 Google TTS API Key**
   ```
   .env 中 GOOGLE_TTS_API_KEY 是否配置
   ```

### Q6: 如何处理并发请求？

**A**:

系统已经通过 Redis 锁机制处理并发：

```
时间轴：
T0: 玩家 A 请求 → 获取锁成功 ✓
T1: 玩家 A 再次请求 → 锁已占用 ✗（返回 429）
T2: 玩家 B 请求不同设备 → 获取锁成功 ✓（不同设备，不冲突）
T5: 锁过期
T6: 玩家 A 再次请求 → 获取锁成功 ✓
```

### Q7: 如何优化性能？

**A**:

**优化建议**：

1. **Redis 连接池**
   ```php
   // config/redis.php
   'connections' => 10,  // 增加连接池大小
   ```

2. **WebSocket 推送异步化**
   ```php
   // 使用队列异步推送（可选）
   queue('service-call')->push($pushData);
   ```

3. **数据库索引**
   ```sql
   -- 消息列表查询优化
   CREATE INDEX idx_admin_dept_type 
     ON yjb_notice (admin_id, department_id, type);
   ```

4. **CDN 加速语音文件**
   ```
   将 Google TTS 生成的文件上传到 CDN
   减少播放延迟
   ```

---

## 更新日志

### v1.2.0 (2026-07-29)

**新增功能**：
- ✅ 消息记录系统：自动保存到 `yjb_notice` 表
- ✅ 消息列表查询：店家后台可查看历史服务铃记录
- ✅ 多语言支持：繁体中文、简体中文、英文、日文

**安全增强**：
- ✅ 数据一致性校验：`device.dept` 必须等于 `admin.dept`
- ✅ 四重数据隔离：防止跨店家串消息
- ✅ 查询双重筛选：`admin_id` + `department_id`

**性能优化**：
- ✅ WebSocket 推送数据精简：从 7 个字段优化到 3 个
- ✅ 数据量减少 50%
- ✅ 语音播报队列：3 秒间隔，避免重叠

### v1.1.0 (2026-07-29)

**优化改进**：
- ✅ 语音播报队列系统
- ✅ 桌面通知多语言
- ✅ 错误处理优化

### v1.0.0 (2026-07-29)

**初始版本**：
- ✅ 实现设备服务铃基本功能
- ✅ 添加 5 秒防重复机制
- ✅ WebSocket 实时推送到店家后台
- ✅ Google TTS 语音播报

---

## 相关文档

- [WebSocket Push 服务配置](../config/plugin/webman/push/app.php)
- [设备管理后台文档](../../gk_admin/CLAUDE.md)
- [多语言系统文档](../../gk_admin/CLAUDE.md#多语言系统)
- [数据权限系统](../../gk_admin/CLAUDE.md#数据权限系统)

---

## 技术支持

如有问题，请联系：
- GitHub Issues: https://github.com/your-repo/issues
- 技术文档: `/docs`
- API 日志: `runtime/logs/webman.log`

---

**文档版本**: v1.2.0  
**最后更新**: 2026-07-29  
**维护者**: 开发团队
