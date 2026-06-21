<?php

namespace Root\Io;

class PullerManage
{
    protected $puller = null;

    public function __construct(string $pullUrl, string $outputFlv, int $duration = 0, bool $autoReconnect = true)
    {
        if (empty($pullUrl)) {
            throw new \RuntimeException('Pull URL cannot be empty');
        }

        if (empty($outputFlv)) {
            throw new \RuntimeException('Output FLV path cannot be empty');
        }

        $urlParts = parse_url($pullUrl);
        $scheme = strtolower($urlParts['scheme'] ?? 'http');

        if ($scheme === 'rtmp') {
            $this->puller = new RtmpPullerClient($pullUrl, $outputFlv, $duration, $autoReconnect);
        } else {
            $this->puller = new FlvPullerClient($pullUrl, $outputFlv, $duration, $autoReconnect);
        }
    }

    public function start(): void
    {
        if ($this->puller) {
            $this->puller->start();
        }
    }
}

class FlvPullerClient
{
    protected string $pullUrl;
    protected string $outputFlv;
    protected int $duration;
    protected bool $autoReconnect;
    protected bool $isRunning = true;
    protected $fileHandle = null;
    protected $socket = null;
    protected bool $isWebSocket = false;
    protected int $retryCount = 0;
    protected int $maxRetries = 5;
    protected int $retryDelay = 3;
    protected bool $chunked = false;
    protected string $chunkBuffer = '';
    protected ?int $startTime = null;
    protected ?int $baseTimestamp = null;
    protected int $bytesWritten = 0;
    protected int $lastStatsTime = 0;

    // FLV常量
    const SCRIPT_TAG = 18;
    const AUDIO_TAG = 8;
    const VIDEO_TAG = 9;

    const VIDEO_FRAME_TYPE_KEY_FRAME = 1;
    const VIDEO_CODEC_ID_AVC = 7;
    const AVC_PACKET_TYPE_SEQUENCE_HEADER = 0;

    const SOUND_FORMAT_AAC = 10;
    const AAC_PACKET_TYPE_SEQUENCE_HEADER = 0;

    public function __construct(string $pullUrl, string $outputFlv, int $duration = 0, bool $autoReconnect = true)
    {
        $this->pullUrl = $pullUrl;
        $this->outputFlv = $outputFlv;
        $this->duration = $duration;
        $this->autoReconnect = $autoReconnect;

        $urlParts = parse_url($pullUrl);
        $scheme = strtolower($urlParts['scheme'] ?? 'http');
        $this->isWebSocket = ($scheme === 'ws' || $scheme === 'wss');

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, [$this, 'handleSignal']);
            pcntl_signal(SIGTERM, [$this, 'handleSignal']);
        }
    }

    public function handleSignal(int $signal): void
    {
        $this->log("收到信号 {$signal}，正在停止拉流...");
        $this->isRunning = false;
    }

    public function start(): void
    {
        $this->log("开始拉流: {$this->pullUrl}");
        $this->log("输出文件: {$this->outputFlv}");

        $dir = dirname($this->outputFlv);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $this->fileHandle = fopen($this->outputFlv, 'wb');
        if (!$this->fileHandle) {
            throw new \RuntimeException("无法创建输出文件: {$this->outputFlv}");
        }

        $this->lastStatsTime = time();

        while ($this->isRunning) {
            try {
                $this->connect();
            } catch (\Exception $e) {
                $this->log("连接失败: {$e->getMessage()}", 'error');
                if (!$this->autoReconnect || !$this->handleReconnect()) {
                    break;
                }
                continue;
            }

            try {
                while ($this->isRunning) {
                    if ($this->duration > 0 && $this->startTime !== null && (time() - $this->startTime) >= $this->duration) {
                        $this->log("已达到指定时长 {$this->duration} 秒，停止拉流");
                        break;
                    }

                    $data = $this->receiveData();
                    if ($data === null || $data === '') {
                        usleep(100000);
                        continue;
                    }

                    $this->processData($data);
                }
            } catch (\Exception $e) {
                $this->log("接收数据异常: {$e->getMessage()}", 'error');
            } finally {
                $this->disconnect();
            }

            if ($this->duration > 0 && $this->startTime !== null && (time() - $this->startTime) >= $this->duration) {
                break;
            }

            if ($this->autoReconnect && $this->isRunning) {
                if (!$this->handleReconnect()) {
                    break;
                }
            } else {
                break;
            }
        }

        if ($this->fileHandle) {
            fclose($this->fileHandle);
            $this->fileHandle = null;
        }

        $sizeMB = round($this->bytesWritten / 1024 / 1024, 2);
        $this->log("拉流结束，文件已保存: {$this->outputFlv} ({$sizeMB} MB)");
    }

    protected function processData(string $data): void
    {
        if ($this->chunked) {
            $this->chunkBuffer .= $data;
            $decoded = $this->decodeChunked($this->chunkBuffer);
            if ($decoded !== null) {
                $this->writeFlvData($decoded);
            }
        } else {
            $this->writeFlvData($data);
        }
    }

    protected function writeFlvData(string $data): void
    {
        // 解析并调整FLV时间戳
        $adjustedData = $this->adjustFlvTimestamps($data);
        fwrite($this->fileHandle, $adjustedData);
        $this->bytesWritten += strlen($adjustedData);

        $now = time();
        if ($now - $this->lastStatsTime >= 5) {
            $sizeMB = round($this->bytesWritten / 1024 / 1024, 2);
            $this->log("已写入 {$sizeMB} MB");
            $this->lastStatsTime = $now;
        }
    }

    protected function adjustFlvTimestamps(string $data): string
    {
        $result = '';
        $offset = 0;
        $dataLen = strlen($data);

        // 检查FLV头部
        if ($offset < $dataLen && substr($data, $offset, 3) === 'FLV') {
            // FLV头部9字节 + PreviousTagSize0 4字节
            $headerLen = 13;
            if ($dataLen >= $headerLen) {
                $result .= substr($data, $offset, $headerLen);
                $offset += $headerLen;
                if ($this->startTime === null) {
                    $this->startTime = time();
                    $this->log("开始计时，将录制 {$this->duration} 秒");
                }
            }
        }

        // 解析FLV Tags
        while ($offset + 11 < $dataLen) {
            $tagType = ord($data[$offset]);
            $dataSize = ((ord($data[$offset + 1]) << 16) | (ord($data[$offset + 2]) << 8) | ord($data[$offset + 3]));
            $timestamp = ((ord($data[$offset + 4]) << 16) | (ord($data[$offset + 5]) << 8) | ord($data[$offset + 6]));
            $timestampExt = ord($data[$offset + 7]);
            $fullTimestamp = ($timestampExt << 24) | $timestamp;

            $tagTotalSize = 11 + $dataSize + 4; // Tag头11字节 + 数据 + PreviousTagSize 4字节

            if ($offset + $tagTotalSize > $dataLen) {
                // 数据不完整，保留剩余数据
                break;
            }

            // 提取Tag数据
            $tagHeader = substr($data, $offset, 11);
            $tagPayload = substr($data, $offset + 11, $dataSize);
            $prevTagSize = substr($data, $offset + 11 + $dataSize, 4);

            // 计算调整后的时间戳
            $adjustedTimestamp = $this->calculateAdjustedTimestamp($tagType, $fullTimestamp, $tagPayload);

            // 重新编码时间戳
            $newTimestampLow = $adjustedTimestamp & 0xFFFFFF;
            $newTimestampHigh = ($adjustedTimestamp >> 24) & 0xFF;

            $newTagHeader = $tagHeader;
            $newTagHeader[4] = chr(($newTimestampLow >> 16) & 0xFF);
            $newTagHeader[5] = chr(($newTimestampLow >> 8) & 0xFF);
            $newTagHeader[6] = chr($newTimestampLow & 0xFF);
            $newTagHeader[7] = chr($newTimestampHigh);

            $result .= $newTagHeader . $tagPayload . $prevTagSize;
            $offset += $tagTotalSize;
        }

        // 保留未处理的数据（可能是不完整的Tag）
        if ($offset < $dataLen) {
            $result .= substr($data, $offset);
        }

        return $result;
    }

    protected function calculateAdjustedTimestamp(int $tagType, int $originalTimestamp, string $payload): int
    {
        // Script data (tagType=18) 时间戳必须为0
        if ($tagType === self::SCRIPT_TAG) {
            return 0;
        }

        // 检查是否是sequence header（解码器初始化数据）
        if ($tagType === self::VIDEO_TAG && strlen($payload) >= 1) {
            $firstByte = ord($payload[0]);
            $codecId = $firstByte & 0x0F;
            if ($codecId === self::VIDEO_CODEC_ID_AVC && strlen($payload) >= 5) {
                $avcPacketType = ord($payload[1]);
                if ($avcPacketType === self::AVC_PACKET_TYPE_SEQUENCE_HEADER) {
                    // AVC sequence header，时间戳必须为0
                    return 0;
                }
            }
        }

        if ($tagType === self::AUDIO_TAG && strlen($payload) >= 1) {
            $firstByte = ord($payload[0]);
            $soundFormat = ($firstByte >> 4) & 0x0F;
            if ($soundFormat === self::SOUND_FORMAT_AAC && strlen($payload) >= 2) {
                $aacPacketType = ord($payload[1]);
                if ($aacPacketType === self::AAC_PACKET_TYPE_SEQUENCE_HEADER) {
                    // AAC sequence header，时间戳必须为0
                    return 0;
                }
            }
        }

        // 普通音视频帧，调整时间戳
        if ($tagType === self::VIDEO_TAG || $tagType === self::AUDIO_TAG) {
            // 设置基准时间戳（第一个普通音视频帧）
            if ($this->baseTimestamp === null) {
                $this->baseTimestamp = $originalTimestamp;
            }
            return max(0, $originalTimestamp - $this->baseTimestamp);
        }

        return $originalTimestamp;
    }

    protected function connect(): void
    {
        $urlParts = parse_url($this->pullUrl);
        $scheme = strtolower($urlParts['scheme'] ?? 'http');
        $host = $urlParts['host'] ?? '127.0.0.1';
        $port = $urlParts['port'] ?? ($scheme === 'https' ? 443 : ($scheme === 'wss' ? 443 : 8501));
        $path = $urlParts['path'] ?? '/';
        if (!empty($urlParts['query'])) {
            $path .= '?' . $urlParts['query'];
        }

        $this->log("连接到 {$host}:{$port}{$path}...");

        if ($this->isWebSocket) {
            $this->connectWebSocket($host, $port, $path, $scheme === 'wss');
        } else {
            $this->connectHttp($host, $port, $path);
        }
    }

    protected function connectHttp(string $host, int $port, string $path): void
    {
        $this->socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 10);
        if (!$this->socket) {
            throw new \RuntimeException("无法连接到 {$host}:{$port} - {$errstr}");
        }
        stream_set_timeout($this->socket, 10);

        $request = "GET {$path} HTTP/1.1\r\n";
        $request .= "Host: {$host}\r\n";
        $request .= "Accept: */*\r\n";
        $request .= "Connection: keep-alive\r\n";
        $request .= "\r\n";

        fwrite($this->socket, $request);

        // 读取HTTP响应头
        $header = '';
        $timeout = time() + 10;

        while (time() < $timeout) {
            $chunk = @fread($this->socket, 4096);
            if ($chunk === false) {
                throw new \RuntimeException("读取响应失败");
            }
            if ($chunk === '') {
                usleep(10000);
                continue;
            }

            $header .= $chunk;
            if (($pos = strpos($header, "\r\n\r\n")) !== false) {
                $headerStr = substr($header, 0, $pos);
                $bodyData = substr($header, $pos + 4);

                if (!preg_match('#^HTTP/\d\.\d 200#', $headerStr)) {
                    $this->safeCloseStream($this->socket);
                    $this->socket = null;
                    throw new \RuntimeException("服务器返回非200状态码");
                }

                $this->chunked = (stripos($headerStr, "Transfer-Encoding: chunked") !== false);
                $this->chunkBuffer = '';

                // 处理HTTP响应体中的初始数据
                if (strlen($bodyData) > 0) {
                    if ($this->chunked) {
                        $this->chunkBuffer = $bodyData;
                        $decoded = $this->decodeChunked($this->chunkBuffer);
                        if ($decoded !== null) {
                            $this->writeFlvData($decoded);
                        }
                    } else {
                        $this->writeFlvData($bodyData);
                    }
                }

                stream_set_blocking($this->socket, false);
                $this->log("HTTP响应状态: 200 OK");
                $this->log("上游连接成功，分块编码: " . ($this->chunked ? '是' : '否'));
                return;
            }
        }

        $this->safeCloseStream($this->socket);
        $this->socket = null;
        throw new \RuntimeException("上游响应超时");
    }

    protected function connectWebSocket(string $host, int $port, string $path, bool $ssl = false): void
    {
        $proto = $ssl ? 'ssl' : 'tcp';
        $this->socket = @stream_socket_client("{$proto}://{$host}:{$port}", $errno, $errstr, 10);
        if (!$this->socket) {
            throw new \RuntimeException("无法连接到 {$host}:{$port} - {$errstr}");
        }

        stream_set_timeout($this->socket, 10);

        $key = base64_encode(random_bytes(16));
        $handshake = "GET {$path} HTTP/1.1\r\n";
        $handshake .= "Host: {$host}:{$port}\r\n";
        $handshake .= "Upgrade: websocket\r\n";
        $handshake .= "Connection: Upgrade\r\n";
        $handshake .= "Sec-WebSocket-Key: {$key}\r\n";
        $handshake .= "Sec-WebSocket-Version: 13\r\n";
        $handshake .= "\r\n";

        fwrite($this->socket, $handshake);

        $response = fread($this->socket, 1024);
        if (!str_contains($response, '101 Switching Protocols')) {
            $this->safeCloseStream($this->socket);
            $this->socket = null;
            throw new \RuntimeException("WebSocket握手失败");
        }

        $this->log("WebSocket握手成功");
    }

    protected function receiveData(): ?string
    {
        if (!$this->socket) {
            return null;
        }

        if ($this->isWebSocket) {
            return $this->receiveWebSocketData();
        } else {
            return $this->receiveHttpData();
        }
    }

    protected function receiveWebSocketData(): ?string
    {
        $frame = @fread($this->socket, 2);
        if (!$frame || strlen($frame) < 2) {
            $info = stream_get_meta_data($this->socket);
            if (!empty($info['eof'])) {
                throw new \RuntimeException("WebSocket连接已关闭");
            }
            return null;
        }

        $firstByte = ord($frame[0]);
        $secondByte = ord($frame[1]);

        $opcode = $firstByte & 0x0F;
        $payloadLen = $secondByte & 0x7F;

        if ($opcode === 0x08) {
            throw new \RuntimeException("WebSocket连接关闭帧");
        }

        if ($opcode !== 0x01 && $opcode !== 0x02) {
            return null;
        }

        if ($payloadLen === 126) {
            $ext = @fread($this->socket, 2);
            if ($ext === false || strlen($ext) < 2) return null;
            $payloadLen = unpack('n', $ext)[1];
        } elseif ($payloadLen === 127) {
            $ext = @fread($this->socket, 8);
            if ($ext === false || strlen($ext) < 8) return null;
            $payloadLen = unpack('J', $ext)[1];
        }

        $data = '';
        while (strlen($data) < $payloadLen) {
            $chunk = @fread($this->socket, $payloadLen - strlen($data));
            if ($chunk === false) {
                $info = stream_get_meta_data($this->socket);
                if (!empty($info['eof'])) {
                    throw new \RuntimeException("WebSocket连接已关闭");
                }
                break;
            }
            $data .= $chunk;
        }

        return $data;
    }

    protected function receiveHttpData(): ?string
    {
        $data = @fread($this->socket, 65536);
        if ($data === false) {
            $info = stream_get_meta_data($this->socket);
            if (!empty($info['eof'])) {
                throw new \RuntimeException("HTTP连接已关闭");
            }
            return null;
        }

        if ($data === '') {
            $info = stream_get_meta_data($this->socket);
            if (!empty($info['eof'])) {
                throw new \RuntimeException("HTTP连接已关闭");
            }
            return null;
        }

        return $data;
    }

    protected function decodeChunked(string &$buf): ?string
    {
        $decoded = '';
        while (true) {
            $pos = strpos($buf, "\r\n");
            if ($pos === false) break;

            $sizeHex = trim(substr($buf, 0, $pos));
            if ($sizeHex === '') {
                $buf = substr($buf, $pos + 2);
                continue;
            }

            if (!ctype_xdigit($sizeHex)) {
                $buf = substr($buf, $pos + 2);
                continue;
            }

            $size = hexdec($sizeHex);
            if ($size === 0) {
                $buf = '';
                return $decoded;
            }

            $start = $pos + 2;
            $end = $start + $size + 2;

            if (strlen($buf) < $end) {
                break;
            }

            $decoded .= substr($buf, $start, $size);
            $buf = substr($buf, $end);
        }

        return $decoded === '' ? null : $decoded;
    }

    protected function disconnect(): void
    {
        $this->safeCloseStream($this->socket);
        $this->socket = null;
        $this->chunked = false;
        $this->chunkBuffer = '';
        $this->baseTimestamp = null; // 重置基准时间戳
    }

    protected function safeCloseStream(&$stream): void
    {
        if ($stream === null) return;
        if (is_resource($stream) && get_resource_type($stream) === 'stream') {
            @stream_socket_shutdown($stream, STREAM_SHUT_RDWR);
            @fclose($stream);
        }
        $stream = null;
    }

    protected function handleReconnect(): bool
    {
        if (!$this->autoReconnect) {
            return false;
        }

        $this->retryCount++;
        if ($this->retryCount > $this->maxRetries) {
            $this->log("已达到最大重试次数 {$this->maxRetries}", 'error');
            return false;
        }

        $this->log("{$this->retryDelay} 秒后进行第 {$this->retryCount} 次重试...");
        sleep($this->retryDelay);
        return true;
    }

    protected function log(string $message, string $level = 'info'): void
    {
        $time = date('Y-m-d H:i:s');
        echo "[{$time}] [{$level}] {$message}\n";
    }
}

class RtmpPullerClient extends FlvPullerClient
{
    public function __construct(string $pullUrl, string $outputFlv, int $duration = 0, bool $autoReconnect = true)
    {
        parent::__construct($pullUrl, $outputFlv, $duration, $autoReconnect);
    }

    protected function connect(): void
    {
        $urlParts = parse_url($this->pullUrl);
        $host = $urlParts['host'] ?? '127.0.0.1';
        $port = $urlParts['port'] ?? 1935;
        $path = $urlParts['path'] ?? '/live';
        $streamKey = basename($path);
        $app = dirname($path);
        if ($app === '/') $app = 'live';

        $this->log("RTMP连接到 {$host}:{$port}/{$app}/{$streamKey}...");

        $this->socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 10);
        if (!$this->socket) {
            throw new \RuntimeException("无法连接到 {$host}:{$port} - {$errstr}");
        }

        stream_set_timeout($this->socket, 30);

        $this->rtmpHandshake($app, $streamKey);
    }

    protected function rtmpHandshake(string $app, string $streamKey): void
    {
        // C0 + C1
        $c0 = chr(0x03);
        $time = pack('N', time());
        $zero = pack('N', 0);
        $random = random_bytes(1528);
        fwrite($this->socket, $c0 . $time . $zero . $random);

        // S0 + S1 + S2
        $s0 = @fread($this->socket, 1);
        $s1 = @fread($this->socket, 1536);
        $s2 = @fread($this->socket, 1536);

        if (strlen($s0) < 1 || strlen($s1) < 1536 || strlen($s2) < 1536) {
            $this->safeCloseStream($this->socket);
            $this->socket = null;
            throw new \RuntimeException("RTMP握手失败");
        }

        // C2
        fwrite($this->socket, $s1);

        $this->log("RTMP握手完成");
        $this->sendRtmpPlayCommand($app, $streamKey);
    }

    protected function sendRtmpPlayCommand(string $app, string $streamKey): void
    {
        // 简化的RTMP play命令
        $command = "\x03\x00\x00\x00\x00";
        $command .= "\x00\x00\x00\x00";
        $command .= "\x02\x00\x04play";
        $command .= "\x00\x00\x00\x00";
        $command .= "\x02\x00" . chr(strlen($streamKey)) . $streamKey;
        $command .= "\x00\x00\x00\x00";

        fwrite($this->socket, $command);
        $this->log("RTMP播放命令已发送: {$streamKey}");
    }
}