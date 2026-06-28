<?php

namespace MediaServer\Flv;

use MediaServer\MediaReader\AudioFrame;
use MediaServer\MediaReader\MediaFrame;
use MediaServer\MediaReader\MetaDataFrame;
use MediaServer\MediaReader\VideoFrame;
use MediaServer\MediaServer;

class FlvPusher
{
    public $playPath;

    protected $pushUrl;

    protected $socket;

    protected $isWebSocket = false;

    protected $wsKey = '';

    protected $wsPath = '/';

    public $isFlvHeader = false;

    public $closed = false;

    protected $connectRetryCount = 0;

    protected $maxConnectRetries = 5;

    protected $retryDelay = 3;

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

        logger()->info('flv pusher init success: {path} -> {url}', ['path' => $playPath, 'url' => $pushUrl]);
    }

    public function startPlay(string $path)
    {
        $publishStream = MediaServer::getPublishStream($path);

        logger()->info('flv pusher start play, path: ' . $path);

        if (!$this->connect()) {
            logger()->error('flv pusher connect failed');
            return;
        }

        if (!$this->isFlvHeader) {
            $flvHeader = "FLV\x01\x00" . pack('NN', 9, 0);
            if ($publishStream->hasAudio()) {
                $flvHeader[4] = chr(ord($flvHeader[4]) | 4);
            }
            if ($publishStream->hasVideo()) {
                $flvHeader[4] = chr(ord($flvHeader[4]) | 1);
            }
            $this->write($flvHeader);
            $this->write(pack('N', 0));
            $this->isFlvHeader = true;
        }

        if ($publishStream->isMetaData()) {
            $metaDataFrame = $publishStream->getMetaDataFrame();
            $this->sendMetaDataFrame($metaDataFrame);
        }

        if ($publishStream->isAVCSequence()) {
            $avcFrame = $publishStream->getAVCSequenceFrame();
            $this->sendVideoFrame($avcFrame);
        }

        if ($publishStream->isAACSequence()) {
            $aacFrame = $publishStream->getAACSequenceFrame();
            $this->sendAudioFrame($aacFrame);
        }

        foreach ($publishStream->getGopCacheQueue() as &$frame) {
            $this->frameSend($frame);
        }
    }

    public function sendMetaDataFrame($metaDataFrame)
    {
        $tag = new FlvTag();
        $tag->type = Flv::SCRIPT_TAG;
        $tag->timestamp = 0;
        $tag->data = (string)$metaDataFrame;
        $tag->dataSize = strlen($tag->data);
        $chunks = Flv::createFlvTag($tag);
        $this->write($chunks);
    }

    public function sendAudioFrame($audioFrame)
    {
        $tag = new FlvTag();
        $tag->type = Flv::AUDIO_TAG;
        $tag->timestamp = $audioFrame->timestamp;
        $tag->data = (string)$audioFrame;
        $tag->dataSize = strlen($tag->data);
        $chunks = Flv::createFlvTag($tag);
        $this->write($chunks);
    }

    public function sendVideoFrame($videoFrame)
    {
        $tag = new FlvTag();
        $tag->type = Flv::VIDEO_TAG;
        $tag->timestamp = $videoFrame->timestamp;
        $tag->data = (string)$videoFrame;
        $tag->dataSize = strlen($tag->data);
        $chunks = Flv::createFlvTag($tag);
        $this->write($chunks);
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
                $this->writeAll($data);
            }
        } catch (\Exception $e) {
            logger()->error('flv pusher write error: ' . $e->getMessage());
            $this->close();
        }
    }

    public function frameSend($frame)
    {
        switch ($frame->FRAME_TYPE) {
            case MediaFrame::VIDEO_FRAME:
                return $this->sendVideoFrame($frame);
            case MediaFrame::AUDIO_FRAME:
                return $this->sendAudioFrame($frame);
            case MediaFrame::META_FRAME:
                return $this->sendMetaDataFrame($frame);
        }
    }

    protected function connect()
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

    protected function sendWebSocketFrame($data)
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
                }
            } catch (\Exception $e) {}

            @fclose($this->socket);
            $this->socket = null;
            logger()->info('flv pusher closed: {path}', ['path' => $this->playPath]);
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
