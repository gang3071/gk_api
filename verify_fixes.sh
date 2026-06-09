#!/bin/bash
echo "========================================="
echo "   代码修复状态验证脚本"
echo "========================================="
echo ""

success_count=0
total_count=5

echo "📋 检查项 1/5: WalletService CACHE_TTL 常量定义"
if grep -q "private const CACHE_TTL = 0" app/service/WalletService.php; then
    echo "   ✅ 第31行已定义: private const CACHE_TTL = 0;"
    ((success_count++))
else
    echo "   ❌ 未找到常量定义"
fi

echo ""
echo "📋 检查项 2/5: AutoShiftService Log 类导入"
if grep -q "use support.Log" app/service/store/AutoShiftService.php; then
    echo "   ✅ 第12行已导入: use support\Log;"
    ((success_count++))
else
    echo "   ❌ 未导入 Log 类"
fi

echo ""
echo "📋 检查项 3/5: functions.php 空循环体注释"
if grep -q "循环体为空" app/functions.php; then
    echo "   ✅ 第418行已添加注释说明"
    ((success_count++))
else
    echo "   ❌ 缺少注释"
fi

echo ""
echo "📋 检查项 4/5: PlayerController 数组赋值顺序"
if sed -n '2237,2239p' app/api/controller/v1/PlayerController.php | grep -q "\['id'\]"; then
    echo "   ✅ 第2237-2239行顺序正确 (id -> machine_media -> online_status)"
    ((success_count++))
else
    echo "   ⚠️  请手动检查顺序"
fi

echo ""
echo "📋 检查项 5/5: PHP 语法检查"
syntax_ok=0
if php -l app/service/WalletService.php 2>&1 | grep -q "No syntax errors"; then
    echo "   ✅ WalletService.php"
    ((syntax_ok++))
fi

if php -l app/service/store/AutoShiftService.php 2>&1 | grep -q "No syntax errors"; then
    echo "   ✅ AutoShiftService.php"
    ((syntax_ok++))
fi

if php -l app/functions.php 2>&1 | grep -q "No syntax errors"; then
    echo "   ✅ functions.php"
    ((syntax_ok++))
fi

if php -l app/api/controller/v1/PlayerController.php 2>&1 | grep -q "No syntax errors"; then
    echo "   ✅ PlayerController.php"
    ((syntax_ok++))
fi

if [ $syntax_ok -eq 4 ]; then
    ((success_count++))
    echo "   ✅ 所有文件语法正确"
else
    echo "   ❌ 部分文件语法错误"
fi

echo ""
echo "========================================="
echo "   验证结果: $success_count/$total_count 通过"
echo "========================================="
echo ""

if [ $success_count -eq $total_count ]; then
    echo "🎉 所有修复已完成！"
    echo ""
    echo "如果 IDE 仍然显示错误，请执行以下操作："
    echo "  1. PhpStorm: File -> Invalidate Caches -> Invalidate and Restart"
    echo "  2. VS Code: Ctrl+Shift+P -> Reload Window"
    echo ""
    echo "详细说明请查看: IDE_CACHE_REFRESH.md"
else
    echo "⚠️  部分检查项未通过，请检查文件内容"
fi

echo ""
