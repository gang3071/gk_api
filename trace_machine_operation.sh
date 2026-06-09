#!/bin/bash
# 机台开分/洗分操作完整流程追踪脚本
# 使用统一的 machine_operations.log 日志文件

WASH_ID=$1

if [ -z "$WASH_ID" ]; then
    echo "用法: ./trace_machine_operation.sh <wash_id>"
    echo "示例: ./trace_machine_operation.sh wash_675f12345"
    echo ""
    echo "快速获取最新 wash_id:"
    echo "  grep '开始洗分' D:/gk_api/runtime/logs/machine_operations.log | tail -1"
    exit 1
fi

# 去除 wash_ 前缀（如果有）
WASH_ID=${WASH_ID#wash_}

echo "========================================"
echo "追踪机台操作: wash_$WASH_ID"
echo "========================================"
echo ""

# ============ gk_api 端 ============
echo "=== [gk_api] 洗分流程 ==="
cd /d/gk_api

# 1. 洗分开始
echo ""
echo "1. 洗分开始："
grep "wash_$WASH_ID" runtime/logs/machine_operations.log | grep "开始洗分" | \
  grep -oP '(player_id|machine_id|wash_id)":("|)\K[^",}]+' | \
  paste - - - | \
  awk '{print "   玩家ID:", $1, " 机台ID:", $2, " wash_id: wash_" $3}'

# 2. Redis 数据读取
echo ""
echo "2. Redis 机台状态："
grep "wash_$WASH_ID" runtime/logs/machine_operations.log | grep "机台状态读取" | \
  grep -oP '(point|bet|win|auto_status)":\K[^,}]+' | \
  paste - - - - | \
  awk '{print "   分数:", $1, " 下注:", $2, " 赢分:", $3, " 自动:", $4}'

# 3. 赠点信息
echo ""
echo "3. 赠点信息："
grep "wash_$WASH_ID" runtime/logs/machine_operations.log | grep "赠点信息" | \
  grep -oP '(give_point|lottery_give_point)":\K[^,}]+' | \
  paste - - | \
  awk '{print "   赠送分数:", $1, " 彩票赠分:", ($2 ? $2 : 0)}'

# 4. 批量指令发送
echo ""
echo "4. 批量指令发送："
BATCH_IDS=$(grep "wash_$WASH_ID" runtime/logs/machine_operations.log | \
  grep -oP 'batch_id":"batch_\K[^"]+' | sort -u)

if [ -z "$BATCH_IDS" ]; then
    echo "   ⚠ 未找到批次ID（可能在准备阶段就失败了）"
else
    for BATCH_ID in $BATCH_IDS; do
        echo ""
        echo "   批次: batch_$BATCH_ID"

        # 指令列表
        grep "batch_$BATCH_ID" runtime/logs/machine_operations.log | \
          grep "批量发送机台指令 - 开始" | \
          grep -oP 'commands_list":\K\[.*?\]' | \
          sed 's/\[//g; s/\]//g; s/"//g' | \
          awk '{print "   指令列表:", $0}'

        # 执行结果
        grep "batch_$BATCH_ID" runtime/logs/machine_operations.log | \
          grep "批量指令执行完成" | \
          grep -oP '(success_count|failed_count|total_duration_ms)":\K[^,}]+' | \
          paste - - - | \
          awk '{
            success_icon = ($2 == 0) ? "✓" : "⚠"
            print "   " success_icon " 成功:", $1, " 失败:", $2, " 耗时:", $3, "ms"
          }'

        # 失败的指令
        FAILED_COUNT=$(grep "batch_$BATCH_ID" runtime/logs/machine_operations.log | \
          grep "指令执行失败\|指令执行异常" | wc -l)

        if [ "$FAILED_COUNT" -gt 0 ]; then
            echo "   ⚠ 失败指令详情:"
            grep "batch_$BATCH_ID" runtime/logs/machine_operations.log | \
              grep "指令执行失败\|指令执行异常" | \
              grep -oP '(cmd|error_message)":("|)\K[^",}]+' | \
              paste - - | \
              awk '{print "     -", $1, ":", $2}'
        fi
    done
fi

# 5. 最终结果
echo ""
echo "5. 洗分结果："
WASH_RESULT=$(grep "wash_$WASH_ID" runtime/logs/machine_operations.log | \
  grep -E "洗分完成|洗分失败" | tail -1)

if echo "$WASH_RESULT" | grep -q "洗分完成"; then
    echo "   ✓ 洗分成功"
    echo "$WASH_RESULT" | grep -oP 'total_duration_ms":\K[^,}]+' | \
      awk '{print "   总耗时:", $1, "ms"}'

    # 性能警告
    echo "$WASH_RESULT" | grep -oP 'total_duration_ms":\K[^,}]+' | \
      awk '{if ($1 > 3000) print "   ⚠ 性能警告：耗时超过3秒"}'

    # 中奖信息
    if echo "$WASH_RESULT" | grep -q "lottery_record"; then
        echo "   🎁 触发彩票中奖"
    fi
elif echo "$WASH_RESULT" | grep -q "洗分失败"; then
    echo "   ✗ 洗分失败"
    echo "$WASH_RESULT" | grep -oP 'error":("|)\K[^",}]+' | \
      awk '{print "   失败原因:", $0}'
else
    echo "   ⚠ 状态未知（可能仍在执行中）"
fi

# ============ gk_work 端 ============
echo ""
echo "=== [gk_work] 指令执行详情 ==="
cd /d/gk_work

if [ -z "$BATCH_IDS" ]; then
    # 通过 wash_id 直接查找
    COUNT=$(grep "wash_$WASH_ID" runtime/logs/machine_operations.log 2>/dev/null | wc -l)
    if [ "$COUNT" -eq 0 ]; then
        echo "⚠ gk_work 未接收到任何指令"
        echo ""
        echo "可能原因："
        echo "  1. gk_api 未发送指令到 gk_work"
        echo "  2. gk_work 服务未运行"
        echo "  3. 网络通讯失败"
        echo "  4. 端口配置错误（检查 GK_WORK_URL 环境变量）"
    else
        echo "gk_work 接收记录："
        grep "wash_$WASH_ID" runtime/logs/machine_operations.log | head -10
    fi
else
    # 通过 batch_id 查找
    for BATCH_ID in $BATCH_IDS; do
        echo ""
        echo "批次: batch_$BATCH_ID"

        # 统计接收情况
        RECEIVED=$(grep "batch_$BATCH_ID" runtime/logs/machine_operations.log 2>/dev/null | \
          grep "准备执行指令" | wc -l)
        COMPLETED=$(grep "batch_$BATCH_ID" runtime/logs/machine_operations.log 2>/dev/null | \
          grep "指令执行完成" | wc -l)
        FAILED=$(grep "batch_$BATCH_ID" runtime/logs/machine_operations.log 2>/dev/null | \
          grep "指令执行失败" | wc -l)

        echo "  接收: $RECEIVED, 完成: $COMPLETED, 失败: $FAILED"

        if [ "$RECEIVED" -eq 0 ]; then
            echo "  ⚠ gk_work 未接收到该批次的指令"
            continue
        fi

        # 每个指令的执行详情
        echo "  指令执行详情:"
        grep "batch_$BATCH_ID" runtime/logs/machine_operations.log | \
          grep "指令执行完成\|指令执行失败" | \
          grep -oP '(command_index|cmd|exec_duration_ms|success)":("|true|false|)\K[^",}]+' | \
          paste - - - - | \
          awk '{
            # 判断成功状态（兼容 true/false 和 1/0）
            status = ($4 == "true" || $4 == "1") ? "✓" : "✗"
            printf "    %s [索引%s] %s: %s ms\n", status, $1, $2, $3
          }'

        # 失败指令的错误信息
        if [ "$FAILED" -gt 0 ]; then
            echo "  失败原因:"
            grep "batch_$BATCH_ID" runtime/logs/machine_operations.log | \
              grep "指令执行失败" | \
              grep -oP 'error":("|)\K[^",}]+' | \
              awk '{print "    -", $0}'
        fi
    done
fi

echo ""
echo "========================================"
echo "追踪完成"
echo "========================================"
echo ""
echo "提示："
echo "  - 查看完整日志: tail -f runtime/logs/machine_operations.log"
echo "  - 查找其他 wash_id: grep '开始洗分' runtime/logs/machine_operations.log"
