<?php

namespace Root\Io;

use MediaServer\Http\HttpWMServer;
use Root\Protocols\Http;
use Root\rtmp\TcpConnection;

/**
 * @purpose 使用了select的IO多路复用模型
 * @note 也可以使用epoll模型，但是windows目前不支持。为了兼容windows和Linux系统，所以选择select模型。
 * @author yanglong
 */
class RtmpDemo2
{
    /** @var array $allSocket 存放所有socket 注意内存泄漏 */
    public static array $allSocket;

    /** @var string $host 监听的ip */
    private string $host = '0.0.0.0';

    /** @var string $port RTMP监听的端口 可修改 */
    public string $rtmpPort = '1935';

    /** @var string $flvPort flv监听端口 可修改 */
    public string $flvPort = '8501';

    /** @var string $webPort web端口 */
    public string $webPort = '80';

    /** @var string $protocol 通信协议 */
    private string $protocol = 'tcp';

    /**  rtmp服务器实例 */
    private static  $instance = null;

    /** @var int 读事件 */
    const  EV_READ = 1;

    /** @var int 写事件 */
    const EV_WRITE = 2;

    /** @var array $_allEvents 所有的事件 */
    private array $_allEvents = [];

    /** @var array $_readFds 读事件 */
    private array $_readFds = [];

    /** @var array $_writeFds 写事件 */
    private array $_writeFds = [];

    /** @var resource $flvServerSocket flv服務端 */
    private static $flvServerSocket = null;

    /** @var resource $webServerSocket web服务器 */
    private static $webServerSocket = null;

    /** @var string $transport 默认通信传输协议 */
    private string $transport = 'tcp';

    /** @var array $serverSocket 服务端socket */
    private array $serverSocket = [];


    /**
     * 添加读写事件
     * @param resource $fd socket链接
     * @param int $flag 读写类型
     * @param array $func 回调函数
     * @return bool
     */
    public function add($fd, int $flag, array $func): bool
    {
        switch ($flag) {
            case self::EV_READ:
            case self::EV_WRITE:
                $count = $flag === self::EV_READ ? \count($this->_readFds) : \count($this->_writeFds);
                /** 建议部署网关分流 */
                if ($count >= 1024) {
                    /** 可以修改默认值并重新编译php ，突破1024的上限，不过作为直播，当达到1024个链接的时候，应该考虑CDN了。 */
                    logger()->warning("系统最大支持1024个链接");
                } else if (\DIRECTORY_SEPARATOR !== '/' && $count >= 256) {
                    /** windows系统被限制为最大256个连接 */
                    logger()->warning("系统调用超出了最大连接数256");
                }
                $fd_key = (int)$fd;
                $this->_allEvents[$fd_key][$flag] = array($func, $fd);
                if ($flag === self::EV_READ) {
                    $this->_readFds[$fd_key] = $fd;
                } else {
                    $this->_writeFds[$fd_key] = $fd;
                }
                break;
        }

        return true;
    }

    /**
     * 删除事件
     * @param resource $fd 链接的socket
     * @param int $flag 事件类型
     * @return bool
     */
    public function del($fd, int $flag): bool
    {
        $fd_key = (int)$fd;
        switch ($flag) {
            case self::EV_READ:
                unset($this->_allEvents[$fd_key][$flag], $this->_readFds[$fd_key]);
                if (empty($this->_allEvents[$fd_key])) {
                    unset($this->_allEvents[$fd_key]);
                }
                return true;
            case self::EV_WRITE:
                unset($this->_allEvents[$fd_key][$flag], $this->_writeFds[$fd_key]);
                if (empty($this->_allEvents[$fd_key])) {
                    unset($this->_allEvents[$fd_key]);
                }
                return true;
        }
        return false;
    }


    /**
     * 创建flv播放服务
     * @return void
     */
    private function createFlvSever(): void
    {
        /** 保存flv服务端的socket */
        self::$flvServerSocket = $this->createServer($this->flvPort);
        logger()->info("flv服务：http://{$this->host}:{$this->flvPort}/{AppName}/{ChannelName}.flv");
        logger()->info("flv服务：ws://{$this->host}:{$this->flvPort}/{AppName}/{ChannelName}.flv");
    }

    /**
     * 创建rtmp服务
     */
    private function createRtmpServer(): void
    {
        $this->createServer($this->rtmpPort);
        logger()->info("rtmp服务：rtmp://{$this->host}:{$this->rtmpPort}/{AppName}/{ChannelName}");
    }

    /**
     * 创建web服务器
     * @return void
     */
    private function createHlsServer(): void
    {
        self::$webServerSocket = $this->createServer($this->webPort);
        logger()->info("hls服务：http://{$this->host}:{$this->webPort}/hls/{AppName}/{ChannelName}/index.m3u8");
    }

    /**
     * 创建服务器
     * @param string $port 监听端口
     * @return false|resource
     */
    private function createServer(string $port)
    {
        /**  拼接监听地址 */
        $listeningAddress = $this->protocol . '://' . $this->host . ':' . $port;
        /** 服务端配置 */
        $contextOptions = [];
        /** 不验证https证书 */
        $contextOptions['ssl'] = ['verify_peer' => false, 'verify_peer_name' => false];
        $contextOptions['socket'] = ['backlog' => 1024,'so_reuseport' => 1,'so_reuseaddr' => 1,];
        /** 配置socket流参数 */
        $context = stream_context_create($contextOptions);
        /** 设置端口复用 解决惊群效应  */
        //stream_context_set_option($context, 'socket', 'so_reuseport', 1);
        /** 设置ip复用 */
        //stream_context_set_option($context, 'socket', 'so_reuseaddr', 1);
        /** 设置服务端：监听地址+端口 */
        $socket = stream_socket_server($listeningAddress, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        /** 设置非阻塞，语法是关闭阻塞 */
        stream_set_blocking($socket, 0);
        /** 将服务端保存所有socket列表  */
        self::$allSocket[(int)$socket] = $socket;
        /** 单独保存服务端 */
        $this->serverSocket[(int)$socket] = $socket;
        /** 返回服务器实例 */
        return $socket;
    }

    /**
     * 获取实例
     * @return self|null
     */
    public static function instance(): ?self
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 启动服务
     */
    public function start(): void
    {
        /** 开启rtmp 服务 */
        $this->createRtmpServer();
        /** 创建flv服务器 */
        $this->createFlvSever();
        /** 创建hls服务器 */
        $this->createHlsServer();
        /** 开始接收客户端请求 */
        $this->accept();
    }

    /**
     * 接受客户端的链接，并处理数据
     */
    private function accept(): void
    {
        /** 创建多个子进程阻塞接收服务端socket 这个while死循环 会导致for循环被阻塞，不往下执行，创建了子进程也没有用，直接在第一个子进程哪里阻塞了 */
        while (true) {
            /** 初始化需要监测的可写入的客户端，需要排除的客户端都为空 */
            $except = [];
            /** 需要监听socket，自动清理已报废的链接 */
            foreach (self::$allSocket as $key => $value) {
                if (!is_resource($value)) {
                    unset(self::$allSocket[$key]);
                }
            }
            $write = $read = self::$allSocket;
            /** 使用stream_select函数监测可读，可写的连接，如果某一个连接接收到数据，那么数据就会改变，select使用的foreach遍历所有的连接，查看是否可读，就是有消息的时候标记为可读 */
            try {
                stream_select($read, $write, $except, 0,100);
            } catch (\Exception $exception) {
                logger()->error($exception->getMessage());
            }

            /** 处理可读的链接 */
            if ($read) {
                foreach ($read as $fd) {
                    $fd_key = (int)$fd;
                    /** 处理多个服务端的链接 */
                    if (in_array($fd, $this->serverSocket)) {
                        /** 读取服务端接收到的 消息，这个消息的内容是客户端连接 ，stream_socket_accept方法负责接收客户端连接 */
                        $clientSocket = stream_socket_accept($fd, 0, $remote_address); //阻塞监听 设置超时0，并获取客户端地址
                        /** 如果这个客户端连接不为空 */
                        if (!empty($clientSocket)) {
                            try {
                                /** 使用tcp解码器 */
                                $connection = new TcpConnection($clientSocket, $remote_address);
                                /** 通信协议 */
                                $connection->transport = $this->transport;
                                /** 如果是flv的链接 就设置为http的协议 flv是长链接 */
                                if (self::$flvServerSocket && $fd == self::$flvServerSocket) {
                                    $connection->protocol = \MediaServer\Http\ExtHttpProtocol::class;
                                    /** 支持http的flv播放 onMessage事件处理请求数据，使用ExtHttp协议处理数据， */
                                    $connection->onMessage = [new HttpWMServer(), 'onHttpRequest'];
                                    /** 支持ws的flv播放 onWebSocketConnect事件处理请求数据 ，如果是ws链接，
                                     * ExtHttpProtocol协议自动切换为ws链接，然后在握手后调用ws链接事件，添加播放设备，返回握手信息 ，
                                     * 后续媒体MediaServer使用ws链接返回媒体数据给链接
                                     */
                                    $connection->onWebSocketConnect = [new HttpWMServer(), 'onWebsocketRequest'];
                                }
                                /** web服务器使用http协议 hls是短连接*/
                                if (self::$webServerSocket && $fd == self::$webServerSocket) {
                                    $connection->protocol = Http::class;
                                    /** 为HLS连接创建WMBufferStream来处理HTTP请求 */
                                    new \MediaServer\Utils\WMBufferStream($connection);
                                }
                                /** 处理rtmp链接的数据 - 只对RTMP端口的连接创建RtmpStream */
                                if ($fd != self::$flvServerSocket && $fd != self::$webServerSocket) {
                                    new \MediaServer\Rtmp\RtmpStream(
                                    /** 使用自定义协议处理传递过来的数据 rtmp是长链接 */
                                        new \MediaServer\Utils\WMBufferStream($connection)
                                    );
                                }
                            } catch (\Exception|\RuntimeException $exception) {
                                logger()->error($exception->getMessage());
                            }
                        }
                        /** 将这个客户端连接保存，目测这里如果不保存，应该是无法发送和接收消息的，就是要把所有的连接都保存在内存中 */
                        self::$allSocket[(int)$clientSocket] = $clientSocket;
                    } else {
                        /** 已经是建立过的链接，则直接该链接的读事件 */
                        if (isset($this->_allEvents[$fd_key][self::EV_READ])) {
                            \call_user_func_array(
                                $this->_allEvents[$fd_key][self::EV_READ][0],
                                array($this->_allEvents[$fd_key][self::EV_READ][1])
                            );
                        }
                    }

                }
            }
            /** 处理可写的链接 */
            if ($write) {
                foreach ($write as $fd) {
                    $fd_key = (int)$fd;
                    /** 调用预定义的可写回调函数 */
                    if (isset($this->_allEvents[$fd_key][self::EV_WRITE])) {
                        \call_user_func_array(
                            $this->_allEvents[$fd_key][self::EV_WRITE][0],
                            array($this->_allEvents[$fd_key][self::EV_WRITE][1])
                        );
                    }
                }
            }
        }
    }
}
