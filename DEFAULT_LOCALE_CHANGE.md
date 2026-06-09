# 默认语言变更：繁体中文 (zh_TW)

## 变更概述

将系统默认语言从简体中文 (`zh_CN`) 改为繁体中文 (`zh_TW`)，以更好地服务主要用户群体。

## 变更详情

### 变更范围
所有使用 `locale() ?? 'zh_CN'` 的代码统一改为 `locale() ?? 'zh_TW'`

### 受影响的文件

| 文件路径 | 变更次数 | 说明 |
|---------|---------|------|
| `app/functions.php` | 3 处 | 机台操作相关函数 |
| `app/api/controller/v1/MachineController.php` | 6 处 | 机台控制器 |
| `app/api/controller/v1/PlayerController.php` | 3 处 | 玩家控制器 |
| `app/api/controller/v1/GamePlatformController.php` | 12 处 | 游戏平台控制器 |
| `app/api/controller/v1/ActivityController.php` | 2 处 | 活动控制器 |
| **总计** | **26 处** | - |

### 代码示例

**修改前**:
```php
$lang = locale() ?? 'zh_CN';  // 默认简体中文
$lang = Str::replace('_', '-', $lang);
```

**修改后**:
```php
$lang = locale() ?? 'zh_TW';  // 默认繁体中文
$lang = Str::replace('_', '-', $lang);
```

## 业务影响

### 1. 用户体验
- ✅ **无语言设置时**: 默认显示繁体中文界面
- ✅ **已有语言设置**: 不受影响，继续使用用户选择的语言
- ✅ **API 调用**: 可通过 `Accept-Language` header 覆盖默认值

### 2. 多语言支持
系统仍支持以下语言（优先级按 `Accept-Language` header）：
- `zh_TW` - 繁体中文（新默认）
- `zh_CN` - 简体中文
- `en` - 英文
- `jp` - 日文

### 3. 翻译资源
确认以下翻译文件完整性：
- ✅ `resource/translations/zh_TW/message.php` - 繁体翻译
- ✅ `resource/translations/zh_CN/message.php` - 简体翻译
- ✅ `resource/translations/en/message.php` - 英文翻译
- ✅ `resource/translations/jp/message.php` - 日文翻译

## 测试建议

### 1. 默认语言测试
```bash
# 不带 Accept-Language header，应返回繁体中文
curl -X POST "http://api-test.5super9.com/api/v1/machine-list" \
  -H "Authorization: Bearer {token}" \
  -d "game_id=1&page=1&size=10"

# 预期: 错误提示为繁体中文（如："機台維護中"）
```

### 2. 多语言切换测试
```bash
# 简体中文
curl -X POST "http://api-test.5super9.com/api/v1/machine-list" \
  -H "Authorization: Bearer {token}" \
  -H "Accept-Language: zh_CN" \
  -d "game_id=1&page=1&size=10"

# 繁体中文
curl -X POST "http://api-test.5super9.com/api/v1/machine-list" \
  -H "Authorization: Bearer {token}" \
  -H "Accept-Language: zh_TW" \
  -d "game_id=1&page=1&size=10"

# 英文
curl -X POST "http://api-test.5super9.com/api/v1/machine-list" \
  -H "Authorization: Bearer {token}" \
  -H "Accept-Language: en" \
  -d "game_id=1&page=1&size=10"

# 日文
curl -X POST "http://api-test.5super9.com/api/v1/machine-list" \
  -H "Authorization: Bearer {token}" \
  -H "Accept-Language: jp" \
  -d "game_id=1&page=1&size=10"
```

### 3. 边界情况测试
```bash
# 无效语言代码，应回退到默认 zh_TW
curl -X POST "http://api-test.5super9.com/api/v1/machine-list" \
  -H "Authorization: Bearer {token}" \
  -H "Accept-Language: fr_FR" \
  -d "game_id=1&page=1&size=10"
```

## 验证点

### 1. 错误提示检查
确认以下错误提示使用繁体中文：
- ❌ "机台维护中" → ✅ "機台維護中"
- ❌ "机台不存在" → ✅ "機台不存在"
- ❌ "系统错误" → ✅ "系統錯誤"

### 2. 功能模块检查
- [ ] 机台列表 - 默认繁体
- [ ] 机台详情 - 默认繁体
- [ ] 机台操作（开分、洗分） - 默认繁体
- [ ] 玩家相关功能 - 默认繁体
- [ ] 游戏平台 - 默认繁体
- [ ] 活动列表 - 默认繁体

### 3. 日志检查
查看日志确认语言参数正确传递：
```bash
# 检查 Redis 日志
tail -f runtime/logs/redis.log | grep "lang"

# 检查 SQL 日志
tail -f runtime/logs/sql.log | grep "lang"
```

## 配置文件建议

### 更新配置文件默认语言
虽然代码已修改，建议同步更新配置文件以保持一致：

**`config/translation.php`**:
```php
return [
    'locale' => 'zh_TW',  // 改为繁体中文
    'fallback_locale' => ['zh_TW', 'zh_CN', 'en', 'jp'],  // 回退顺序
    // ...
];
```

**`config/app.php`** (如果有):
```php
return [
    'locale' => 'zh_TW',
    'fallback_locale' => 'zh_TW',
    // ...
];
```

## 相关中间件

### Lang 中间件建议更新
**`app/middleware/Lang.php`**:
```php
public function process(Request $request, callable $handler): Response
{
    // 优先使用 Accept-Language header，否则使用繁体中文
    $lang = $request->header('Accept-Language') ?? 'zh_TW';
    $lang = str_replace('-', '_', $lang);
    locale(session('lang', $lang));
    return $handler($request);
}
```

## 回滚方案

如果需要回滚到简体中文，执行以下命令：

```bash
cd D:\gk_api

# 批量回滚
sed -i "s/locale() ?? 'zh_TW'/locale() ?? 'zh_CN'/g" \
  app/functions.php \
  app/api/controller/v1/MachineController.php \
  app/api/controller/v1/PlayerController.php \
  app/api/controller/v1/GamePlatformController.php \
  app/api/controller/v1/ActivityController.php

echo "已回滚为简体中文 (zh_CN)"
```

## 常见问题

### Q1: 为什么不直接修改配置文件？
**A**: 
1. 配置文件的 `locale` 可能被中间件覆盖
2. 代码级别的默认值更可靠（防御性编程）
3. 配置文件和代码同时修改可确保完全一致

### Q2: 会影响已有用户的语言设置吗？
**A**: 
- **不会**。已通过 `Accept-Language` header 或 session 设置语言的用户不受影响
- 只影响**从未设置语言**的新用户或新会话

### Q3: 简体中文用户还能使用吗？
**A**: 
- **可以**。只需在请求中添加 `Accept-Language: zh_CN` header
- 前端应用应在用户首次访问时提供语言选择并保存到本地存储

### Q4: 日志和数据库记录使用什么语言？
**A**: 
- **日志**: 应使用英文或中文（便于开发人员调试）
- **数据库**: 存储翻译 key，显示时根据用户语言动态翻译
- **错误响应**: 使用 `trans()` 函数根据用户语言返回

## 相关文档

- [EXPLODE_NULL_FIX.md](./EXPLODE_NULL_FIX.md) - PHP 8 类型错误修复
- [slot_wash_point_fix_verification.md](./slot_wash_point_fix_verification.md) - 洗分功能修复
- [config/translation.php](./config/translation.php) - 翻译配置文件

---

**变更日期**: 2026-06-05  
**变更人**: Claude  
**影响范围**: 全局默认语言设置  
**业务影响**: 低（不影响已有用户语言设置，仅改变新用户默认语言）
