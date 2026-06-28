<?php

namespace MediaServer\Flv;

/**
 * @purpose 推流客户端
 * @author yanglong
 * @note 微型推流客户端，使用ws-flv协议
 */
class FlvSinglePusher
{
    protected $playPath;

    protected $pushUrl;

    protected $socket;

    protected $isWebSocket = false;

    protected $wsKey = '';

    protected $wsPath = '/';

    protected $closed = false;

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

        stream_set_timeout($this->socket, 30);
        stream_set_blocking($this->socket, true);

        if ($this->isWebSocket) {
            return $this->webSocketHandshake($host, $port);
        } else {
            return $this->httpConnect($host);
        }
    }

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
        while (!feof($this->socket)) {
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
        $timeout = time() + 10;
        while (time() < $timeout && !feof($this->socket)) {
            $line = fgets($this->socket);
            if ($line === false) break;
            $response .= $line;
            if (trim($line) === '') break;
        }

        logger()->debug('flv pusher handshake response: ' . strtok($response, "\r\n"));

        if (!preg_match('#Sec-WebSocket-Accept:\s(.*)$#mUi', $response, $matches)) {
            logger()->error('flv pusher handshake failed: no Sec-WebSocket-Accept header');
            return false;
        }

        $responseKey = trim($matches[1]);
        $expectedKey = base64_encode(sha1($this->wsKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

        if ($responseKey !== $expectedKey) {
            logger()->error('flv pusher handshake failed: Sec-WebSocket-Accept verify failed');
            return false;
        }

        logger()->info('flv pusher websocket handshake success');
        return true;
    }

    public function write($data)
    {
        if (!$this->socket || $this->closed) {
            return;
        }

        try {
            if ($this->isWebSocket) {
                $this->sendWebSocketFrame($data);
            } else {
                // ★ HTTP-FLV 使用 chunked encoding
                $this->writeChunked($data);
            }
        } catch (\Exception $e) {
            logger()->error('flv single pusher write error: ' . $e->getMessage());
            $this->close();
            throw $e;
        }
    }

    /**
     * ★ 发送 Chunked 编码的数据
     */
    private function writeChunked($data)
    {
        $chunkSize = dechex(strlen($data));
        $chunk = $chunkSize . "\r\n" . $data . "\r\n";
        return $this->writeAll($chunk);
    }

    private function sendWebSocketFrame($data) {
        $len = strlen($data);
        $frame = '';

        // 第一个字节: FIN(1) + RSV(3) + Opcode(4)
        // 0x82 = 1000 0010 = FIN + Binary
        $frame .= chr(0x82);

        // 第二个字节: MASK(1) + Payload length(7)
        // 客户端必须设置 MASK 位
        if ($len < 126) {
            $frame .= chr(0x80 | $len);  // MASK=1, length=$len
        } elseif ($len < 65536) {
            $frame .= chr(0x80 | 126);   // MASK=1, length=126
            $frame .= pack('n', $len);   // 2字节无符号整数
        } else {
            $frame .= chr(0x80 | 127);   // MASK=1, length=127
            $frame .= pack('J', $len);   // 8字节无符号整数
        }

        // 生成 4 字节随机掩码
        $mask = random_bytes(4);
        $frame .= $mask;

        // 掩码处理数据
        for ($i = 0; $i < $len; $i++) {
            $frame .= chr(ord($data[$i]) ^ ord($mask[$i % 4]));
        }

        return $this->writeAll($frame);
    }

    protected function sendCloseFrame()
    {
        if (!$this->socket) return;
        $frame = chr(0x88);
        $frame .= chr(0x80);
        $mask = random_bytes(4);
        $frame .= $mask;
        @fwrite($this->socket, $frame);
    }

    protected function writeAll($data)
    {
        if (!$this->socket) return 0;

        $len = strlen($data);
        $written = 0;

        while ($written < $len) {
            $result = @fwrite($this->socket, substr($data, $written));
            if ($result === false) {
                throw new \Exception("flv pusher write data failed");
            }
            $written += $result;
        }

        return $written;
    }

    public function close()
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        if ($this->socket) {
            try {
                if ($this->isWebSocket) {
                    $this->sendCloseFrame();
                } else {
                    // ★ 发送 chunked encoding 结束标记
                    $this->writeAll("0\r\n\r\n");
                }
            } catch (\Exception $e) {}

            @fclose($this->socket);
            $this->socket = null;
        }
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function __destruct()
    {
        $this->close();
    }
}