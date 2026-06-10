#!/bin/sh

# 主服务器
# FLV_URL="http://127.0.0.1:8501/a/b.flv"
# 网关服务器
FLV_URL="http://127.0.0.1:8080/a/b.flv"
CONCURRENCY=20000
DURATION=5
BATCH_SIZE=1000         # 更小的批次，降低瞬时压力
SLEEP_BETWEEN=0.3

# 检查 cgroup 进程限制
PIDS_MAX=$(cat /sys/fs/cgroup/pids/pids.max 2>/dev/null || echo "unknown")
echo "当前容器 pids.max: $PIDS_MAX"

OK=$(mktemp)
ERR=$(mktemp)
ERR_DETAIL=$(mktemp)

cleanup() {
    echo "\n中断，清理..."
    pkill -P $$ 2>/dev/null
    rm -f $OK $ERR $ERR_DETAIL
    exit 1
}
trap cleanup INT TERM

total=0
while [ $total -lt $CONCURRENCY ]; do
    num=$BATCH_SIZE
    if [ $((total + num)) -gt $CONCURRENCY ]; then
        num=$((CONCURRENCY - total))
    fi

    echo "启动一批：$num 个客户端"
    i=0
    while [ $i -lt $num ]; do
        (
            errfile=$(mktemp)
            curl -s -N --max-time $DURATION \
                -H "User-Agent: Mozilla/5.0 (compatible; test)" \
                "$FLV_URL" -o /dev/null 2>"$errfile"
            ret=$?
            if [ $ret -eq 0 ] || [ $ret -eq 28 ]; then
                echo "OK" >> $OK
            else
                echo "FAIL (code $ret): $(cat "$errfile")" >> $ERR
                echo "$(cat "$errfile")" >> $ERR_DETAIL
            fi
            rm -f "$errfile"
        ) &
        i=$((i + 1))
    done

    total=$((total + num))
    sleep $SLEEP_BETWEEN
done

echo "所有客户端已启动，等待完成..."
wait

SUCCESS=$(wc -l < $OK)
FAIL=$(wc -l < $ERR)

echo "\n===== 结果 ====="
echo "成功: $SUCCESS"
echo "失败: $FAIL"
if [ $FAIL -gt 0 ]; then
    echo "前几条失败原因:"
    head -n 5 $ERR_DETAIL
fi

rm -f $OK $ERR $ERR_DETAIL