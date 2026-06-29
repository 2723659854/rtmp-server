#!/bin/sh

# ================= 配置区 =================
# 直播流地址
FLV_URL="http://127.0.0.1:8501/a/b.flv"
# 目标并发数
CONCURRENCY=1000
# 每个客户端拉流持续时间（秒）
DURATION=10
# 每批启动数量
BATCH_SIZE=500
# 批次间隔（秒）
SLEEP_BETWEEN=0.1

# 最小接受数据字节数（FLV头+至少几个tag）
MIN_BYTES=4096

# ================= 系统资源检查 =================
echo "===== 系统限制检查 ====="
PIDS_MAX=$(cat /sys/fs/cgroup/pids/pids.max 2>/dev/null || echo "unknown")
echo "容器 pids.max: $PIDS_MAX"
echo "当前 ulimit -n: $(ulimit -n)"
echo "当前 ulimit -u: $(ulimit -u)"
echo

# 建议的最低要求
REQUIRED_FD=$((CONCURRENCY + 100))
if [ "$(ulimit -n)" -lt "$REQUIRED_FD" ]; then
    echo "⚠️ 文件描述符限制过低（需要至少 $REQUIRED_FD），尝试自动调整..."
    ulimit -n $REQUIRED_FD 2>/dev/null || {
        echo "❌ 无法调整，请用 root 执行：ulimit -n $REQUIRED_FD && ./play.sh"
        exit 1
    }
fi

# ================= 临时文件 =================
OK_FILE=$(mktemp)
ERR_FILE=$(mktemp)
ERR_DETAIL=$(mktemp)
STATS_FILE=$(mktemp)   # 记录首字节时间等信息

cleanup() {
    echo "\n中断，清理子进程..."
    pkill -P $$ 2>/dev/null
    rm -f "$OK_FILE" "$ERR_FILE" "$ERR_DETAIL" "$STATS_FILE"
    exit 1
}
trap cleanup INT TERM

# ================= 单个客户端测试函数 =================
test_client() {
    local errfile
    errfile=$(mktemp)
    local output
    local ret

    # 使用 -w 输出关键信息，用分隔符方便提取
    output=$(curl -s -N --max-time "$DURATION" \
        -H "User-Agent: Mozilla/5.0 (compatible; test)" \
        -H "Accept: */*" \
        -w "\n%{http_code}\n%{size_download}\n%{time_starttransfer}" \
        "$FLV_URL" -o /dev/null 2>"$errfile")
    ret=$?

    # 如果 curl 返回 0 但输出为空，可能是被信号中断，按失败处理
    if [ -z "$output" ]; then
        echo "FAIL (empty output, ret=$ret)" >> "$ERR_FILE"
        cat "$errfile" >> "$ERR_DETAIL"
        rm -f "$errfile"
        return
    fi

    # 提取指标 (最后三行)
    http_code=$(echo "$output" | tail -n 3 | sed -n '1p')
    size_down=$(echo "$output" | tail -n 3 | sed -n '2p')
    time_first=$(echo "$output" | tail -n 3 | sed -n '3p')

    # 基本成功判断：curl 返回 0 且 HTTP 200 且收到足够数据
    if [ "$ret" -eq 0 ] && [ "$http_code" = "200" ] && [ "$size_down" -gt "$MIN_BYTES" ]; then
        # 进一步校验数据是否为 FLV 格式（取前3字节）
        # 我们重新请求一次获取开头片段，但会增加开销。为了不破坏并发结构，
        # 此脚本用 curl 的输出重定向到临时文件来提取前3字节。
        # 简单起见，这里只记录接收大小和时间，不做流式签名检查。
        # 如果你一定要校验 FLV，可以把 -o 改为写入临时文件然后检查。
        echo "OK" >> "$OK_FILE"
        echo "$time_first $size_down" >> "$STATS_FILE"
    elif [ "$ret" -eq 28 ]; then
        # 超时：连接存在但可能无数据或慢
        if [ "$size_down" -gt "$MIN_BYTES" ]; then
            # 超时前收到了足够数据，可视为有限成功
            echo "OK (timeout with data)" >> "$OK_FILE"
            echo "$time_first $size_down" >> "$STATS_FILE"
        else
            echo "FAIL (timeout no data, http=$http_code, size=$size_down)" >> "$ERR_FILE"
            echo "ret=28 http=$http_code size=$size_down" >> "$ERR_DETAIL"
        fi
    else
        # 其他错误
        echo "FAIL (ret=$ret http=$http_code size=$size_down): $(cat "$errfile")" >> "$ERR_FILE"
        echo "ret=$ret http=$http_code size=$size_down $(cat "$errfile")" >> "$ERR_DETAIL"
    fi

    rm -f "$errfile"
}

# ================= 分批启动 =================
total=0
batch_num=1
while [ "$total" -lt "$CONCURRENCY" ]; do
    num=$BATCH_SIZE
    if [ $((total + num)) -gt "$CONCURRENCY" ]; then
        num=$((CONCURRENCY - total))
    fi

    echo "启动批次 $batch_num: $num 个客户端 (累计 $((total+num)))"
    i=0
    while [ "$i" -lt "$num" ]; do
        test_client &
        i=$((i + 1))
    done

    total=$((total + num))
    batch_num=$((batch_num + 1))
    sleep "$SLEEP_BETWEEN"
done

echo "所有客户端已启动，等待完成..."
wait

# ================= 结果统计 =================
SUCCESS=$(wc -l < "$OK_FILE" 2>/dev/null || echo 0)
FAIL=$(wc -l < "$ERR_FILE" 2>/dev/null || echo 0)

echo ""
echo "===== 测试结果 ====="
echo "成功: $SUCCESS"
echo "失败: $FAIL"

if [ "$FAIL" -gt 0 ]; then
    echo ""
    echo "前 10 条失败原因:"
    head -n 10 "$ERR_DETAIL"
fi

if [ "$SUCCESS" -gt 0 ]; then
    echo ""
    echo "首字节时间 (time_starttransfer) 与接收字节数分布 (前20个):"
    head -n 20 "$STATS_FILE"
    # 可计算平均值等，此处省略
fi

# 清理
rm -f "$OK_FILE" "$ERR_FILE" "$ERR_DETAIL" "$STATS_FILE"