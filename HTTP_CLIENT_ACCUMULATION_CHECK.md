# Laravel HTTP Client 参数累积问题全面检查

## 问题原理

**Laravel HTTP Client 的链式调用特点：**

以下方法会**累积**而不是替换：
```php
$client = Http::timeout(5);
$client->withHeaders(['X-Test' => 'A']);  // 累积
$client->withHeaders(['X-Test' => 'B']);  // 结果: X-Test: A,B ❌

$client->withOptions(['verify' => false]); // 累积
$client->withOptions(['timeout' => 10]);   // 结果: 两个选项都生效

$client->withMiddleware($middleware1);     // 累积
$client->withMiddleware($middleware2);     // 结果: 两个中间件都执行
```

## 可能累积的方法

| 方法 | 是否累积 | 影响 |
|------|---------|------|
| `withHeaders()` | ✅ 是 | ⚠️ **高危** - header 值会拼接 |
| `withOptions()` | ✅ 是 | ⚠️ **中危** - 配置会合并 |
| `withMiddleware()` | ✅ 是 | ⚠️ **中危** - 中间件会叠加 |
| `withToken()` | ✅ 是 | ⚠️ **高危** - Authorization header 累积 |
| `withBasicAuth()` | ✅ 是 | ⚠️ **高危** - Authorization header 累积 |
| `withDigestAuth()` | ✅ 是 | ⚠️ **高危** - Authorization header 累积 |
| `withBody()` | ❌ 否 | ✅ 安全 - 会替换 |
| `timeout()` | ❌ 否 | ✅ 安全 - 会替换 |
| `retry()` | ❌ 否 | ✅ 安全 - 会替换 |
| `asJson()` | ❌ 否 | ✅ 安全 - 设置 Content-Type |
| `asForm()` | ❌ 否 | ✅ 安全 - 设置 Content-Type |

## gk_api 项目检查结果

### ✅ 已修复的问题

**文件：** `app/service/machine/MachineClient.php`

**之前的问题：**
```php
private static $httpClientPool = null;

$this->getHttpClient()  // 返回静态实例
    ->withHeaders([...]) // ❌ 累积！
    ->post(...);
```

**修复后：**
```php
Http::timeout($this->timeout)  // ✅ 每次新实例
    ->withHeaders([...])
    ->post(...);
```

### 🔍 详细检查

#### 1. withHeaders() - ✅ 已安全

**所有使用位置：**

```php
// Line 112 - sendCommand
Http::timeout($this->timeout)
    ->withHeaders($headers)  // ✅ 新实例，安全
    ->post(...);

// Line 314 - batchSendCommands
Http::timeout($this->timeout)
    ->withHeaders([...])     // ✅ 新实例，安全
    ->post(...);

// Line 403 - checkOnline
Http::timeout($this->timeout)
    ->withHeaders([...])     // ✅ 新实例，安全
    ->post(...);

// Line 477 - batchCheckOnline
Http::timeout($this->timeout)
    ->withHeaders([...])     // ✅ 新实例，安全
    ->post(...);
```

**结论：** ✅ 所有 `withHeaders()` 都在新实例上调用，不会累积

#### 2. withOptions() - ✅ 已废弃

**位置：** Line 44 - `getHttpClient()` 方法

**状态：** 这个方法已经不再被调用（已被替换）

**结论：** ✅ 无风险

#### 3. 其他 with* 方法 - ✅ 未使用

搜索结果：
```bash
grep -rn "withToken\|withBasicAuth\|withDigestAuth\|withMiddleware" app/service/machine/MachineClient.php
# 无结果
```

**结论：** ✅ 未使用这些可能累积的方法

## 其他项目检查

### gk_admin

**使用方式：**
```php
// app/service/payment/GBpayService.php
$response = Http::timeout(7)
    ->withHeaders($headers)
    ->asForm()
    ->post($url, $params);
```

**状态：** ✅ 安全 - 每次创建新实例

### gk_work

**角色：** 仅接收 HTTP 请求，不发送

**状态：** ✅ 不涉及 HTTP Client

## 潜在风险场景

### ❌ 危险模式1：静态实例

```php
class MyClient {
    private static $client;
    
    public function request() {
        if (!self::$client) {
            self::$client = Http::timeout(5);
        }
        // ❌ 危险：headers 会累积
        return self::$client->withHeaders([...])->post(...);
    }
}
```

### ❌ 危险模式2：属性存储

```php
class MyClient {
    private $http;
    
    public function __construct() {
        $this->http = Http::timeout(5);
    }
    
    public function request() {
        // ❌ 危险：headers 会累积
        return $this->http->withHeaders([...])->post(...);
    }
}
```

### ✅ 安全模式：每次新实例

```php
class MyClient {
    public function request() {
        // ✅ 安全：每次新实例
        return Http::timeout(5)
            ->withHeaders([...])
            ->post(...);
    }
}
```

## 检查清单

### 对于现有代码

- [x] 搜索 `static.*Http`
- [x] 搜索 `private.*httpClient`
- [x] 搜索 `protected.*httpClient`
- [x] 检查所有 `withHeaders()` 调用
- [x] 检查所有 `withOptions()` 调用
- [x] 检查所有 `withToken()` 调用
- [x] 检查所有 `withMiddleware()` 调用

### 对于新代码

编写新的 HTTP 客户端代码时：

✅ **推荐：**
```php
Http::timeout(5)->withHeaders([...])->post(...);
```

❌ **避免：**
```php
// 不要存储客户端实例
$this->http = Http::timeout(5);
$this->http->withHeaders([...])->post(...);
```

## 测试方法

### 如何验证是否有累积问题

```php
// 测试代码
$client = Http::timeout(5);
$client->withHeaders(['X-Test' => 'A']);
$client->withHeaders(['X-Test' => 'B']);

// 发送请求并抓包查看
$response = $client->get('http://httpbin.org/headers');
$headers = $response->json()['headers'];

// 检查是否有累积
if (isset($headers['X-Test']) && strpos($headers['X-Test'], ',') !== false) {
    echo "❌ 检测到 header 累积: " . $headers['X-Test'];
} else {
    echo "✅ 正常";
}
```

## 全局搜索命令

```bash
# 在所有项目中检查潜在问题

# 1. 检查静态 HTTP 客户端
grep -rn "static.*Http\|static.*httpClient" app/ --include="*.php"

# 2. 检查实例属性
grep -rn "private.*httpClient\|protected.*httpClient" app/ --include="*.php"

# 3. 检查所有 withHeaders 使用
grep -rn "withHeaders" app/ --include="*.php"

# 4. 检查 getHttpClient 模式
grep -rn "getHttpClient\|getClient" app/ --include="*.php"
```

## 结论

### 当前状态

| 项目 | 状态 | 风险等级 |
|------|------|---------|
| gk_api | ✅ 已修复 | 🟢 无风险 |
| gk_admin | ✅ 安全 | 🟢 无风险 |
| gk_work | ✅ 不涉及 | 🟢 无风险 |

### 最佳实践

1. **永远不要存储 HTTP 客户端实例**
2. **每次请求创建新的 `Http::timeout()` 实例**
3. **如果必须复用，使用依赖注入而非静态变量**
4. **定期检查是否有静态 HTTP 客户端模式**

---

**检查时间：** 2026-06-04  
**检查范围：** gk_api, gk_admin, gk_work  
**检查结果：** ✅ 所有项目安全
