<?php

namespace Root\Io;

class FlvGateway
{
    public $upstreamBaseUrl = 'http://127.0.0.1:8501';
    public $listenPort = 8080;

    private $serverSocket = null;
    private $streams = [];
    private $clients = [];
    private $pendingClients = [];

    public $debug = true;
    private $statsInterval = 10;
    private $lastStatsTime = 0;
    private $clientIdCounter = 0;

    const RING_BUFFER_SIZE = 8 * 1024 * 1024;   // 8MB
    const MAX_FLUSH_CHUNK  = 65536;             // 64KB

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

    // --------------------------------------------------
    // Socket 有效性检查（兼容 PHP 8）
    // --------------------------------------------------
    private function isSocketValid($sock)
    {
        if ($sock === null) return false;
        if ($sock instanceof \Socket) {
            try {
                return @socket_get_option($sock, SOL_SOCKET, SO_TYPE) !== false;
            } catch (\Error $e) {
                return false;
            }
        }
        return false;
    }

    private function safeCloseSocket(&$sock)
    {
        if ($sock === null) return;
        try {
            if ($this->isSocketValid($sock)) {
                @socket_shutdown($sock, 2);
                @socket_close($sock);
            }
        } catch (\Error $e) {}
        $sock = null;
    }

    // --------------------------------------------------
    // 启动
    // --------------------------------------------------
    public function start()
    {
        $this->serverSocket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (!$this->serverSocket) die("ERROR: 创建 socket 失败\n");
        @socket_set_option($this->serverSocket, SOL_SOCKET, SO_REUSEADDR, 1);
        if (!@socket_bind($this->serverSocket, '0.0.0.0', $this->listenPort)) {
            $err = socket_last_error($this->serverSocket);
            die("ERROR: 端口 {$this->listenPort} 绑定失败 (错误码 {$err})\n");
        }
        if (!@socket_listen($this->serverSocket, 65535)) die("ERROR: 监听失败\n");
        @socket_set_nonblock($this->serverSocket);

        $this->log("✅ 网关已启动 监听 0.0.0.0:{$this->listenPort} 上游 {$this->upstreamBaseUrl}");
        $this->lastStatsTime = time();
        $this->eventLoop();
    }

    // --------------------------------------------------
    // 事件循环
    // --------------------------------------------------
    private function eventLoop()
    {
        while (true) {
            $read = [];
            if ($this->isSocketValid($this->serverSocket)) {
                $read[] = $this->serverSocket;
            } else break;

            foreach ($this->streams as $s) {
                if ($this->isSocketValid($s['upstreamSocket'])) $read[] = $s['upstreamSocket'];
            }
            foreach ($this->clients as $c) {
                if ($this->isSocketValid($c['socket'])) $read[] = $c['socket'];
            }
            foreach ($this->pendingClients as $c) {
                if ($this->isSocketValid($c['socket'])) $read[] = $c['socket'];
            }

            $write = [];
            foreach ($this->clients as $c) {
                if ($this->isSocketValid($c['socket']) &&
                    $c['readOffset'] < ($this->streams[$c['stream']]['ringTotalWritten'] ?? 0)) {
                    $write[] = $c['socket'];
                }
            }
            foreach ($this->pendingClients as $c) {
                if ($this->isSocketValid($c['socket']) &&
                    !empty($this->streams[$c['stream']]['cache']['initData'])) {
                    $write[] = $c['socket'];
                }
            }

            $n = @socket_select($read, $write, $except, 0, 200000);
            if ($n === false) { usleep(10000); continue; }
            if ($n > 0) {
                foreach ($read as $sock) {
                    if (!$this->isSocketValid($sock)) continue;
                    if ($sock === $this->serverSocket) $this->acceptClient();
                    else $this->handleRead($sock);
                }
            }

            $this->flushAllClients();
            $this->gcStreams();

            if ($this->debug && time() - $this->lastStatsTime >= $this->statsInterval) {
                $this->printStats();
                $this->lastStatsTime = time();
            }
        }
    }

    // --------------------------------------------------
    // 客户端接入
    // --------------------------------------------------
    private function acceptClient()
    {
        $client = @socket_accept($this->serverSocket);
        if (!$client || !$this->isSocketValid($client)) return;

        $this->clientIdCounter++;
        $clientId = $this->clientIdCounter;
        @socket_set_nonblock($client);
        @socket_set_option($client, SOL_SOCKET, TCP_NODELAY, 1);
        @socket_set_option($client, SOL_SOCKET, SO_SNDBUF, 65536);

        // 读取 HTTP 请求
        $req = '';
        for ($i = 0; $i < 30; $i++) {
            if (!$this->isSocketValid($client)) break;
            $chunk = @socket_read($client, 4096);
            if ($chunk === false) { usleep(1000); continue; }
            if ($chunk === '') break;
            $req .= $chunk;
            if (strpos($req, "\r\n\r\n") !== false) break;
        }

        preg_match('#GET\s+/([^\s]+)#', $req, $m);
        $path = preg_replace('/\.flv$/', '', $m[1] ?? '');
        if (!$path) {
            @socket_write($client, "HTTP/1.1 400\r\nConnection: close\r\n\r\n");
            $this->safeCloseSocket($client);
            return;
        }

        $this->log("[{$path}] 请求 客户端{$clientId}");
        @socket_write($client, "HTTP/1.1 200 OK\r\nContent-Type: video/x-flv\r\nConnection: keep-alive\r\n\r\n");

        // 初始化流并确保共享 initData 存在（若缓存已就绪）
        $this->initStream($path);
        $stream = &$this->streams[$path];
        if ($stream['cache']['ready'] && empty($stream['cache']['initData'])) {
            // 构建共享初始化数据（通常已在 notifyPendingClients 中设置，此处作为兜底）
            $stream['cache']['initData'] = $stream['cache']['flvHeader']
                . $stream['cache']['metaDataTag']
                . $stream['cache']['videoSequence']
                . $stream['cache']['audioSequence']
                . $stream['cache']['gopData'];
        }

        // 所有新客户端先进入 pending，使用共享 initData + 偏移量
        $this->pendingClients[$clientId] = [
            'socket'     => $client,
            'stream'     => $path,
            'initOffset' => 0,
        ];

        if (!$stream['cache']['ready'] &&
            (!$stream['upstreamSocket'] || !$this->isSocketValid($stream['upstreamSocket']))) {
            $this->connectUpstream($path);
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
        if ($socket !== null) $this->safeCloseSocket($socket);
        if ($path) $this->log("客户端{$id}断开 流:/{$path} 剩余:" . count($this->clients));
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

    // --------------------------------------------------
    // 流初始化（每个节目独立）
    // --------------------------------------------------
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
                    'initData'      => '',   // 共享初始化数据（仅一份）
                    'ready'         => false,
                ],
            ];
        }
        return $this->streams[$path];
    }

    // --------------------------------------------------
    // 环形缓冲区（每个节目独立）
    // --------------------------------------------------
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

    // --------------------------------------------------
    // 数据发送（共享 initData 与环形缓冲）
    // --------------------------------------------------
    private function flushAllClients()
    {
        $removeIds = [];

        // 1. pending 客户端：从共享 initData 发送
        foreach ($this->pendingClients as $id => &$c) {
            $path = $c['stream'];
            $stream = &$this->streams[$path];
            $initData = $stream['cache']['initData'] ?? '';
            if ($initData === '') continue;       // 初始化数据尚未就绪

            if (!$this->isSocketValid($c['socket'])) {
                $removeIds[] = $id;
                continue;
            }

            $offset    = $c['initOffset'];
            $remaining = strlen($initData) - $offset;
            if ($remaining <= 0) {
                // 已发完，转入正式客户端
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
            $written = @socket_write($c['socket'], $chunk);
            if ($written === false) {
                $err = socket_last_error($c['socket']);
                if ($err !== SOCKET_EWOULDBLOCK && $err !== 0) $removeIds[] = $id;
                continue;
            }

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
        unset($c);

        // 2. 正式客户端：从共享环形缓冲区发送
        foreach ($this->clients as $id => &$c) {
            if (!$this->isSocketValid($c['socket'])) {
                $removeIds[] = $id;
                continue;
            }
            $path = $c['stream'];
            $stream = &$this->streams[$path];
            $totalWritten = $stream['ringTotalWritten'];
            $clientOffset = $c['readOffset'];

            if ($clientOffset >= $totalWritten) continue;

            $lag = $totalWritten - $clientOffset;

            // 严重落后：发送追赶包（内部重置 readOffset）
            if ($lag > self::RING_BUFFER_SIZE) {
                if (!$this->sendCatchUpPacketInternal($id, $c)) {
                    $removeIds[] = $id;
                }
                continue;
            }

            $sendLen = min(self::MAX_FLUSH_CHUNK, $lag);
            $data = $this->readFromRingBuffer($path, $clientOffset, $sendLen);
            if ($data === '' || $data === false) continue;

            $written = @socket_write($c['socket'], $data);
            if ($written === false) {
                $err = socket_last_error($c['socket']);
                if ($err !== SOCKET_EWOULDBLOCK && $err !== 0) $removeIds[] = $id;
                continue;
            }
            $c['readOffset'] += $written;
            $stream['bytesSent'] += $written;
        }
        unset($c);

        // 统一移除
        foreach ($removeIds as $id) $this->removeClient($id);
    }

    // 追赶包：向客户端发送最新的初始化数据，并重置读取位置
    private function sendCatchUpPacketInternal($clientId, &$clientData)
    {
        if (!isset($this->clients[$clientId])) return false;
        $path   = $clientData['stream'];
        $stream = &$this->streams[$path];
        $cache  = $stream['cache'];
        if (!$cache['ready'] || !$this->isSocketValid($clientData['socket'])) return false;

        // 使用共享 initData（确保存在）
        if (empty($cache['initData'])) {
            $cache['initData'] = $cache['flvHeader']
                . $cache['metaDataTag']
                . $cache['videoSequence']
                . $cache['audioSequence']
                . $cache['gopData'];
        }
        $catchUp = $cache['initData'];

        $this->log("[{$path}] 客户端{$clientId}严重落后，发送追赶包 (" . $this->formatBytes(strlen($catchUp)) . ")");
        $written = @socket_write($clientData['socket'], $catchUp);
        if ($written === false) return false;

        $clientData['readOffset'] = $stream['ringTotalWritten'];
        $stream['bytesSent'] += $written;
        return true;
    }

    // --------------------------------------------------
    // 上游连接与重连
    // --------------------------------------------------
    private function connectUpstream($path)
    {
        $stream = &$this->streams[$path];
        $url = "{$this->upstreamBaseUrl}/{$path}.flv";
        $p = parse_url($url);
        $host = $p['host'];
        $port = $p['port'] ?? 80;
        $reqPath = $p['path'];

        $this->log("[{$path}] 正在连接上游 {$host}:{$port}{$reqPath}");

        $sock = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (!$sock || !$this->isSocketValid($sock)) {
            $this->log("[{$path}] 创建 socket 失败");
            return;
        }

        @socket_set_option($sock, SOL_SOCKET, SO_RCVBUF, 262144);
        if (!@socket_connect($sock, $host, $port)) {
            $err = socket_last_error($sock);
            $this->log("[{$path}] 连接上游失败，错误码: {$err}");
            $this->safeCloseSocket($sock);
            return;
        }

        $req = "GET {$reqPath} HTTP/1.1\r\nHost: {$host}\r\nAccept: */*\r\nConnection: keep-alive\r\n\r\n";
        @socket_write($sock, $req);

        $resp = '';
        for ($i = 0; $i < 50; $i++) {
            if (!$this->isSocketValid($sock)) break;
            $chunk = @socket_read($sock, 4096);
            if ($chunk === false) { usleep(10000); continue; }
            if ($chunk === '') break;
            $resp .= $chunk;
            $pos = strpos($resp, "\r\n\r\n");
            if ($pos !== false) {
                $header = substr($resp, 0, $pos);
                $body   = substr($resp, $pos + 4);
                $stream['chunked'] = (stripos($header, "Transfer-Encoding: chunked") !== false);
                $stream['httpHeaderParsed'] = true;
                @socket_set_nonblock($sock);
                if ($stream['chunked']) { $stream['chunkBuffer'] = $body; }
                else { $stream['buffer'] = $body; }
                $stream['upstreamSocket'] = $sock;
                $stream['lastReadTime'] = time();
                $this->log("[{$path}] 上游连接成功");
                return;
            }
        }
        $this->safeCloseSocket($sock);
    }

    private function reconnectUpstream($path)
    {
        $stream = &$this->streams[$path];
        $this->safeCloseSocket($stream['upstreamSocket']);

        // 重置流状态，但保留客户端列表（他们将被移回 pending）
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
            'initData'      => '',   // 清空共享初始化数据
            'ready'         => false,
        ];

        // 所有正式客户端移回 pending，等待新流初始化
        foreach ($this->clients as $id => $c) {
            if ($c['stream'] === $path) {
                $this->pendingClients[$id] = [
                    'socket'     => $c['socket'],
                    'stream'     => $path,
                    'initOffset' => 0,
                ];
                unset($this->clients[$id]);
            }
        }

        $this->log("[{$path}] 2秒后重连上游...");
        sleep(2);
        $this->connectUpstream($path);
    }

    // --------------------------------------------------
    // 数据读取与解析
    // --------------------------------------------------
    private function handleRead($sock)
    {
        foreach ($this->streams as $path => $stream) {
            if ($stream['upstreamSocket'] === $sock) {
                $this->readUpstream($path);
                return;
            }
        }

        // 客户端断开检测
        if (!$this->isSocketValid($sock)) {
            $this->removeClientBySocket($sock);
            return;
        }
        $data = @socket_read($sock, 1);
        if ($data === '' || ($data === false && socket_last_error($sock) !== SOCKET_EWOULDBLOCK)) {
            $this->removeClientBySocket($sock);
        }
    }

    private function readUpstream($path)
    {
        $stream = &$this->streams[$path];
        if (!$this->isSocketValid($stream['upstreamSocket'])) {
            $this->reconnectUpstream($path);
            return;
        }

        $data = @socket_read($stream['upstreamSocket'], 65536);
        if ($data === false || $data === '') {
            $this->log("[{$path}] 上游断开");
            $this->reconnectUpstream($path);
            return;
        }

        $stream['bytesReceived'] += strlen($data);
        $stream['lastReadTime'] = time();

        if ($stream['chunked']) {
            $stream['chunkBuffer'] .= $data;
            $decoded = $this->decodeChunked($stream['chunkBuffer']);
            if ($decoded !== null) $stream['buffer'] .= $decoded;
        } else {
            $stream['buffer'] .= $data;
        }
        $this->processFlvData($path);
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
                // 收集 MetaData
                if ($tagType === 18 && !$cache['metaDataTag']) {
                    $cache['metaDataTag'] = $tag;
                    $this->writeToRingBuffer($path, $tag);
                    $this->log("[{$path}] MetaData ✓");
                    continue;
                }
                // 收集视频序列头
                if ($tagType === 9 && !$cache['videoSequence']) {
                    if (strlen($payload) >= 2 && ((ord($payload[0]) >> 4) & 0x0F) === 1 && ord($payload[1]) === 0) {
                        $cache['videoSequence'] = $tag;
                        $this->writeToRingBuffer($path, $tag);
                        $this->log("[{$path}] 视频序列头 ✓");
                        continue;
                    }
                }
                // 收集音频序列头
                if ($tagType === 8 && !$cache['audioSequence']) {
                    if (strlen($payload) >= 2 && ((ord($payload[0]) >> 4) & 0x0F) === 10 && ord($payload[1]) === 0) {
                        $cache['audioSequence'] = $tag;
                        $this->writeToRingBuffer($path, $tag);
                        $this->log("[{$path}] 音频序列头 ✓");
                        continue;
                    }
                }

                // 基础信息收集完毕，开始构建 GOP
                if ($cache['flvHeader'] && $cache['videoSequence'] && $cache['audioSequence']) {
                    if ($this->isVideoKeyFrame($tagType, $payload)) {
                        $cache['gopData'] = $tag;   // 新 GOP 开始
                        $this->log("[{$path}] GOP重置 新关键帧");
                    } else {
                        $cache['gopData'] .= $tag;
                    }
                    $this->writeToRingBuffer($path, $tag);

                    // 当遇到关键帧且 GOP 不为空时，标记就绪
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

            // 已就绪：持续更新 GOP（用于追赶包）并写入环形缓冲
            if ($this->isVideoKeyFrame($tagType, $payload)) {
                $cache['gopData'] = $tag;
            } else {
                $cache['gopData'] .= $tag;
            }
            $this->writeToRingBuffer($path, $tag);
        }
    }

    // 构建共享初始化数据并通知等待客户端
    private function notifyPendingClients($path)
    {
        $cache = &$this->streams[$path]['cache'];
        if (!$cache['ready']) return;

        // 构建共享 initData（仅一份）
        $cache['initData'] = $cache['flvHeader']
            . $cache['metaDataTag']
            . $cache['videoSequence']
            . $cache['audioSequence']
            . $cache['gopData'];

        $count = 0;
        foreach ($this->pendingClients as $id => &$c) {
            if ($c['stream'] === $path) {
                $c['initOffset'] = 0;   // 重置偏移，准备发送
                $count++;
            }
        }
        unset($c);
        $this->log("[{$path}] 共享初始化数据就绪 (" . $this->formatBytes(strlen($cache['initData'])) . ")，唤醒 {$count} 个等待客户端");
    }

    // --------------------------------------------------
    // 资源清理与统计
    // --------------------------------------------------
    private function gcStreams()
    {
        foreach ($this->streams as $path => $stream) {
            $hasClient = false;
            foreach ($this->clients as $c) { if ($c['stream'] === $path) { $hasClient = true; break; } }
            foreach ($this->pendingClients as $c) { if ($c['stream'] === $path) { $hasClient = true; break; } }
            if (!$hasClient && $stream['upstreamSocket']) {
                $this->log("[{$path}] 无客户端，关闭上游");
                $this->safeCloseSocket($this->streams[$path]['upstreamSocket']);
                $this->streams[$path]['upstreamSocket'] = null;
            }
        }
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
            $upstreamStatus = ($stream['upstreamSocket'] && $this->isSocketValid($stream['upstreamSocket'])) ? '✓' : '✗';
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