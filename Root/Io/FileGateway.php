<?php

namespace Root\Io;

/**
 * 静态文件服务器（高并发版 v2）
 * 修复 seek 导致的 cancelled，改用定时器批量写，确保兼容性
 */
class FileGateway
{
    private string $host;
    private int $port;
    private string $documentRoot;
    private bool $enableDirListing;
    public $debug = false;

    private $serverSocket = null;
    private array $clients = [];
    private array $clientBuffers = [];
    private array $clientFiles = [];
    private array $clientFileSizes = [];
    private array $clientSentBytes = [];
    private array $clientRanges = [];
    private array $clientLastActive = [];
    private array $clientResponseSent = [];

    private const CHUNK_SIZE = 65536;
    private const MAX_BUFFER_SIZE = 1048576;
    private const SMALL_FILE_THRESHOLD = 65536;
    private const CONNECTION_TIMEOUT = 30;
    private const MAX_CONNECTIONS = 10000;

    private bool $useEvent = false;
    private $base = null;
    private array $_readEvents = [];
    private $timerEvent = null;

    private array $mimeTypes = [
        'mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'm4s' => 'video/iso.segment',
        'flv' => 'video/x-flv', 'f4v' => 'video/x-f4v', 'webm' => 'video/webm',
        'mkv' => 'video/x-matroska', 'avi' => 'video/x-msvideo', 'mov' => 'video/quicktime',
        'ts' => 'video/mp2t', 'm3u8' => 'application/vnd.apple.mpegurl', 'm3u' => 'audio/x-mpegurl',
        'mp3' => 'audio/mpeg', 'aac' => 'audio/aac', 'ogg' => 'audio/ogg',
        'wav' => 'audio/wav', 'flac' => 'audio/flac', 'm4a' => 'audio/mp4',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon', 'bmp' => 'image/bmp',
        'html' => 'text/html', 'htm' => 'text/html', 'css' => 'text/css',
        'js' => 'application/javascript', 'json' => 'application/json', 'xml' => 'application/xml',
        'txt' => 'text/plain', 'pdf' => 'application/pdf', 'zip' => 'application/zip',
        'gz' => 'application/gzip', 'tar' => 'application/x-tar',
        'ttf' => 'font/ttf', 'otf' => 'font/otf', 'woff' => 'font/woff', 'woff2' => 'font/woff2',
    ];

    public function __construct(
        string $host = '0.0.0.0',
        int    $port = 8100,
        string $documentRoot = '',
        bool   $enableDirListing = false
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->documentRoot = $documentRoot ?: __DIR__;
        $this->enableDirListing = $enableDirListing;
        if (!is_dir($this->documentRoot)) mkdir($this->documentRoot, 0777, true);
    }

    public function start(): void
    {
        $context = stream_context_create([
            'socket' => ['backlog' => 65535, 'so_reuseport' => true, 'so_reuseaddr' => true]
        ]);
        $this->serverSocket = @stream_socket_server("tcp://{$this->host}:{$this->port}", $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        if (!$this->serverSocket) throw new \RuntimeException("启动失败: {$errstr} ({$errno})");
        stream_set_blocking($this->serverSocket, false);

        $this->useEvent = extension_loaded('event') && DIRECTORY_SEPARATOR === '/';
        echo "高并发静态文件服务器 v2\n";
        echo "监听 {$this->host}:{$this->port}\n";
        echo "IO模型: " . ($this->useEvent ? "epoll" : "select") . "\n";
        echo "最大连接: " . self::MAX_CONNECTIONS . "\n\n";

        $this->useEvent ? $this->startEventLoop() : $this->startSelectLoop();
    }

    // ========== Epoll 循环（使用定时器批量写，避免写事件 bug） ==========
    private function startEventLoop(): void
    {
        $config = new \EventConfig();
        $config->avoidMethod('select');
        $this->base = new \EventBase($config);

        $this->addReadEvent($this->serverSocket, fn() => $this->acceptClient());

        // 核心：每 10ms 统一处理所有客户端的写入，兼容 select 模式行为
        $this->timerEvent = \Event::timer($this->base, function () {
            $this->flushAllWrites();
            $this->cleanupTimeoutClients();
            $this->timerEvent->add(0.01);
        });
        $this->timerEvent->add(0.01);

        $this->base->loop();
    }

    // ========== Select 循环 ==========
    private function startSelectLoop(): void
    {
        while (true) {
            $read = $this->serverSocket ? [$this->serverSocket] : [];
            foreach ($this->clients as $sock) $read[] = $sock;

            $write = [];
            foreach ($this->clients as $sock) {
                $id = (int)$sock;
                if (!empty($this->clientBuffers[$id]) || (isset($this->clientFiles[$id]) && $this->clientFiles[$id] !== null)) {
                    $write[] = $sock;
                }
            }

            $except = null;
            @stream_select($read, $write, $except, 0, 100000);

            if (in_array($this->serverSocket, $read)) $this->acceptClient();

            foreach ($read as $sock) {
                if ($sock === $this->serverSocket) continue;
                $this->handleRead((int)$sock, $sock);
            }

            foreach ($write as $sock) {
                $this->handleWrite((int)$sock, $sock);
            }

            $this->cleanupTimeoutClients();
        }
    }

    // ========== 批量写（epoll 模式复用） ==========
    private function flushAllWrites(): void
    {
        foreach ($this->clients as $id => $socket) {
            // 只在有数据要发时才写，避免空转
            if (!empty($this->clientBuffers[$id]) || (isset($this->clientFiles[$id]) && $this->clientFiles[$id] !== null)) {
                $this->handleWrite($id, $socket);
            }
        }
    }

    // ========== Epoll 读事件管理 ==========
    private function addReadEvent($fd, callable $cb): void
    {
        if (!$this->useEvent || !is_resource($fd)) return;
        $key = (int)$fd;
        if (isset($this->_readEvents[$key])) return;
        $event = new \Event($this->base, $fd, \Event::READ | \Event::PERSIST, function ($fd) use ($cb) {
            try { $cb($fd); } catch (\Throwable $e) { $this->log("读事件异常: " . $e->getMessage()); }
        });
        $event->add();
        $this->_readEvents[$key] = $event;
    }

    // ========== 连接管理 ==========
    private function acceptClient(): void
    {
        if (count($this->clients) >= self::MAX_CONNECTIONS) {
            $tmp = @stream_socket_accept($this->serverSocket, 0, $peer);
            if ($tmp) fclose($tmp);
            return;
        }
        $client = @stream_socket_accept($this->serverSocket, 0, $peer);
        if (!$client) return;
        stream_set_blocking($client, false);
        $id = (int)$client;
        $this->clients[$id] = $client;
        $this->clientBuffers[$id] = '';
        $this->clientFiles[$id] = null;
        $this->clientFileSizes[$id] = 0;
        $this->clientSentBytes[$id] = 0;
        $this->clientRanges[$id] = null;
        $this->clientLastActive[$id] = time();
        $this->clientResponseSent[$id] = false;
        $this->addReadEvent($client, fn() => $this->handleRead($id, $client));
    }

    private function handleRead(int $id, $socket): void
    {
        $this->clientLastActive[$id] = time();
        $data = @fread($socket, 8192);
        if ($data === false || $data === '') { $this->closeClient($id); return; }
        $this->clientBuffers[$id] .= $data;
        if (strlen($this->clientBuffers[$id]) > self::MAX_BUFFER_SIZE) {
            $this->sendError($id, 413, 'Request Entity Too Large');
            $this->closeClient($id);
            return;
        }
        if (strpos($this->clientBuffers[$id], "\r\n\r\n") !== false) {
            $this->handleRequest($id);
        }
    }

    // ========== HTTP 请求处理（与之前相同） ==========
    private function handleRequest(int $id): void
    {
        $raw = $this->clientBuffers[$id];
        $this->clientBuffers[$id] = '';
        $lines = explode("\r\n", $raw);
        $first = $lines[0] ?? '';
        $parts = explode(' ', $first);
        $method = $parts[0] ?? 'GET';
        $uri = $parts[1] ?? '/';
        if (!in_array($method, ['GET', 'HEAD'])) { $this->sendError($id, 405); return; }

        $rangeHeader = null;
        for ($i = 1; $i < count($lines); $i++) {
            if (empty($lines[$i])) break;
            $colon = strpos($lines[$i], ':');
            if ($colon === false) continue;
            if (strtolower(trim(substr($lines[$i], 0, $colon))) === 'range') {
                $rangeHeader = trim(substr($lines[$i], $colon + 1));
            }
        }

        $urlPath = parse_url($uri, PHP_URL_PATH);
        $urlPath = $urlPath ?: '/';
        $urlPath = urldecode($urlPath);
        $urlPath = str_replace('\\', '/', $urlPath);
        $urlPath = preg_replace('#/\.\.(/|$)#', '/', $urlPath);
        $urlPath = preg_replace('#/\.(/|$)#', '/', $urlPath);
        $filePath = $this->documentRoot . '/' . ltrim($urlPath, '/');
        $filePath = realpath($filePath);
        if ($filePath === false || strpos($filePath, realpath($this->documentRoot)) !== 0) {
            $this->sendError($id, 403); return;
        }
        if (is_dir($filePath)) {
            $this->handleDirectory($id, $filePath, $urlPath, $method);
            return;
        }
        if (is_file($filePath) && is_readable($filePath)) {
            $this->handleFile($id, $filePath, $method, $rangeHeader);
            return;
        }
        $this->sendError($id, 404);
    }

    private function handleDirectory(int $id, string $dirPath, string $urlPath, string $method): void
    {
        foreach (['index.html', 'index.htm'] as $if) {
            $ip = $dirPath . '/' . $if;
            if (is_file($ip)) { $this->handleFile($id, $ip, $method, null); return; }
        }
        if ($this->enableDirListing) $this->sendDirListing($id, $dirPath, $urlPath);
        else $this->sendError($id, 403, 'Directory listing not allowed');
    }

    private function sendDirListing(int $id, string $dirPath, string $urlPath): void
    {
        $files = scandir($dirPath);
        $html = "<!DOCTYPE html><html><head><meta charset=\"utf-8\"><title>Index of {$urlPath}</title>"
            . "<style>body{font-family:sans-serif;margin:20px;}table{border-collapse:collapse;width:100%;}"
            . "th,td{text-align:left;padding:8px;border-bottom:1px solid #ddd;}tr:hover{background:#f5f5f5;}"
            . "a{color:#0366d6;text-decoration:none;}a:hover{text-decoration:underline;}</style></head><body>"
            . "<h1>Index of {$urlPath}</h1><table><tr><th>Name</th><th>Size</th><th>Modified</th></tr>";
        if ($urlPath !== '/') {
            $parent = dirname($urlPath);
            if ($parent === '\\' || $parent === '.') $parent = '/';
            $html .= "<tr><td><a href=\"{$parent}\">../</a></td><td>-</td><td>-</td></tr>";
        }
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $full = $dirPath . '/' . $file;
            $isDir = is_dir($full);
            $name = $isDir ? "{$file}/" : $file;
            $fileUrl = rtrim($urlPath, '/') . '/' . $file;
            $size = $isDir ? '-' : $this->formatBytes(filesize($full));
            $mtime = date('Y-m-d H:i', filemtime($full));
            $html .= "<tr><td><a href=\"{$fileUrl}\">{$name}</a></td><td>{$size}</td><td>{$mtime}</td></tr>";
        }
        $html .= "</table></body></html>";
        $response = "HTTP/1.1 200 OK\r\nContent-Type: text/html; charset=utf-8\r\nContent-Length: " . strlen($html) . "\r\nConnection: close\r\n\r\n";
        $this->clientBuffers[$id] = $response . $html;
    }

    // ========== 文件发送 ==========
    private function handleFile(int $id, string $filePath, string $method, ?string $rangeHeader): void
    {
        $fileSize = filesize($filePath);
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mime = $this->mimeTypes[$ext] ?? 'application/octet-stream';

        if ($rangeHeader) {
            $this->handleRangeRequest($id, $filePath, $fileSize, $mime, $rangeHeader, $method);
            return;
        }

        if ($method === 'HEAD') {
            $this->clientBuffers[$id] = "HTTP/1.1 200 OK\r\nContent-Type: {$mime}\r\nContent-Length: {$fileSize}\r\nConnection: close\r\n\r\n";
            return;
        }

        if ($fileSize <= self::SMALL_FILE_THRESHOLD) {
            $content = file_get_contents($filePath);
            $this->clientBuffers[$id] = "HTTP/1.1 200 OK\r\nContent-Type: {$mime}\r\nContent-Length: {$fileSize}\r\nConnection: close\r\n\r\n" . $content;
            return;
        }

        $handle = fopen($filePath, 'rb');
        if (!$handle) { $this->sendError($id, 500); return; }
        $this->clientBuffers[$id] = "HTTP/1.1 200 OK\r\nContent-Type: {$mime}\r\nContent-Length: {$fileSize}\r\nConnection: close\r\n\r\n";
        $this->clientFiles[$id] = $handle;
        $this->clientFileSizes[$id] = $fileSize;
        $this->clientSentBytes[$id] = 0;
    }

    private function handleRangeRequest(int $id, string $filePath, int $fileSize, string $mime, string $rangeHeader, string $method): void
    {
        if (!preg_match('/bytes\s*=\s*(\d*)-(\d*)/', $rangeHeader, $m)) {
            $this->sendError($id, 416); return;
        }
        $start = $m[1] !== '' ? (int)$m[1] : 0;
        $end   = $m[2] !== '' ? (int)$m[2] : $fileSize - 1;
        if ($start >= $fileSize) { $this->sendError($id, 416); return; }
        if ($end >= $fileSize) $end = $fileSize - 1;
        $contentLength = $end - $start + 1;

        $header = "HTTP/1.1 206 Partial Content\r\n" .
            "Content-Type: {$mime}\r\n" .
            "Content-Length: {$contentLength}\r\n" .
            "Content-Range: bytes {$start}-{$end}/{$fileSize}\r\n" .
            "Accept-Ranges: bytes\r\n" .
            "Connection: close\r\n\r\n";

        if ($method === 'HEAD') {
            $this->clientBuffers[$id] = $header;
            return;
        }

        if ($contentLength <= self::SMALL_FILE_THRESHOLD) {
            $h = fopen($filePath, 'rb');
            fseek($h, $start);
            $data = fread($h, $contentLength);
            fclose($h);
            $this->clientBuffers[$id] = $header . $data;
            return;
        }

        $handle = fopen($filePath, 'rb');
        fseek($handle, $start);
        $this->clientBuffers[$id] = $header;
        $this->clientFiles[$id] = $handle;
        $this->clientFileSizes[$id] = $end;          // 结束字节索引
        $this->clientSentBytes[$id] = $start;
        $this->clientRanges[$id] = [$start, $end];
    }

    // ========== 写处理（发送缓冲区/文件流） ==========
    private function handleWrite(int $id, $socket): void
    {
        // 1. 先发送缓冲区
        if (!empty($this->clientBuffers[$id])) {
            $written = @fwrite($socket, $this->clientBuffers[$id]);
            if ($written === false) { $this->closeClient($id); return; }
            $this->clientBuffers[$id] = substr($this->clientBuffers[$id], $written);
            if (!empty($this->clientBuffers[$id])) return;   // 没发完，等下次
        }

        // 2. 发送文件流（如果有）
        if (isset($this->clientFiles[$id]) && $this->clientFiles[$id] !== null) {
            $this->streamChunk($id, $socket);
        } else {
            // 没文件要发，且缓冲区已空 → 关闭连接
            $this->closeClient($id);
        }
    }

    private function streamChunk(int $id, $socket): void
    {
        $handle = $this->clientFiles[$id];
        if (feof($handle)) { $this->closeClient($id); return; }

        $range = $this->clientRanges[$id] ?? null;
        if ($range) {
            $cur = ftell($handle);
            if ($cur > $range[1]) { $this->closeClient($id); return; }
            $remaining = $range[1] - $cur + 1;
            $chunkSize = min(self::CHUNK_SIZE, $remaining);
        } else {
            $chunkSize = self::CHUNK_SIZE;
        }

        $data = @fread($handle, $chunkSize);
        if ($data === false || $data === '') { $this->closeClient($id); return; }

        $written = @fwrite($socket, $data);
        if ($written === false || $written === 0) { $this->closeClient($id); return; }

        $this->clientSentBytes[$id] += $written;

        // 没发完整个 chunk，回退指针到未发部分开头
        if ($written < strlen($data)) {
            fseek($handle, $written - strlen($data), SEEK_CUR);
        }

        // 发送完毕？
        if (feof($handle) || ($range && $this->clientSentBytes[$id] > $range[1])) {
            $this->closeClient($id);
        }
    }

    // ========== 辅助方法 ==========
    private function closeClient(int $id): void
    {
        if (isset($this->clientFiles[$id]) && $this->clientFiles[$id]) fclose($this->clientFiles[$id]);
        if (isset($this->clients[$id])) {
            if (isset($this->_readEvents[$id])) {
                $this->_readEvents[$id]->free();
                unset($this->_readEvents[$id]);
            }
            @fclose($this->clients[$id]);
        }
        unset($this->clients[$id], $this->clientBuffers[$id], $this->clientFiles[$id],
            $this->clientFileSizes[$id], $this->clientSentBytes[$id], $this->clientRanges[$id],
            $this->clientLastActive[$id], $this->clientResponseSent[$id]);
    }

    private function cleanupTimeoutClients(): void
    {
        $now = time();
        foreach ($this->clientLastActive as $id => $last) {
            if ($now - $last > self::CONNECTION_TIMEOUT) {
                $this->closeClient($id);
            }
        }
    }

    private function sendError(int $id, int $code, string $msg = ''): void
    {
        $statusText = [400=>'Bad Request',403=>'Forbidden',404=>'Not Found',405=>'Method Not Allowed',
            413=>'Request Entity Too Large',416=>'Range Not Satisfiable',500=>'Internal Server Error'];
        $text = $statusText[$code] ?? 'Error';
        $body = "<!DOCTYPE html><html><head><meta charset=\"utf-8\"><title>{$code} {$text}</title>"
            . "<style>body{font-family:sans-serif;text-align:center;margin-top:100px;}</style></head>"
            . "<body><h1>{$code} {$text}</h1><p>{$msg}</p></body></html>";
        $response = "HTTP/1.1 {$code} {$text}\r\nContent-Type: text/html; charset=utf-8\r\n"
            . "Content-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n";
        if (isset($this->clients[$id])) $this->clientBuffers[$id] = $response . $body;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = floor(log($bytes, 1024));
        return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
    }

    private function log($msg): void { if ($this->debug) echo "$msg\n"; }

    public function stop(): void
    {
        foreach (array_keys($this->clients) as $id) $this->closeClient($id);
        if ($this->serverSocket) { fclose($this->serverSocket); $this->serverSocket = null; }
    }

    public function __destruct() { $this->stop(); }
}