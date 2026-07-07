<?php

namespace MediaServer\Flv;

/**
 * @purpose 推流客户端
 * @author yanglong
 * @note 微型推流客户端，使用ws-flv协议
 */
class FlvSinglePusher
{
    /** 节目地址 */
    protected $playPath;

    /** 推流地址 */
    protected $pushUrl;

    /** 客户端socket连接 */
    protected $socket;

    /** 是否是websocket链接 */
    protected $isWebSocket = false;

    /** ws加密key */
    protected $wsKey = '';

    /** ws路径，链接地址 */
    protected $wsPath = '/';

    /** 是否已关闭 */
    protected $closed = false;

    /** 发送数据暂存区 */
    protected $sendBuffer = '';

    /** 发送暂存区大小 */
    protected $sendBufferSize = 0;

    /** 暂存区最大容量 */
    protected $maxBufferSize = 10485760;

    /** 最近一次清空暂存区时间 */
    protected $lastFlushTime = 0;

    /**
     * 初始化
     * @param string $playPath 节目地址
     * @param string $pushUrl 目标服务器地址
     */
    public function __construct(string $playPath, string $pushUrl)
    {
        $this->playPath = $playPath;
        $this->pushUrl = $pushUrl;

        $urlParts = parse_url($pushUrl);
        $scheme = strtolower($urlParts['scheme'] ?? 'http');
        $this->isWebSocket = ($scheme === 'ws' || $scheme === 'wss');
        $this->wsPath = $urlParts['path'] ?? '/';
        if (!empty($urlParts['query'])) {
            $this->wsPath .= '?' . $urlParts['query'];
        }
    }

    /**
     * 连接目标服务器
     * @return bool
     */
    public function connect()
    {
        $urlParts = parse_url($this->pushUrl);
        $host = $urlParts['host'];
        $port = $urlParts['port'] ?? ($this->isWebSocket ? 8501 : 8501);

        $protocolName = $this->isWebSocket ? 'WebSocket-FLV' : 'HTTP-FLV';
        logger()->info("flv pusher connecting {$protocolName} server: {$host}:{$port}");

        $this->socket = @fsockopen($host, $port, $errno, $errstr, 10);
        if (!$this->socket) {
            logger()->error("flv pusher socket connect failed: {$errstr} ({$errno})");
            return false;
        }

        // 握手阶段设置合理的超时（3秒），确保能读取服务器响应
        stream_set_timeout($this->socket, 3);

        if ($this->isWebSocket) {
            $result = $this->webSocketHandshake($host, $port);
        } else {
            $result = $this->httpConnect($host);
        }

        // 握手完成后切换到非阻塞模式，用于后续流式数据传输
        if ($result) {
            stream_set_blocking($this->socket, false);
        }

        return $result;
    }

    /**
     * http-flv握手
     * @param $host
     * @return bool
     */
    protected function httpConnect($host)
    {
        $path = $this->wsPath;
        $request = "POST {$path} HTTP/1.1\r\n";
        $request .= "Host: {$host}\r\n";
        $request .= "Content-Type: video/x-flv\r\n";
        $request .= "Connection: keep-alive\r\n";
        $request .= "Transfer-Encoding: chunked\r\n";
        $request .= "\r\n";

        $result = fwrite($this->socket, $request);
        if ($result === false) {
            logger()->error('flv pusher send http request failed');
            return false;
        }

        $response = '';
        $headersEnded = false;
        /** 设置3秒时间等待对方服务器响应，若超过3秒没有响应，则认为对方服务器完蛋 */
        $timeout = time() + 3;
        while (time() < $timeout && !feof($this->socket)) {
            $line = fgets($this->socket);
            if ($line === false) break;
            $response .= $line;
            if (trim($line) === '') {
                $headersEnded = true;
                break;
            }
        }

        if (!$headersEnded) {
            logger()->error('flv pusher read server response failed');
            return false;
        }

        $firstLine = strtok($response, "\r\n");
        logger()->debug('flv pusher server response: ' . $firstLine);

        if (strpos($firstLine, '200') === false) {
            logger()->error('flv pusher server non-200 status: ' . $firstLine);
            return false;
        }

        logger()->info('flv pusher http connect success');
        return true;
    }

    /**
     * ws-flv握手
     * @param $host
     * @param $port
     * @return bool
     * @throws \Random\RandomException
     */
    protected function webSocketHandshake($host, $port)
    {
        $this->wsKey = base64_encode(random_bytes(16));

        $handshake = "GET {$this->wsPath} HTTP/1.1\r\n";
        $handshake .= "Host: {$host}:{$port}\r\n";
        $handshake .= "Connection: Upgrade\r\n";
        $handshake .= "Pragma: no-cache\r\n";
        $handshake .= "Cache-Control: no-cache\r\n";
        $handshake .= "User-Agent: FlvPusher/1.0\r\n";
        $handshake .= "Upgrade: websocket\r\n";
        $handshake .= "Origin: http://{$host}:{$port}\r\n";
        $handshake .= "Sec-WebSocket-Version: 13\r\n";
        $handshake .= "Accept-Encoding: gzip, deflate, br\r\n";
        $handshake .= "Accept-Language: zh-CN,zh;q=0.9\r\n";
        $handshake .= "Sec-WebSocket-Key: {$this->wsKey}\r\n";
        $handshake .= "Sec-WebSocket-Extensions: permessage-deflate; client_max_window_bits\r\n";
        $handshake .= "\r\n";

        $result = fwrite($this->socket, $handshake);
        if ($result === false) {
            logger()->error('flv pusher send websocket handshake failed');
            return false;
        }

        $response = '';
        /** 若超过3秒，对方服务器没有返回响应，则认为对方服务器完蛋了 */
        $timeout = time() + 3;
        while (time() < $timeout && !feof($this->socket)) {
            $line = fgets($this->socket);
            if ($line === false) break;
            $response .= $line;
            if (trim($line) === '') break;
        }

        logger()->debug('flv pusher handshake response: ' . strtok($response, "\r\n"));

        if (!\preg_match("/Sec-WebSocket-Accept: *(.*?)\r\n/i", $response, $matches)) {
            logger()->error('flv pusher handshake failed: no Sec-WebSocket-Accept header');
            return false;
        }

        $responseKey = trim($matches[1]);
        $expectedKey = \base64_encode(\sha1($this->wsKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

        //logger()->debug('flv pusher Sec-WebSocket-Accept: response=' . $responseKey . ' expected=' . $expectedKey);

        if ($responseKey !== $expectedKey) {
            logger()->error('flv pusher handshake failed: Sec-WebSocket-Accept verify failed');
            return false;
        }

        logger()->info('flv pusher websocket handshake success');
        return true;
    }

    /**
     * 发送数据
     * @param $data
     * @return void
     * @throws \Exception
     */
    public function write($data)
    {
        if (!$this->socket || $this->closed) {
            return;
        }

        try {
            if ($this->isWebSocket) {
                $frame = $this->buildWebSocketFrame($data);
            } else {
                $frame = $this->buildChunkedFrame($data);
            }

            $frameSize = strlen($frame);

            /** 检查数据暂存区如果存在溢出 ，先刷新暂存区，然后再判断如果还是溢出，直接关闭客户端，防止服务器崩溃 */
            if ($this->sendBufferSize + $frameSize > $this->maxBufferSize) {
                $this->flush();
                if ($this->sendBufferSize + $frameSize > $this->maxBufferSize) {
                    logger()->error('flv pusher buffer overflow for ' . $this->pushUrl);
                    $this->close();
                    return;
                }
            }

            $this->sendBuffer .= $frame;
            $this->sendBufferSize += $frameSize;

            if ($this->sendBufferSize > 4396 || (microtime(true) - $this->lastFlushTime) > 0.005) {
                $this->flush();
            }
        } catch (\Exception $e) {
            logger()->error('flv single pusher write error: ' . $e->getMessage());
            $this->close();
            throw $e;
        }
    }

    /**
     * 构建http-flv分片数据
     * @param $data
     * @return string
     */
    protected function buildChunkedFrame($data)
    {
        $chunkSize = dechex(strlen($data));
        return $chunkSize . "\r\n" . $data . "\r\n";
    }

    /**
     * 构建ws-flv分片数据
     * @param $data
     * @return string
     * @throws \Random\RandomException
     */
    protected function buildWebSocketFrame($data)
    {
        $len = strlen($data);
        $frame = '';

        $frame .= chr(0x82);

        if ($len < 126) {
            $frame .= chr(0x80 | $len);
        } elseif ($len < 65536) {
            $frame .= chr(0x80 | 126);
            $frame .= pack('n', $len);
        } else {
            $frame .= chr(0x80 | 127);
            $frame .= pack('J', $len);
        }

        $mask = random_bytes(4);
        $frame .= $mask;

        $maskedData = '';
        for ($i = 0; $i < $len; $i++) {
            $maskedData .= chr(ord($data[$i]) ^ ord($mask[$i % 4]));
        }
        $frame .= $maskedData;

        return $frame;
    }

    /**
     * 刷新暂存区
     * @return void
     * @note 强制将暂存区的数据推送到对方服务器，因为连接是非阻塞的，那么会断断续续的写入，但是因为是本地连接忽略网络波动，而且刷新临界值很小，所以一般暂存区数据不会很大
     */
    public function flush()
    {
        if (!$this->socket || $this->closed || $this->sendBufferSize === 0) {
            return;
        }

        try {
            $written = @fwrite($this->socket, $this->sendBuffer);
            if ($written !== false && $written > 0) {
                $this->sendBuffer = substr($this->sendBuffer, $written);
                $this->sendBufferSize -= $written;
            }
            $this->lastFlushTime = microtime(true);
        } catch (\Exception $e) {
            logger()->error('flv single pusher flush error: ' . $e->getMessage());
            $this->close();
        }
    }

    /**
     * 发送关闭连接帧
     * @return void
     * @throws \Random\RandomException
     * @note 这个是处理ws协议的，防止黑客入侵ws链接
     */
    protected function sendCloseFrame()
    {
        if (!$this->socket) return;
        $frame = chr(0x88);
        $frame .= chr(0x80);
        $mask = random_bytes(4);
        $frame .= $mask;
        @fwrite($this->socket, $frame);
    }

    /**
     * 关闭连接
     * @return void
     */
    public function close()
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        if ($this->socket) {
            try {
                if ($this->isWebSocket) {
                    if ($this->sendBufferSize > 0) {
                        $this->flush();
                    }
                    /** 发送ws-flv关闭帧 */
                    $this->sendCloseFrame();
                } else {
                    if ($this->sendBufferSize > 0) {
                        $this->flush();
                    }
                    /** http-flv 发送关闭结束符 */
                    @fwrite($this->socket, "0\r\n\r\n");
                }
            } catch (\Exception $e) {}

            @fclose($this->socket);
            $this->socket = null;
        }

        $this->sendBuffer = '';
        $this->sendBufferSize = 0;
    }

    /**
     * 是否已关闭
     * @return bool
     */
    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * 销毁转播客户端
     */
    public function __destruct()
    {
        $this->close();
    }
}