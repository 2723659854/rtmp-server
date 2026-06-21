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

        if ($offset < $dataLen && substr($data, $offset, 3) === 'FLV') {
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

        while ($offset + 11 < $dataLen) {
            $tagType = ord($data[$offset]);
            $dataSize = ((ord($data[$offset + 1]) << 16) | (ord($data[$offset + 2]) << 8) | ord($data[$offset + 3]));
            $timestamp = ((ord($data[$offset + 4]) << 16) | (ord($data[$offset + 5]) << 8) | ord($data[$offset + 6]));
            $timestampExt = ord($data[$offset + 7]);
            $fullTimestamp = ($timestampExt << 24) | $timestamp;

            $tagTotalSize = 11 + $dataSize + 4;

            if ($offset + $tagTotalSize > $dataLen) {
                break;
            }

            $tagHeader = substr($data, $offset, 11);
            $tagPayload = substr($data, $offset + 11, $dataSize);
            $prevTagSize = substr($data, $offset + 11 + $dataSize, 4);

            $adjustedTimestamp = $this->calculateAdjustedTimestamp($tagType, $fullTimestamp, $tagPayload);

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

        if ($offset < $dataLen) {
            $result .= substr($data, $offset);
        }

        return $result;
    }

    protected function calculateAdjustedTimestamp(int $tagType, int $originalTimestamp, string $payload): int
    {
        if ($tagType === self::SCRIPT_TAG) {
            return 0;
        }

        if ($tagType === self::VIDEO_TAG && strlen($payload) >= 1) {
            $firstByte = ord($payload[0]);
            $codecId = $firstByte & 0x0F;
            if ($codecId === self::VIDEO_CODEC_ID_AVC && strlen($payload) >= 5) {
                $avcPacketType = ord($payload[1]);
                if ($avcPacketType === self::AVC_PACKET_TYPE_SEQUENCE_HEADER) {
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
                    return 0;
                }
            }
        }

        if ($tagType === self::VIDEO_TAG || $tagType === self::AUDIO_TAG) {
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
        $this->baseTimestamp = null;
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
    const RTMP_SIG_SIZE = 1536;

    protected $socket = null;
    protected int $chunkSizeR = 128;
    protected int $chunkSizeW = 128;
    protected array $prevReadingPacket = [];
    protected array $prevSendingPacket = [];
    protected int $streamId = 0;
    protected bool $flvHeaderWritten = false;
    protected array $readingOperations = [];
    protected array $cachedPackets = []; // 缓存被listenForResponse消费的音视频数据
    protected int $windowAckSize = 2500000; // 窗口确认大小
    protected int $bytesReceived = 0; // 已接收的字节数
    protected int $bytesSinceLastAck = 0; // 自上次ACK以来接收的字节数
    protected int $ackThreshold = 10000; // ACK阈值（每收到10KB发送一次ACK）

    public function __construct(string $pullUrl, string $outputFlv, int $duration = 0, bool $autoReconnect = true)
    {
        parent::__construct($pullUrl, $outputFlv, $duration, $autoReconnect);
    }

    protected function connect(): void
    {
        $urlParts = parse_url($this->pullUrl);
        $host = $urlParts['host'] ?? '127.0.0.1';
        $port = $urlParts['port'] ?? 1935;
        $path = $urlParts['path'] ?? '/live/stream';

        $pathParts = explode('/', trim($path, '/'));
        $app = $pathParts[0] ?? 'live';
        $streamKey = $pathParts[1] ?? 'stream';

        $this->log("RTMP连接到 {$host}:{$port}/{$app}/{$streamKey}...");

        $this->socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 10);
        if (!$this->socket) {
            throw new \RuntimeException("无法连接到 {$host}:{$port} - {$errstr}");
        }

        // 重置状态
        $this->cachedPackets = [];
        $this->prevReadingPacket = [];
        $this->readingOperations = [];
        $this->chunkSizeR = 128;
        $this->streamId = 0;
        $this->bytesReceived = 0;
        $this->bytesSinceLastAck = 0;
        $this->windowAckSize = 2500000;

        // 保持阻塞模式，使用stream_select来等待数据
        stream_set_timeout($this->socket, 30);

        $this->handshake($host, $port);
        
        // 发送Window Ack Size
        $this->sendWindowAckSize($this->windowAckSize);

        $this->sendConnect($app);
        $this->sendCreateStream();
        $this->sendPlay($streamKey);

        $this->log("RTMP播放命令已发送: {$streamKey}");
        $this->log("缓存包数量: " . count($this->cachedPackets), 'debug');
    }

    protected function handshake(string $host, int $port): void
    {
        $stream = new \Xiaosongshu\Flv2mp4\SabreAMF\RtmpStream();

        $stream->writeByte(0x03);

        $ctime = time();
        $stream->writeInt32($ctime);
        $stream->write("\x80\x00\x03\x02");

        $crandom = '';
        for ($i = 0; $i < self::RTMP_SIG_SIZE - 8; $i++) {
            $crandom .= chr(rand(0, 255));
        }
        $stream->write($crandom);
        fwrite($this->socket, $stream->flush());

        $s0 = @fread($this->socket, 1);
        $s1 = @fread($this->socket, self::RTMP_SIG_SIZE);
        $s2 = @fread($this->socket, self::RTMP_SIG_SIZE);

        if (strlen($s0) < 1 || strlen($s1) < self::RTMP_SIG_SIZE || strlen($s2) < self::RTMP_SIG_SIZE) {
            $this->safeCloseStream($this->socket);
            $this->socket = null;
            throw new \RuntimeException("RTMP握手失败");
        }

        $s1Stream = new \Xiaosongshu\Flv2mp4\SabreAMF\RtmpStream($s1);
        $s1Time = $s1Stream->readInt32();
        $s1Stream->readInt32();
        $s1Raw = $s1Stream->readRaw();

        $c2 = new \Xiaosongshu\Flv2mp4\SabreAMF\RtmpStream();
        $c2->writeInt32($s1Time);
        $c2->writeInt32($ctime);
        $c2->write($s1Raw);
        fwrite($this->socket, $c2->flush());

        $this->log("RTMP握手完成");
    }

    protected function sendConnect(string $app): void
    {
        $connectObj = (object)[
            'app' => $app,
            'flashVer' => 'LNX 10,0,32,18',
            'swfUrl' => null,
            'tcUrl' => 'rtmp://' . $this->getHost() . ':' . $this->getPort() . '/' . $app,
            'fpad' => false,
            'capabilities' => 0.0,
            'audioCodecs' => 0x01,
            'videoCodecs' => 0xFF,
            'videoFunction' => 0,
            'pageUrl' => null,
            'objectEncoding' => 0x03
        ];

        $message = new \Xiaosongshu\Flv2mp4\SabreAMF\RtmpMessage('connect', $connectObj);
        $message->encode();
        $packet = $message->getPacket();
        $this->sendPacket($packet);

        $this->log("发送connect命令: {$app}");
        // 不调用listenForResponse，避免状态混乱
        // 在receiveData()中处理所有响应和数据
    }

    protected function sendCreateStream(): void
    {
        $message = new \Xiaosongshu\Flv2mp4\SabreAMF\RtmpMessage('createStream', null);
        $message->encode();
        $packet = $message->getPacket();
        $packet->streamId = 0;
        $this->sendPacket($packet);

        $this->log("发送createStream命令");
        // 不调用listenForResponse，避免状态混乱
        // streamId会在receiveData()中通过解析响应获得
    }

    protected function sendPlay(string $streamKey): void
    {
        $message = new \Xiaosongshu\Flv2mp4\SabreAMF\RtmpMessage('play', null, [$streamKey]);
        $message->encode();
        $packet = $message->getPacket();
        $packet->streamId = $this->streamId;
        $this->sendPacket($packet);

        $this->log("发送play命令: {$streamKey}");
        // 不调用listenForResponse，直接进入数据接收循环
        // 服务器会立即开始发送音视频数据
    }

    protected function sendPacket(\Xiaosongshu\Flv2mp4\SabreAMF\RtmpPacket $packet): void
    {
        if (!$packet->length) {
            $packet->length = strlen($packet->payload);
        }

        if (isset($this->prevSendingPacket[$packet->chunkStreamId])) {
            if ($packet->length == $this->prevSendingPacket[$packet->chunkStreamId]->length) {
                $packet->chunkType = \Xiaosongshu\Flv2mp4\SabreAMF\RtmpPacket::CHUNK_TYPE_2;
            } else {
                $packet->chunkType = \Xiaosongshu\Flv2mp4\SabreAMF\RtmpPacket::CHUNK_TYPE_1;
            }
        } else {
            $packet->chunkType = \Xiaosongshu\Flv2mp4\SabreAMF\RtmpPacket::CHUNK_TYPE_0;
        }

        $this->prevSendingPacket[$packet->chunkStreamId] = $packet;

        $headerSize = \Xiaosongshu\Flv2mp4\SabreAMF\RtmpPacket::$SIZES[$packet->chunkType];
        $header = new \Xiaosongshu\Flv2mp4\SabreAMF\RtmpStream();
        $header->writeByte($packet->chunkType << 6 | $packet->chunkStreamId);

        if ($headerSize > 1) {
            $packet->timestamp = time();
            $header->writeInt24($packet->timestamp);
        }

        if ($headerSize > 4) {
            $header->writeInt24($packet->length);
            $header->writeByte($packet->type);
        }

        if ($headerSize > 8) {
            $header->writeInt32LE($packet->streamId);
        }

        fwrite($this->socket, $header->flush());

        $buffer = $packet->payload;
        $bufferLen = strlen($buffer);
        $offset = 0;

        while ($offset < $bufferLen) {
            $chunkSize = $packet->type == \Xiaosongshu\Flv2mp4\SabreAMF\RtmpPacket::TYPE_INVOKE_AMF0 ||
                         $packet->type == \Xiaosongshu\Flv2mp4\SabreAMF\RtmpPacket::TYPE_INVOKE_AMF3
                         ? $this->chunkSizeW : $packet->length;
            if ($bufferLen - $offset < $this->chunkSizeW) {
                $chunkSize = $bufferLen - $offset;
            }

            fwrite($this->socket, substr($buffer, $offset, $chunkSize));
            $offset += $chunkSize;

            if ($offset < $bufferLen) {
                fwrite($this->socket, chr(0xC0 | $packet->chunkStreamId));
            }
        }
    }

    protected function listenForResponse(int $chunkStreamId)
    {
        $timeout = time() + 5;
        while (time() < $timeout) {
            $p = $this->readPacket();
            if (!$p) {
                usleep(10000);
                continue;
            }

            switch ($p['type']) {
                case 0x01:
                    $s = new \Xiaosongshu\Flv2mp4\SabreAMF\RtmpStream($p['payload']);
                    $this->chunkSizeR = $s->readInt32();
                    $this->log("设置chunkSizeR=" . $this->chunkSizeR, 'debug');
                    break;
                case 0x03:
                    break;
                case 0x04:
                    $this->handlePing($p);
                    break;
                case 0x05:
                    break;
                case 0x06:
                    break;
                case 0x14:
                    // 收到响应，清理readingOperations的状态，避免后续readPacket()读取位置错误
                    // 但是，socket中可能还有部分数据未被读取（不完整的chunk），需要丢弃
                    $this->readingOperations = [];
                    $this->prevReadingPacket = [];
                    $this->log("收到响应，清理readingOperations和prevReadingPacket", 'debug');
                    return $this->decodeInvoke($p['payload']);
                case 0x12:
                    // 缓存元数据
                    $this->cachedPackets[] = $p;
                    $this->log("缓存元数据包: type=0x12, length=" . strlen($p['payload']), 'debug');
                    break;
                case 0x08:
                case 0x09:
                    // 缓存音视频数据
                    $this->cachedPackets[] = $p;
                    $this->log("缓存音视频包: type=0x" . dechex($p['type']) . ", length=" . strlen($p['payload']), 'debug');
                    break;
                default:
                    $this->log("未知包类型: type=0x" . dechex($p['type']), 'debug');
                    break;
            }
            usleep(1000);
        }
        // 超时返回，也要清理状态
        $this->readingOperations = [];
        return null;
    }

    protected function handlePing(array $p): void
    {
        $pingPayload = $p['payload'];
        if (strlen($pingPayload) >= 8) {
            $eventType = ord($pingPayload[0]) | (ord($pingPayload[1]) << 8);
            if ($eventType === 0x0006) {
                $timestamp = substr($pingPayload, 2, 4);
                $response = chr(0x00) . chr(0x07) . $timestamp;
                $this->sendUserControlMessage($response);
            }
        }
    }

    protected function sendUserControlMessage(string $payload): void
    {
        $packet = new \Xiaosongshu\Flv2mp4\SabreAMF\RtmpPacket();
        $packet->chunkStreamId = 2;
        $packet->streamId = $this->streamId;
        $packet->type = 0x04;
        $packet->length = strlen($payload);
        $packet->payload = $payload;
        $packet->chunkType = \Xiaosongshu\Flv2mp4\SabreAMF\RtmpPacket::CHUNK_TYPE_0;
        $this->sendPacket($packet);
    }

    protected function sendAck(int $bytesReceived): void
    {
        $payload = chr(($bytesReceived >> 24) & 0xFF) .
                   chr(($bytesReceived >> 16) & 0xFF) .
                   chr(($bytesReceived >> 8) & 0xFF) .
                   chr($bytesReceived & 0xFF);
        
        $packet = new \Xiaosongshu\Flv2mp4\SabreAMF\RtmpPacket();
        $packet->chunkStreamId = 2;
        $packet->streamId = 0;
        $packet->type = 0x03; // Acknowledgement
        $packet->length = 4;
        $packet->payload = $payload;
        $packet->chunkType = \Xiaosongshu\Flv2mp4\SabreAMF\RtmpPacket::CHUNK_TYPE_0;
        $this->sendPacket($packet);
        $this->log("发送ACK: {$bytesReceived} bytes", 'debug');
    }

    protected function sendWindowAckSize(int $size): void
    {
        $payload = chr(($size >> 24) & 0xFF) .
                   chr(($size >> 16) & 0xFF) .
                   chr(($size >> 8) & 0xFF) .
                   chr($size & 0xFF);
        
        $packet = new \Xiaosongshu\Flv2mp4\SabreAMF\RtmpPacket();
        $packet->chunkStreamId = 2;
        $packet->streamId = 0;
        $packet->type = 0x05; // Window Acknowledgement Size
        $packet->length = 4;
        $packet->payload = $payload;
        $packet->chunkType = \Xiaosongshu\Flv2mp4\SabreAMF\RtmpPacket::CHUNK_TYPE_0;
        $this->sendPacket($packet);
        $this->log("发送Window Ack Size: {$size}", 'debug');
    }

    protected function decodeInvoke(string $payload)
    {
        $stream = new \Xiaosongshu\Flv2mp4\SabreAMF\SabreAMF_InputStream($payload);
        $deserializer = new \Xiaosongshu\Flv2mp4\SabreAMF\AMF0\SabreAMF_AMF0_Deserializer($stream);

        $command = $deserializer->readAMFData();
        $transId = $deserializer->readAMFData();
        $cmdObj = $deserializer->readAMFData();

        $args = [];
        try {
            $args = $deserializer->readAMFData();
        } catch (\Exception $e) {
            $args = null;
        }

        return is_array($args) ? $args : [];
    }

    protected function readPacket()
    {
        if (!$this->socket) return null;

        $header = @fread($this->socket, 1);
        if ($header === false || strlen($header) < 1) {
            return null;
        }

        $firstByte = ord($header[0]);
        $chunkType = (($firstByte & 0xc0) >> 6);
        $chunkStreamId = $firstByte & 0x3f;

        // 解析chunk stream id
        switch ($chunkStreamId) {
            case 0:
                $secondByte = @fread($this->socket, 1);
                if ($secondByte === false || strlen($secondByte) < 1) return null;
                $chunkStreamId = 64 + ord($secondByte[0]);
                break;
            case 1:
                $secondByte = @fread($this->socket, 1);
                $thirdByte = @fread($this->socket, 1);
                if ($secondByte === false || strlen($secondByte) < 1 || $thirdByte === false || strlen($thirdByte) < 1) return null;
                $chunkStreamId = 64 + ord($secondByte[0]) + ord($thirdByte[0]) * 256;
                break;
        }

        // 检查是否有正在读取的消息（用于chunk合并）
        if (!isset($this->readingOperations[$chunkStreamId])) {
            $this->readingOperations[$chunkStreamId] = [
                'bytesRead' => 0,
                'length' => 0,
                'type' => 0,
                'streamId' => 0,
                'timestamp' => 0,
                'payload' => '',
                'deltaTimestamp' => 0 // 用于CHUNK_TYPE_3的时间戳增量
            ];
        }

        $op = &$this->readingOperations[$chunkStreamId];

        // 判断是否是后续chunk（正在读取的消息未完成）
        // CHUNK_TYPE_3表示这是后续chunk，无论前一个消息是否完整
        $isContinuation = ($chunkType == \Xiaosongshu\Flv2mp4\SabreAMF\RtmpPacket::CHUNK_TYPE_3 && 
                          $op['bytesRead'] > 0 && $op['length'] > 0 && $op['bytesRead'] < $op['length']);

        $this->log("chunkType={$chunkType}, csid={$chunkStreamId}, isContinuation=" . ($isContinuation ? 'true' : 'false') . ", bytesRead={$op['bytesRead']}, length={$op['length']}, prevReadingPacket=" . (isset($this->prevReadingPacket[$chunkStreamId]) ? 'yes' : 'no'), 'debug');

        if ($isContinuation) {
            // 这是后续chunk，不读取任何header数据，直接读取payload
            $timestamp = $op['timestamp'];
            $length = $op['length'];
            $type = $op['type'];
            $streamId = $op['streamId'];
            $bytesRead = $op['bytesRead'];
            $payload = $op['payload'];
        } else {
            // 这是新消息的第一个chunk，需要读取header

            // 从prevReadingPacket继承信息（用于CHUNK_TYPE_1/2/3）
            $timestamp = 0;
            $length = 0;
            $type = 0;
            $streamId = 0;
            
            // CHUNK_TYPE_3必须从prevReadingPacket继承信息
            // 如果没有继承信息，说明这是一个无效的包，跳过
            if ($chunkType == \Xiaosongshu\Flv2mp4\SabreAMF\RtmpPacket::CHUNK_TYPE_3) {
                if (!isset($this->prevReadingPacket[$chunkStreamId])) {
                    $this->log("CHUNK_TYPE_3没有继承信息，跳过: csid={$chunkStreamId}", 'debug');
                    // 尝试读取1字节的时间戳增量（如果有的话），然后跳过
                    // CHUNK_TYPE_3的header大小是1字节，已经读取了chunkType，所以不需要再读取
                    return null;
                }
            }
            
            if (isset($this->prevReadingPacket[$chunkStreamId])) {
                $prev = $this->prevReadingPacket[$chunkStreamId];
                switch ($chunkType) {
                    case \Xiaosongshu\Flv2mp4\SabreAMF\RtmpPacket::CHUNK_TYPE_3:
                        // CHUNK_TYPE_3: 只有1字节头（可能有时间戳增量），继承所有信息
                        $timestamp = $prev['timestamp'];
                        $length = $prev['length'];
                        $type = $prev['type'];
                        $streamId = $prev['streamId'];
                        break;
                    case \Xiaosongshu\Flv2mp4\SabreAMF\RtmpPacket::CHUNK_TYPE_2:
                        // CHUNK_TYPE_2: 有3字节头（timestamp），继承length/type/streamId
                        $length = $prev['length'];
                        $type = $prev['type'];
                        $streamId = $prev['streamId'];
                        break;
                    case \Xiaosongshu\Flv2mp4\SabreAMF\RtmpPacket::CHUNK_TYPE_1:
                        // CHUNK_TYPE_1: 有7字节头（timestamp+length+type），继承streamId
                        $streamId = $prev['streamId'];
                        break;
                    case \Xiaosongshu\Flv2mp4\SabreAMF\RtmpPacket::CHUNK_TYPE_0:
                        // CHUNK_TYPE_0: 有完整头，不继承任何信息
                        break;
                }
            }

            // 读取header
            $headerSize = \Xiaosongshu\Flv2mp4\SabreAMF\RtmpPacket::$SIZES[$chunkType];
            $headerSize--;

            if ($headerSize > 0) {
                $headerData = @fread($this->socket, $headerSize);
                if ($headerData === false || strlen($headerData) < $headerSize) {
                    $this->log("读取header失败: expected={$headerSize}, got=" . strlen($headerData), 'debug');
                    return null;
                }

                $this->log("原始header数据: " . bin2hex($headerData), 'debug');

                $offset = 0;
                if ($headerSize >= 3) {
                    $timestamp = (ord($headerData[$offset++]) << 16) | 
                                (ord($headerData[$offset++]) << 8) | 
                                ord($headerData[$offset++]);

                    if ($timestamp === 0xFFFFFF) {
                        if ($offset + 4 <= $headerSize) {
                            $extTimestamp = (ord($headerData[$offset++]) << 24) | 
                                            (ord($headerData[$offset++]) << 16) | 
                                            (ord($headerData[$offset++]) << 8) | 
                                            ord($headerData[$offset++]);
                            $timestamp = $extTimestamp;
                        }
                    }
                }
                if ($headerSize >= 6) {
                    $length = (ord($headerData[$offset++]) << 16) | 
                             (ord($headerData[$offset++]) << 8) | 
                             ord($headerData[$offset++]);
                }
                if ($headerSize > 6) {
                    $type = ord($headerData[$offset++]);
                }
                if ($headerSize == 11) {
                    $streamId = ord($headerData[$offset]) | 
                               (ord($headerData[$offset+1]) << 8) | 
                               (ord($headerData[$offset+2]) << 16) | 
                               (ord($headerData[$offset+3]) << 24);
                }
            }

            $this->log("读取header后: type=0x" . dechex($type) . ", length={$length}, timestamp={$timestamp}, streamId={$streamId}", 'debug');

            $bytesRead = 0;
            $payload = '';
            
            // 只有在chunkType=0时才保存新的prevReadingPacket
            // CHUNK_TYPE_1/2/3应该继承之前的值，不应该覆盖
            if ($chunkType == \Xiaosongshu\Flv2mp4\SabreAMF\RtmpPacket::CHUNK_TYPE_0) {
                $this->prevReadingPacket[$chunkStreamId] = [
                    'timestamp' => $timestamp,
                    'length' => $length,
                    'type' => $type,
                    'streamId' => $streamId
                ];
                $this->log("保存prevReadingPacket: csid={$chunkStreamId}, type=0x" . dechex($type) . ", length={$length}", 'debug');
            }

            // 保存新消息的信息到readingOperations
            $op['length'] = $length;
            $op['type'] = $type;
            $op['streamId'] = $streamId;
            $op['timestamp'] = $timestamp;
            $op['payload'] = '';
            $op['bytesRead'] = 0;
        }

        // 计算需要读取的数据量
        $nToRead = $length - $bytesRead;
        $nChunk = $this->chunkSizeR;
        if ($nToRead < $nChunk) {
            $nChunk = $nToRead;
        }

        if ($nChunk > 0) {
            // 循环读取完整的chunk数据
            $chunk = '';
            $remaining = $nChunk;
            $attempts = 0;
            $maxAttempts = 100; // 最大尝试次数，防止死循环
            
            while (strlen($chunk) < $nChunk && $attempts < $maxAttempts) {
                $data = @fread($this->socket, $remaining);
                if ($data === false) {
                    $this->log("读取payload失败: expected={$nChunk}, got=" . strlen($chunk) . ", bytesRead={$bytesRead}, length={$length}", 'error');
                    return null;
                }
                if ($data === '') {
                    // 没有数据可读，等待一下
                    usleep(1000); // 1ms
                    $attempts++;
                    continue;
                }
                $chunk .= $data;
                $remaining -= strlen($data);
                $attempts = 0; // 重置尝试次数
                
                // 如果读取的数据小于期望，但大于0，说明可能数据已经全部到达
                // 继续尝试读取剩余数据
            }
            
            // 如果读取的数据不足，但已经读取了部分数据，也返回这部分数据
            // 这样可以处理服务器发送的数据小于chunkSize的情况
            if (strlen($chunk) > 0) {
                $payload .= $chunk;
                $bytesRead += strlen($chunk);
                $op['payload'] = $payload;
                $op['bytesRead'] = $bytesRead;
                
                $this->log("读取payload: expected={$nChunk}, got=" . strlen($chunk) . ", total={$bytesRead}/{$length}", 'debug');
                
                // 统计接收的字节数
                $this->bytesReceived += strlen($chunk);
                $this->bytesSinceLastAck += strlen($chunk);
                
                // 如果接收的字节数超过阈值，发送ACK
                if ($this->bytesSinceLastAck >= $this->ackThreshold) {
                    $this->sendAck($this->bytesReceived);
                    $this->bytesSinceLastAck = 0;
                }
            } else {
                $this->log("读取payload失败: expected={$nChunk}, got=0, bytesRead={$bytesRead}, length={$length}", 'error');
                return null;
            }
        }

        // 检查消息是否完整
        if ($bytesRead >= $length && $length > 0) {
            // 消息完整，保存到prevReadingPacket（用于下一个消息的继承）
            $this->prevReadingPacket[$chunkStreamId] = [
                'timestamp' => $timestamp,
                'length' => $length,
                'type' => $type,
                'streamId' => $streamId
            ];

            // 重置readingOperations
            $op['bytesRead'] = 0;
            $op['payload'] = '';

            return [
                'chunkStreamId' => $chunkStreamId,
                'type' => $type,
                'streamId' => $streamId,
                'timestamp' => $timestamp,
                'payload' => $payload
            ];
        }

        return null;
    }

    protected function receiveData(): ?string
    {
        if (!$this->socket) return null;

        // 首先处理缓存的数据
        while (!empty($this->cachedPackets)) {
            $p = array_shift($this->cachedPackets);
            $this->log("处理缓存包: type=0x" . dechex($p['type']) . ", length=" . strlen($p['payload']), 'debug');
            
            switch ($p['type']) {
                case 0x08:
                case 0x09:
                    // 收到音视频数据，立即发送ACK
                    if ($this->bytesReceived > 0) {
                        $this->sendAck($this->bytesReceived);
                    }
                    return $this->rtmpToFlvTag($p['type'], $p['timestamp'], $p['payload']);
                case 0x12:
                    return $this->rtmpToFlvTag(18, $p['timestamp'], $p['payload']);
                default:
                    break;
            }
        }

        // 阻塞模式读取数据
        while (true) {
            $p = $this->readPacket();
            if (!$p) return null;

            $this->log("收到RTMP包: type=0x" . dechex($p['type']) . ", csid=" . $p['chunkStreamId'] . ", length=" . strlen($p['payload']) . ", timestamp=" . $p['timestamp'], 'debug');

            switch ($p['type']) {
                case 0x08:
                case 0x09:
                    // 收到音视频数据，立即发送ACK
                    $this->sendAck($this->bytesReceived);
                    return $this->rtmpToFlvTag($p['type'], $p['timestamp'], $p['payload']);
                case 0x12:
                    return $this->rtmpToFlvTag(18, $p['timestamp'], $p['payload']);
                case 0x01:
                    $s = new \Xiaosongshu\Flv2mp4\SabreAMF\RtmpStream($p['payload']);
                    $this->chunkSizeR = $s->readInt32();
                    $this->log("设置chunkSizeR=" . $this->chunkSizeR, 'debug');
                    break;
                case 0x03:
                    break;
                case 0x04:
                    $this->handlePing($p);
                    break;
                case 0x05:
                    // Window Acknowledgement Size
                    if (strlen($p['payload']) >= 4) {
                        $this->windowAckSize = (ord($p['payload'][0]) << 24) |
                                               (ord($p['payload'][1]) << 16) |
                                               (ord($p['payload'][2]) << 8) |
                                               ord($p['payload'][3]);
                        $this->log("收到Window Ack Size: {$this->windowAckSize}", 'debug');
                        // 发送确认
                        $this->sendWindowAckSize($this->windowAckSize);
                    }
                    break;
                case 0x06:
                    // Set Peer Bandwidth
                    if (strlen($p['payload']) >= 5) {
                        $bandwidth = (ord($p['payload'][0]) << 24) |
                                    (ord($p['payload'][1]) << 16) |
                                    (ord($p['payload'][2]) << 8) |
                                    ord($p['payload'][3]);
                        $limitType = ord($p['payload'][4]);
                        $this->log("收到Peer Bandwidth: {$bandwidth}, limitType={$limitType}", 'debug');
                        // 发送Window Ack Size确认
                        $this->sendWindowAckSize($this->windowAckSize);
                    }
                    break;
                case 0x14:
                    // 处理Invoke命令响应
                    $response = $this->decodeInvoke($p['payload']);
                    if (is_array($response) && isset($response['cmd'])) {
                        $cmd = $response['cmd'];
                        $this->log("收到Invoke响应: cmd={$cmd}", 'debug');
                        if ($cmd === '_result' && isset($response['data'][0])) {
                            // createStream响应，获取streamId
                            $this->streamId = (int)$response['data'][0];
                            $this->log("创建流成功，streamId: {$this->streamId}");
                        }
                    }
                    break;
                default:
                    break;
            }
        }
    }

    protected function rtmpToFlvTag(int $tagType, int $timestamp, string $payload): string
    {
        $dataSize = strlen($payload);

        $tsLow = $timestamp & 0xFFFFFF;
        $tsHigh = ($timestamp >> 24) & 0xFF;

        $tag = chr($tagType);
        $tag .= chr(($dataSize >> 16) & 0xFF);
        $tag .= chr(($dataSize >> 8) & 0xFF);
        $tag .= chr($dataSize & 0xFF);
        $tag .= chr(($tsLow >> 16) & 0xFF);
        $tag .= chr(($tsLow >> 8) & 0xFF);
        $tag .= chr($tsLow & 0xFF);
        $tag .= chr($tsHigh);
        $tag .= "\x00\x00\x00";
        $tag .= $payload;

        $prevTagSize = 11 + $dataSize;
        $tag .= chr(($prevTagSize >> 24) & 0xFF);
        $tag .= chr(($prevTagSize >> 16) & 0xFF);
        $tag .= chr(($prevTagSize >> 8) & 0xFF);
        $tag .= chr($prevTagSize & 0xFF);

        return $tag;
    }

    protected function writeFlvData(string $data): void
    {
        if (!$this->flvHeaderWritten) {
            $flvHeader = 'FLV' . chr(1) . chr(5) . "\x00\x00\x00\x09" . "\x00\x00\x00\x00";
            fwrite($this->fileHandle, $flvHeader);
            $this->flvHeaderWritten = true;
            $this->bytesWritten += 13;

            if ($this->startTime === null) {
                $this->startTime = time();
                $this->log("开始计时，将录制 {$this->duration} 秒");
            }
        }

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

    protected function processData(string $data): void
    {
        $this->writeFlvData($data);
    }

    protected function disconnect(): void
    {
        $this->safeCloseStream($this->socket);
        $this->socket = null;
        $this->prevReadingPacket = [];
        $this->prevSendingPacket = [];
        $this->readingOperations = [];
        $this->baseTimestamp = null;
        $this->flvHeaderWritten = false;
    }

    protected function getHost(): string
    {
        $urlParts = parse_url($this->pullUrl);
        return $urlParts['host'] ?? '127.0.0.1';
    }

    protected function getPort(): int
    {
        $urlParts = parse_url($this->pullUrl);
        return $urlParts['port'] ?? 1935;
    }
}