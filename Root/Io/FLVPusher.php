<?php

namespace Root\Io;

/**
 * Production-grade FLV Pusher for RTMP Server
 *
 * Features:
 * - 按原始时间戳精确推流（伪直播）
 * - 自动断线重连
 * - 内存优化（流式读取，不加载整个文件）
 * - 实时进度上报
 * - 支持推流倍速（0.5x/1x/2x）
 * - 详细的日志输出
 * - 信号处理（优雅退出）
 *
 * Usage:
 *   php flv_pusher.php /path/to/video.flv [push_url] [speed]
 *   php flv_pusher.php test.flv http://127.0.0.1:8501/live/stream 1.0
 *   php flv_pusher.php test.flv http://127.0.0.1:8501/live/stream 2.0  # 2倍速
 *
 * @author yanglong
 * @version 1.0.1
 */

class FLVPusher {
    private $filePath;
    private $pushUrl;
    private $speed = 1.0;
    private $autoReconnect = true;
    private $maxRetries = 5;
    private $retryDelay = 3;
    private $verbose = true;
    private $statsEnabled = true;
    private $useChunked = true;

    private $socket;
    private $totalBytes = 0;
    private $totalTags = 0;
    private $startTime;
    private $lastTimestamp = 0;
    private $isRunning = true;
    private $retryCount = 0;

    private $stats = [
        'tags_sent' => 0,
        'bytes_sent' => 0,
        'start_time' => 0,
        'last_report_time' => 0,
        'reconnect_count' => 0,
    ];

    public function __construct($filePath, $pushUrl, $speed = 1.0, $autoReconnect = true) {
        $this->filePath = $filePath;
        $this->pushUrl = $pushUrl;
        $this->speed = max(0.1, min(10.0, (float)$speed));
        $this->autoReconnect = $autoReconnect;

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, [$this, 'handleSignal']);
            pcntl_signal(SIGTERM, [$this, 'handleSignal']);
        }
    }

    public function handleSignal($signal) {
        $this->log("[信号] 收到退出信号，正在优雅关闭...", 'warning');
        $this->isRunning = false;
        $this->closeConnection();
        exit(0);
    }

    public function start() {
        $this->log("========================================", 'info');
        $this->log("FLV Pusher v1.0.1", 'info');
        $this->log("========================================", 'info');
        $this->log("文件: {$this->filePath}", 'info');
        $this->log("推流地址: {$this->pushUrl}", 'info');
        $this->log("推流速度: {$this->speed}x", 'info');
        $this->log("自动重连: " . ($this->autoReconnect ? '是' : '否'), 'info');
        $this->log("========================================", 'info');

        if (!file_exists($this->filePath)) {
            $this->log("文件不存在: {$this->filePath}", 'error');
            return false;
        }

        $fileSize = filesize($this->filePath);
        $this->log("文件大小: " . $this->formatBytes($fileSize), 'info');

        $result = $this->doPush();

        if ($this->statsEnabled) {
            $this->printFinalStats();
        }

        return $result;
    }

    private function doPush() {
        $this->stats['start_time'] = microtime(true);
        $this->stats['last_report_time'] = $this->stats['start_time'];

        $fileHandle = null;
        $retryCount = 0;

        while ($this->isRunning && $retryCount <= $this->maxRetries) {
            try {
                $fileHandle = fopen($this->filePath, 'rb');
                if (!$fileHandle) {
                    throw new \Exception("无法打开文件: {$this->filePath}");
                }

                if (!$this->connect()) {
                    throw new \Exception("连接服务器失败");
                }

                $result = $this->pushStream($fileHandle);
                fclose($fileHandle);

                if ($result === true) {
                    $this->log("推流完成！", 'success');
                    return true;
                }

            } catch (\Exception $e) {
                $this->log("推流错误: " . $e->getMessage(), 'error');
                if ($fileHandle) fclose($fileHandle);
                $this->closeConnection();

                if ($this->autoReconnect && $retryCount < $this->maxRetries) {
                    $retryCount++;
                    $this->stats['reconnect_count']++;
                    $this->log("等待 {$this->retryDelay} 秒后进行第 {$retryCount} 次重连...", 'warning');
                    sleep($this->retryDelay);
                    continue;
                } else {
                    $this->log("达到最大重连次数，推流失败", 'error');
                    return false;
                }
            }
        }

        return false;
    }

    private function connect() {
        $urlParts = parse_url($this->pushUrl);
        $host = $urlParts['host'];
        $port = $urlParts['port'] ?? 8501;
        $path = $urlParts['path'] ?? '/';

        $this->log("连接 HTTP-FLV 服务器: {$host}:{$port}", 'info');

        $this->socket = @fsockopen($host, $port, $errno, $errstr, 10);
        if (!$this->socket) {
            $this->log("Socket 连接失败: {$errstr} ({$errno})", 'error');
            return false;
        }

        stream_set_timeout($this->socket, 30);
        stream_set_blocking($this->socket, true);

        $request = $this->buildHTTPRequest($host, $path);
        $this->log("发送 HTTP 请求头", 'debug');

        $result = fwrite($this->socket, $request);
        if ($result === false) {
            $this->log("发送请求头失败", 'error');
            return false;
        }

        // 读取响应头
        $response = '';
        $headersEnded = false;
        while (!feof($this->socket)) {
            $line = fgets($this->socket);
            if ($line === false) break;

            $response .= $line;

            // 检测响应头结束
            if (trim($line) === '') {
                $headersEnded = true;
                break;
            }
        }

        if (!$headersEnded) {
            $this->log("读取服务器响应失败", 'error');
            return false;
        }

        $firstLine = strtok($response, "\r\n");
        $this->log("服务器响应: " . $firstLine, 'debug');

        if (strpos($firstLine, '200') === false) {
            $this->log("服务器返回非200状态: " . $firstLine, 'error');
            return false;
        }

        $this->log("HTTP 连接成功", 'success');
        return true;
    }

    private function buildHTTPRequest($host, $path) {
        $request = "POST {$path} HTTP/1.1\r\n";
        $request .= "Host: {$host}\r\n";
        $request .= "Content-Type: video/x-flv\r\n";
        $request .= "Connection: keep-alive\r\n";

        if ($this->useChunked) {
            $request .= "Transfer-Encoding: chunked\r\n";
        }

        $request .= "\r\n";
        return $request;
    }

    /**
     * 修复版本：正确处理 FLV 文件解析和发送
     */
    private function pushStream($fileHandle) {
        // 1. 读取 FLV Header (9 bytes)
        $flvHeader = fread($fileHandle, 9);
        if (strlen($flvHeader) != 9) {
            throw new \Exception("无效的 FLV 文件：无法读取 Header");
        }

        // 验证 FLV 文件
        if (substr($flvHeader, 0, 3) !== 'FLV') {
            throw new \Exception("不是有效的 FLV 文件");
        }

        $version = ord($flvHeader[3]);
        $typeFlags = ord($flvHeader[4]);
        $hasAudio = ($typeFlags & 0x04) ? true : false;
        $hasVideo = ($typeFlags & 0x01) ? true : false;
        $dataOffset = $this->readInt32(substr($flvHeader, 5, 4));

        $this->log(sprintf("FLV 版本: %d, Audio=%s, Video=%s, Header偏移: %d",
            $version,
            $hasAudio ? '是' : '否',
            $hasVideo ? '是' : '否',
            $dataOffset
        ), 'info');

        // 如果 dataOffset > 9，说明 Header 后面有额外数据
        if ($dataOffset > 9) {
            $extraData = fread($fileHandle, $dataOffset - 9);
            $flvHeader .= $extraData;
        }

        // 发送 FLV Header
        if ($this->useChunked) {
            $this->sendChunk($flvHeader);
        } else {
            $this->writeAll($flvHeader);
        }

        // 2. 读取并发送第一个 Previous Tag Size (4 bytes, 总是 0)
        $prevTagSize = fread($fileHandle, 4);
        if (strlen($prevTagSize) != 4) {
            throw new \Exception("读取 Previous Tag Size 失败");
        }

        if ($this->useChunked) {
            $this->sendChunk($prevTagSize);
        } else {
            $this->writeAll($prevTagSize);
        }

        // 3. 循环读取和发送 Tags
        $startRealTime = microtime(true);
        $tagCount = 0;
        $firstTimestamp = -1;
        $lastProgressTime = 0;

        while ($this->isRunning && !feof($fileHandle)) {
            // 检查文件位置
            $position = ftell($fileHandle);
            $fileSize = filesize($this->filePath);

            if ($position >= $fileSize - 4) { // 减去 PreviousTagSize 的4字节
                $this->log("已到达文件末尾，推流完成", 'info');
                break;
            }

            // 读取 Tag Header (11 bytes)
            $tagHeader = fread($fileHandle, 11);
            if (strlen($tagHeader) < 11) {
                $this->log("读取 Tag Header 失败，文件可能已结束", 'warning');
                break;
            }

            // 解析 Tag Header
            $tagType = ord($tagHeader[0]);
            $dataSize = $this->readUInt24(substr($tagHeader, 1, 3));
            $timestamp = $this->readUInt24(substr($tagHeader, 4, 3));
            $timestampExt = ord($tagHeader[7]);
            $streamId = $this->readUInt24(substr($tagHeader, 8, 3));

            // 计算完整时间戳
            $fullTimestamp = ($timestampExt << 24) | $timestamp;

            $tagTypeName = $this->getTagTypeName($tagType);

            // 验证数据大小
            if ($dataSize <= 0 || $dataSize > 10 * 1024 * 1024) { // 最大10MB
                $this->log("异常的 Tag 数据大小: {$dataSize} 字节, TagType: {$tagTypeName}，跳过此Tag", 'warning');

                // 尝试定位到下一个 Tag (跳过数据 + PreviousTagSize)
                $seekOffset = $dataSize + 4;
                if ($seekOffset > 0 && $seekOffset < 50 * 1024 * 1024) { // 50MB 限制
                    $currentPos = ftell($fileHandle);
                    if (fseek($fileHandle, $seekOffset, SEEK_CUR) === 0) {
                        $newPos = ftell($fileHandle);
                        $this->log("跳过异常Tag，从 {$currentPos} 移动到 {$newPos}", 'debug');
                        continue;
                    }
                }
                break;
            }

            // 检查剩余数据是否足够
            $remainingData = $fileSize - $position - 11;
            if ($dataSize + 4 > $remainingData) {
                $this->log("Tag 数据大小 {$dataSize} 超出剩余文件大小 {$remainingData}", 'warning');
                break;
            }

            // 读取 Tag Data
            $tagData = fread($fileHandle, $dataSize);
            if (strlen($tagData) != $dataSize) {
                throw new \Exception(sprintf(
                    "读取 Tag Data 失败: 期望 %d 字节，实际 %d 字节, TagType: %s, 文件位置: %d",
                    $dataSize,
                    strlen($tagData),
                    $tagTypeName,
                    $position
                ));
            }

            // 读取 Previous Tag Size (4 bytes)
            $prevTagSizeBinary = fread($fileHandle, 4);
            if (strlen($prevTagSizeBinary) != 4 && !feof($fileHandle)) {
                throw new \Exception("读取 Previous Tag Size 失败");
            }

            // 记录第一个时间戳
            if ($firstTimestamp < 0) {
                $firstTimestamp = $fullTimestamp;
            }

            // 构建完整的 Tag (Header + Data)
            $fullTag = $tagHeader . $tagData;

            // 速率控制
            if ($this->speed > 0 && $fullTimestamp > 0) {
                $adjustedTimestamp = $fullTimestamp - $firstTimestamp;
                $elapsedReal = (microtime(true) - $startRealTime) * 1000;
                $targetTimestamp = $adjustedTimestamp / $this->speed;

                if ($targetTimestamp > $elapsedReal) {
                    $sleepMs = $targetTimestamp - $elapsedReal;
                    if ($sleepMs > 0 && $sleepMs < 5000) { // 最多等待5秒
                        usleep((int)($sleepMs * 1000));
                    }
                }
            }

            // 发送 Tag 数据
            if ($this->useChunked) {
                $this->sendChunk($fullTag);
            } else {
                $this->writeAll($fullTag);
            }

            // 发送 Previous Tag Size
            if ($this->useChunked) {
                $this->sendChunk($prevTagSizeBinary);
            } else {
                $this->writeAll($prevTagSizeBinary);
            }

            // 更新统计
            $this->stats['tags_sent']++;
            $this->stats['bytes_sent'] += strlen($fullTag) + 4; // Tag + PreviousTagSize
            $tagCount++;

            $this->lastTimestamp = $fullTimestamp;

            // 定期输出进度
            $currentTime = microtime(true);
            if ($this->statsEnabled && $tagCount % 100 == 0 && ($currentTime - $lastProgressTime) >= 1) {
                $this->printProgress($fullTimestamp);
                $lastProgressTime = $currentTime;
            }

            // 检查连接
            if (!$this->isConnected()) {
                throw new \Exception("连接已断开");
            }
        }

        $this->log("共处理 {$tagCount} 个 Tag", 'info');

        // 发送结束标记
        if ($this->useChunked) {
            fwrite($this->socket, "0\r\n\r\n");
        }

        return true;
    }

    private function getTagTypeName($type) {
        switch ($type) {
            case 8: return '音频(Audio)';
            case 9: return '视频(Video)';
            case 18: return '脚本(Script)';
            default: return "未知({$type})";
        }
    }

    private function sendChunk($data) {
        $chunkSize = dechex(strlen($data));
        $chunk = $chunkSize . "\r\n" . $data . "\r\n";
        return $this->writeAll($chunk);
    }

    private function writeAll($data) {
        $len = strlen($data);
        $written = 0;

        while ($written < $len) {
            $result = fwrite($this->socket, substr($data, $written));
            if ($result === false) {
                throw new \Exception("写入数据失败");
            }
            $written += $result;
        }

        $this->totalBytes += $written;
        return $written;
    }

    private function isConnected() {
        if (!$this->socket) return false;

        $metadata = stream_get_meta_data($this->socket);
        if ($metadata['eof']) return false;

        return true;
    }

    private function closeConnection() {
        if ($this->socket) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    private function readUInt24($bytes) {
        if (strlen($bytes) < 3) return 0;
        return (ord($bytes[0]) << 16) | (ord($bytes[1]) << 8) | ord($bytes[2]);
    }

    private function readInt32($bytes) {
        if (strlen($bytes) < 4) return 0;
        return (ord($bytes[0]) << 24) | (ord($bytes[1]) << 16) | (ord($bytes[2]) << 8) | ord($bytes[3]);
    }

    private function printProgress($currentTimestamp) {
        $elapsed = microtime(true) - $this->stats['start_time'];
        $speed = $elapsed > 0 ? $this->stats['tags_sent'] / $elapsed : 0;
        $bitrate = $elapsed > 0 ? ($this->stats['bytes_sent'] * 8 / $elapsed) / 1000 : 0;
        $progress = $this->totalBytes > 0 ?
            round(($this->stats['tags_sent'] / max(1, $this->stats['tags_sent'] + 100)) * 100, 1) : 0;

        $this->log(sprintf(
            "[进度] 已发送: %d tags | 时间戳: %s | 速率: %.1f tags/s | 码率: %.1f kbps",
            $this->stats['tags_sent'],
            $this->formatTime($currentTimestamp),
            $speed,
            $bitrate
        ), 'debug');
    }

    private function printFinalStats() {
        $elapsed = microtime(true) - $this->stats['start_time'];
        $avgSpeed = $elapsed > 0 ? $this->stats['tags_sent'] / $elapsed : 0;
        $totalBitrate = $elapsed > 0 ? ($this->stats['bytes_sent'] * 8 / $elapsed) / 1000 : 0;

        $this->log("========================================", 'info');
        $this->log("推流统计", 'info');
        $this->log("========================================", 'info');
        $this->log("总耗时: " . $this->formatTime($elapsed * 1000), 'info');
        $this->log("发送 Tag 数: " . number_format($this->stats['tags_sent']), 'info');
        $this->log("发送字节数: " . $this->formatBytes($this->stats['bytes_sent']), 'info');
        $this->log("平均速率: " . number_format($avgSpeed, 1) . " tags/s", 'info');
        $this->log("平均码率: " . number_format($totalBitrate, 1) . " kbps", 'info');
        $this->log("重连次数: " . $this->stats['reconnect_count'], 'info');
        $this->log("========================================", 'info');
    }

    private function formatTime($ms) {
        $seconds = floor($ms / 1000);
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        if ($hours > 0) {
            return sprintf("%02d:%02d:%02d", $hours, $minutes, $secs);
        } else {
            return sprintf("%02d:%02d", $minutes, $secs);
        }
    }

    private function formatBytes($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    private function log($message, $level = 'info') {
        if (!$this->verbose && $level == 'debug') {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $prefix = '';

        switch ($level) {
            case 'error':
                $prefix = "\033[31m[ERROR]\033[0m";
                break;
            case 'warning':
                $prefix = "\033[33m[WARN]\033[0m";
                break;
            case 'success':
                $prefix = "\033[32m[SUCCESS]\033[0m";
                break;
            case 'debug':
                $prefix = "\033[90m[DEBUG]\033[0m";
                break;
            default:
                $prefix = "[INFO]";
        }

        echo "[{$timestamp}] {$prefix} {$message}\n";
    }
}

