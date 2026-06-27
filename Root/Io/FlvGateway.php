<?php

namespace Root\Io;

/**
 * @purpose flv网关（epoll/select 自适应，HTTP-FLV + WebSocket-FLV 双协议支持）
 * @author yanglong
 */
class FlvGateway
{
    public $upstreamBaseUrl = 'http://127.0.0.1:8501';
    public $listenPort = 8080;

    private $serverSocket = null;
    private $streams = [];
    private $clients = [];
    private $pendingClients = [];

    public $debug = false;
    private $statsInterval = 10;
    private $lastStatsTime = 0;
    private $clientIdCounter = 0;

    const RING_BUFFER_SIZE = 8 * 1024 * 1024;
    const MAX_FLUSH_CHUNK  = 65536;

    public $maxClientsPerStream = 0;

    private $useEvent = false;
    private $base = null;
    private $_readEvents = [];
    private $_timerEvent = null;
    private $handshakeClients = [];

    public function __construct($port = null, $upstream = null)
    {
        if ($port !== null) $this->listenPort = (int)$port;
        if ($upstream !== null) $this->upstreamBaseUrl = rtrim($upstream, '/');
    }

    private function log($msg)
    {
        if ($this->debug) {
            echo "[" . date('H:i:s') . "] {$msg}\n";
        }
    }

    private function isStreamValid($stream): bool
    {
        return is_resource($stream) && get_resource_type($stream) === 'stream';
    }

    private function safeCloseStream(&$stream): void
    {
        if ($stream === null) return;
        $this->removeReadEvent($stream);
        if ($this->isStreamValid($stream)) {
            @stream_socket_shutdown($stream, STREAM_SHUT_RDWR);
            @fclose($stream);
        }
        $stream = null;
    }

    /**
     * WebSocket 数据帧封装（二进制帧）
     */
    private function encodeWebSocketFrame(string $data): string
    {
        $len = strlen($data);
        $frame = chr(0x82); // FIN=1, opcode=2 (binary)

        if ($len <= 125) {
            $frame .= chr($len);
        } elseif ($len <= 65535) {
            $frame .= chr(126) . pack('n', $len);
        } else {
            $frame .= chr(127) . pack('J', $len);
        }

        return $frame . $data;
    }

    public function start(): void
    {
        $context = stream_context_create([
            'socket' => [
                'backlog'      => 65535,
                'so_reuseport' => true,
                'so_reuseaddr' => true,
            ]
        ]);

        $this->serverSocket = @stream_socket_server("tcp://0.0.0.0:{$this->listenPort}", $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        if (!$this->serverSocket) die("启动失败: {$errstr} ({$errno})\n");
        stream_set_blocking($this->serverSocket, false);

        $this->useEvent = extension_loaded('event') && DIRECTORY_SEPARATOR === '/';
        $this->log("网关启动 端口:{$this->listenPort} 上游:{$this->upstreamBaseUrl} IO:" . ($this->useEvent ? "epoll" : "select"));
        $this->lastStatsTime = time();

        $this->useEvent ? $this->startEventLoop() : $this->startSelectLoop();
    }

    // ========== Epoll 事件循环 ==========
    private function startEventLoop(): void
    {
        $config = new \EventConfig();
        $config->avoidMethod('select');
        $this->base = new \EventBase($config);

        $this->addReadEvent($this->serverSocket, fn() => $this->acceptClient());

        $this->addPeriodicTimer(0.01, function () {
            try {
                $this->flushAllClients();
                $this->gcStreams();
                if ($this->debug && time() - $this->lastStatsTime >= $this->statsInterval) {
                    $this->printStats();
                    $this->lastStatsTime = time();
                }
            } catch (\Throwable $e) {
                $this->log("定时器异常: " . $e->getMessage());
            }
        });

        $this->base->loop();
    }

    /**
     * 刷新客户端数据
     * @param array|null $writableSockets 可写 socket 列表（select 模式传入，epoll 模式传 null 处理全部）
     */
    private function flushAllClients(?array $writableSockets = null): void
    {
        $removeIds = [];
        $useWriteSet = is_array($writableSockets);

        // ========== 处理等待队列 (pendingClients) ==========
        foreach ($this->pendingClients as $id => &$c) {
            $path = $c['stream'];
            $stream = &$this->streams[$path];
            $initData = $stream['cache']['initData'] ?? '';
            if ($initData === '') continue;

            if (!$this->isStreamValid($c['socket'])) {
                $removeIds[] = $id;
                continue;
            }

            if ($useWriteSet && !in_array($c['socket'], $writableSockets, true)) continue;

            // 初始化 pendingWrite
            if (!isset($c['pendingWrite'])) {
                $c['pendingWrite'] = '';
            }

            // 先发送上次未写完的 WebSocket frame
            if ($c['pendingWrite'] !== '') {
                $written = @fwrite($c['socket'], $c['pendingWrite']);
                if ($written === false || ($written === 0 && !$this->isStreamValid($c['socket']))) {
                    $removeIds[] = $id;
                    continue;
                }
                if ($written > 0) {
                    $stream['bytesSent'] += $written;
                    if ($written < strlen($c['pendingWrite'])) {
                        $c['pendingWrite'] = substr($c['pendingWrite'], $written);
                        continue;
                    }
                    $c['pendingWrite'] = '';
                } else {
                    continue;
                }
            }

            $offset    = $c['initOffset'];
            $remaining = strlen($initData) - $offset;
            /** 这里是客户端初始化关键，此处决定了客户端能否解码 */
            if ($remaining <= 0) {
                // 初始化数据已全部发出，转为正式客户端
                $this->clients[$id] = [
                    'socket'       => $c['socket'],
                    'stream'       => $path,
                    'isWebSocket'  => $c['isWebSocket'],
                    'readOffset'   => $stream['ringTotalWritten'],
                    'pendingWrite' => '',
                ];
                unset($this->pendingClients[$id]);
                $this->log("[{$path}] 客户端{$id}就绪");
                if ($this->useEvent) {
                    $this->addReadEvent($c['socket'], fn($fd) => $this->handleClientRead($id, $fd));
                }
                continue;
            }

            // WebSocket 每次只发 8192 字节原始数据，避免 frame 太大
            $maxChunk = $c['isWebSocket'] ? 8192 : self::MAX_FLUSH_CHUNK;
            $chunkSize = min($maxChunk, $remaining);
            $flvChunk  = substr($initData, $offset, $chunkSize);

            if ($c['isWebSocket']) {
                $sendChunk = $this->encodeWebSocketFrame($flvChunk);
            } else {
                $sendChunk = $flvChunk;
            }

            $written = @fwrite($c['socket'], $sendChunk);
            if ($written === false || ($written === 0 && !$this->isStreamValid($c['socket']))) {
                $removeIds[] = $id;
                continue;
            }
            if ($written > 0) {
                $stream['bytesSent'] += $written;
                if ($written < strlen($sendChunk)) {
                    // 部分写入，保存剩余数据
                    $c['pendingWrite'] = substr($sendChunk, $written);
                }
                $c['initOffset'] += $chunkSize;
                /** 初始化数据发送完成 ，客户端才能解码，这里也是客户端解码的关键 */
                if ($c['initOffset'] >= strlen($initData)) {
                    $this->clients[$id] = [
                        'socket'       => $c['socket'],
                        'stream'       => $path,
                        'isWebSocket'  => $c['isWebSocket'],
                        'readOffset'   => $stream['ringTotalWritten'],
                        'pendingWrite' => $c['pendingWrite'],
                    ];
                    unset($this->pendingClients[$id]);
                    $this->log("[{$path}] 客户端{$id}就绪");
                    if ($this->useEvent) {
                        $this->addReadEvent($c['socket'], fn($fd) => $this->handleClientRead($id, $fd));
                    }
                }
            }
        }
        unset($c);

        // ========== 处理已连接客户端 (clients) ==========
        foreach ($this->clients as $id => &$c) {
            if (!$this->isStreamValid($c['socket'])) {
                $removeIds[] = $id;
                continue;
            }
            $path = $c['stream'];
            $stream = &$this->streams[$path];
            $totalWritten = $stream['ringTotalWritten'];
            $clientOffset = $c['readOffset'];

            if ($clientOffset >= $totalWritten) continue;

            $lag = $totalWritten - $clientOffset;
            if ($lag > self::RING_BUFFER_SIZE) {
                if (!$this->sendCatchUpPacketInternal($id, $c)) {
                    $removeIds[] = $id;
                }
                continue;
            }

            if ($useWriteSet && !in_array($c['socket'], $writableSockets, true)) continue;

            // 初始化 pendingWrite
            if (!isset($c['pendingWrite'])) {
                $c['pendingWrite'] = '';
            }

            // 先发送上次未写完的 WebSocket frame
            if ($c['pendingWrite'] !== '') {
                $written = @fwrite($c['socket'], $c['pendingWrite']);
                if ($written === false || ($written === 0 && !$this->isStreamValid($c['socket']))) {
                    $removeIds[] = $id;
                    continue;
                }
                if ($written > 0) {
                    $stream['bytesSent'] += $written;
                    if ($written < strlen($c['pendingWrite'])) {
                        $c['pendingWrite'] = substr($c['pendingWrite'], $written);
                        continue;
                    }
                    $c['pendingWrite'] = '';
                } else {
                    continue;
                }
            }

            // WebSocket 每次只发小块，避免 frame 太大导致部分写入
            $maxChunk = $c['isWebSocket'] ? 8192 : self::MAX_FLUSH_CHUNK;
            $sendLen = min($maxChunk, $lag);
            $data = $this->readFromRingBuffer($path, $clientOffset, $sendLen);
            if ($data === '' || $data === false) continue;

            if ($c['isWebSocket']) {
                $sendData = $this->encodeWebSocketFrame($data);
            } else {
                $sendData = $data;
            }

            $written = @fwrite($c['socket'], $sendData);
            if ($written === false || ($written === 0 && !$this->isStreamValid($c['socket']))) {
                $removeIds[] = $id;
                continue;
            }
            if ($written > 0) {
                $stream['bytesSent'] += $written;
                if ($written < strlen($sendData)) {
                    // WebSocket frame 部分写入，保存剩余
                    $c['pendingWrite'] = substr($sendData, $written);
                }
                // HTTP-FLV 用实际写入字节数；WebSocket 用原始数据长度
                if ($c['isWebSocket']) {
                    $c['readOffset'] += strlen($data);
                } else {
                    $c['readOffset'] += $written;
                }
            }
        }
        unset($c);

        foreach ($removeIds as $id) $this->removeClient($id);
    }

    private function sendCatchUpPacketInternal($clientId, &$clientData)
    {
        if (!isset($this->clients[$clientId])) return false;
        $path   = $clientData['stream'];
        $stream = &$this->streams[$path];
        $cache  = $stream['cache'];
        if (!$cache['ready'] || !$this->isStreamValid($clientData['socket'])) return false;

        $catchUp = $cache['flvHeader']
            . $cache['metaDataTag']
            . $cache['videoSequence']
            . $cache['audioSequence']
            . $cache['gopData'];

        $sendData = $clientData['isWebSocket'] ? $this->encodeWebSocketFrame($catchUp) : $catchUp;
        $this->log("[{$path}] 客户端{$clientId}严重落后，发送追赶包 (" . $this->formatBytes(strlen($catchUp)) . ")");
        $written = @fwrite($clientData['socket'], $sendData);
        if ($written === false || $written === 0) return false;

        $clientData['readOffset'] = $stream['ringTotalWritten'];
        $stream['bytesSent'] += $written;
        return true;
    }

    private function readFromRingBuffer($path, $offset, $len)
    {
        $stream = $this->streams[$path];
        $totalWritten = $stream['ringTotalWritten'];
        $size = self::RING_BUFFER_SIZE;

        $lag = $totalWritten - $offset;
        if ($lag <= 0) return '';
        $len = min($len, $lag);

        $startPos = ($stream['ringWritePos'] - $lag + $size) % $size;
        $buffer = $stream['ringBuffer'];

        if ($startPos + $len <= $size) {
            return substr($buffer, $startPos, $len);
        } else {
            $first = $size - $startPos;
            return substr($buffer, $startPos, $first) . substr($buffer, 0, $len - $first);
        }
    }

    // ========== Select 事件循环 ==========
    private function startSelectLoop(): void
    {
        while (true) {
            $read = $this->serverSocket ? [$this->serverSocket] : [];
            foreach ($this->streams as $s) if ($this->isStreamValid($s['upstreamSocket'])) $read[] = $s['upstreamSocket'];
            foreach ($this->clients as $c) if ($this->isStreamValid($c['socket'])) $read[] = $c['socket'];
            foreach ($this->pendingClients as $c) if ($this->isStreamValid($c['socket'])) $read[] = $c['socket'];
            foreach ($this->handshakeClients as $c) if ($this->isStreamValid($c['socket'])) $read[] = $c['socket'];

            $write = [];
            foreach ($this->clients as $c)
                if ($this->isStreamValid($c['socket']) && $c['readOffset'] < ($this->streams[$c['stream']]['ringTotalWritten'] ?? 0))
                    $write[] = $c['socket'];
            foreach ($this->pendingClients as $c)
                if ($this->isStreamValid($c['socket']) && !empty($this->streams[$c['stream']]['cache']['initData']))
                    $write[] = $c['socket'];

            $except = null;
            @stream_select($read, $write, $except, 0, 10000);
            foreach ($read as $sock) {
                if ($sock === $this->serverSocket) $this->acceptClient();
                else {
                    $isHandshake = false;
                    foreach ($this->handshakeClients as $hc) {
                        if ($hc['socket'] === $sock) { $isHandshake = true; break; }
                    }
                    if ($isHandshake) {
                        $this->processHandshakeClients();
                    } else {
                        $this->handleRead($sock);
                    }
                }
            }

            $this->flushAllClients($write);
            $this->gcStreams();

            if ($this->debug && time() - $this->lastStatsTime >= $this->statsInterval) {
                $this->printStats();
                $this->lastStatsTime = time();
            }
        }
    }

    // ========== Epoll 事件管理 ==========
    private function addReadEvent($fd, callable $callback): void
    {
        if (!$this->useEvent || !$this->isStreamValid($fd)) return;
        $key = (int)$fd;
        if (isset($this->_readEvents[$key])) return;
        $event = new \Event($this->base, $fd, \Event::READ | \Event::PERSIST, function ($fd) use ($callback) {
            try { $callback($fd); } catch (\Throwable $e) { $this->log("读事件异常: " . $e->getMessage()); }
        });
        $event->add();
        $this->_readEvents[$key] = $event;
    }

    private function removeReadEvent($fd): void
    {
        if (!$this->useEvent || !$fd) return;
        $key = (int)$fd;
        if (isset($this->_readEvents[$key])) {
            $this->_readEvents[$key]->free();
            unset($this->_readEvents[$key]);
        }
    }

    private function addPeriodicTimer($interval, callable $callback): void
    {
        if (!$this->useEvent) return;
        $event = \Event::timer($this->base, function () use ($callback, $interval, &$event) {
            try { $callback(); } catch (\Throwable $e) { $this->log("定时器异常: " . $e->getMessage()); }
            $event->add($interval);
        });
        $event->add($interval);
        $this->_timerEvent = $event;
    }

    // ========== 客户端接入 ==========
    private function acceptClient(): void
    {
        $client = @stream_socket_accept($this->serverSocket, 0, $peerName);
        if (!$client || !$this->isStreamValid($client)) return;

        $this->clientIdCounter++;
        $clientId = $this->clientIdCounter;
        stream_set_blocking($client, false);
        stream_set_write_buffer($client, 0);
        if (function_exists('socket_import_stream')) {
            $sock = socket_import_stream($client);
            if ($sock) socket_set_option($sock, SOL_TCP, TCP_NODELAY, 1);
        }

        $this->handshakeClients[$clientId] = [
            'socket'    => $client,
            'buffer'    => '',
            'startTime' => microtime(true),
        ];

        $this->log("客户端{$clientId}连接，等待握手");

        if ($this->useEvent) {
            $this->addReadEvent($client, fn($fd) => $this->handleHandshakeRead($clientId, $fd));
        }
    }

    private function processHandshakeClients(): void
    {
        $now = microtime(true);
        $removeIds = [];

        foreach ($this->handshakeClients as $id => &$hc) {
            $sock = $hc['socket'];
            if (!$this->isStreamValid($sock)) {
                $removeIds[] = $id;
                continue;
            }

            if ($now - $hc['startTime'] > 5.0) {
                $this->log("握手超时，关闭连接");
                $removeIds[] = $id;
                continue;
            }

            $chunk = @fread($sock, 8192);
            if ($chunk === false || $chunk === '') {
                $info = stream_get_meta_data($sock);
                if (!empty($info['eof'])) {
                    $removeIds[] = $id;
                }
                continue;
            }

            $hc['buffer'] .= $chunk;
            if (strpos($hc['buffer'], "\r\n\r\n") !== false) {
                $req = $hc['buffer'];
                $this->completeHandshake($id, $hc, $req);
            }
        }
        unset($hc);

        foreach ($removeIds as $id) {
            if (isset($this->handshakeClients[$id])) {
                $this->safeCloseStream($this->handshakeClients[$id]['socket']);
                unset($this->handshakeClients[$id]);
            }
        }
    }

    private function handleHandshakeRead(int $clientId, $fd): void
    {
        if (!isset($this->handshakeClients[$clientId])) return;
        $hc = &$this->handshakeClients[$clientId];
        $sock = $hc['socket'];

        $chunk = @fread($sock, 8192);
        if ($chunk === false || $chunk === '') {
            $info = stream_get_meta_data($sock);
            if (!empty($info['eof'])) {
                $this->removeReadEvent($sock);
                $this->safeCloseStream($sock);
                unset($this->handshakeClients[$clientId]);
            }
            return;
        }

        $hc['buffer'] .= $chunk;
        if (strpos($hc['buffer'], "\r\n\r\n") !== false) {
            $this->removeReadEvent($sock);
            $req = $hc['buffer'];
            $this->completeHandshake($clientId, $hc, $req);
        }
    }

    private function handleClientRead(int $clientId, $fd): void
    {
        if (!isset($this->clients[$clientId])) return;
        $c = &$this->clients[$clientId];
        $sock = $c['socket'];

        if ($c['isWebSocket']) {
            if ($this->processWebSocketMessage($sock)) {
                $this->removeReadEvent($sock);
                $this->removeClient($clientId);
            }
            return;
        }

        $data = @fread($sock, 1);
        if ($data === '' || $data === false) {
            $info = stream_get_meta_data($sock);
            if (!empty($info['eof'])) {
                $this->removeReadEvent($sock);
                $this->removeClient($clientId);
            }
        }
    }

    /**
     * 必须允许跨域，否则浏览器无法播放
     * @param int $clientId
     * @param array $hc
     * @param string $req
     * @return void
     */
    private function completeHandshake(int $clientId, array &$hc, string $req): void
    {
        $client = $hc['socket'];
        unset($this->handshakeClients[$clientId]);

        $this->log("客户端{$clientId}握手完成 " . substr($req, 0, 100));

        // 处理 CORS 预检请求
        if (preg_match('#OPTIONS\s+/([^\s]+)#', $req)) {
            @fwrite($client, "HTTP/1.1 204 No Content\r\n"
                . "Access-Control-Allow-Origin: *\r\n"
                . "Access-Control-Allow-Methods: GET, OPTIONS\r\n"
                . "Access-Control-Allow-Headers: *\r\n"
                . "Access-Control-Max-Age: 86400\r\n"
                . "Connection: close\r\n\r\n");
            $this->safeCloseStream($client);
            return;
        }

        $isWebSocket = preg_match('/Upgrade:\s*websocket/i', $req) && preg_match('/Connection:\s*Upgrade/i', $req);

        // 提取路径，去掉 .flv 后缀
        preg_match('#GET\s+/([^\s]+)#', $req, $m);
        $path = preg_replace('/\.flv$/', '', $m[1] ?? '');
        if (!$path) {
            @fwrite($client, "HTTP/1.1 400 Bad Request\r\n"
                . "Access-Control-Allow-Origin: *\r\n"
                . "Connection: close\r\n\r\n");
            $this->safeCloseStream($client);
            return;
        }

        $this->log("[{$path}] 客户端{$clientId} 协议:" . ($isWebSocket ? 'WebSocket' : 'HTTP'));

        // WebSocket 握手
        if ($isWebSocket) {
            $wsKey = '';
            if (preg_match('/Sec-WebSocket-Key:\s*([^\r\n]+)/i', $req, $keyMatch)) {
                $wsKey = trim($keyMatch[1]);
            }
            $acceptKey = base64_encode(sha1($wsKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
            @fwrite($client, "HTTP/1.1 101 Switching Protocols\r\n"
                . "Upgrade: websocket\r\n"
                . "Connection: Upgrade\r\n"
                . "Sec-WebSocket-Accept: {$acceptKey}\r\n"
                . "Sec-WebSocket-Version: 13\r\n"
                . "Access-Control-Allow-Origin: *\r\n"
                . "\r\n");
        } else {
            // HTTP FLV 响应
            @fwrite($client, "HTTP/1.1 200 OK\r\n"
                . "Content-Type: video/x-flv\r\n"
                . "Access-Control-Allow-Origin: *\r\n"
                . "Access-Control-Allow-Methods: GET, OPTIONS\r\n"
                . "Access-Control-Allow-Headers: *\r\n"
                . "Connection: keep-alive\r\n\r\n");
        }

        // 初始化流
        $this->initStream($path);
        $stream = &$this->streams[$path];

        // 创建等待客户端
        $this->pendingClients[$clientId] = [
            'socket'       => $client,
            'stream'       => $path,
            'isWebSocket'  => $isWebSocket,
            'initOffset'   => 0,
            'pendingWrite' => '',
        ];

        // 注册读事件
        if ($this->useEvent) {
            $this->addReadEvent($client, fn($fd) => $this->handleClientRead($clientId, $fd));
        }

        // 如果上游未连接，尝试连接（懒加载模式）
        if (!$this->isStreamValid($stream['upstreamSocket'])) {
            if ($stream['cache']['ready']) {
                $this->resetStreamCache($path);
            }
            $this->log("[{$path}] 新客户端{$clientId}到达，上游未连接，尝试连接");
            $this->connectUpstream($path);
        }
    }

    private function initStream($path)
    {
        if (!isset($this->streams[$path])) {
            $this->streams[$path] = [
                'path'             => $path,
                'upstreamSocket'   => null,
                'buffer'           => '',
                'chunkBuffer'      => '',
                'chunked'          => false,
                'httpHeaderParsed' => false,
                'bytesReceived'    => 0,
                'bytesSent'        => 0,
                'lastReadTime'     => 0,
                'ringBuffer'       => str_repeat("\0", self::RING_BUFFER_SIZE),
                'ringWritePos'     => 0,
                'ringTotalWritten' => 0,
                'cache' => [
                    'flvHeader'     => '',
                    'metaDataTag'   => '',
                    'videoSequence' => '',
                    'audioSequence' => '',
                    'gopData'       => '',
                    'initData'      => '',
                    'ready'         => false,
                ],
            ];
        }
        return $this->streams[$path];
    }

    /**
     * 通知客户端流已断开（优雅关闭）
     */
    private function notifyClientDisconnect($socket, bool $isWebSocket): void
    {
        if (!$this->isStreamValid($socket)) return;

        if ($isWebSocket) {
            // WebSocket Close 帧
            $closeFrame = chr(0x88) . chr(0x02) . chr(0x03) . chr(0xE9);
            @fwrite($socket, $closeFrame);
        }
    }

    // ========== 上游连接 ==========
    private function connectUpstream(string $path): bool
    {
        $stream = &$this->streams[$path];
        $url = "{$this->upstreamBaseUrl}/{$path}.flv";
        $p = parse_url($url);
        $host = $p['host'];
        $port = $p['port'] ?? 80;
        $reqPath = $p['path'] ?? '/';

        $this->log("[{$path}] 连接上游 {$host}:{$port}{$reqPath}");

        $sock = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 5);
        if (!$sock || !$this->isStreamValid($sock)) {
            $this->log("[{$path}] 连接失败: {$errstr} ({$errno})");
            return false;
        }

        stream_set_timeout($sock, 5);
        if (function_exists('socket_import_stream')) {
            $sockResource = socket_import_stream($sock);
            if ($sockResource) socket_set_option($sockResource, SOL_TCP, TCP_NODELAY, 1);
        }

        // 发送 HTTP 请求
        @fwrite($sock, "GET {$reqPath} HTTP/1.1\r\nHost: {$host}\r\nAccept: */*\r\nConnection: keep-alive\r\n\r\n");

        // 读取 HTTP 响应头
        $resp = '';
        $maxRetries = 50;
        for ($i = 0; $i < $maxRetries; $i++) {
            $chunk = @fread($sock, 4096);
            if ($chunk === false) break;
            if ($chunk === '') {
                usleep(10000);
                continue;
            }
            $resp .= $chunk;
            $headerEndPos = strpos($resp, "\r\n\r\n");
            if ($headerEndPos !== false) {
                $header = substr($resp, 0, $headerEndPos);
                $body = substr($resp, $headerEndPos + 4);

                // 检查 HTTP 状态码
                if (stripos($header, 'HTTP/1.1 200') === false && stripos($header, 'HTTP/1.0 200') === false) {
                    $this->log("[{$path}] 上游返回非200状态码: " . substr($header, 0, 100));
                    $this->safeCloseStream($sock);
                    return false;
                }

                $stream['chunked'] = (stripos($header, "Transfer-Encoding: chunked") !== false);
                $stream['httpHeaderParsed'] = true;
                stream_set_blocking($sock, false);

                // 初始化 buffer
                $stream['chunkBuffer'] = $stream['chunked'] ? $body : '';
                $stream['buffer'] = $stream['chunked'] ? '' : $body;

                $stream['upstreamSocket'] = $sock;
                $stream['lastReadTime'] = time();
                $stream['bytesReceived'] = 0;

                $this->addReadEvent($sock, fn() => $this->readUpstream($path));

                // 立即尝试解析 buffer 中的数据
                if ($stream['buffer'] !== '') {
                    $this->processFlvData($path);
                }

                $this->log("[{$path}] 上游连接成功 (buffer初始长度:" . strlen($stream['buffer']) . " bytes)");
                return true;
            }
        }

        $this->safeCloseStream($sock);
        $this->log("[{$path}] 上游响应异常（未找到完整HTTP头）");
        return false;
    }

    // ========== 上游断开处理 ==========
    private function handleUpstreamDisconnect(string $path): void
    {
        $this->log("[{$path}] 上游断开，清理该路节目的所有客户端和状态");

        $stream = &$this->streams[$path];

        // 关闭上游连接
        $this->safeCloseStream($stream['upstreamSocket']);

        // 收集该路节目的所有客户端
        $removeClientIds = [];
        foreach ($this->clients as $id => $c) {
            if ($c['stream'] === $path) {
                $this->notifyClientDisconnect($c['socket'], $c['isWebSocket']);
                $removeClientIds[] = $id;
            }
        }
        foreach ($this->pendingClients as $id => $c) {
            if ($c['stream'] === $path) {
                $this->notifyClientDisconnect($c['socket'], $c['isWebSocket']);
                $removeClientIds[] = $id;
            }
        }

        // 断开这些客户端
        foreach ($removeClientIds as $id) {
            $this->removeClient($id);
        }

        // 清空该路节目的所有状态和数据
        $this->cleanupStream($path);

        $this->log("[{$path}] 节目清理完成");
    }

    /**
     * 彻底清理某路节目的所有资源
     */
    private function cleanupStream(string $path): void
    {
        if (!isset($this->streams[$path])) return;

        $stream = &$this->streams[$path];

        // 关闭上游连接
        $this->safeCloseStream($stream['upstreamSocket']);

        // 释放大内存
        $stream['ringBuffer'] = '';
        $stream['buffer'] = '';
        $stream['chunkBuffer'] = '';
        $stream['cache'] = [];

        // 从流列表中移除
        unset($this->streams[$path]);

        $this->log("[{$path}] 流资源已完全释放");
    }

    /**
     * 处理 WebSocket 客户端消息（ping/pong/close）
     */
    private function processWebSocketMessage($sock): bool
    {
        $frame = @fread($sock, 2);
        if (!$frame || strlen($frame) < 2) {
            $info = stream_get_meta_data($sock);
            return !empty($info['eof']);
        }

        $firstByte = ord($frame[0]);
        $secondByte = ord($frame[1]);
        $opcode = $firstByte & 0x0F;
        $payloadLen = $secondByte & 0x7F;

        if ($payloadLen === 126) {
            $ext = @fread($sock, 2);
            if ($ext === false || strlen($ext) < 2) return false;
            $payloadLen = unpack('n', $ext)[1];
        } elseif ($payloadLen === 127) {
            $ext = @fread($sock, 8);
            if ($ext === false || strlen($ext) < 8) return false;
            $payloadLen = unpack('J', $ext)[1];
        }

        $masked = ($secondByte & 0x80) !== 0;
        $mask = '';
        if ($masked) {
            $mask = @fread($sock, 4);
            if ($mask === false || strlen($mask) < 4) return false;
        }

        $payload = '';
        while (strlen($payload) < $payloadLen) {
            $chunk = @fread($sock, $payloadLen - strlen($payload));
            if ($chunk === false) {
                $info = stream_get_meta_data($sock);
                return !empty($info['eof']);
            }
            $payload .= $chunk;
        }

        if ($masked && $payloadLen > 0) {
            $unmasked = '';
            for ($i = 0; $i < $payloadLen; $i++) {
                $unmasked .= chr(ord($payload[$i]) ^ ord($mask[$i % 4]));
            }
            $payload = $unmasked;
        }

        $this->log("WebSocket消息: opcode=0x" . dechex($opcode) . " payloadLen={$payloadLen}");

        switch ($opcode) {
            case 0x08: // Close
                return true;
            case 0x09: // Ping
                $pongFrame = chr(0x8A) . chr($payloadLen) . $payload;
                @fwrite($sock, $pongFrame);
                break;
        }

        return false;
    }

    // ========== 数据读取 ==========
    private function handleRead($sock): void
    {
        // 检查是否是上游连接
        foreach ($this->streams as $path => $stream) {
            if ($stream['upstreamSocket'] === $sock) {
                $this->readUpstream($path);
                return;
            }
        }

        // 检查是否是已连接客户端
        foreach ($this->clients as $id => $c) {
            if ($c['socket'] === $sock) {
                if ($c['isWebSocket']) {
                    if ($this->processWebSocketMessage($sock)) {
                        $this->removeClient($id);
                    }
                    return;
                }
            }
        }

        // 检查是否是等待握手的客户端
        foreach ($this->pendingClients as $id => $c) {
            if ($c['socket'] === $sock) {
                if ($c['isWebSocket']) {
                    if ($this->processWebSocketMessage($sock)) {
                        $this->removeClient($id);
                    }
                    return;
                }
            }
        }

        // 未识别的 socket，尝试读取检查是否断开
        if (!$this->isStreamValid($sock)) {
            return;
        }
        $data = @fread($sock, 1);
        if ($data === '' || $data === false) {
            $this->removeClientBySocket($sock);
        }
    }

    private function removeClientBySocket($sock)
    {
        foreach ($this->clients as $id => $c) {
            if ($c['socket'] === $sock) { $this->removeClient($id); return; }
        }
        foreach ($this->pendingClients as $id => $c) {
            if ($c['socket'] === $sock) { $this->removeClient($id); return; }
        }
    }

    private function removeClient($id)
    {
        $socket = null;
        $path   = null;
        if (isset($this->clients[$id])) {
            $path   = $this->clients[$id]['stream'];
            $socket = $this->clients[$id]['socket'];
            unset($this->clients[$id]);
        } elseif (isset($this->pendingClients[$id])) {
            $path   = $this->pendingClients[$id]['stream'];
            $socket = $this->pendingClients[$id]['socket'];
            unset($this->pendingClients[$id]);
        }
        if ($socket !== null) {
            $this->removeReadEvent($socket);
            $this->safeCloseStream($socket);
        }
        if ($path) $this->log("客户端{$id}断开 流:/{$path} 剩余客户端:" . count($this->clients));
    }

    private function readUpstream(string $path): void
    {
        $stream = &$this->streams[$path];
        if (!$this->isStreamValid($stream['upstreamSocket'])) {
            $this->handleUpstreamDisconnect($path);
            return;
        }

        $data = @fread($stream['upstreamSocket'], 65536);
        if ($data === false) {
            $this->handleUpstreamDisconnect($path);
            return;
        }

        // 非阻塞无数据返回空字符串，需通过 eof 判断是否真正断开
        if ($data === '') {
            $info = stream_get_meta_data($stream['upstreamSocket']);
            if (!empty($info['eof'])) {
                $this->log("[{$path}] 上游连接EOF");
                $this->handleUpstreamDisconnect($path);
            }
            return;
        }

        $stream['bytesReceived'] += strlen($data);
        $stream['lastReadTime'] = time();

        if ($stream['chunked']) {
            $stream['chunkBuffer'] .= $data;
            if (($decoded = $this->decodeChunked($stream['chunkBuffer'])) !== null) {
                if ($decoded !== '') {
                    $stream['buffer'] .= $decoded;
                }
            }
        } else {
            $stream['buffer'] .= $data;
        }

        $this->processFlvData($path);
    }

    private function gcStreams(): void
    {
        // 清理没有客户端且上游已断开的流
        foreach ($this->streams as $path => $stream) {
            $hasClients = false;
            foreach ($this->clients as $c) {
                if ($c['stream'] === $path) { $hasClients = true; break; }
            }
            foreach ($this->pendingClients as $c) {
                if ($c['stream'] === $path) { $hasClients = true; break; }
            }

            if (!$hasClients && !$this->isStreamValid($stream['upstreamSocket'])) {
                $this->log("[{$path}] 无客户端且上游已断开，清理流资源");
                $this->cleanupStream($path);
            }
        }
    }

    private function resetStreamCache($path)
    {
        $stream = &$this->streams[$path];
        $stream['buffer'] = '';
        $stream['chunkBuffer'] = '';
        $stream['chunked'] = false;
        $stream['httpHeaderParsed'] = false;
        $stream['ringBuffer'] = str_repeat("\0", self::RING_BUFFER_SIZE);
        $stream['ringWritePos'] = 0;
        $stream['ringTotalWritten'] = 0;
        $stream['cache'] = [
            'flvHeader'     => '',
            'metaDataTag'   => '',
            'videoSequence' => '',
            'audioSequence' => '',
            'gopData'       => '',
            'initData'      => '',
            'ready'         => false,
        ];
    }

    private function decodeChunked(&$buf)
    {
        $decoded = '';
        while (true) {
            $pos = strpos($buf, "\r\n");
            if ($pos === false) break;
            $size = hexdec(trim(substr($buf, 0, $pos)));
            if ($size === 0) { $buf = ''; return $decoded; }
            $start = $pos + 2;
            $end   = $start + $size + 2;
            if (strlen($buf) < $end) break;
            $decoded .= substr($buf, $start, $size);
            $buf = substr($buf, $end);
        }
        return $decoded;
    }

    private function isVideoKeyFrame($tagType, $payload)
    {
        if ($tagType !== 9 || strlen($payload) < 2) return false;
        return ((ord($payload[0]) >> 4) & 0x0F) === 1 && ord($payload[1]) === 1;
    }

    private function processFlvData($path)
    {
        $stream = &$this->streams[$path];
        $cache  = &$stream['cache'];

        if (!$cache['flvHeader'] && strlen($stream['buffer']) >= 13) {
            $cache['flvHeader'] = substr($stream['buffer'], 0, 13);
            $stream['buffer'] = substr($stream['buffer'], 13);
            $this->log("[{$path}] FLV头 ✓");
        }

        while (strlen($stream['buffer']) >= 11) {
            $tagType   = ord($stream['buffer'][0]);
            $dataSize  = (ord($stream['buffer'][1]) << 16) | (ord($stream['buffer'][2]) << 8) | ord($stream['buffer'][3]);
            $totalSize = 11 + $dataSize + 4;
            if (strlen($stream['buffer']) < $totalSize) break;

            $tag     = substr($stream['buffer'], 0, $totalSize);
            $stream['buffer'] = substr($stream['buffer'], $totalSize);
            $payload = substr($tag, 11, $dataSize);

            // 第一阶段：收集元数据
            if (!$cache['ready']) {
                if ($tagType === 18 && !$cache['metaDataTag']) {
                    $cache['metaDataTag'] = $tag;
                    $this->log("[{$path}] MetaData ✓ (" . $this->formatBytes($dataSize) . ")");
                    continue;
                }
                if ($tagType === 9 && !$cache['videoSequence']) {
                    if (strlen($payload) >= 2 && ((ord($payload[0]) >> 4) & 0x0F) === 1 && ord($payload[1]) === 0) {
                        $cache['videoSequence'] = $tag;
                        $this->log("[{$path}] Video Sequence ✓");
                        continue;
                    }
                }
                if ($tagType === 8 && !$cache['audioSequence']) {
                    if (strlen($payload) >= 2 && ((ord($payload[0]) >> 4) & 0x0F) === 10 && ord($payload[1]) === 0) {
                        $cache['audioSequence'] = $tag;
                        $this->log("[{$path}] Audio Sequence ✓");
                        continue;
                    }
                }

                // 元数据收齐后，开始收集 GOP 并构建 initData
                if ($cache['flvHeader'] && $cache['videoSequence'] && $cache['audioSequence']) {
                    if ($this->isVideoKeyFrame($tagType, $payload)) {
                        $cache['gopData'] = $tag;
                    } else {
                        $cache['gopData'] .= $tag;
                    }

                    // 第一个关键帧出现时，标记就绪
                    if ($cache['gopData'] !== '' && $this->isVideoKeyFrame($tagType, $payload)) {
                        $cache['ready'] = true;
                        $cache['initData'] = $cache['flvHeader']
                            . $cache['metaDataTag']
                            . $cache['videoSequence']
                            . $cache['audioSequence']
                            . $cache['gopData'];
                        $this->log("[{$path}] 初始化完毕 GOP:" . $this->formatBytes(strlen($cache['gopData'])));
                        $this->notifyPendingClients($path);
                    }
                    continue;
                }
                continue;
            }

            // 第二阶段：正常写入 ring buffer，并持续更新 GOP 和 initData
            $this->writeToRingBuffer($path, $tag);

            /** 此处是确保客户端秒开播，并且不存在马赛克的关键步骤 */
            if ($this->isVideoKeyFrame($tagType, $payload)) {
                // 遇到新的关键帧，更新 GOP 和 initData
                $cache['gopData'] = $tag;
                $cache['initData'] = $cache['flvHeader']
                    . $cache['metaDataTag']
                    . $cache['videoSequence']
                    . $cache['audioSequence']
                    . $cache['gopData'];
                $this->log("[{$path}] GOP重置，关键帧 (" . $this->formatBytes(strlen($tag)) . ")");
            } else {
                $cache['gopData'] .= $tag;
                $cache['initData'] .= $tag;
            }
        }
    }

    private function notifyPendingClients($path)
    {
        $cache = &$this->streams[$path]['cache'];
        if (!$cache['ready']) return;

        $count = 0;
        foreach ($this->pendingClients as $id => &$c) {
            if ($c['stream'] === $path) {
                $c['initOffset'] = 0;
                $count++;
            }
        }
        unset($c);
        $this->log("[{$path}] 初始化数据就绪 (" . $this->formatBytes(strlen($cache['initData'])) . ")，唤醒 {$count} 个等待客户端");
    }

    private function writeToRingBuffer($path, $data)
    {
        $stream = &$this->streams[$path];
        $len = strlen($data);
        $pos = $stream['ringWritePos'];
        $size = self::RING_BUFFER_SIZE;

        if ($pos + $len <= $size) {
            for ($i = 0; $i < $len; $i++) {
                $stream['ringBuffer'][$pos + $i] = $data[$i];
            }
        } else {
            $first = $size - $pos;
            for ($i = 0; $i < $first; $i++) {
                $stream['ringBuffer'][$pos + $i] = $data[$i];
            }
            for ($i = $first; $i < $len; $i++) {
                $stream['ringBuffer'][$i - $first] = $data[$i];
            }
        }
        $stream['ringWritePos'] = ($pos + $len) % $size;
        $stream['ringTotalWritten'] += $len;
    }

    private function printStats()
    {
        $this->log("========== 统计 ==========");
        $this->log("活跃流:" . count($this->streams) . " 客户端:" . count($this->clients) . " 等待:" . count($this->pendingClients));
        foreach ($this->streams as $path => $stream) {
            $clientCount = 0;
            foreach ($this->clients as $c) { if ($c['stream'] === $path) $clientCount++; }
            $pendingCount = 0;
            foreach ($this->pendingClients as $c) { if ($c['stream'] === $path) $pendingCount++; }
            $upstreamStatus = ($stream['upstreamSocket'] && $this->isStreamValid($stream['upstreamSocket'])) ? '✓' : '✗';
            $lastRead = $stream['lastReadTime'] ? (time() - $stream['lastReadTime']) . 's' : '-';
            $gopSize = strlen($stream['cache']['gopData'] ?? '');
            $ringUsage = min($stream['ringTotalWritten'], self::RING_BUFFER_SIZE);
            $this->log("  /{$path} 上游:{$upstreamStatus} 客户端:{$clientCount}+{$pendingCount} "
                . "收:" . $this->formatBytes($stream['bytesReceived'])
                . " 发:" . $this->formatBytes($stream['bytesSent'])
                . " 环形:" . $this->formatBytes($ringUsage) . " GOP:" . $this->formatBytes($gopSize) . " 读:{$lastRead}");
        }
        $this->log("==========================");
    }

    private function formatBytes($b)
    {
        if ($b >= 1048576) return round($b / 1048576, 2) . 'MB';
        if ($b >= 1024) return round($b / 1024, 2) . 'KB';
        return $b . 'B';
    }
}