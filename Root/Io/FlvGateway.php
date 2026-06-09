<?php

namespace Root\Io;

/**
 * @purpose flv网关
 * @author yanglong
 * @note 启动命令 php flvGateway.php 8080 http://127.0.0.1:8501
 * @note 可以实现多节点多层级部署，示例如下
 *  # 一级网关
 *  php flvGateway.php 8080 http://127.0.0.1:8501
 *
 *  # 二级网关
 *  php flvGateway.php 8081 http://127.0.0.1:8080
 *
 *  # 三级网关
 *  php flvGateway.php 8082 http://127.0.0.1:8081
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

    // ====== 客户端ID计数器 ======
    private $clientIdCounter = 0;

    public function __construct($port = null, $upstream = null)
    {
        if ($port !== null) $this->listenPort = (int)$port;
        if ($upstream !== null) $this->upstreamBaseUrl = rtrim($upstream, '/');
    }

    private function log($msg)
    {
        if ($this->debug) {
            $time = date('H:i:s');
            echo "[{$time}] {$msg}\n";
        }
    }

    public function start()
    {
        $this->serverSocket = \socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        \socket_set_option($this->serverSocket, SOL_SOCKET, SO_REUSEADDR, 1);
        \socket_bind($this->serverSocket, '0.0.0.0', $this->listenPort);
        \socket_listen($this->serverSocket, 1024);
        \socket_set_nonblock($this->serverSocket);

        $this->log("网关启动 端口:{$this->listenPort} 上游:{$this->upstreamBaseUrl}");
        $this->lastStatsTime = time();
        $this->eventLoop();
    }

    private function initStream($path)
    {
        if (!isset($this->streams[$path])) {
            $this->streams[$path] = [
                'path' => $path,
                'upstreamSocket' => null,
                'buffer' => '',
                'chunkBuffer' => '',
                'chunked' => false,
                'httpHeaderParsed' => false,
                'bytesReceived' => 0,
                'bytesSent' => 0,
                'lastReadTime' => 0,
                'cache' => [
                    'flvHeader' => '',
                    'metaDataTag' => '',
                    'videoSequence' => '',
                    'audioSequence' => '',
                    'gopData' => '',
                    'gopKeyFrameCount' => 0,
                    'ready' => false,
                ],
            ];
            $this->log("[{$path}] 新流创建");
        }
        return $this->streams[$path];
    }

    private function eventLoop()
    {
        while (true) {
            $read = [$this->serverSocket];
            foreach ($this->streams as $stream) {
                if ($stream['upstreamSocket']) $read[] = $stream['upstreamSocket'];
            }
            foreach ($this->clients as $c) $read[] = $c['socket'];
            foreach ($this->pendingClients as $c) $read[] = $c['socket'];

            $write = [];
            foreach ($this->clients as $c) {
                if (!empty($c['sendBuffer'])) $write[] = $c['socket'];
            }
            foreach ($this->pendingClients as $c) {
                if (!empty($c['initData'])) $write[] = $c['socket'];
            }

            $except = null;
            $n = @socket_select($read, $write, $except, 0, 200000);
            if ($n === false) { usleep(10000); continue; }

            if ($n > 0) {
                foreach ($read as $sock) {
                    if ($sock === $this->serverSocket) {
                        $this->acceptClient();
                    } else {
                        $this->handleRead($sock);
                    }
                }
            }

            $this->flushAllClients();

            if ($this->debug && time() - $this->lastStatsTime >= $this->statsInterval) {
                $this->printStats();
                $this->lastStatsTime = time();
            }
        }
    }

    private function flushAllClients()
    {
        // 发送pending客户端的初始化数据
        foreach ($this->pendingClients as $id => &$c) {
            if (empty($c['initData'])) continue;

            // ====== 限制每次发送量，避免阻塞 ======
            $chunk = substr($c['initData'], 0, 65536); // 最多64KB
            $written = @socket_write($c['socket'], $chunk);

            if ($written === false) {
                $err = socket_last_error($c['socket']);
                if ($err !== SOCKET_EWOULDBLOCK && $err !== 0) {
                    $this->removeClient($id);
                }
                continue;
            }

            $c['initData'] = substr($c['initData'], $written);
            if (isset($this->streams[$c['stream']])) {
                $this->streams[$c['stream']]['bytesSent'] += $written;
            }

            if (empty($c['initData'])) {
                $this->clients[$id] = [
                    'socket' => $c['socket'],
                    'stream' => $c['stream'],
                    'sendBuffer' => ''
                ];
                unset($this->pendingClients[$id]);
                $this->log("[{$c['stream']}] 客户端{$id}就绪 总数:" . count($this->clients));
            }
        }
        unset($c);

        // 发送正式客户端的缓冲数据
        foreach ($this->clients as $id => &$c) {
            if (empty($c['sendBuffer'])) continue;

            // ====== 限制每次发送量 ======
            $chunk = substr($c['sendBuffer'], 0, 65536);
            $written = @socket_write($c['socket'], $chunk);

            if ($written === false) {
                $err = socket_last_error($c['socket']);
                if ($err !== SOCKET_EWOULDBLOCK && $err !== 0) {
                    $this->removeClient($id);
                }
                continue;
            }

            $c['sendBuffer'] = substr($c['sendBuffer'], $written);
            if (isset($this->streams[$c['stream']])) {
                $this->streams[$c['stream']]['bytesSent'] += $written;
            }
        }
        unset($c);
    }

    private function printStats()
    {
        $this->log("========== 统计 ==========");
        $this->log("活跃流:" . count($this->streams) . " 客户端:" . count($this->clients) . " 等待:" . count($this->pendingClients));
        foreach ($this->streams as $path => $stream) {
            $clientCount = 0; $totalBuffer = 0;
            foreach ($this->clients as $c) {
                if ($c['stream'] === $path) { $clientCount++; $totalBuffer += strlen($c['sendBuffer']); }
            }
            $pendingCount = 0;
            foreach ($this->pendingClients as $c) { if ($c['stream'] === $path) $pendingCount++; }
            $upstreamStatus = $stream['upstreamSocket'] ? '✓' : '✗';
            $lastRead = $stream['lastReadTime'] ? (time() - $stream['lastReadTime']) . 's' : '-';
            $gopSize = strlen($stream['cache']['gopData']);
            $this->log("  /{$path} 上游:{$upstreamStatus} 客户端:{$clientCount}+{$pendingCount} "
                . "收:" . $this->formatBytes($stream['bytesReceived'])
                . " 发:" . $this->formatBytes($stream['bytesSent'])
                . " 缓冲:{$totalBuffer}B GOP:" . $this->formatBytes($gopSize) . " 读:{$lastRead}");
        }
        $this->log("==========================");
    }

    private function formatBytes($b)
    {
        if ($b >= 1048576) return round($b / 1048576, 2) . 'MB';
        if ($b >= 1024) return round($b / 1024, 2) . 'KB';
        return $b . 'B';
    }

    private function acceptClient()
    {
        $client = @socket_accept($this->serverSocket);
        if (!$client) return;

        // ====== 使用独立ID计数器，避免socket对象转int的问题 ======
        $this->clientIdCounter++;
        $clientId = $this->clientIdCounter;

        \socket_set_nonblock($client);
        @\socket_set_option($client, SOL_SOCKET, TCP_NODELAY, 1);

        // ====== SO_SNDBUF可能受限，降低到64KB ======
        @\socket_set_option($client, SOL_SOCKET, SO_SNDBUF, 65536);

        $req = '';
        for ($i = 0; $i < 30; $i++) {
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
            socket_close($client);
            return;
        }

        $this->log("[{$path}] 请求 客户端{$clientId}");
        @socket_write($client, "HTTP/1.1 200 OK\r\nContent-Type: video/x-flv\r\nConnection: keep-alive\r\n\r\n");

        $stream = $this->initStream($path);

        if ($stream['cache']['ready']) {
            $initData = $stream['cache']['flvHeader']
                . $stream['cache']['metaDataTag']
                . $stream['cache']['videoSequence']
                . $stream['cache']['audioSequence']
                . $stream['cache']['gopData'];
            $this->clients[$clientId] = ['socket' => $client, 'stream' => $path, 'sendBuffer' => $initData];
            $this->log("[{$path}] 客户端{$clientId}缓存命中 " . $this->formatBytes(strlen($initData)));
            return;
        }

        $this->pendingClients[$clientId] = ['socket' => $client, 'stream' => $path, 'initData' => ''];
        if (!$stream['upstreamSocket']) $this->connectUpstream($path);
    }

    private function connectUpstream($path)
    {
        $stream = &$this->streams[$path];
        $url = "{$this->upstreamBaseUrl}/{$path}.flv";
        $p = parse_url($url);
        $host = $p['host'];
        $port = $p['port'] ?? 80;
        $reqPath = $p['path'];

        $this->log("[{$path}] 连接上游 {$host}:{$port}{$reqPath}");

        $sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($sock, SOL_SOCKET, SO_RCVBUF, 262144);
        if (!@socket_connect($sock, $host, $port)) { socket_close($sock); return; }

        $req = "GET {$reqPath} HTTP/1.1\r\nHost: {$host}\r\nAccept: */*\r\nConnection: keep-alive\r\n\r\n";
        socket_write($sock, $req);

        $resp = '';
        for ($i = 0; $i < 50; $i++) {
            $chunk = @socket_read($sock, 4096);
            if ($chunk === false) { usleep(10000); continue; }
            if ($chunk === '') break;
            $resp .= $chunk;
            $pos = strpos($resp, "\r\n\r\n");
            if ($pos !== false) {
                $header = substr($resp, 0, $pos);
                $body = substr($resp, $pos + 4);
                $stream['chunked'] = (stripos($header, "chunked") !== false);
                $stream['httpHeaderParsed'] = true;
                socket_set_nonblock($sock);
                if ($stream['chunked']) { $stream['chunkBuffer'] = $body; }
                else { $stream['buffer'] = $body; }
                $stream['upstreamSocket'] = $sock;
                $stream['lastReadTime'] = time();
                $this->log("[{$path}] 上游连接成功");
                return;
            }
        }
        socket_close($sock);
    }

    private function handleRead($sock)
    {
        foreach ($this->streams as $path => &$stream) {
            if ($stream['upstreamSocket'] === $sock) { $this->readUpstream($path); return; }
        }
        unset($stream);

        // ====== 检测客户端断开 ======
        $data = @socket_read($sock, 1);
        if ($data === '' || ($data === false && socket_last_error($sock) !== SOCKET_EWOULDBLOCK)) {
            // 查找并移除该socket对应的客户端
            $this->removeClientBySocket($sock);
        }
    }

    /**
     * 根据socket资源移除客户端
     */
    private function removeClientBySocket($sock)
    {
        foreach ($this->clients as $id => $c) {
            if ($c['socket'] === $sock) {
                $this->removeClient($id);
                return;
            }
        }
        foreach ($this->pendingClients as $id => $c) {
            if ($c['socket'] === $sock) {
                $this->removeClient($id);
                return;
            }
        }
    }

    private function readUpstream($path)
    {
        $stream = &$this->streams[$path];
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

    private function reconnectUpstream($path)
    {
        $stream = &$this->streams[$path];
        if ($stream['upstreamSocket']) {
            @socket_close($stream['upstreamSocket']);
            $stream['upstreamSocket'] = null;
        }
        $stream['buffer'] = '';
        $stream['chunkBuffer'] = '';
        $stream['chunked'] = false;
        $stream['httpHeaderParsed'] = false;
        $stream['cache'] = [
            'flvHeader' => '', 'metaDataTag' => '',
            'videoSequence' => '', 'audioSequence' => '',
            'gopData' => '', 'gopKeyFrameCount' => 0, 'ready' => false
        ];

        // 现有客户端移到等待队列
        foreach ($this->clients as $id => $c) {
            if ($c['stream'] === $path) {
                $this->pendingClients[$id] = [
                    'socket' => $c['socket'],
                    'stream' => $path,
                    'initData' => ''
                ];
                unset($this->clients[$id]);
            }
        }

        $this->log("[{$path}] 2秒后重连...");
        sleep(2);
        $this->connectUpstream($path);
    }

    private function decodeChunked(&$buf)
    {
        $decoded = '';
        while (true) {
            $pos = strpos($buf, "\r\n");
            if ($pos === false) break;
            $size = hexdec(trim(substr($buf, 0, $pos)));
            if ($size === 0) { $buf = ''; return $decoded; }
            $start = $pos + 2; $end = $start + $size + 2;
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
        $cache = &$stream['cache'];

        if (!$cache['flvHeader'] && strlen($stream['buffer']) >= 13) {
            $cache['flvHeader'] = substr($stream['buffer'], 0, 13);
            $stream['buffer'] = substr($stream['buffer'], 13);
            $this->log("[{$path}] FLV头 ✓");
        }

        while (strlen($stream['buffer']) >= 11) {
            $tagType = ord($stream['buffer'][0]);
            $dataSize = (ord($stream['buffer'][1]) << 16) | (ord($stream['buffer'][2]) << 8) | ord($stream['buffer'][3]);
            $totalSize = 11 + $dataSize + 4;
            if (strlen($stream['buffer']) < $totalSize) break;

            $tag = substr($stream['buffer'], 0, $totalSize);
            $stream['buffer'] = substr($stream['buffer'], $totalSize);
            $payload = substr($tag, 11, $dataSize);

            if (!$cache['ready']) {
                if ($tagType === 18 && !$cache['metaDataTag']) {
                    $cache['metaDataTag'] = $tag;
                    $this->log("[{$path}] MetaData ✓");
                    continue;
                }
                if ($tagType === 9 && !$cache['videoSequence']) {
                    if (strlen($payload) >= 2 && ((ord($payload[0]) >> 4) & 0x0F) === 1 && ord($payload[1]) === 0) {
                        $cache['videoSequence'] = $tag;
                        $this->log("[{$path}] 视频序列头 ✓");
                        continue;
                    }
                }
                if ($tagType === 8 && !$cache['audioSequence']) {
                    if (strlen($payload) >= 2 && ((ord($payload[0]) >> 4) & 0x0F) === 10 && ord($payload[1]) === 0) {
                        $cache['audioSequence'] = $tag;
                        $this->log("[{$path}] 音频序列头 ✓");
                        continue;
                    }
                }
                if ($cache['flvHeader'] && $cache['videoSequence'] && $cache['audioSequence']) {
                    $cache['gopData'] .= $tag;
                    if ($this->isVideoKeyFrame($tagType, $payload)) {
                        $cache['gopKeyFrameCount']++;
                        $this->log("[{$path}] GOP关键帧 #{$cache['gopKeyFrameCount']} " . $this->formatBytes(strlen($cache['gopData'])));
                    }
                    if ($cache['gopKeyFrameCount'] >= 2) {
                        $cache['ready'] = true;
                        $this->log("[{$path}] >>> 初始化完毕 <<<");
                        $this->notifyPendingClients($path);
                    }
                    continue;
                }
            }

            $this->broadcastToStream($path, $tag);
        }
    }

    private function notifyPendingClients($path)
    {
        $cache = $this->streams[$path]['cache'];
        $initData = $cache['flvHeader'] . $cache['metaDataTag'] . $cache['videoSequence'] . $cache['audioSequence'] . $cache['gopData'];
        $count = 0;
        foreach ($this->pendingClients as $id => &$c) {
            if ($c['stream'] === $path) {
                $c['initData'] = $initData;
                $count++;
            }
        }
        unset($c);
        $this->log("[{$path}] 通知{$count}个等待客户端 GOP:" . $this->formatBytes(strlen($cache['gopData'])));
    }

    private function broadcastToStream($path, $data)
    {
        foreach ($this->clients as $id => &$c) {
            if ($c['stream'] === $path) $c['sendBuffer'] .= $data;
        }
        unset($c);
    }

    private function removeClient($id)
    {
        $path = null;
        if (isset($this->clients[$id])) {
            $path = $this->clients[$id]['stream'];
            @socket_close($this->clients[$id]['socket']);
            unset($this->clients[$id]);
        }
        if (isset($this->pendingClients[$id])) {
            $path = $this->pendingClients[$id]['stream'];
            @socket_close($this->pendingClients[$id]['socket']);
            unset($this->pendingClients[$id]);
        }
        $this->log("客户端{$id}断开 流:/{$path} 剩余:" . count($this->clients));
    }
}