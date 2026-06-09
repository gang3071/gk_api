# PHP 8 类型错误修复：explode() null 参数问题

## 错误信息
```json
{
  "code": 100,
  "msg": "explode(): Passing null to parameter #2 ($string) of type string is deprecated",
  "data": {}
}
```

## 问题原因

### 根本原因
在 PHP 8 中，`Str::replace()` 函数的参数不能为 null。当 `locale()` 函数返回 null 时，执行 `Str::replace('_', '-', $lang)` 会触发类型错误。

### 问题场景
在洗分操作（wash_point）时，`machineWash()` 函数调用链如下：
1. `locale()` 可能返回 null（Translation 未初始化或会话中没有语言设置）
2. `Str::replace('_', '-', null)` 触发 PHP 8 类型错误
3. 导致洗分操作失败，返回"系统错误"

### 受影响的代码模式
```php
// ❌ 错误写法（PHP 8 会报错）
$lang = locale();  // 可能返回 null
$lang = Str::replace('_', '-', $lang);  // null 导致类型错误

// ✅ 正确写法
$lang = locale() ?? 'zh_TW';  // 默认值保护（繁体中文）
$lang = Str::replace('_', '-', $lang);  // 安全转换
```

## 已修复的文件

### 1. `app/functions.php`
- **修复位置**: 所有 `machineOpenAny()`、`isAllowClientGivePoint()`、`machineWash()` 函数
- **修复内容**: 添加 null 合并操作符 `??`，默认值为 `'zh_CN'`
- **影响范围**: 
  - 机台开分逻辑
  - 机台洗分逻辑
  - 客户端赠点权限检查

**示例**:
```php
// 修复前
$lang = locale();
$services = MachineServices::createServices($machine, $lang);

// 修复后
$lang = locale() ?? 'zh_TW';  // 默认繁体中文
$lang = Str::replace('_', '-', $lang);
$services = MachineServices::createServices($machine, $lang);
```

### 2. `app/api/controller/v1/MachineController.php`
- **修复位置**: `machineList()`, `machineInfo()`, `checkAction()` 等方法
- **修复内容**: 所有 `locale()` 调用后添加 `?? 'zh_CN'`
- **影响范围**: 
  - 机台列表查询
  - 机台详情查询
  - 机台操作验证

### 3. `app/api/controller/v1/PlayerController.php`
- **修复位置**: `favoriteMachineList()`, `playingMachine()`, `bankList()` 等方法
- **修复内容**: 所有 `locale()` 调用后添加 `?? 'zh_CN'`
- **影响范围**:
  - 玩家收藏机台列表
  - 玩家游戏中机台
  - 银行卡列表

### 4. `app/api/controller/v1/GamePlatformController.php`
- **修复位置**: `gamePlatformList()`, `getPlatformList()`, `lobbyLogin()`, `enterGame()` 等方法
- **修复内容**: 所有 `locale()` 调用后添加 `?? 'zh_CN'`
- **影响范围**:
  - 游戏平台列表
  - 电子游戏列表
  - 进入游戏大厅
  - 游戏登录

### 5. `app/api/controller/v1/ActivityController.php`
- **修复位置**: `activityList()`, `activityInfo()` 方法
- **修复内容**: 所有 `locale()` 调用后添加 `?? 'zh_CN'`
- **影响范围**:
  - 活动列表查询
  - 活动详情查询

## 修复统计

| 文件 | 修复次数 | 影响方法数 |
|------|---------|-----------|
| app/functions.php | 3 | 3 个全局函数 |
| app/api/controller/v1/MachineController.php | 6 | 6 个控制器方法 |
| app/api/controller/v1/PlayerController.php | 3 | 3 个控制器方法 |
| app/api/controller/v1/GamePlatformController.php | 12 | 12 个控制器方法 |
| app/api/controller/v1/ActivityController.php | 2 | 2 个控制器方法 |
| **总计** | **26** | **26 个方法** |

## 为什么选择 'zh_TW' 作为默认值？

1. **用户需求**: 主要用户群体使用繁体中文（台湾、香港、澳门地区）
2. **业务定位**: 项目主要服务繁体中文市场
3. **用户体验**: 默认繁体避免用户每次都需要设置语言
4. **多语言支持**: 即使使用默认值，后续仍可通过 `Accept-Language` header 覆盖（支持 zh_CN、en、zh_TW、jp）

## 测试验证

### 1. 基本功能测试
```bash
# 测试洗分操作（wash_point）
curl -X POST "http://api-test.5super9.com/api/v1/slot-action?machine_id=1274&action=wash_point" \
  -H "Authorization: Bearer {token}" \
  -H "Accept-Language: zh_CN"
```

**预期结果**: 
- ✅ 不再返回 "explode() null 参数" 错误
- ✅ 正常执行洗分逻辑或返回业务错误（如"未开分"）

### 2. 边界条件测试
- **无语言 header**: 应使用默认值 `zh_TW`（繁体中文）
- **无效语言代码**: 应使用默认值 `zh_TW`
- **空 session**: 应使用默认值 `zh_TW`

### 3. 多语言测试
```bash
# 测试各语言环境
for lang in zh_CN en zh_TW jp; do
  echo "Testing $lang..."
  curl -X POST "http://api-test.5super9.com/api/v1/slot-action?action=pressure_score&machine_id=1274" \
    -H "Authorization: Bearer {token}" \
    -H "Accept-Language: $lang"
done
```

## 相关问题

### Q1: 为什么 `locale()` 会返回 null？
**A**: 可能的原因：
1. Translation 组件未初始化
2. Session 中没有设置语言
3. 中间件执行顺序问题（Lang 中间件未执行）

### Q2: 为什么不修改 `locale()` 函数本身？
**A**: 
1. `locale()` 是 webman 框架的辅助函数（`support/helpers.php`）
2. 修改框架代码可能影响其他功能
3. 在调用处添加默认值更安全、更可控

### Q3: 还有其他类似的 PHP 8 兼容性问题吗？
**A**: 可能存在，建议排查：
1. `explode()`、`implode()` 的 null 参数
2. `array_*()` 函数的 null 参数
3. 严格类型声明下的类型不匹配

## 后续优化建议

### 1. 中间件优化
确保 `Lang` 中间件始终执行并设置默认语言：

```php
// app/middleware/Lang.php
public function process(Request $request, callable $handler): Response
{
    $lang = $request->header('Accept-Language') ?? 'zh_TW';  // 默认繁体中文
    $lang = str_replace('-', '_', $lang);
    locale(session('lang', $lang));
    return $handler($request);
}
```

### 2. 创建辅助函数
创建一个安全的语言获取函数：

```php
/**
 * 获取当前语言（带默认值保护）
 * @param string $default 默认语言
 * @return string
 */
function safeLocale(string $default = 'zh_TW'): string
{
    $lang = locale() ?? $default;
    return str_replace('_', '-', $lang);
}
```

### 3. 全局搜索
定期搜索可能存在类型问题的代码：

```bash
# 搜索可能的 null 参数问题
grep -rn "Str::replace.*locale()" app/
grep -rn "explode.*\$" app/ | grep -v "??"
```

## 相关文档

- [SLOT_WASH_POINT_FIX_VERIFICATION.md](./slot_wash_point_fix_verification.md) - 洗分功能修复文档
- [PHP 8 Breaking Changes](https://www.php.net/manual/en/migration80.incompatible.php)
- [Laravel Str Helper](https://laravel.com/docs/8.x/helpers#method-str-replace)

---

**修复日期**: 2026-06-05  
**修复人**: Claude  
**PHP 版本**: 8.0+  
**影响范围**: 全局（所有依赖 locale() 的功能）
