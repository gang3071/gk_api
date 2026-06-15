#!/bin/bash
echo ""
echo "=================================================="
echo "🚀 收到 GitHub Action 触发，开始执行本地服务器部署..."
date "+%Y-%m-%d %H:%M:%S"
echo "=================================================="

# ==================== 配置区 ====================
PROJECT_DIR="/www/wwwroot/api-test.5super9.com"
DEFAULT_BRANCH="jin"  # 默认分支
# ================================================

# 1. 获取分支名参数
BRANCH_PARAM="$1"

echo "--- DEBUG START ---"
echo "原始接收到的参数 (\$1) 是: [${BRANCH_PARAM}]"
echo "--- DEBUG END ---"

# 处理分支名（URL编码转换）
if [ -z "$BRANCH_PARAM" ]; then
    echo "⚠️  未检测到分支参数，使用默认分支: $DEFAULT_BRANCH"
    BRANCH_NAME="$DEFAULT_BRANCH"
else
    # 将 URL 编码的 %2F 转换回 /
    # 例如: fix%2Fwallet → fix/wallet
    BRANCH_NAME=$(echo "$BRANCH_PARAM" | sed 's/%2F/\//g')
    echo "📦 接收到分支参数: $BRANCH_PARAM"

    if [ "$BRANCH_NAME" != "$BRANCH_PARAM" ]; then
        echo "   转换后分支名: $BRANCH_NAME"
    fi
fi

echo "📌 目标分支: $BRANCH_NAME"

# 2. 检查项目目录
if [ ! -d "$PROJECT_DIR" ]; then
    echo "❌  找不到项目目录 $PROJECT_DIR，请检查！"
    exit 1
fi

cd "$PROJECT_DIR" || exit 1
echo "📂 工作目录: $(pwd)"

# 3. 核心更新流程
echo ""
echo "📦 [1/5] 正在清理本地环境..."

# 🔧 清理可能存在的错误本地分支（避免 origin/xxx 歧义）
if git show-ref --verify --quiet "refs/heads/origin/$BRANCH_NAME"; then
    echo "⚠️  检测到错误的本地分支 'origin/$BRANCH_NAME'，正在删除..."
    git branch -D "origin/$BRANCH_NAME" 2>/dev/null || true
fi

# 强制放弃本地所有未提交的修改
echo "   清理未提交的修改..."
git reset --hard HEAD
git clean -fd

# 4. 拉取最新代码
echo ""
echo "📡 [2/5] 正在从远程仓库拉取最新代码..."
git fetch --prune --all

# 检查远程分支是否存在（使用完整路径避免歧义）
if ! git rev-parse --verify "remotes/origin/$BRANCH_NAME" >/dev/null 2>&1; then
    echo "❌  远程分支 origin/$BRANCH_NAME 不存在！"
    echo ""
    echo "可用的远程分支："
    git branch -r | grep "origin/" | head -10
    exit 1
fi

# 5. 切换并更新分支
echo ""
echo "🔄 [3/5] 正在切换到分支 $BRANCH_NAME 并更新到最新版本..."

# 强制切换到目标分支（如果不存在则创建）
git checkout -f -B "$BRANCH_NAME"

# 🔧 关键修复：使用完整的远程引用路径避免歧义
# remotes/origin/$BRANCH_NAME 而不是 origin/$BRANCH_NAME 或 FETCH_HEAD
git reset --hard "remotes/origin/$BRANCH_NAME"

# 🔍 验证更新结果
echo ""
echo "🌿 [验证] 当前服务器所在的分支状态："
echo "--------------------------------------------------"
git branch -vv | grep "*"
echo "--------------------------------------------------"

echo ""
echo "📄 [验证] 当前分支最新的 3 条提交记录："
echo "--------------------------------------------------"
git log --oneline --graph -n 3
echo "--------------------------------------------------"

# 获取当前 commit hash
CURRENT_COMMIT=$(git rev-parse --short HEAD)
echo ""
echo "✅ 代码已更新到: $CURRENT_COMMIT"

# 6. 更新 Composer 依赖
echo ""
echo "🧩 [4/5] 正在处理 Composer 依赖..."
export HOME=/root
export COMPOSER_HOME=/root/.config/composer
export COMPOSER_ALLOW_SUPERUSER=1

# 使用 --no-dev 减少生产环境不必要的依赖
composer install \
    --no-interaction \
    --no-dev \
    --optimize-autoloader \
    --ignore-platform-req=ext-mongodb \
    2>&1 | grep -v "Package.*is abandoned" | grep -v "Warning: Ambiguous"

if [ $? -eq 0 ]; then
    echo "✅ Composer 依赖更新成功"
else
    echo "⚠️  Composer 依赖更新失败，但继续执行..."
fi

# 7. 数据库迁移
echo ""
echo "🗄️ [5/5] 正在执行数据库迁移 (Phinx)..."

if [ -f "vendor/bin/phinx" ]; then
    echo "   检测到 Phinx，开始执行迁移..."

    # 检查 phinx.php 配置文件是否存在
    if [ -f "phinx.php" ]; then
        # 执行迁移（使用 development 环境）
        php vendor/bin/phinx migrate -e development 2>&1

        if [ $? -eq 0 ]; then
            echo "✅ 数据库迁移完成"
        else
            echo "⚠️  数据库迁移失败，但继续部署..."
        fi
    else
        echo "⚠️  未找到 phinx.php 配置文件，跳过迁移"
    fi
else
    echo "⚠️  未找到 phinx 二进制文件，跳过迁移"
fi

# 8. 重载服务
echo ""
echo "🔄 正在重载 Webman 服务..."

# 检查服务是否在运行
if php start.php status 2>&1 | grep -q "not run"; then
    echo "⚠️  服务未运行，尝试启动..."
    php start.php start -d
    SERVICE_ACTION="started"
else
    php start.php reload
    SERVICE_ACTION="reloaded"
fi

if [ $? -eq 0 ]; then
    echo "✅ Webman 服务已 $SERVICE_ACTION"
else
    echo "❌ Webman 服务操作失败！"
    exit 1
fi

# 9. 完成
echo ""
echo "=================================================="
echo "🎉 部署完毕！"
echo "   项目: gk_api (API服务)"
echo "   分支: $BRANCH_NAME"
echo "   提交: $CURRENT_COMMIT"
echo "   目录: $PROJECT_DIR"
echo "   时间: $(date '+%Y-%m-%d %H:%M:%S')"
echo "=================================================="
echo ""

exit 0
