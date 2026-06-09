#!/bin/sh

# ===================== 配置项（直接改这里）=====================
FLV_URL="http://192.168.110.72:8501/a/b.flv"
CONCURRENCY=10
DURATION=10
# =================================================================

RED='\033[0;31m'
GREEN='\033[0;32m'
NC='\033[0m'

echo "=============================================="
echo " HTTP-FLV 直播服务器并发拉流测试脚本"
echo " 测试地址：$FLV_URL"
echo " 并发数：$CONCURRENCY"
echo " 单请求持续时间：$DURATION 秒"
echo " 不保存文件，仅测试连通性"
echo "=============================================="
echo ""

SUCCESS_LOG=$(mktemp)
FAIL_LOG=$(mktemp)

test_single_stream() {
    local id=$1
    if wget --spider -q --timeout=5 --tries=1 "$FLV_URL"; then
        wget "$FLV_URL" -q -O /dev/null --timeout=$((DURATION+5)) &
        WGET_PID=$!
        sleep $DURATION
        kill $WGET_PID 2>/dev/null
        echo "客户端 $id：成功" >> $SUCCESS_LOG
    else
        echo "客户端 $id：失败" >> $FAIL_LOG
    fi
}

echo "正在启动 $CONCURRENCY 个并发拉流测试..."
echo "测试中，请等待 $DURATION 秒..."
echo ""

# 修复这里：兼容 sh
i=1
while [ $i -le $CONCURRENCY ]; do
    test_single_stream $i &
    i=$((i+1))
done

wait

SUCCESS=$(cat $SUCCESS_LOG | wc -l)
FAIL=$(cat $FAIL_LOG | wc -l)

echo "=============================================="
echo "测试完成！结果统计："
echo "成功拉流：$SUCCESS 个客户端"
echo "拉流失败：$FAIL 个客户端"
echo "总并发数：$CONCURRENCY"
echo "=============================================="

rm -f $SUCCESS_LOG $FAIL_LOG