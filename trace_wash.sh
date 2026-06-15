#!/bin/bash
# 跨项目追踪洗分流程（gk_api + gk_work）

WASH_ID=$1

if [ -z "$WASH_ID" ]; then
    echo "用法: ./trace_wash.sh <wash_id>"
    echo "示例: ./trace_wash.sh wash_675a1234"
    echo ""
    echo "快速获取最新 wash_id:"
    echo "  cd D:/gk_api"
    echo "  grep '开始洗分' runtime/logs/webman.log | tail -1"
    exit 1
fi

# 确保 wash_id 没有 "wash_" 前缀
WASH_ID=${WASH_ID#wash_}

echo "========================================"
echo "追踪洗分: wash_$WASH_ID"
echo "========================================"
echo ""

# gk_api 端
echo "=== [gk_api] 洗分流程 ==="
cd /d/gk_api
grep "wash_$WASH_ID" runtime/logs/webman.log | grep -E "开始洗分|洗分完成|洗分失败"

echo ""
echo "=== [gk_api] 批量指令 ==="
BATCH_IDS=$(grep "wash_$WASH_ID" runtime/logs/webman.log | grep -oP 'batch_id":"batch_\K[^"]+' | sort -u)

if [ -z "$BATCH_IDS" ]; then
    echo "未找到批量指令（可能洗分在准备阶段就失败了）"
else
    echo "找到批次: $BATCH_IDS"

    for BATCH_ID in $BATCH_IDS; do
        echo ""
        echo "--- 批次 batch_$BATCH_ID ---"

        # 批次摘要
        grep "batch_$BATCH_ID" runtime/logs/webman.log | \
          grep "批量指令执行完成" | \
          grep -oP '(success_count|failed_count|total_duration_ms)":\K[0-9.]+' | \
          paste - - - | \
          awk '{print "  成功:", $1, "失败:", $2, "耗时:", $3, "ms"}'

        # 失败的指令
        FAILED=$(grep "batch_$BATCH_ID" runtime/logs/webman.log | grep "指令执行失败\|指令执行异常" | wc -l)
        if [ "$FAILED" -gt 0 ]; then
            echo "  ⚠ 失败指令:"
            grep "batch_$BATCH_ID" runtime/logs/webman.log | \
              grep "指令执行失败\|指令执行异常" | \
              grep -oP '(cmd|message)":("|)\K[^",}]+' | \
              paste - - | \
              awk '{print "    -", $1, ":", $2}'
        fi
    done
fi

echo ""
echo "=== [gk_work] 指令接收 ==="
cd /d/gk_work

if [ -z "$BATCH_IDS" ]; then
    # 通过 wash_id 直接查找
    COUNT=$(grep "wash_$WASH_ID" runtime/logs/webman.log 2>/dev/null | wc -l)
    if [ "$COUNT" -eq 0 ]; then
        echo "未找到对应的指令接收日志"
        echo "可能原因："
        echo "  1. 指令未发送到 gk_work"
        echo "  2. gk_work 服务未运行"
        echo "  3. 网络通讯失败"
    else
        grep "wash_$WASH_ID" runtime/logs/webman.log
    fi
else
    for BATCH_ID in $BATCH_IDS; do
        echo ""
        echo "--- 批次 batch_$BATCH_ID ---"

        # 统计接收的指令数
        RECEIVED=$(grep "batch_$BATCH_ID" runtime/logs/webman.log 2>/dev/null | grep "准备执行指令" | wc -l)
        COMPLETED=$(grep "batch_$BATCH_ID" runtime/logs/webman.log 2>/dev/null | grep "指令执行完成" | wc -l)
        FAILED=$(grep "batch_$BATCH_ID" runtime/logs/webman.log 2>/dev/null | grep "指令执行失败" | wc -l)

        echo "  接收: $RECEIVED, 完成: $COMPLETED, 失败: $FAILED"

        # 显示每个指令的执行情况
        if [ "$RECEIVED" -gt 0 ]; then
            echo "  指令详情:"
            grep "batch_$BATCH_ID" runtime/logs/webman.log | \
              grep "指令执行完成\|指令执行失败" | \
              grep -oP '(command_index|cmd|exec_duration_ms|success)":("|true|false|)\K[^",}]+' | \
              paste - - - - | \
              awk '{
                status = ($4 == "true" || $4 == "1") ? "✓" : "✗"
                printf "    %s [%s] %s: %s ms\n", status, $1, $2, $3
              }'
        else
            echo "  ⚠ gk_work 未接收到该批次的指令"
        fi
    done
fi

echo ""
echo "========================================"
echo "追踪完成"
echo "========================================"
