<?php

namespace Root\Io;

use MediaServer\Http\HttpWMServer;
use Root\Protocols\Http;
use Root\rtmp\TcpConnection;

/**
 * @purpose 总服务器
 * @author yanglong
 * @time 2026年6月10日15:22:54
 * @note 自动根据环境适配模型，windows选择select，Linux选择epoll
 */
class RtmpDemo
{
    public static array $allSocket;

    private string $host = '0.0.0.0';
    public string $rtmpPort = '1935';
    public string $flvPort = '8501';
    public string $webPort = '80';
    private string $protocol = 'tcp';
    private static ?RtmpDemo $instance = null;

    const EV_READ  = 1;
    const EV_WRITE = 2;

    private array $_allEvents = [];
    private array $_readFds = [];
    private array $_writeFds = [];
    private static $flvServerSocket = null;
    private static $webServerSocket = null;
    private string $transport = 'tcp';
    private array $serverSocket = [];

    // Event 模式专用
    private $base = null;
    private $_eventObjects = [];
    private bool $useEvent = false;

    // 连接数上限（可根据服务器内存调整）
    private int $maxConnections = 20000;

    /**
     * 添加事件
     */
    public function add($fd, int $flag, array $func): bool
    {
        if (!in_array($flag, [self::EV_READ, self::EV_WRITE], true)) {
            return false;
        }

        $fd_key = (int)$fd;

        // 连接数统计（仅提示）
        $count = $flag === self::EV_READ ? count($this->_readFds) : count($this->_writeFds);
        if ($count >= 1024 && !$this->useEvent) {
            logger()->warning("连接数已达 1024，select 模型可能受限");
        }

        $this->_allEvents[$fd_key][$flag] = [$func, $fd];
        if ($flag === self::EV_READ) {
            $this->_readFds[$fd_key] = $fd;
        } else {
            $this->_writeFds[$fd_key] = $fd;
        }

        if ($this->useEvent) {
            $eventKey = "{$fd_key}_{$flag}";
            if (isset($this->_eventObjects[$eventKey])) {
                return true;
            }

            $eventFlag = ($flag === self::EV_READ) ? \Event::READ : \Event::WRITE;
            $eventFlag |= \Event::PERSIST;

            $callback = function ($fd, $what, $arg) use ($fd_key, $flag) {
                try {
                    if (isset($this->_allEvents[$fd_key][$flag])) {
                        \call_user_func_array(
                            $this->_allEvents[$fd_key][$flag][0],
                            [$this->_allEvents[$fd_key][$flag][1]]
                        );
                    }
                } catch (\Throwable $e) {
                    logger()->error("Event callback error: {$e->getMessage()}");
                }
            };

            $event = new \Event($this->base, $fd, $eventFlag, $callback);
            $event->add();
            $this->_eventObjects[$eventKey] = $event;
        }

        return true;
    }

    public function del($fd, int $flag): bool
    {
        $fd_key = (int)$fd;
        if ($this->useEvent) {
            $eventKey = "{$fd_key}_{$flag}";
            if (isset($this->_eventObjects[$eventKey])) {
                $this->_eventObjects[$eventKey]->free();
                unset($this->_eventObjects[$eventKey]);
            }
        }

        if ($flag === self::EV_READ) {
            unset($this->_allEvents[$fd_key][$flag], $this->_readFds[$fd_key]);
        } elseif ($flag === self::EV_WRITE) {
            unset($this->_allEvents[$fd_key][$flag], $this->_writeFds[$fd_key]);
        }

        if (empty($this->_allEvents[$fd_key])) {
            unset($this->_allEvents[$fd_key]);
        }
        return true;
    }

    /** 多进程相关静态属性 */
    private static int $copyPort = 0;
    private static int $workerId = 0;
    private static int $workerCount = 1;
    private static bool $isWorker = false;

    /** flv复制流 */
    public static $flvServerCopySocket = null;

    /**
     * 设置复制流端口
     */
    public static function setCopyPort(int $port): void
    {
        self::$copyPort = $port;
    }

    /**
     * 设置 Worker ID
     */
    public static function setWorkerId(int $id): void
    {
        self::$workerId = $id;
    }

    /**
     * 设置 Worker 总数
     */
    public static function setWorkerCount(int $count): void
    {
        self::$workerCount = $count;
    }

    /**
     * 设置是否为 Worker 进程
     */
    public static function setIsWorker(bool $isWorker): void
    {
        self::$isWorker = $isWorker;
    }

    /**
     * 获取当前进程的复制流端口
     */
    public static function getCopyPort(): int
    {
        return self::$copyPort;
    }

    /**
     * 获取当前 Worker ID
     */
    public static function getWorkerId(): int
    {
        return self::$workerId;
    }

    /**
     * 获取 Worker 总数
     */
    public static function getWorkerCount(): int
    {
        return self::$workerCount;
    }

    /**
     * 修改 createFlvSever 方法，支持动态复制端口
     */
    private function createFlvSever(): void
    {
        // 对外服务端口（所有 Worker 共享）
        self::$flvServerSocket = $this->createServer($this->flvPort);
        logger()->info("http-flv服务：http://{$this->host}:{$this->flvPort}/{AppName}/{ChannelName}.flv");
        logger()->info("ws-flv服务：ws://{$this->host}:{$this->flvPort}/{AppName}/{ChannelName}.flv");

        // 检查是否需要创建复制流端口
        $copyPort = self::$copyPort;

        // 如果设置了复制流端口，且大于 0，则创建
        if ($copyPort > 0 && self::$isWorker) {
            self::$flvServerCopySocket = $this->createServer((string)$copyPort);
            logger()->info("FLV 复制http-flv流服务（Worker " . self::$workerId . "）：http://{$this->host}:{$copyPort}/{AppName}/{ChannelName}.flv");
            logger()->info("FLV 复制ws-flv流服务（Worker " . self::$workerId . "）：ws://{$this->host}:{$copyPort}/{AppName}/{ChannelName}.flv");
        }
    }

    private function createRtmpServer(): void
    {
        $this->createServer($this->rtmpPort);
        logger()->info("rtmp服务：rtmp://{$this->host}:{$this->rtmpPort}/{AppName}/{ChannelName}");
    }

    private function createHlsServer(): void
    {
        self::$webServerSocket = $this->createServer($this->webPort);
        logger()->info("hls服务：http://{$this->host}:{$this->webPort}/hls/{AppName}/{ChannelName}/index.m3u8");
    }

    private function createServer(string $port)
    {
        $listeningAddress = "{$this->protocol}://{$this->host}:{$port}";
        $contextOptions = [
            'ssl'    => ['verify_peer' => false, 'verify_peer_name' => false],
            'socket' => ['backlog' => 10240, 'so_reuseport' => 1, 'so_reuseaddr' => 1],
        ];
        $context = stream_context_create($contextOptions);
        $socket = stream_socket_server($listeningAddress, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        if (!$socket) {
            throw new \RuntimeException("Failed to create server on port {$port}: [{$errno}] {$errstr}");
        }
        stream_set_blocking($socket, false);
        self::$allSocket[(int)$socket] = $socket;
        $this->serverSocket[(int)$socket] = $socket;
        return $socket;
    }

    public static function instance(): ?RtmpDemo
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function start(): void
    {
        $this->useEvent = extension_loaded('event') && \DIRECTORY_SEPARATOR === '/';

        $this->createRtmpServer();
        $this->createFlvSever();
        $this->createHlsServer();

        if ($this->useEvent) {
            $this->startEventLoop();
        } else {
            $this->startSelectLoop();
        }
    }

    private function startEventLoop(): void
    {
        $config = new \EventConfig();
        $config->avoidMethod('select');
        $this->base = new \EventBase($config);

        foreach ($this->serverSocket as $fd) {
            $this->add($fd, self::EV_READ, [$this, 'onServerAccept']);
        }

        $this->base->loop();
    }

    /**
     * 接受新连接（包含连接数限制）
     */
    public function onServerAccept($fd): void
    {
        // 连接数保护：避免内存耗尽
        if (count(self::$allSocket) >= $this->maxConnections) {
            $client = @stream_socket_accept($fd, 0, $remote_address);
            if ($client) {
                logger()->warning("连接数已达上限 {$this->maxConnections}，拒绝新连接");
                fclose($client);
            }
            return;
        }

        $clientSocket = @stream_socket_accept($fd, 0, $remote_address);
        if (!$clientSocket) {
            return;
        }

        try {
            $connection = new TcpConnection($clientSocket, $remote_address);
            $connection->transport = $this->transport;

            if (self::$flvServerSocket && $fd === self::$flvServerSocket) {
                $connection->protocol = \MediaServer\Http\ExtHttpProtocol::class;
                $connection->onMessage = [new HttpWMServer(), 'onHttpRequest'];
                $connection->onWebSocketConnect = [new HttpWMServer(), 'onWebsocketRequest'];
            }  elseif (self::$flvServerCopySocket && $fd === self::$flvServerCopySocket) {
                $connection->protocol = \MediaServer\Http\ExtHttpProtocol::class;
                $connection->onMessage = [new HttpWMServer(), 'onHttpRequest'];
                $connection->onWebSocketConnect = [new HttpWMServer(), 'onWebsocketRequest'];
            }
            elseif (self::$webServerSocket && $fd === self::$webServerSocket) {
                $connection->protocol = Http::class;
                new \MediaServer\Utils\WMBufferStream($connection);
            } else {
                new \MediaServer\Rtmp\RtmpStream(
                    new \MediaServer\Utils\WMBufferStream($connection)
                );
            }

            self::$allSocket[(int)$clientSocket] = $clientSocket;
        } catch (\Throwable $e) {
            logger()->error("Accept error: {$e->getMessage()}");
            @fclose($clientSocket);
        }
    }

    private function startSelectLoop(): void
    {
        while (true) {
            $except = [];
            foreach (self::$allSocket as $key => $value) {
                if (!is_resource($value)) {
                    unset(self::$allSocket[$key]);
                }
            }
            $write = $read = self::$allSocket;

            try {
                stream_select($read, $write, $except, 0, 100);
            } catch (\Exception $e) {
                logger()->error($e->getMessage());
                continue;
            }

            if ($read) {
                foreach ($read as $fd) {
                    $fd_key = (int)$fd;
                    if (in_array($fd, $this->serverSocket)) {
                        // 连接数保护
                        if (count(self::$allSocket) >= $this->maxConnections) {
                            $tmp = @stream_socket_accept($fd, 0, $remote_address);
                            if ($tmp) fclose($tmp);
                            continue;
                        }

                        $clientSocket = stream_socket_accept($fd, 0, $remote_address);
                        if (!empty($clientSocket)) {
                            try {
                                $connection = new TcpConnection($clientSocket, $remote_address);
                                $connection->transport = $this->transport;

                                if (self::$flvServerSocket && $fd == self::$flvServerSocket) {
                                    $connection->protocol = \MediaServer\Http\ExtHttpProtocol::class;
                                    $connection->onMessage = [new HttpWMServer(), 'onHttpRequest'];
                                    $connection->onWebSocketConnect = [new HttpWMServer(), 'onWebsocketRequest'];
                                }
                                elseif (self::$flvServerCopySocket && $fd == self::$flvServerCopySocket) {
                                    $connection->protocol = \MediaServer\Http\ExtHttpProtocol::class;
                                    $connection->onMessage = [new HttpWMServer(), 'onHttpRequest'];
                                    $connection->onWebSocketConnect = [new HttpWMServer(), 'onWebsocketRequest'];
                                }

                                elseif (self::$webServerSocket && $fd == self::$webServerSocket) {
                                    $connection->protocol = Http::class;
                                    new \MediaServer\Utils\WMBufferStream($connection);
                                } else {
                                    new \MediaServer\Rtmp\RtmpStream(
                                        new \MediaServer\Utils\WMBufferStream($connection)
                                    );
                                }
                            } catch (\Exception|\RuntimeException $exception) {
                                logger()->error($exception->getMessage());
                            }
                            self::$allSocket[(int)$clientSocket] = $clientSocket;
                        }
                    } else {
                        if (isset($this->_allEvents[$fd_key][self::EV_READ])) {
                            \call_user_func_array(
                                $this->_allEvents[$fd_key][self::EV_READ][0],
                                [$this->_allEvents[$fd_key][self::EV_READ][1]]
                            );
                        }
                    }
                }
            }

            if ($write) {
                foreach ($write as $fd) {
                    $fd_key = (int)$fd;
                    if (isset($this->_allEvents[$fd_key][self::EV_WRITE])) {
                        \call_user_func_array(
                            $this->_allEvents[$fd_key][self::EV_WRITE][0],
                            [$this->_allEvents[$fd_key][self::EV_WRITE][1]]
                        );
                    }
                }
            }
        }
    }
}