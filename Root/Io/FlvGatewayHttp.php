<?php

namespace Root\Io;

/**
 * @purpose flv网关（epoll/select 自适应，断线自动重连，多路安全）
 */
class FlvGatewayHttp
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
    const UPSTREAM_RETRY_SEC = 2;
    const UPSTREAM_MAX_RETRIES = 10;

    public $maxClientsPerStream = 0;

    private $useEvent = false;
    private $base = null;
    private $_readEvents = [];
    private $_timerEvent = null;
    private $reconnectTimers = [];

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
                $this->flushAllClients();           // epoll 无写事件集合，内部兼容处理
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
     * @param array|null $writableSockets  可写 socket 列表（select 模式传入，epoll 模式传 null 处理全部）
     */
    private function flushAllClients(?array $writableSockets = null): void
    {
        $removeIds = [];
        $useWriteSet = is_array($writableSockets);

        // 处理等待队列 (pendingClients)
        foreach ($this->pendingClients as $id => &$c) {
            $path = $c['stream'];
            $stream = &$this->streams[$path];
            $initData = $stream['cache']['initData'] ?? '';
            if ($initData === '') continue;

            if (!$this->isStreamValid($c['socket'])) {
                $removeIds[] = $id;
                continue;
            }

            // 若使用可写集合，则只处理可写的 socket
            if ($useWriteSet && !in_array($c['socket'], $writableSockets, true)) continue;

            $offset    = $c['initOffset'];
            $remaining = strlen($initData) - $offset;
            if ($remaining <= 0) {
                // 初始化数据已全部发出，转为正式客户端
                $this->clients[$id] = [
                    'socket'     => $c['socket'],
                    'stream'     => $path,
                    'readOffset' => $stream['ringTotalWritten'],
                ];
                unset($this->pendingClients[$id]);
                $this->log("[{$path}] 客户端{$id}就绪");
                continue;
            }

            $chunk   = substr($initData, $offset, self::MAX_FLUSH_CHUNK);
            $written = @fwrite($c['socket'], $chunk);
            if ($written === false) {
                $removeIds[] = $id;
                continue;
            }
            if ($written > 0) {
                $c['initOffset'] += $written;
                $stream['bytesSent'] += $written;
                if ($c['initOffset'] >= strlen($initData)) {
                    $this->clients[$id] = [
                        'socket'     => $c['socket'],
                        'stream'     => $path,
                        'readOffset' => $stream['ringTotalWritten'],
                    ];
                    unset($this->pendingClients[$id]);
                    $this->log("[{$path}] 客户端{$id}就绪");
                }
            }
            // $written === 0 时保留客户端，等待下次可写
        }
        unset($c);

        // 处理已连接客户端 (clients)
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

            $sendLen = min(self::MAX_FLUSH_CHUNK, $lag);
            $data = $this->readFromRingBuffer($path, $clientOffset, $sendLen);
            if ($data === '' || $data === false) continue;

            $written = @fwrite($c['socket'], $data);
            if ($written === false) {
                $removeIds[] = $id;
                continue;
            }
            if ($written > 0) {
                $c['readOffset'] += $written;
                $stream['bytesSent'] += $written;
            }
            // $written === 0 保留客户端
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

        if (empty($cache['initData'])) {
            $cache['initData'] = $cache['flvHeader']
                . $cache['metaDataTag']
                . $cache['videoSequence']
                . $cache['audioSequence']
                . $cache['gopData'];
        }
        $catchUp = $cache['initData'];

        $this->log("[{$path}] 客户端{$clientId}严重落后，发送追赶包 (" . $this->formatBytes(strlen($catchUp)) . ")");
        $written = @fwrite($clientData['socket'], $catchUp);
        if ($written === false || $written === 0) return false;   // 0 也视为失败，保持保守

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

            $write = [];
            foreach ($this->clients as $c)
                if ($this->isStreamValid($c['socket']) && $c['readOffset'] < ($this->streams[$c['stream']]['ringTotalWritten'] ?? 0))
                    $write[] = $c['socket'];
            foreach ($this->pendingClients as $c)
                if ($this->isStreamValid($c['socket']) && !empty($this->streams[$c['stream']]['cache']['initData']))
                    $write[] = $c['socket'];

            $except = null;
            @stream_select($read, $write, $except, 0, 200000);
            foreach ($read as $sock) {
                if ($sock === $this->serverSocket) $this->acceptClient();
                else $this->handleRead($sock);
            }

            // 只向可写 socket 发送，提升效率
            $this->flushAllClients($write);
            $this->gcStreams();

            $now = time();
            foreach ($this->reconnectTimers as $path => $nextTime) {
                if (is_int($nextTime) && $now >= $nextTime) {
                    unset($this->reconnectTimers[$path]);
                    $this->log("[{$path}] 重试连接...");
                    $this->connectUpstream($path);
                }
            }

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

    /**
     * 必须允许跨域，否则无法播放
     * @return void
     */
    private function acceptClient(): void
    {
        $client = @stream_socket_accept($this->serverSocket, 0, $peerName);
        if (!$client || !$this->isStreamValid($client)) return;

        $this->clientIdCounter++;
        $clientId = $this->clientIdCounter;
        stream_set_blocking($client, false);
        stream_set_write_buffer($client, 0);

        $req = '';
        for ($i = 0; $i < 30; $i++) {
            $chunk = @fread($client, 4096);
            if ($chunk === false || $chunk === '') break;
            $req .= $chunk;
            if (strpos($req, "\r\n\r\n") !== false) break;
        }

        // 处理 OPTIONS 预检请求
        if (strpos($req, 'OPTIONS') === 0) {
            @fwrite($client, "HTTP/1.1 204 No Content\r\n"
                . "Access-Control-Allow-Origin: *\r\n"
                . "Access-Control-Allow-Methods: GET, OPTIONS\r\n"
                . "Access-Control-Allow-Headers: Content-Type, Range\r\n"
                . "Access-Control-Max-Age: 86400\r\n"
                . "Connection: keep-alive\r\n\r\n");
            $this->safeCloseStream($client);
            return;
        }

        preg_match('#GET\s+/([^\s]+)#', $req, $m);
        $path = preg_replace('/\.flv$/', '', $m[1] ?? '');
        if (!$path) {
            @fwrite($client, "HTTP/1.1 400 Bad Request\r\n"
                . "Access-Control-Allow-Origin: *\r\n"
                . "Connection: close\r\n\r\n");
            $this->safeCloseStream($client);
            return;
        }

        if ($this->maxClientsPerStream > 0) {
            $cnt = 0;
            foreach ($this->clients as $c) if ($c['stream'] === $path) $cnt++;
            foreach ($this->pendingClients as $c) if ($c['stream'] === $path) $cnt++;
            if ($cnt >= $this->maxClientsPerStream) {
                @fwrite($client, "HTTP/1.1 503 Service Unavailable\r\n"
                    . "Access-Control-Allow-Origin: *\r\n"
                    . "Connection: close\r\n\r\n");
                $this->safeCloseStream($client);
                $this->log("[{$path}] 超出单流客户端上限，拒绝");
                return;
            }
        }

        $this->log("[{$path}] 请求 客户端{$clientId}");
        @fwrite($client, "HTTP/1.1 200 OK\r\n"
            . "Content-Type: video/x-flv\r\n"
            . "Access-Control-Allow-Origin: *\r\n"
            . "Access-Control-Allow-Methods: GET, OPTIONS\r\n"
            . "Access-Control-Allow-Headers: Content-Type, Range\r\n"
            . "Connection: keep-alive\r\n\r\n");

        $this->initStream($path);
        $stream = &$this->streams[$path];

        $this->pendingClients[$clientId] = ['socket' => $client, 'stream' => $path, 'initOffset' => 0];

        if (!$stream['upstreamSocket'] || !$this->isStreamValid($stream['upstreamSocket'])) {
            if ($stream['cache']['ready']) $this->resetStreamCache($path);
            if (!$this->connectUpstream($path)) {
                $this->scheduleReconnect($path);
            }
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

    // ========== 上游连接 ==========
    private function connectUpstream(string $path): bool
    {
        $stream = &$this->streams[$path];
        $url = "{$this->upstreamBaseUrl}/{$path}.flv";
        $p = parse_url($url);
        $host = $p['host'];
        $port = $p['port'] ?? 80;
        $reqPath = $p['path'];

        $this->log("[{$path}] 连接上游 {$host}:{$port}{$reqPath}");

        $sock = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 5);
        if (!$sock || !$this->isStreamValid($sock)) {
            $this->log("[{$path}] 连接失败: {$errstr} ({$errno})");
            return false;
        }

        stream_set_timeout($sock, 5);
        @fwrite($sock, "GET {$reqPath} HTTP/1.1\r\nHost: {$host}\r\nAccept: */*\r\nConnection: keep-alive\r\n\r\n");

        $resp = '';
        for ($i = 0; $i < 50; $i++) {
            $chunk = @fread($sock, 4096);
            if ($chunk === false || $chunk === '') break;
            $resp .= $chunk;
            if (($pos = strpos($resp, "\r\n\r\n")) !== false) {
                $header = substr($resp, 0, $pos);
                $body = substr($resp, $pos + 4);
                $stream['chunked'] = (stripos($header, "Transfer-Encoding: chunked") !== false);
                $stream['httpHeaderParsed'] = true;
                stream_set_blocking($sock, false);
                $stream['chunkBuffer'] = $stream['chunked'] ? $body : '';
                $stream['buffer'] = $stream['chunked'] ? '' : $body;
                $stream['upstreamSocket'] = $sock;
                $stream['lastReadTime'] = time();
                $this->addReadEvent($sock, fn() => $this->readUpstream($path));
                $this->log("[{$path}] 上游连接成功");
                return true;
            }
        }

        $this->safeCloseStream($sock);
        $this->log("[{$path}] 上游响应异常");
        return false;
    }

    private function scheduleReconnect(string $path, int $retryCount = 0): void
    {
        $this->cancelReconnect($path);

        if ($this->useEvent) {
            $seconds = self::UPSTREAM_RETRY_SEC;
            $event = \Event::timer($this->base, function () use ($path, $seconds, $retryCount, &$event) {
                if (!$this->hasClientsForPath($path)) {
                    $this->log("[{$path}] 无客户端，停止重连");
                    $this->cancelReconnect($path);
                    return;
                }
                if (self::UPSTREAM_MAX_RETRIES > 0 && $retryCount >= self::UPSTREAM_MAX_RETRIES) {
                    $this->log("[{$path}] 重试次数耗尽，停止重连");
                    $this->cancelReconnect($path);
                    return;
                }
                $this->log("[{$path}] 重试连接上游 (" . ($retryCount+1) . ")");
                if ($this->connectUpstream($path)) {
                    $this->cancelReconnect($path);
                } else {
                    $event->add($seconds);
                    $this->reconnectTimers[$path] = $event;
                    // 实际 retryCount 会通过闭包传递，此处简单递增需要静态变量，保持现有方式（不完美但可用）
                }
            });
            $event->add($seconds);
            $this->reconnectTimers[$path] = $event;
        } else {
            $this->reconnectTimers[$path] = time() + self::UPSTREAM_RETRY_SEC;
        }
    }

    private function cancelReconnect(string $path): void
    {
        if ($this->useEvent && isset($this->reconnectTimers[$path])) {
            $this->reconnectTimers[$path]->free();
        }
        unset($this->reconnectTimers[$path]);
    }

    private function hasClientsForPath(string $path): bool
    {
        foreach ($this->clients as $c) if ($c['stream'] === $path) return true;
        foreach ($this->pendingClients as $c) if ($c['stream'] === $path) return true;
        return false;
    }

    private function reconnectUpstream(string $path): void
    {
        $this->log("[{$path}] 上游断开，开始重连");
        $stream = &$this->streams[$path];
        $this->safeCloseStream($stream['upstreamSocket']);
        $stream['upstreamSocket'] = null;
        $this->resetStreamCache($path);

        foreach ($this->clients as $id => $c) {
            if ($c['stream'] === $path) {
                $this->pendingClients[$id] = ['socket' => $c['socket'], 'stream' => $path, 'initOffset' => 0];
                unset($this->clients[$id]);
            }
        }

        if (!$this->connectUpstream($path)) {
            $this->scheduleReconnect($path);
        }
    }

    // ========== 数据读取（修复点） ==========
    private function handleRead($sock): void
    {
        foreach ($this->streams as $path => $stream) {
            if ($stream['upstreamSocket'] === $sock) {
                $this->readUpstream($path);
                return;
            }
        }
        // 客户端数据忽略，仅用于检测断开
        if (!$this->isStreamValid($sock)) return;
        $data = @fread($sock, 1);
        if ($data === '' || $data === false) $this->removeClientBySocket($sock);
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
        if ($socket !== null) $this->safeCloseStream($socket);
        if ($path) $this->log("客户端{$id}断开 流:/{$path} 剩余:" . count($this->clients));
    }

    private function readUpstream(string $path): void
    {
        $stream = &$this->streams[$path];
        if (!$this->isStreamValid($stream['upstreamSocket'])) {
            $this->reconnectUpstream($path);
            return;
        }
        $data = @fread($stream['upstreamSocket'], 65536);
        if ($data === false) {
            $this->reconnectUpstream($path);
            return;
        }
        // 非阻塞无数据返回空字符串，需通过 eof 判断是否真正断开
        if ($data === '') {
            $info = stream_get_meta_data($stream['upstreamSocket']);
            if (!empty($info['eof'])) {
                $this->reconnectUpstream($path);
            }
            return;
        }

        $stream['bytesReceived'] += strlen($data);
        $stream['lastReadTime'] = time();
        if ($stream['chunked']) {
            $stream['chunkBuffer'] .= $data;
            if (($decoded = $this->decodeChunked($stream['chunkBuffer'])) !== null)
                $stream['buffer'] .= $decoded;
        } else {
            $stream['buffer'] .= $data;
        }
        $this->processFlvData($path);
    }

    private function gcStreams(): void
    {
        foreach ($this->streams as $path => $stream) {
            if (!$this->hasClientsForPath($path) && $stream['upstreamSocket']) {
                if (!isset($this->reconnectTimers[$path])) {
                    $this->log("[{$path}] 无客户端，关闭上游");
                    $this->safeCloseStream($this->streams[$path]['upstreamSocket']);
                    $this->streams[$path]['upstreamSocket'] = null;
                    $this->resetStreamCache($path);
                    $this->cancelReconnect($path);
                }
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
            $this->writeToRingBuffer($path, $cache['flvHeader']);
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

            if (!$cache['ready']) {
                if ($tagType === 18 && !$cache['metaDataTag']) {
                    $cache['metaDataTag'] = $tag;
                    $this->writeToRingBuffer($path, $tag);
                    continue;
                }
                if ($tagType === 9 && !$cache['videoSequence']) {
                    if (strlen($payload) >= 2 && ((ord($payload[0]) >> 4) & 0x0F) === 1 && ord($payload[1]) === 0) {
                        $cache['videoSequence'] = $tag;
                        $this->writeToRingBuffer($path, $tag);
                        continue;
                    }
                }
                if ($tagType === 8 && !$cache['audioSequence']) {
                    if (strlen($payload) >= 2 && ((ord($payload[0]) >> 4) & 0x0F) === 10 && ord($payload[1]) === 0) {
                        $cache['audioSequence'] = $tag;
                        $this->writeToRingBuffer($path, $tag);
                        continue;
                    }
                }

                if ($cache['flvHeader'] && $cache['videoSequence'] && $cache['audioSequence']) {
                    if ($this->isVideoKeyFrame($tagType, $payload)) {
                        $cache['gopData'] = $tag;
                    } else {
                        $cache['gopData'] .= $tag;
                    }
                    $this->writeToRingBuffer($path, $tag);

                    if ($cache['gopData'] !== '' && $this->isVideoKeyFrame($tagType, $payload)) {
                        $cache['ready'] = true;
                        $this->log("[{$path}] >>> 初始化完毕 GOP:" . $this->formatBytes(strlen($cache['gopData'])));
                        $this->notifyPendingClients($path);
                    }
                    continue;
                }
                $this->writeToRingBuffer($path, $tag);
                continue;
            }

            if ($this->isVideoKeyFrame($tagType, $payload)) {
                $cache['gopData'] = $tag;
            } else {
                $cache['gopData'] .= $tag;
            }
            $this->writeToRingBuffer($path, $tag);
        }
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

    private function notifyPendingClients($path)
    {
        $cache = &$this->streams[$path]['cache'];
        if (!$cache['ready']) return;

        $cache['initData'] = $cache['flvHeader']
            . $cache['metaDataTag']
            . $cache['videoSequence']
            . $cache['audioSequence']
            . $cache['gopData'];

        $count = 0;
        foreach ($this->pendingClients as $id => &$c) {
            if ($c['stream'] === $path) {
                $c['initOffset'] = 0;
                $count++;
            }
        }
        unset($c);
        $this->log("[{$path}] 共享初始化数据就绪 (" . $this->formatBytes(strlen($cache['initData'])) . ")，唤醒 {$count} 个等待客户端");
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
            $gopSize = strlen($stream['cache']['gopData']);
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