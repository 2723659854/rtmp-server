<?php

namespace Root\Io;


/**
 * 静态文件服务器
 * @purpose 主要用于hls切片文件播放，flv录制文件播放，mp4录制文件播放
 * @author yanglong
 * @note 启动命令： php fileGateway.php 0.0.0.0 8100 /path/to/media --dir 表示监听所有IP，指定监听8100端口，指定根目录，开启目录扫描
 * @command php fileGateway.php [host] [port] [document_root] [--dir]
 */
class FileGatewaySelect
{
    // 服务器配置
    private string $host;
    private int $port;
    private string $documentRoot;
    private bool $enableDirListing;

    // 连接相关
    private $serverSocket;
    private array $clients = [];
    private array $clientBuffers = [];
    private array $clientFiles = [];      // 文件句柄
    private array $clientFileSizes = []; // 文件总大小
    private array $clientSentBytes = []; // 已发送字节数
    private array $clientRanges = [];    // Range 请求范围

    // MIME 类型映射
    private array $mimeTypes = [
        // 视频
        'mp4' => 'video/mp4',
        'm4v' => 'video/mp4',
        'm4s' => 'video/iso.segment',
        'flv' => 'video/x-flv',
        'f4v' => 'video/x-f4v',
        'webm' => 'video/webm',
        'mkv' => 'video/x-matroska',
        'avi' => 'video/x-msvideo',
        'mov' => 'video/quicktime',
        'ts' => 'video/mp2t',
        'm3u8' => 'application/vnd.apple.mpegurl',
        'm3u' => 'audio/x-mpegurl',

        // 音频
        'mp3' => 'audio/mpeg',
        'aac' => 'audio/aac',
        'ogg' => 'audio/ogg',
        'wav' => 'audio/wav',
        'flac' => 'audio/flac',
        'm4a' => 'audio/mp4',

        // 图片
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'bmp' => 'image/bmp',

        // 网页
        'html' => 'text/html',
        'htm' => 'text/html',
        'css' => 'text/css',
        'js' => 'application/javascript',
        'json' => 'application/json',
        'xml' => 'application/xml',
        'txt' => 'text/plain',

        // 文档
        'pdf' => 'application/pdf',
        'zip' => 'application/zip',
        'gz' => 'application/gzip',
        'tar' => 'application/x-tar',

        // 字体
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
    ];

    // 分块大小（64KB）
    private const CHUNK_SIZE = 65536;

    // 缓冲区最大限制
    private const MAX_BUFFER_SIZE = 1048576; // 1MB

    // 是否开启调试模式
    public $debug = false;

    /**
     * 构造函数
     * @param string $host 监听地址
     * @param int $port 监听端口
     * @param string $documentRoot 静态文件根目录
     * @param bool $enableDirListing 是否启用目录浏览
     */
    public function __construct(
        string $host = '0.0.0.0',
        int    $port = 8100,
        string $documentRoot = '',
        bool   $enableDirListing = false
    )
    {
        $this->host = $host;
        $this->port = $port;
        $this->documentRoot = $documentRoot ?: __DIR__;
        $this->enableDirListing = $enableDirListing;

        // 确保根目录存在
        if (!is_dir($this->documentRoot)) {
            mkdir($this->documentRoot, 0777, true);
        }

        echo "静态文件服务器配置:\n";
        echo "  监听地址: {$this->host}:{$this->port}\n";
        echo "  根目录: {$this->documentRoot}\n";
        echo "  目录浏览: " . ($this->enableDirListing ? '开启' : '关闭') . "\n\n";
    }

    /**
     * 启动服务器
     */
    public function start(): void
    {
        // 创建服务器 socket
        $context = stream_context_create([
            'socket' => [
                'backlog' => 1024,
                'so_reuseport' => true,
            ]
        ]);

        $this->serverSocket = @stream_socket_server(
            "tcp://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context
        );

        if (!$this->serverSocket) {
            throw new \RuntimeException("无法启动服务器: {$errstr} ({$errno})");
        }

        stream_set_blocking($this->serverSocket, false);

        echo "服务器已启动，监听 {$this->host}:{$this->port}\n";
        echo "按 Ctrl+C 停止服务\n\n";

        // 主事件循环
        $this->eventLoop();
    }

    /**
     * 事件循环（select 模型）
     */
    private function eventLoop(): void
    {
        while (true) {
            // 构建可读 socket 数组
            $readSockets = $this->clients;
            $readSockets[] = $this->serverSocket;

            // 构建可写 socket 数组（有数据待发送的客户端）
            $writeSockets = [];
            foreach ($this->clients as $id => $socket) {
                if (!empty($this->clientBuffers[$id])) {
                    $writeSockets[] = $socket;
                }
                // 有打开的文件需要继续发送
                if (isset($this->clientFiles[$id]) && $this->clientFiles[$id] !== null) {
                    $writeSockets[] = $socket;
                }
            }

            $except = null;

            // select 等待（100ms 超时，避免空转）
            $ready = @stream_select($readSockets, $writeSockets, $except, 0, 100000);

            if ($ready === false) {
                // 被信号中断，继续循环
                continue;
            }

            // 处理新连接
            if (in_array($this->serverSocket, $readSockets)) {
                $this->acceptNewConnection();
            }

            // 处理可读的客户端（接收 HTTP 请求）
            foreach ($readSockets as $socket) {
                if ($socket === $this->serverSocket) continue;
                $id = (int)$socket;
                $this->handleRead($id, $socket);
            }

            // 处理可写的客户端（发送响应数据）
            foreach ($writeSockets as $socket) {
                $id = (int)$socket;
                $this->handleWrite($id, $socket);
            }

            // 清理超时连接
            $this->cleanupConnections();
        }
    }

    /**
     * 接受新连接
     */
    private function acceptNewConnection(): void
    {
        $newSocket = @stream_socket_accept($this->serverSocket, 0, $peerName);
        if ($newSocket) {
            stream_set_blocking($newSocket, false);
            $id = (int)$newSocket;
            $this->clients[$id] = $newSocket;
            $this->clientBuffers[$id] = '';
            $this->clientFiles[$id] = null;
            $this->clientFileSizes[$id] = 0;
            $this->clientSentBytes[$id] = 0;
            $this->clientRanges[$id] = null;

            if ($this->debug){
                echo "[连接] 新客户端: {$peerName} (ID: {$id})\n";
            }
        }
    }

    /**
     * 处理客户端数据（接收 HTTP 请求）
     */
    private function handleRead(int $id, $socket): void
    {
        $data = @fread($socket, 8192);

        if ($data === false || $data === '') {
            $this->closeClient($id);
            return;
        }

        // 追加到缓冲区
        $this->clientBuffers[$id] .= $data;

        // 检查缓冲区大小，防止攻击
        if (strlen($this->clientBuffers[$id]) > self::MAX_BUFFER_SIZE) {
            $this->sendError($id, 413, 'Request Entity Too Large');
            $this->closeClient($id);
            return;
        }

        // 检查是否收到完整的 HTTP 请求
        if (strpos($this->clientBuffers[$id], "\r\n\r\n") !== false) {
            $this->handleRequest($id);
        }
    }

    /**
     * 处理 HTTP 请求
     */
    private function handleRequest(int $id): void
    {
        $rawRequest = $this->clientBuffers[$id];
        $this->clientBuffers[$id] = '';

        // 解析请求行
        $lines = explode("\r\n", $rawRequest);
        $firstLine = $lines[0] ?? '';
        $parts = explode(' ', $firstLine);

        $method = $parts[0] ?? 'GET';
        $uri = $parts[1] ?? '/';

        // 解析请求头
        $headers = [];
        $rangeHeader = null;
        for ($i = 1; $i < count($lines); $i++) {
            if (empty($lines[$i])) break;
            $colonPos = strpos($lines[$i], ':');
            if ($colonPos !== false) {
                $key = strtolower(trim(substr($lines[$i], 0, $colonPos)));
                $value = trim(substr($lines[$i], $colonPos + 1));
                $headers[$key] = $value;

                if ($key === 'range') {
                    $rangeHeader = $value;
                }
            }
        }

        // 只支持 GET 和 HEAD
        if (!in_array($method, ['GET', 'HEAD'])) {
            $this->sendError($id, 405, 'Method Not Allowed');
            return;
        }

        // 解析 URL 路径
        $urlPath = parse_url($uri, PHP_URL_PATH);
        if ($urlPath === false || $urlPath === null) {
            $urlPath = '/';
        }

        // URL 解码并防止路径穿越
        $urlPath = urldecode($urlPath);
        $urlPath = str_replace('\\', '/', $urlPath);

        // 移除路径中的 ../ 防止目录穿越攻击
        $urlPath = preg_replace('#/\.\.(/|$)#', '/', $urlPath);
        $urlPath = preg_replace('#/\.(/|$)#', '/', $urlPath);

        // 构建文件系统路径
        $filePath = $this->documentRoot . '/' . ltrim($urlPath, '/');
        $filePath = realpath($filePath);

        // 安全检查：确保文件在根目录内
        if ($filePath === false || strpos($filePath, realpath($this->documentRoot)) !== 0) {
            $this->sendError($id, 403, 'Forbidden');
            return;
        }

        // 目录处理
        if (is_dir($filePath)) {
            $this->handleDirectory($id, $filePath, $urlPath, $method);
            return;
        }

        // 文件处理
        if (is_file($filePath) && is_readable($filePath)) {
            $this->handleFile($id, $filePath, $method, $rangeHeader);
            return;
        }

        // 文件/目录不存在
        $this->sendError($id, 404, 'Not Found');
    }

    /**
     * 处理目录请求
     */
    private function handleDirectory(int $id, string $dirPath, string $urlPath, string $method): void
    {
        // 查找默认索引文件
        $indexFiles = ['index.html', 'index.htm', 'index.php'];
        foreach ($indexFiles as $indexFile) {
            $indexPath = $dirPath . '/' . $indexFile;
            if (is_file($indexPath)) {
                $this->handleFile($id, $indexPath, $method, null);
                return;
            }
        }

        // 目录浏览
        if ($this->enableDirListing) {
            $this->sendDirListing($id, $dirPath, $urlPath);
            return;
        }

        $this->sendError($id, 403, 'Directory listing not allowed');
    }

    /**
     * 发送目录列表
     */
    private function sendDirListing(int $id, string $dirPath, string $urlPath): void
    {
        $files = scandir($dirPath);
        $html = "<!DOCTYPE html>\n<html>\n<head>\n";
        $html .= "<meta charset=\"utf-8\">\n";
        $html .= "<title>Index of {$urlPath}</title>\n";
        $html .= "<style>body{font-family:sans-serif;margin:20px;}";
        $html .= "table{border-collapse:collapse;width:100%;}";
        $html .= "th,td{text-align:left;padding:8px;border-bottom:1px solid #ddd;}";
        $html .= "tr:hover{background:#f5f5f5;}";
        $html .= "a{color:#0366d6;text-decoration:none;}";
        $html .= "a:hover{text-decoration:underline;}</style>\n";
        $html .= "</head>\n<body>\n";
        $html .= "<h1>Index of {$urlPath}</h1>\n";
        $html .= "<table>\n";
        $html .= "<tr><th>Name</th><th>Size</th><th>Modified</th></tr>\n";

        // 返回上级目录链接
        if ($urlPath !== '/') {
            $parentUrl = dirname($urlPath);
            if ($parentUrl === '\\' || $parentUrl === '.') $parentUrl = '/';
            $html .= "<tr><td><a href=\"{$parentUrl}\">../</a></td><td>-</td><td>-</td></tr>\n";
        }

        foreach ($files as $file) {
            if ($file === '.') continue;
            if ($file === '..') continue;

            $fullPath = $dirPath . '/' . $file;
            $isDir = is_dir($fullPath);
            $displayName = $isDir ? "{$file}/" : $file;
            $fileUrl = rtrim($urlPath, '/') . '/' . $file;

            $size = $isDir ? '-' : $this->formatBytes(filesize($fullPath));
            $modified = date('Y-m-d H:i', filemtime($fullPath));

            $html .= "<tr><td><a href=\"{$fileUrl}\">{$displayName}</a></td>";
            $html .= "<td>{$size}</td><td>{$modified}</td></tr>\n";
        }

        $html .= "</table>\n</body>\n</html>";

        $response = "HTTP/1.1 200 OK\r\n";
        $response .= "Content-Type: text/html; charset=utf-8\r\n";
        $response .= "Content-Length: " . strlen($html) . "\r\n";
        $response .= "Connection: close\r\n";
        $response .= "\r\n";

        if (isset($this->clients[$id])) {
            $this->clientBuffers[$id] = $response . $html;
        }
    }

    /**
     * 处理文件请求（支持大文件流式传输）
     */
    private function handleFile(int $id, string $filePath, string $method, ?string $rangeHeader): void
    {
        $fileSize = filesize($filePath);
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mimeType = $this->mimeTypes[$extension] ?? 'application/octet-stream';

        // 处理 Range 请求（断点续传）
        if ($rangeHeader !== null) {
            $this->handleRangeRequest($id, $filePath, $fileSize, $mimeType, $rangeHeader, $method);
            return;
        }

        // HEAD 请求只返回头
        if ($method === 'HEAD') {
            $response = "HTTP/1.1 200 OK\r\n";
            $response .= "Content-Type: {$mimeType}\r\n";
            $response .= "Content-Length: {$fileSize}\r\n";
            $response .= "Accept-Ranges: bytes\r\n";
            $response .= "Connection: close\r\n";
            $response .= "\r\n";
            $this->clientBuffers[$id] = $response;
            return;
        }

        // 全部强制使用流式传输，防止高并发的情况下，塞满内存
        $this->startStreamingFile($id, $filePath, $fileSize, $mimeType);
    }

    /**
     * 处理 Range 请求
     */
    private function handleRangeRequest(int $id, string $filePath, int $fileSize, string $mimeType, string $rangeHeader, string $method): void
    {
        // 解析 Range: bytes=start-end
        if (!preg_match('/bytes\s*=\s*(\d*)-(\d*)/', $rangeHeader, $matches)) {
            $this->sendError($id, 416, 'Range Not Satisfiable');
            return;
        }

        $start = $matches[1] !== '' ? (int)$matches[1] : 0;
        $end = $matches[2] !== '' ? (int)$matches[2] : $fileSize - 1;

        // 修正范围
        if ($start >= $fileSize) {
            $this->sendError($id, 416, 'Range Not Satisfiable');
            return;
        }
        if ($end >= $fileSize) {
            $end = $fileSize - 1;
        }

        $contentLength = $end - $start + 1;

        $response = "HTTP/1.1 206 Partial Content\r\n";
        $response .= "Content-Type: {$mimeType}\r\n";
        $response .= "Content-Length: {$contentLength}\r\n";
        $response .= "Content-Range: bytes {$start}-{$end}/{$fileSize}\r\n";
        $response .= "Accept-Ranges: bytes\r\n";
        $response .= "Connection: close\r\n";
        $response .= "\r\n";

        if ($method === 'HEAD') {
            $this->clientBuffers[$id] = $response;
            return;
        }

        // 流式发送 Range 数据
        $this->startStreamingFile($id, $filePath, $fileSize, $mimeType, $start, $end);

        // 先发送响应头
        $this->clientBuffers[$id] = $response;
    }

    /**
     * 开始流式发送文件
     */
    private function startStreamingFile(int $id, string $filePath, int $fileSize, string $mimeType, int $rangeStart = 0, ?int $rangeEnd = null): void
    {
        // 先发送响应头
        if ($rangeStart > 0 || $rangeEnd !== null) {
            // Range 响应头
            $end = $rangeEnd ?? $fileSize - 1;
            $contentLength = $end - $rangeStart + 1;
            $response = "HTTP/1.1 206 Partial Content\r\n";
            $response .= "Content-Type: {$mimeType}\r\n";
            $response .= "Content-Length: {$contentLength}\r\n";
            $response .= "Content-Range: bytes {$rangeStart}-{$end}/{$fileSize}\r\n";
            $response .= "Accept-Ranges: bytes\r\n";
            $response .= "Connection: close\r\n";
            $response .= "\r\n";
        } else {
            $response = "HTTP/1.1 200 OK\r\n";
            $response .= "Content-Type: {$mimeType}\r\n";
            $response .= "Content-Length: {$fileSize}\r\n";
            $response .= "Accept-Ranges: bytes\r\n";
            $response .= "Connection: close\r\n";
            $response .= "\r\n";
        }

        $this->clientBuffers[$id] = $response;

        // 打开文件准备流式发送
        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            $this->closeClient($id);
            return;
        }

        // 定位到 Range 起始位置
        if ($rangeStart > 0) {
            fseek($handle, $rangeStart);
        }

        $this->clientFiles[$id] = $handle;
        $this->clientFileSizes[$id] = $rangeEnd ?? $fileSize;
        $this->clientSentBytes[$id] = $rangeStart;
        $this->clientRanges[$id] = $rangeEnd !== null ? [$rangeStart, $rangeEnd] : null;
    }

    /**
     * 处理可写事件（发送响应数据）
     */
    private function handleWrite(int $id, $socket): void
    {
        // 先发送缓冲区数据
        if (!empty($this->clientBuffers[$id])) {
            $written = @fwrite($socket, $this->clientBuffers[$id]);
            if ($written === false) {
                $this->closeClient($id);
                return;
            }
            $this->clientBuffers[$id] = substr($this->clientBuffers[$id], $written);

            // 如果还有缓冲数据，等待下次写入
            if (!empty($this->clientBuffers[$id])) {
                return;
            }
        }

        // 发送文件数据（流式传输）
        if (isset($this->clientFiles[$id]) && $this->clientFiles[$id] !== null) {
            $this->streamFileChunk($id, $socket);
        } else {
            // 没有文件要发送，关闭连接
            $this->closeClient($id);
        }
    }

    /**
     * 流式发送文件块
     */
    private function streamFileChunk(int $id, $socket): void
    {
        $handle = $this->clientFiles[$id];

        if (feof($handle)) {
            // 文件发送完毕
            $this->closeClient($id);
            return;
        }

        // 检查 Range 限制
        $range = $this->clientRanges[$id] ?? null;
        if ($range !== null) {
            $endByte = $range[1];
            $currentPos = ftell($handle);
            if ($currentPos > $endByte) {
                $this->closeClient($id);
                return;
            }

            // 限制读取长度
            $remaining = $endByte - $currentPos + 1;
            $chunkSize = min(self::CHUNK_SIZE, $remaining);
        } else {
            $chunkSize = self::CHUNK_SIZE;
        }

        $chunk = @fread($handle, $chunkSize);

        if ($chunk === false || $chunk === '') {
            $this->closeClient($id);
            return;
        }

        $written = @fwrite($socket, $chunk);

        if ($written === false || $written === 0) {
            $this->closeClient($id);
            return;
        }

        $this->clientSentBytes[$id] += $written;

        // 如果写入不完整，回退文件指针
        if ($written < strlen($chunk)) {
            fseek($handle, $written - strlen($chunk), SEEK_CUR);
        }

        // 检查是否发送完毕
        if (feof($handle) || ($range !== null && $this->clientSentBytes[$id] > $range[1])) {
            $this->closeClient($id);
        }
    }

    /**
     * 发送错误响应
     */
    private function sendError(int $id, int $code, string $message): void
    {
        $statusText = [
            400 => 'Bad Request',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            413 => 'Request Entity Too Large',
            416 => 'Range Not Satisfiable',
            500 => 'Internal Server Error',
        ];

        $text = $statusText[$code] ?? 'Error';

        $body = "<!DOCTYPE html>\n<html>\n<head>\n";
        $body .= "<meta charset=\"utf-8\">\n";
        $body .= "<title>{$code} {$text}</title>\n";
        $body .= "<style>body{font-family:sans-serif;text-align:center;margin-top:100px;}</style>\n";
        $body .= "</head>\n<body>\n";
        $body .= "<h1>{$code} {$text}</h1>\n";
        $body .= "<p>{$message}</p>\n";
        $body .= "</body>\n</html>";

        $response = "HTTP/1.1 {$code} {$text}\r\n";
        $response .= "Content-Type: text/html; charset=utf-8\r\n";
        $response .= "Content-Length: " . strlen($body) . "\r\n";
        $response .= "Connection: close\r\n";
        $response .= "\r\n";

        if (isset($this->clients[$id])) {
            $this->clientBuffers[$id] = $response . $body;
        }
    }

    /**
     * 关闭客户端连接
     */
    private function closeClient(int $id): void
    {
        // 关闭文件句柄
        if (isset($this->clientFiles[$id]) && $this->clientFiles[$id] !== null) {
            fclose($this->clientFiles[$id]);
        }

        // 关闭 socket
        if (isset($this->clients[$id])) {
            @fclose($this->clients[$id]);
        }

        // 清理数据
        unset(
            $this->clients[$id],
            $this->clientBuffers[$id],
            $this->clientFiles[$id],
            $this->clientFileSizes[$id],
            $this->clientSentBytes[$id],
            $this->clientRanges[$id]
        );

        if ($this->debug) {
            echo "[断开] 客户端 {$id} 已断开\n";
        }
    }

    /**
     * 清理超时连接
     */
    private function cleanupConnections(): void
    {
        // 可在此处添加超时检测逻辑
        // 当前版本依赖客户端主动断开
    }

    /**
     * 格式化字节大小
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = floor(log($bytes, 1024));
        return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
    }

    /**
     * 停止服务器
     */
    public function stop(): void
    {
        // 关闭所有客户端
        foreach (array_keys($this->clients) as $id) {
            $this->closeClient($id);
        }

        // 关闭服务器 socket
        if ($this->serverSocket) {
            fclose($this->serverSocket);
            $this->serverSocket = null;
        }
        echo "服务器已停止\n";
    }

    public function __destruct()
    {
        $this->stop();
    }
}

