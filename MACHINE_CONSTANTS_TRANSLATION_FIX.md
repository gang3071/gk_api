# 机台常量翻译文件修复

## 问题描述

在完成常量统一和清理后，发现以下错误：
```
{"code":0,"msg":"Undefined constant app\service\machine\Slot::READ_SCORE"}
```

## 问题根源

虽然已经在代码中将 `READ_SCORE` 替换为 `MACHINE_POINT`，但**翻译文件**中仍然引用了旧的常量名，导致运行时错误。

翻译文件位置：
- `resource/translations/zh_CN/machine_action.php`
- `resource/translations/en/machine_action.php`
- `resource/translations/zh_TW/machine_action.php`
- `resource/translations/jp/machine_action.php`

## 修复内容

### 替换的常量引用

在所有 4 个翻译文件中将以下引用替换：

| 旧常量 | 新常量 | 影响文件 |
|--------|--------|----------|
| `Slot::READ_SCORE` | `Slot::MACHINE_POINT` | 4个翻译文件 |
| `SongSlot::READ_SCORE` | `SongSlot::MACHINE_POINT` | 4个翻译文件 |

### 修改后的翻译映射

#### 中文简体 (zh_CN)
```php
Slot::MACHINE_POINT => '读取分数',
SongSlot::MACHINE_POINT => '读取分数',
```

#### 英文 (en)
```php
Slot::MACHINE_POINT => 'Read Score',
SongSlot::MACHINE_POINT => 'Read Score',
```

#### 中文繁体 (zh_TW)
```php
Slot::MACHINE_POINT => '讀取分數',
SongSlot::MACHINE_POINT => '讀取分數',
```

#### 日文 (jp)
```php
Slot::MACHINE_POINT => '読取点数',
SongSlot::MACHINE_POINT => '読取スコア',
```

## 验证结果

✅ 所有翻译文件中的 `READ_SCORE` 已成功替换为 `MACHINE_POINT`  
✅ 项目中不再有任何对 `READ_SCORE` 常量的引用  
✅ 洗分操作错误已修复

## 受影响的文件

```
resource/translations/zh_CN/machine_action.php  (2处修改)
resource/translations/en/machine_action.php     (2处修改)
resource/translations/zh_TW/machine_action.php  (2处修改)
resource/translations/jp/machine_action.php     (2处修改)
```

## 经验教训

在进行常量重命名时，需要检查的位置：
1. ✅ PHP 代码文件 (app/)
2. ✅ 服务类文件 (app/service/)
3. ✅ **翻译文件 (resource/translations/)** ← 之前遗漏
4. ✅ 配置文件 (config/)
5. ✅ 视图模板文件（如果有）

## 完整性检查命令

```bash
# 检查项目中是否还有对旧常量的引用
grep -rn "::READ_SCORE" app/ resource/ config/ --include="*.php"

# 应该返回空结果，表示已完全替换
```

---

**修复时间：** 2026-06-02  
**问题类型：** 常量重命名后翻译文件未同步更新  
**修复状态：** ✅ 已完成
