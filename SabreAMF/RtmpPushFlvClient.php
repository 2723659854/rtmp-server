<?php
require_once __DIR__ . '/RtmpClient.php';
require_once __DIR__ . '/InputStream.php';
require_once __DIR__ . '/OutputStream.php';
require_once __DIR__ . '/AMF0/Deserializer.php';
require_once __DIR__ . '/AMF0/Serializer.php';

/**
 * RTMP推流客户端
 * 用于读取FLV文件并通过RTMP协议推流到服务器
 * 
 * Features:
 * - 按原始时间戳精确推流（伪直播）
 * - 自动断线重连
 * - 实时进度上报
 * - 支持推流倍速（0.1x-10x）
 * - 详细的日志输出
 * 
 * @version 1.1.0
 */
class RtmpPushFlvClient extends RTMPClient
{
    const RTMP_SIG_SIZE = 1536;

    /** @var int 流ID */
    private $streamId = 0;

    /** @var bool 是否已发布 */
    private $published = false;

    /** @var int 时间戳基准 */
    private $baseTimestamp = 0;

    /** @var int 上一次发送的视频时间戳 */
    private $lastVideoTimestamp = -1;

    /** @var int 上一次发送的音频时间戳 */
    private $lastAudioTimestamp = -1;

    /** @var int 发送块大小 */
    private $sendChunkSize = 4096;

    /** @var int 音频块流ID */
    private $audioChunkStreamId = 4;

    /** @var int 视频块流ID */
    private $videoChunkStreamId = 5;

    /** @var int 元数据块流ID */
    private $metaChunkStreamId = 3;

    /** @var string 推流地址 */
    private $pushUrl = '';

    /** @var string 文件路径 */
    private $filePath = '';

    /** @var float 推流速度 */
    private $speed = 1.0;

    /** @var bool 自动重连 */
    private $autoReconnect = true;

    /** @var int 最大重连次数 */
    private $maxRetries = 5;

    /** @var int 重连延迟（秒） */
    private $retryDelay = 3;

    /** @var bool 是否正在运行 */
    private $isRunning = true;

    /** @var array 统计信息 */
    private $stats = [
        'tags_sent' => 0,
        'bytes_sent' => 0,
        'start_time' => 0,
        'last_report_time' => 0,
        'reconnect_count' => 0,
        'audio_tags' => 0,
        'video_tags' => 0,
        'meta_tags' => 0,
    ];

    /** @var int 上次进度报告时间 */
    private $lastProgressTime = 0;

    /** @var string 服务器主机 */
    private $host = '';

    /** @var int 服务器端口 */
    private $port = 1935;

    /** @var string 应用名称 */
    private $app = '';

    /** @var string 流名称 */
    private $streamKey = '';

    /**
     * 构造函数
     * @param string $filePath FLV文件路径
     * @param string $pushUrl RTMP推流地址
     * @param float $speed 推流速度倍数
     * @param bool $autoReconnect 是否自动重连
     */
    public function __construct($filePath = '', $pushUrl = '', $speed = 1.0, $autoReconnect = true)
    {
        $this->filePath = $filePath;
        $this->pushUrl = $pushUrl;
        $this->speed = max(0.1, min(10.0, (float)$speed));
        $this->autoReconnect = $autoReconnect;

        // 解析RTMP URL
        if ($pushUrl && preg_match('#^rtmp://([^:/]+)(?::(\d+))?/([^/]+)/(.+)$#', $pushUrl, $matches)) {
            $this->host = $matches[1];
            $this->port = intval($matches[2] ?: 1935);
            $this->app = $matches[3];
            $this->streamKey = $matches[4];
        }

        // 注册信号处理
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, [$this, 'handleSignal']);
            pcntl_signal(SIGTERM, [$this, 'handleSignal']);
        }
    }

    /**
     * 处理信号
     */
    public function handleSignal($signal)
    {
        $this->log("[信号] 收到退出信号，正在优雅关闭...", 'warning');
        $this->isRunning = false;
        $this->close();
        $this->printFinalStats();
        exit(0);
    }

    /**
     * 日志输出
     * @param string $message 消息
     * @param string $level 级别 (info, warning, error, success, debug)
     */
    private function log($message, $level = 'info')
    {
        $timestamp = date('Y-m-d H:i:s');
        $levelUpper = strtoupper($level);
        $prefix = '[' . $timestamp . '] [' . $levelUpper . '] ';
        echo $prefix . $message . "\n";
    }

    /**
     * 格式化字节
     * @param int $bytes 字节数
     * @return string
     */
    private function formatBytes($bytes)
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return sprintf("%.2f GB", $bytes / (1024 * 1024 * 1024));
        } elseif ($bytes >= 1024 * 1024) {
            return sprintf("%.2f MB", $bytes / (1024 * 1024));
        } elseif ($bytes >= 1024) {
            return sprintf("%.2f KB", $bytes / 1024);
        } else {
            return $bytes . ' B';
        }
    }

    /**
     * 格式化时间
     * @param int $seconds 秒数
     * @return string
     */
    private function formatTime($seconds)
    {
        $minutes = floor($seconds / 60);
        $secs = $seconds % 60;
        return sprintf("%02d:%02d", $minutes, $secs);
    }

    /**
     * 打印进度
     * @param int $timestamp 当前时间戳
     */
    private function printProgress($timestamp)
    {
        $currentTime = microtime(true);
        $elapsed = $currentTime - $this->stats['start_time'];
        
        // 计算速率
        $tagsPerSec = $this->stats['tags_sent'] / max(1, $elapsed);
        
        // 计算码率 (kbps)
        $bitsPerSec = ($this->stats['bytes_sent'] * 8) / max(1, $elapsed);
        $kbps = $bitsPerSec / 1000;
        
        // 时间戳格式化
        $tsSeconds = floor($timestamp / 1000);
        $tsFormatted = $this->formatTime($tsSeconds);
        
        $this->log(sprintf(
            "[进度] 已发送: %d tags | 时间戳: %s | 速率: %.1f tags/s | 码率: %.1f kbps",
            $this->stats['tags_sent'],
            $tsFormatted,
            $tagsPerSec,
            $kbps
        ), 'debug');
    }

    /**
     * 打印最终统计
     */
    private function printFinalStats()
    {
        $elapsed = microtime(true) - $this->stats['start_time'];
        $elapsedFormatted = $this->formatTime((int)$elapsed);
        
        $avgTagsPerSec = $this->stats['tags_sent'] / max(1, $elapsed);
        $avgKbps = ($this->stats['bytes_sent'] * 8) / max(1, $elapsed) / 1000;

        $this->log("========================================", 'info');
        $this->log("推流统计", 'info');
        $this->log("========================================", 'info');
        $this->log("总耗时: {$elapsedFormatted}", 'info');
        $this->log("发送 Tag 数: {$this->stats['tags_sent']}", 'info');
        $this->log("音频 Tag 数: {$this->stats['audio_tags']}", 'info');
        $this->log("视频 Tag 数: {$this->stats['video_tags']}", 'info');
        $this->log("元数据 Tag 数: {$this->stats['meta_tags']}", 'info');
        $this->log("发送字节数: " . $this->formatBytes($this->stats['bytes_sent']), 'info');
        $this->log("平均速率: " . sprintf("%.1f", $avgTagsPerSec) . " tags/s", 'info');
        $this->log("平均码率: " . sprintf("%.1f", $avgKbps) . " kbps", 'info');
        $this->log("重连次数: {$this->stats['reconnect_count']}", 'info');
        $this->log("========================================", 'info');
    }

    /**
     * 启动推流
     * @return bool
     */
    public function start()
    {
        $this->log("========================================", 'info');
        $this->log("RTMP Pusher v1.1.0", 'info');
        $this->log("========================================", 'info');
        $this->log("文件: {$this->filePath}", 'info');
        $this->log("推流地址: {$this->pushUrl}", 'info');
        $this->log("协议: RTMP", 'info');
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

        $this->printFinalStats();

        return $result;
    }

    /**
     * 执行推流（带重连）
     * @return bool
     */
    private function doPush()
    {
        $retryCount = 0;

        while ($this->isRunning && $retryCount <= $this->maxRetries) {
            try {
                // 连接服务器
                $this->log("连接 RTMP 服务器: {$this->host}:{$this->port}", 'info');
                $this->connect($this->host, $this->app, $this->port);

                // 发送FCPublish
                $this->log("发送 FCPublish...", 'debug');
                $this->fcPublish($this->streamKey);

                // 发布流
                $this->log("发布流: {$this->streamKey}", 'debug');
                $this->publish($this->streamKey, 'live');

                $this->log("RTMP 连接成功", 'success');

                // 推送FLV文件
                $result = $this->pushFlv($this->filePath, $this->speed);

                if ($result === true) {
                    $this->log("推流完成！", 'success');
                    $this->close();
                    return true;
                }

            } catch (Exception $e) {
                $this->log("推流错误: " . $e->getMessage(), 'error');
                $this->close();

                if ($this->autoReconnect && $retryCount < $this->maxRetries) {
                    $retryCount++;
                    $this->stats['reconnect_count']++;
                    $this->log("等待 {$this->retryDelay} 秒后进行第 {$retryCount} 次重连...", 'warning');
                    sleep($this->retryDelay);
                    
                    // 重置状态
                    $this->published = false;
                    $this->streamId = 0;
                    $this->lastVideoTimestamp = -1;
                    $this->lastAudioTimestamp = -1;
                    continue;
                } else {
                    $this->log("达到最大重连次数，推流失败", 'error');
                    return false;
                }
            }
        }

        return false;
    }

    /**
     * 发布流
     * @param string $streamKey 流名称
     * @param string $type 发布类型 (live/record/append)
     * @return bool
     */
    public function publish($streamKey, $type = 'live')
    {
        // 创建流
        $this->createStream();

        // 发布流
        $this->sendPublish($streamKey, $type);

        // 设置块大小
        $this->sendSetChunkSize($this->sendChunkSize);

        $this->published = true;
        return true;
    }

    /**
     * 创建流
     */
    private function createStream()
    {
        $result = $this->call('createStream');
        if ($result && isset($result[0])) {
            $this->streamId = (int)$result[0];
        }
    }

    /**
     * 发送发布命令
     * @param string $streamKey
     * @param string $type
     */
    private function sendPublish($streamKey, $type = 'live')
    {
        $message = new RtmpMessage('publish', null, [$streamKey, $type]);
        $packet = $this->encodeAMF0Message($message);
        $packet->streamId = $this->streamId;

        $this->sendPacket($packet);
    }

    /**
     * 使用AMF0编码消息
     * @param RtmpMessage $message
     * @return RtmpPacket
     */
    private function encodeAMF0Message(RtmpMessage $message)
    {
        $p = new RtmpPacket();
        $p->chunkStreamId = 3;
        $p->streamId = 0;
        $p->chunkType = RtmpPacket::CHUNK_TYPE_0;
        $p->type = RtmpPacket::TYPE_INVOKE_AMF0;

        $stream = new SabreAMF_OutputStream();
        $serializer = new SabreAMF_AMF0_Serializer($stream);

        $serializer->writeAMFData($message->commandName);
        $serializer->writeAMFData(0);
        $serializer->writeAMFData(null);

        if ($message->arguments != null) {
            foreach ($message->arguments as $arg) {
                $serializer->writeAMFData($arg);
            }
        }

        $p->payload = $stream->getRawData();
        $p->length = strlen($p->payload);

        $message->setPacket($p);
        return $p;
    }

    /**
     * 发送设置块大小命令
     * @param int $size
     */
    private function sendSetChunkSize($size)
    {
        $p = new RtmpPacket();
        $p->chunkStreamId = 2;
        $p->type = RtmpPacket::TYPE_CHUNK_SIZE;
        $p->streamId = 0;
        $p->chunkType = RtmpPacket::CHUNK_TYPE_0;

        $stream = new RtmpStream();
        $stream->writeInt32($size);

        $p->payload = $stream->flush();
        $p->length = strlen($p->payload);

        $this->sendPacket($p);
    }

    /**
     * 推送FLV文件
     * @param string $flvFile FLV文件路径
     * @param float $speed 推流速度倍数
     * @return bool
     */
    public function pushFlv($flvFile, $speed = 1.0)
    {
        if (!file_exists($flvFile)) {
            throw new Exception("FLV file not found: $flvFile");
        }

        if (!$this->published) {
            throw new Exception("Stream not published, call publish() first");
        }

        // 读取整个FLV文件
        $flvData = file_get_contents($flvFile);
        if ($flvData === false || strlen($flvData) < 13) {
            throw new Exception("Cannot read FLV file: $flvFile");
        }

        // 验证FLV签名
        if (substr($flvData, 0, 3) !== 'FLV') {
            throw new Exception("Invalid FLV file: wrong signature");
        }

        $version = ord($flvData[3]);
        $flags = ord($flvData[4]);
        $hasAudio = ($flags & 4) !== 0;
        $hasVideo = ($flags & 1) !== 0;

        $this->log(sprintf("FLV 版本: %d, Audio=%s, Video=%s", 
            $version, 
            $hasAudio ? '是' : '否', 
            $hasVideo ? '是' : '否'
        ), 'info');

        // 计算真实时长
        $realDuration = $this->scanMaxTimestamp($flvData);
        $this->log("FLV 真实时长: {$realDuration} 秒", 'info');

        // 跳过FLV header
        $offset = 9;
        $offset += 4; // 跳过PreviousTagSize

        $totalLen = strlen($flvData);
        $tagCount = 0;
        $this->baseTimestamp = 0;
        $firstTag = true;
        $startTime = microtime(true);
        $this->stats['start_time'] = $startTime;
        $this->lastProgressTime = $startTime;

        // 解析并发送标签
        while ($this->isRunning && $offset < $totalLen) {
            // 确保有足够的字节读取标签头(11字节) + PreviousTagSize(4字节)
            if ($offset + 15 > $totalLen) {
                $this->log("已到达文件末尾，推流完成", 'info');
                break;
            }

            // 读取标签头 (11字节)
            $tagType = ord($flvData[$offset]);
            $offset++;

            // DataSize: 3 bytes, big-endian
            $dataSize = (ord($flvData[$offset]) << 16) | (ord($flvData[$offset + 1]) << 8) | ord($flvData[$offset + 2]);
            $offset += 3;

            // Timestamp: 3 bytes, big-endian + 1 byte extended
            $timestamp = (ord($flvData[$offset]) << 16) | (ord($flvData[$offset + 1]) << 8) | ord($flvData[$offset + 2]);
            $offset += 3;
            $timestamp |= (ord($flvData[$offset]) << 24);
            $offset++;

            // StreamID: 3 bytes, big-endian (通常为0)
            $offset += 3;

            // 确保有足够的数据读取 body + PreviousTagSize
            if ($offset + $dataSize + 4 > $totalLen) {
                break;
            }

            // 读取标签数据
            $tagData = substr($flvData, $offset, $dataSize);
            $offset += $dataSize;

            // 读取PreviousTagSize (4字节)
            $prevTagSize = (ord($flvData[$offset]) << 24) | (ord($flvData[$offset + 1]) << 16) 
                         | (ord($flvData[$offset + 2]) << 8) | ord($flvData[$offset + 3]);
            $offset += 4;

            $tagCount++;

            // 处理时间戳
            if ($firstTag && $dataSize > 0) {
                $this->baseTimestamp = $timestamp;
                $firstTag = false;
            }

            $adjustedTimestamp = $timestamp - $this->baseTimestamp;

            // 跳过无效或空的标签
            if ($dataSize <= 0) {
                continue;
            }

            // 根据标签类型发送
            switch ($tagType) {
                case 8: // 音频
                    $this->sendAudioData($tagData, $adjustedTimestamp);
                    $this->stats['audio_tags']++;
                    break;
                case 9: // 视频
                    $this->sendVideoData($tagData, $adjustedTimestamp);
                    $this->stats['video_tags']++;
                    break;
                case 18: // 脚本数据
                    $this->sendMetaData($tagData, $adjustedTimestamp);
                    $this->stats['meta_tags']++;
                    break;
            }

            // 更新统计
            $this->stats['tags_sent']++;
            $this->stats['bytes_sent'] += $dataSize + 11 + 4;

            // 控制推流速度
            if ($speed > 0 && $adjustedTimestamp > 0) {
                $elapsed = (microtime(true) - $startTime) * 1000;
                $expectedTime = ($adjustedTimestamp / $speed);
                $delay = (int)(($expectedTime - $elapsed) * 1000);
                if ($delay > 0) {
                    usleep($delay);
                }
            }

            // 定期输出进度
            $currentTime = microtime(true);
            if ($tagCount % 100 == 0 && ($currentTime - $this->lastProgressTime) >= 1) {
                $this->printProgress($adjustedTimestamp);
                $this->lastProgressTime = $currentTime;
            }
        }

        $this->log("共处理 {$tagCount} 个 Tag", 'info');
        return true;
    }

    /**
     * 快速扫描FLV获取最大时间戳
     * @param string $flvData FLV数据
     * @return float 最大时间戳（秒）
     */
    private function scanMaxTimestamp($flvData)
    {
        $offset = 9 + 4; // 跳过header和first prev tag size
        $totalLen = strlen($flvData);
        $maxTimestamp = 0;

        while ($offset < $totalLen) {
            if ($offset + 15 > $totalLen) break;

            $tagType = ord($flvData[$offset]);
            $offset++;

            $dataSize = (ord($flvData[$offset]) << 16) | (ord($flvData[$offset + 1]) << 8) | ord($flvData[$offset + 2]);
            $offset += 3;

            $timestamp = (ord($flvData[$offset]) << 16) | (ord($flvData[$offset + 1]) << 8) | ord($flvData[$offset + 2]);
            $offset += 3;
            $timestamp |= (ord($flvData[$offset]) << 24);
            $offset++;

            $offset += 3; // StreamID

            if ($timestamp > $maxTimestamp) {
                $maxTimestamp = $timestamp;
            }

            if ($dataSize <= 0 || $dataSize > 50 * 1024 * 1024) break;
            $offset += $dataSize + 4;
        }

        return $maxTimestamp / 1000;
    }

    /**
     * 发送音频数据
     * @param string $data 音频数据
     * @param int $timestamp 时间戳
     */
    private function sendAudioData($data, $timestamp)
    {
        $p = new RtmpPacket();
        $p->chunkStreamId = $this->audioChunkStreamId;
        $p->type = RtmpPacket::TYPE_AUDIO;
        $p->streamId = $this->streamId;
        $p->payload = $data;
        $p->length = strlen($data);

        // 计算时间戳增量
        $delta = $timestamp - $this->lastAudioTimestamp;
        
        // 如果增量有效且上一个包存在，使用CHUNK_TYPE_1发送增量
        // 否则使用CHUNK_TYPE_0发送绝对时间戳
        if ($this->lastAudioTimestamp >= 0 && $delta >= 0) {
            $p->chunkType = RtmpPacket::CHUNK_TYPE_1;
            $p->timestamp = $delta;  // CHUNK_TYPE_1发送的是增量
        } else {
            $p->chunkType = RtmpPacket::CHUNK_TYPE_0;
            $p->timestamp = $timestamp;  // CHUNK_TYPE_0发送的是绝对时间戳
        }

        $this->lastAudioTimestamp = $timestamp;
        $this->sendMediaPacket($p);
    }

    /**
     * 发送视频数据
     * @param string $data 视频数据
     * @param int $timestamp 时间戳
     */
    private function sendVideoData($data, $timestamp)
    {
        $p = new RtmpPacket();
        $p->chunkStreamId = $this->videoChunkStreamId;
        $p->type = RtmpPacket::TYPE_VIDEO;
        $p->streamId = $this->streamId;
        $p->payload = $data;
        $p->length = strlen($data);

        // 计算时间戳增量
        $delta = $timestamp - $this->lastVideoTimestamp;
        
        // 如果增量有效且上一个包存在，使用CHUNK_TYPE_1发送增量
        // 否则使用CHUNK_TYPE_0发送绝对时间戳
        if ($this->lastVideoTimestamp >= 0 && $delta >= 0) {
            $p->chunkType = RtmpPacket::CHUNK_TYPE_1;
            $p->timestamp = $delta;  // CHUNK_TYPE_1发送的是增量
        } else {
            $p->chunkType = RtmpPacket::CHUNK_TYPE_0;
            $p->timestamp = $timestamp;  // CHUNK_TYPE_0发送的是绝对时间戳
        }

        $this->lastVideoTimestamp = $timestamp;
        $this->sendMediaPacket($p);
    }

    /**
     * 发送元数据
     * @param string $data 元数据
     * @param int $timestamp 时间戳
     */
    private function sendMetaData($data, $timestamp)
    {
        $stream = new SabreAMF_InputStream($data);
        $deserializer = new SabreAMF_AMF0_Deserializer($stream);
        
        $cmd = $deserializer->readAMFData();
        $dataObj = $deserializer->readAMFData();
        
        $outputStream = new SabreAMF_OutputStream();
        $serializer = new SabreAMF_AMF0_Serializer($outputStream);
        
        $serializer->writeAMFData('@setDataFrame');
        $serializer->writeAMFData($cmd);
        $serializer->writeAMFData($dataObj);
        
        $p = new RtmpPacket();
        $p->chunkStreamId = $this->metaChunkStreamId;
        $p->type = RtmpPacket::TYPE_METADATA;
        $p->streamId = $this->streamId;
        $p->timestamp = $timestamp;
        $p->payload = $outputStream->getRawData();
        $p->length = strlen($p->payload);
        $p->chunkType = RtmpPacket::CHUNK_TYPE_0;

        $this->sendMediaPacket($p);
    }

    /**
     * 发送媒体数据包
     * @param RtmpPacket $packet
     */
    private function sendMediaPacket(RtmpPacket $packet)
    {
        if (!$packet->length) {
            $packet->length = strlen($packet->payload);
        }

        // 构建块头
        $header = new RtmpStream();

        // 写入基本头
        $header->writeByte($packet->chunkType << 6 | $packet->chunkStreamId);

        // 根据块类型写入额外头信息
        if ($packet->chunkType <= RtmpPacket::CHUNK_TYPE_1) {
            // 时间戳 (3字节)
            if ($packet->timestamp >= 0xFFFFFF) {
                $header->writeInt24(0xFFFFFF);
            } else {
                $header->writeInt24($packet->timestamp);
            }
        }

        if ($packet->chunkType <= RtmpPacket::CHUNK_TYPE_1) {
            // 长度 (3字节)
            $header->writeInt24($packet->length);
            // 类型 (1字节)
            $header->writeByte($packet->type);
        }

        if ($packet->chunkType == RtmpPacket::CHUNK_TYPE_0) {
            // 流ID (4字节, 小端)
            $header->writeInt32LE($packet->streamId);
        }

        // 发送头
        $this->socketWrite($header);

        // 发送扩展时间戳
        if ($packet->timestamp >= 0xFFFFFF && $packet->chunkType <= RtmpPacket::CHUNK_TYPE_1) {
            $extTimestamp = new RtmpStream();
            $extTimestamp->writeInt32($packet->timestamp);
            $this->socketWrite($extTimestamp);
        }

        // 分块发送数据
        $offset = 0;
        $firstChunk = true;

        while ($offset < $packet->length) {
            if (!$firstChunk) {
                // 发送类型3头 (继续块)
                $this->socketWrite(new RtmpStream(chr(0xC0 | $packet->chunkStreamId)));
            }
            $firstChunk = false;

            $chunkSize = min($this->sendChunkSize, $packet->length - $offset);
            $chunkData = new RtmpStream(substr($packet->payload, $offset, $chunkSize));
            $this->socketWrite($chunkData, $chunkSize);
            $offset += $chunkSize;
        }
    }

    /**
     * 发送FCPublish命令
     * @param string $streamKey
     */
    public function fcPublish($streamKey)
    {
        $p = new RtmpPacket();
        $p->chunkStreamId = 3;
        $p->type = RtmpPacket::TYPE_INVOKE_AMF0;
        $p->streamId = 0;
        $p->chunkType = RtmpPacket::CHUNK_TYPE_0;

        $stream = new SabreAMF_OutputStream();
        $serializer = new SabreAMF_AMF0_Serializer($stream);

        $serializer->writeAMFData('FCPublish');
        $serializer->writeAMFData(0);
        $serializer->writeAMFData(null);
        $serializer->writeAMFData($streamKey);

        $p->payload = $stream->getRawData();
        $p->length = strlen($p->payload);

        $this->sendPacket($p);
    }

    /**
     * 关闭连接
     */
    public function close()
    {
        $this->published = false;
        parent::close();
    }
}