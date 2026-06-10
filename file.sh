#!/bin/sh

# ==============================================
# 静态文件服务器并发测试脚本
# 用法：根据需要修改下面的配置，然后执行 sh test_static.sh
# ==============================================

# 目标 URL（请改为你的静态文件路径）
# 建议使用一个大文件（如 100MB+ 的 mp4），以充分测试服务器吞吐能力
FILE_URL="http://127.0.0.1:8100/123.mp4"

# 并发客户端总数
CONCURRENCY=20000

# 每个客户端下载持续时间（秒）
# 如果设为 0，则下载完整文件（适合大文件压测）
# 如果设为 >0，则客户端在指定时间后强制断开（模拟短连接）
DURATION=5

# 是否使用 Range 请求（模拟真实视频播放行为）
# 0 = 不启用，1 = 启用随机 Range 请求
USE_RANGE=0

# 每个批次启动的客户端数量（避免瞬间压力过大）
BATCH_SIZE=1000

# 批次间隔（秒）
SLEEP_BETWEEN=0.2

# ==============================================
# 前置检查
# ==============================================
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

echo "静态文件并发测试"
echo "目标 URL: $FILE_URL"
echo "并发数: $CONCURRENCY"
echo "每客户端持续时间: ${DURATION}s (0=完整下载)"
echo "Range 请求: $([ $USE_RANGE -eq 1 ] && echo "启用" || echo "禁用")"
echo "批次大小: $BATCH_SIZE"
echo "-----------------------------------"

# ==============================================
# 启动客户端
# ==============================================
total=0
while [ $total -lt $CONCURRENCY ]; do
    # 计算本批次数量
    num=$BATCH_SIZE
    if [ $((total + num)) -gt $CONCURRENCY ]; then
        num=$((CONCURRENCY - total))
    fi

    echo "启动一批：$num 个客户端（已启动 $total）"
    i=0
    while [ $i -lt $num ]; do
        (
            errfile=$(mktemp)

            # 根据 USE_RANGE 决定 curl 参数
            if [ $USE_RANGE -eq 1 ]; then
                # 随机生成一个起始字节（假设文件很大，如 400MB）
                START=$((RANDOM * 1024))
                RANGE_HEADER="Range: bytes=${START}-"
                curl -s -N --max-time $DURATION \
                    -H "$RANGE_HEADER" \
                    -H "User-Agent: Mozilla/5.0 (compatible; test)" \
                    "$FILE_URL" -o /dev/null 2>"$errfile"
            else
                if [ $DURATION -eq 0 ]; then
                    # 完整下载
                    curl -s -N \
                        -H "User-Agent: Mozilla/5.0 (compatible; test)" \
                        "$FILE_URL" -o /dev/null 2>"$errfile"
                else
                    # 限制时长
                    curl -s -N --max-time $DURATION \
                        -H "User-Agent: Mozilla/5.0 (compatible; test)" \
                        "$FILE_URL" -o /dev/null 2>"$errfile"
                fi
            fi

            ret=$?
            if [ $ret -eq 0 ]; then
                echo "OK" >> $OK
            elif [ $ret -eq 28 ]; then
                # curl 超时（--max-time）也算成功（因为是我们主动断开的）
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

# ==============================================
# 统计结果
# ==============================================
SUCCESS=$(wc -l < $OK)
FAIL=$(wc -l < $ERR)

echo ""
echo "===== 结果 ====="
echo "成功: $SUCCESS"
echo "失败: $FAIL"
if [ $FAIL -gt 0 ]; then
    echo "前几条失败原因:"
    head -n 5 $ERR_DETAIL
fi

rm -f $OK $ERR $ERR_DETAIL