#!/bin/bash
# 静态文件网关并发测试脚本
# 用法: ./test_static.sh <URL> [并发数] [总请求数]
# 示例: ./test_static.sh http://127.0.0.1:8100/test.ts 1000

set -e

# 默认参数
URL="${1:-http://127.0.0.1:8100/123.mp4}"
CONCURRENT="${2:-100}"
TOTAL="${3:-$CONCURRENT}"   # 默认总请求数等于并发数

# 颜色输出
GREEN='\033[0;32m'
RED='\033[0;31m'
NC='\033[0m' # 无颜色

echo "=============================================="
echo " 静态文件网关并发测试"
echo " URL: $URL"
echo " 并发数: $CONCURRENT"
echo " 总请求: $TOTAL"
echo "=============================================="

# 创建临时目录存放结果
TMPDIR=$(mktemp -d)
trap "rm -rf $TMPDIR" EXIT

# 测试开始时间
START_TIME=$(date +%s)

# 并发执行 curl 请求
seq 1 "$TOTAL" | xargs -P "$CONCURRENT" -I {} sh -c '
    HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "'"$URL"'" --max-time 10 --connect-timeout 5 2>/dev/null)
    if [ "$HTTP_CODE" = "200" ]; then
        echo "OK" > "'"$TMPDIR"'/result_{}.txt"
    else
        echo "FAIL:$HTTP_CODE" > "'"$TMPDIR"'/result_{}.txt"
    fi
'

# 测试结束时间
END_TIME=$(date +%s)
DURATION=$((END_TIME - START_TIME))

# 统计结果
SUCCESS=$(grep -l "^OK$" "$TMPDIR"/result_*.txt 2>/dev/null | wc -l)
FAIL=$(grep -l "^FAIL:" "$TMPDIR"/result_*.txt 2>/dev/null | wc -l)
TOTAL_SENT=$((SUCCESS + FAIL))

echo ""
echo "=============================================="
echo " 测试完成！结果统计："
echo " 成功拉流：${GREEN}${SUCCESS}${NC} 个客户端"
echo " 拉流失败：${RED}${FAIL}${NC} 个客户端"
echo " 总并发数：${TOTAL_SENT}"
echo " 耗时：${DURATION} 秒"
echo "=============================================="

# 显示部分失败原因（最多5条）
if [ "$FAIL" -gt 0 ]; then
    echo ""
    echo " 失败原因示例（前5个）："
    grep -h "^FAIL:" "$TMPDIR"/result_*.txt 2>/dev/null | sort | uniq -c | sort -rn | head -5
fi