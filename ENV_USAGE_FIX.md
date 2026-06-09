# env() 函数使用不当修复说明

## 问题背景

在 Webman 框架中，`env()` 函数只应该在配置文件（`config/` 目录）中使用。
如果在应用代码中直接调用 `env()`，当配置缓存后，该函数会始终返回默认值，导致配置失效。

**警告信息：**
```
env() function is used outside of config files. It always returns the default value if config is cached.
```

## 修复方案

### 1. 创建统一的服务配置文件

新增 `config/services.php` 文件，统一管理所有外部服务和URL配置：

```php
return [
    'app' => [
        'url' => env('APP_URL', 'http://127.0.0.1:8787'),
    ],
    'game_platform_proxy' => [...],
    'gk_work' => [...],
    'wallet' => [...],
    'turnstile' => [...],
    'push' => [...],
    'strategy' => [...],
    'google_cloud_storage' => [...],
];
```

### 2. 替换所有应用代码中的 env() 调用

**修改前：**
```php
$url = env('APP_URL', 'http://127.0.0.1:8787');
```

**修改后：**
```php
$url = config('services.app.url');
```

## 修复文件清单

### 配置文件
- ✅ `config/services.php` - 新增统一服务配置

### 应用代码
- ✅ `app/functions.php`
  - `saveImg()` - APP_URL
  - `getStrategyUrl()` - STRATEGY_URL

- ✅ `app/service/GamePlatformProxyService.php`
  - `isEnabled()` - GAME_PLATFORM_PROXY_ENABLE
  - `proxy()` - GAME_PLATFORM_PROXY_HOST, GAME_PLATFORM_PROXY_PORT
  - `sendTelegramNotification()` - TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID, GAME_PLATFORM_PROXY_TELEGRAM_NOTIFY
  - `getConfig()` - GAME_PLATFORM_PROXY_HOST, GAME_PLATFORM_PROXY_PORT

- ✅ `app/service/WalletService.php`
  - `isCacheEnabled()` - WALLET_CACHE_ENABLED

- ✅ `app/service/TurnstileService.php`
  - `verify()` - TURNSTILE_SECRET_KEY
  - `isEnabled()` - TURNSTILE_ENABLED
  - `getSiteKey()` - TURNSTILE_SITE_KEY

- ✅ `app/service/machine/MachineClient.php`
  - `__construct()` - GK_WORK_URL

- ✅ `app/service/store/AutoShiftService.php`
  - `sendNotification()` - PUSH_API_URL, PUSH_APP_KEY, PUSH_APP_SECRET

- ✅ `app/api/controller/v1/PlayerController.php`
  - 文件上传 - APP_URL

- ✅ `app/model/PlayerBank.php`
  - `extractPathFromUrl()` - GOOGLE_CLOUD_STORAGE_BUCKET

## 验证结果

执行以下命令验证，确认应用代码中已无 `env()` 调用：

```bash
grep -r "env(" app/ --include="*.php"
```

结果：无匹配文件 ✅

## 最佳实践

### ✅ 正确用法
```php
// 在配置文件中（config/*.php）
return [
    'feature_flag' => env('FEATURE_ENABLED', false),
];

// 在应用代码中
if (config('app.feature_flag')) {
    // ...
}
```

### ❌ 错误用法
```php
// 在应用代码中直接调用
if (env('FEATURE_ENABLED', false)) {  // 🚨 配置缓存后失效！
    // ...
}
```

## 配置缓存说明

Webman 框架支持配置缓存以提升性能：

```bash
# 生成配置缓存（生产环境推荐）
php webman config:cache

# 清除配置缓存
php webman config:clear
```

启用配置缓存后，`env()` 在非配置文件中的调用会失效，只返回默认值。

## 注意事项

1. **所有 `env()` 调用必须在 `config/` 目录的配置文件中**
2. **应用代码中统一使用 `config()` 函数读取配置**
3. **添加新的环境变量时，先在 `config/services.php` 中定义**
4. **生产环境建议启用配置缓存以提升性能**

## 相关环境变量

确保 `.env` 文件中包含以下配置：

```env
# 应用配置
APP_URL=http://127.0.0.1:8787

# 游戏平台代理
GAME_PLATFORM_PROXY_ENABLE=true
GAME_PLATFORM_PROXY_HOST=10.140.0.10
GAME_PLATFORM_PROXY_PORT=8080

# Telegram 通知
GAME_PLATFORM_PROXY_TELEGRAM_NOTIFY=false
TELEGRAM_BOT_TOKEN=
TELEGRAM_CHAT_ID=

# GK Work 服务
GK_WORK_URL=http://127.0.0.1:8788

# 钱包缓存
WALLET_CACHE_ENABLED=true

# Turnstile 验证
TURNSTILE_ENABLED=false
TURNSTILE_SITE_KEY=
TURNSTILE_SECRET_KEY=

# 推送服务
PUSH_API_URL=http://10.140.0.6:3232
PUSH_APP_KEY=20f94408fc4c52845f162e92a253c7a3
PUSH_APP_SECRET=3151f8648a6ccd9d4515386f34127e28

# 攻略服务
STRATEGY_URL=http://8.218.226.64:777/#/pages/detail/index?id=

# Google Cloud Storage
GOOGLE_CLOUD_STORAGE_BUCKET=yjbfile
```

---

**修复完成时间：** 2026-05-29  
**影响范围：** 9个文件  
**状态：** ✅ 已完成
