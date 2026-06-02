<?php

namespace Root\rtmp;

use Root\Io\RtmpDemo;

/**
 * @purpose 这里是tcp协议操作类
 */
class TcpConnection extends ConnectionInterface
{
    /**
     * 最大缓存
     * @var int
     */
    const READ_BUFFER_SIZE = 655350;

    /**
     * 初始化状态
     * @var int
     */
    const STATUS_INITIAL = 0;

    /**
     * 连接中
     * @var int
     */
    const STATUS_CONNECTING = 1;

    /**
     * 链接已建立
     * @var int
     */
    const STATUS_ESTABLISHED = 2;

    /**
     * 链接关闭中
     * @var int
     */
    const STATUS_CLOSING = 4;

    /**
     * 链接已关闭
     * @var int
     */
    const STATUS_CLOSED = 8;

    /**
     * 接收到数据回调函数
     * @var callable
     */
    public $onMessage = null;

    /**
     * @var callable $onWebSocketConnect ws链接处理事件
     */
    public $onWebSocketConnect = null;

    /**
     * 当tcp发送fin新号的时候触发这个回调函数
     * @var callable
     */
    public $onClose = null;

    /**
     * 当发生错误的时候触发这个回调
     * @var callable
     */
    public $onError = null;

    /**
     * 当缓存区满的时候触发
     * @var callable
     */
    public $onBufferFull = null;

    /**
     * 当缓存区已空闲的时候触发
     * @var callable
     */
    public $onBufferDrain = null;

    /**
     * 使用的协议
     * @var ProtocolInterface
     */
    public $protocol = null;

    /**
     * Transport (tcp/udp/unix/ssl).
     * 数据传输协议
     * @var string
     */
    public $transport = 'tcp';

    /**
     * 链接所属工作进程
     */
    public $worker = null;

    /**
     * 已读字节数
     * @var int
     */
    public $bytesRead = 0;

    /**
     * 已写字节数
     * @var int
     */
    public $bytesWritten = 0;

    /**
     * 链接的id
     * @var int
     */
    public $id = 0;

    /**
     * 复制的上面的id,当客户端被复制的时候
     * @var int
     */
    protected $_id = 0;

    /**
     * 最大发送长度，如果达到这个字节，上面的暂存区满函数会被触发
     * @var int
     */
    public $maxSendBufferSize = 10485760;

    /**
     * 内容
     * @var object|null
     */
    public $context = null;

    /**
     * 默认最大发送长度
     * @var int
     */
    public static $defaultMaxSendBufferSize = 10485760;

    /**
     * 当前链接最大包长度
     * @var int
     */
    public $maxPackageSize = 10485760;

    /**
     * 默认链接最大包长度
     * @var int
     * @note 因为是音视频直播流，数据流比较大，所以把缓存扩大了10倍，防止缓存满了自动断开连接。
     */
    public static $defaultMaxPackageSize = 10485760;

    /**
     * id记录器
     * @var int
     */
    protected static $_idRecorder = 1;

    /**
     * socket链接
     * @var resource
     */
    protected $_socket = null;

    /**
     * 发送暂存数据
     * @var string
     */
    protected $_sendBuffer = '';

    /**
     * 接收暂存数据
     * @var string
     */
    protected $_recvBuffer = '';

    /**
     * 当前包长度
     * @var int
     */
    protected $_currentPackageLength = 0;

    /**
     * 链接状态
     * @var int
     */
    protected $_status = self::STATUS_ESTABLISHED;

    /**
     * 客户端地址
     * @var string
     */
    protected $_remoteAddress = '';

    /**
     * 暂停状态？
     * @var bool
     */
    protected $_isPaused = false;

    /**
     * SSL 完成握手与否
     *
     * @var bool
     */
    protected $_sslHandshakeCompleted = false;

    /**
     * 状态码字典
     * @var array
     */
    public static $_statusToString = array(
        self::STATUS_INITIAL     => 'INITIAL',
        self::STATUS_CONNECTING  => 'CONNECTING',
        self::STATUS_ESTABLISHED => 'ESTABLISHED',
        self::STATUS_CLOSING     => 'CLOSING',
        self::STATUS_CLOSED      => 'CLOSED',
    );

    /**
     * 初始化
     * @param resource $socket 客户端链接
     * @param string   $remote_address 客户端地址
     */
    public function __construct($socket, $remote_address = '')
    {
        /** 连接数+1 */
        ++self::$statistics['connection_count'];
        /** 给每一个连接分配一个id */
        $this->id = $this->_id = self::$_idRecorder++;
        /** 如果已经到了php的最大整数 归零 */
        if(self::$_idRecorder === \PHP_INT_MAX){
            self::$_idRecorder = 0;
        }
        /** 保存链接 */
        $this->_socket = $socket;
        /** 设置非阻塞，语法是关闭阻塞 异步 */
        \stream_set_blocking($this->_socket, 0);
        // 与hhvm兼容 就是与php被编译后的高级别字节码兼容
        if (\function_exists('stream_set_read_buffer')) {
            /** 设置指定流的读取缓冲区大小 就是不缓存数据，直接读取 ，流式读取 */
            \stream_set_read_buffer($this->_socket, 0);
        }
        /** 给这个连接设置可读事件回调函数，就是这个链接可读的时候，Io模型调用baseRead事件来读取数据 */
        RtmpDemo::instance()->add($this->_socket, EventInterface::EV_READ, array($this, 'baseRead'));
        /** 初始化链接的暂存区大小，包大小 ，并保存客户端地址 */
        $this->maxSendBufferSize        = self::$defaultMaxSendBufferSize;
        $this->maxPackageSize           = self::$defaultMaxPackageSize;
        $this->_remoteAddress           = $remote_address;
        /** 初始化链接的内容为空 */
        $this->context = new \stdClass;
    }

    /**
     * 获取链接的状态
     * @param bool $raw_output 是否返回原始状态码
     *
     * @return int|string
     */
    public function getStatus($raw_output = true)
    {
        if ($raw_output) {
            return $this->_status;
        }
        return self::$_statusToString[$this->_status];
    }

    /**
     * 向客户端发送数据
     * @param mixed $send_buffer 需要发送的内容
     * @param bool  $raw 是否发送原始数据，就是是否需要编码
     * @return bool|null
     */
    public function send($send_buffer, $raw = false)
    {
        /** 链接被关闭，不发送 */
        if ($this->_status === self::STATUS_CLOSING || $this->_status === self::STATUS_CLOSED) {
            return false;
        }

        /** 在发送之前对数据进行编码 */
        if (false === $raw && $this->protocol !== null) {
            $parser      = $this->protocol;
            $send_buffer = $parser::encode($send_buffer, $this);
            if ($send_buffer === '') {
                return;
            }
        }

        /** 如果链接尚未建立 并且协议是ssl ，需要加密，那就要先建立ssl链接 */
        if ($this->_status !== self::STATUS_ESTABLISHED ||
            ($this->transport === 'ssl' && $this->_sslHandshakeCompleted !== true)
        ) {
            if ($this->_sendBuffer && $this->bufferIsFull()) {
                ++self::$statistics['send_fail'];
                return false;
            }
            /** 暂存数据 */
            $this->_sendBuffer .= $send_buffer;
            $this->checkBufferWillFull();
            return;
        }

        /** 如果暂存区是空的 直接发送 */
        if ($this->_sendBuffer === '') {
            /** 如果传输协议是ssl */
            if ($this->transport === 'ssl') {
                /** 添加baseWrite事件 IO模型在链接可写的时候触发这个函数 */
                RtmpDemo::instance()->add($this->_socket, EventInterface::EV_WRITE, array($this, 'baseWrite'));
                /** 暂存数据 */
                $this->_sendBuffer = $send_buffer;
                $this->checkBufferWillFull();
                return;
            }
            /** 初始化长度为0 */
            $len = 0;
            try {
                /** 向客户端发送数据 */
                $len = @\fwrite($this->_socket, $send_buffer);
            } catch (\Exception $e) {
                logger()->error($e->getMessage());
            }
            /** 发送成功，更新已发送长度 */

            if ($len === \strlen($send_buffer)) {
                $this->bytesWritten += $len;
                return true;
            }
            /** 如果只发送了一部分数据 ，就要更新待发送数据，更新暂存区数据 */

            if ($len > 0) {
                $this->_sendBuffer = \substr($send_buffer, $len);
                $this->bytesWritten += $len;
            } else {
                /** 如果链接已经关闭了 */
                if (!\is_resource($this->_socket) || \feof($this->_socket)) {
                    ++self::$statistics['send_fail'];
                    /** 触发错误事件 */
                    if ($this->onError) {
                        try {
                            \call_user_func($this->onError, $this, 2, 'client closed');
                        } catch (\Exception $e) {
                            logger()->error($e->getMessage());
                        }
                    }
                    /** 销毁链接 */
                    $this->destroy();
                    return false;
                }
                /** 还原暂存区数据 */
                $this->_sendBuffer = $send_buffer;
            }
            /** 给客户端添加可写事件，当客户端链接空闲的时候，发送数据 */
            RtmpDemo::instance()->add($this->_socket, EventInterface::EV_WRITE, array($this, 'baseWrite'));
            /** 检查暂存区是否已满 */
            $this->checkBufferWillFull();
            return;
        }

        if ($this->bufferIsFull()) {
            ++self::$statistics['send_fail'];
            return false;
        }
        /** 数据存入暂存区 */
        $this->_sendBuffer .= $send_buffer;
        $this->checkBufferWillFull();
    }

    /**
     * 获取客户端IP
     * @return string
     */
    public function getRemoteIp()
    {
        $pos = \strrpos($this->_remoteAddress, ':');
        if ($pos) {
            return (string) \substr($this->_remoteAddress, 0, $pos);
        }
        return '';
    }

    /**
     * 获取客户端端口
     * @return int
     */
    public function getRemotePort()
    {
        if ($this->_remoteAddress) {
            return (int) \substr(\strrchr($this->_remoteAddress, ':'), 1);
        }
        return 0;
    }

    /**
     * 获取客户端地址
     * @return string
     */
    public function getRemoteAddress()
    {
        return $this->_remoteAddress;
    }

    /**
     * 获取本机IP
     * @return string
     */
    public function getLocalIp()
    {
        $address = $this->getLocalAddress();
        $pos = \strrpos($address, ':');
        if (!$pos) {
            return '';
        }
        return \substr($address, 0, $pos);
    }

    /**
     * 获取本机端口
     * @return int
     */
    public function getLocalPort()
    {
        $address = $this->getLocalAddress();
        $pos = \strrpos($address, ':');
        if (!$pos) {
            return 0;
        }
        return (int)\substr(\strrchr($address, ':'), 1);
    }

    /**
     * 获取本机地址
     * @return string
     */
    public function getLocalAddress()
    {
        if (!\is_resource($this->_socket)) {
            return '';
        }
        return (string)@\stream_socket_get_name($this->_socket, false);
    }

    /**
     * 获取暂存区数据长度
     * @return integer
     */
    public function getSendBufferQueueSize()
    {
        return \strlen($this->_sendBuffer);
    }

    /**
     * 获取接收数据暂存区数据长度
     * @return integer
     */
    public function getRecvBufferQueueSize()
    {
        return \strlen($this->_recvBuffer);
    }

    /**
     * 是否ip4
     * return bool.
     */
    public function isIpV4()
    {
        if ($this->transport === 'unix') {
            return false;
        }
        return \strpos($this->getRemoteIp(), ':') === false;
    }

    /**
     * 是否Ip6
     * return bool.
     */
    public function isIpV6()
    {
        if ($this->transport === 'unix') {
            return false;
        }
        return \strpos($this->getRemoteIp(), ':') !== false;
    }

    /**
     * 暂停链接，删除这个链接的可读事件，不再读取链接的数据
     * @return void
     */
    public function pauseRecv()
    {

        RtmpDemo::instance()->del($this->_socket, EventInterface::EV_READ);

        $this->_isPaused = true;
    }

    /**
     * 恢复链接，
     * @return void
     */
    public function resumeRecv()
    {
        if ($this->_isPaused === true) {
            /** 给链接添加可读事件 */
            RtmpDemo::instance()->add($this->_socket, EventInterface::EV_READ, array($this, 'baseRead'));
            $this->_isPaused = false;
            /** 并且立即读取数据 */
            $this->baseRead($this->_socket, false);
        }
    }



    /**
     * 读取链接的数据
     * @param resource $socket 链接
     * @param bool $check_eof 是否检查已接收完数据
     * @return void
     */
    public function baseRead($socket, $check_eof = true)
    {
        /** ssl 握手 */
        if ($this->transport === 'ssl' && $this->_sslHandshakeCompleted !== true) {
            if ($this->doSslHandshake($socket)) {
                $this->_sslHandshakeCompleted = true;
                /** 如果有可发送数据则添加可写事件 */
                if ($this->_sendBuffer) {
                    RtmpDemo::instance()->add($socket, EventInterface::EV_WRITE, array($this, 'baseWrite'));
                }
            } else {
                return;
            }
        }
        /** 读取的数据 */
        $buffer = '';
        try {
            /** 按最大长度读取数据 */
            $buffer = @\fread($socket, self::READ_BUFFER_SIZE);
        } catch (\Exception $e) {}

        /** 如果数据为空 */
        if ($buffer === '' || $buffer === false) {
            /** 如果要检查 ，关闭链接 */
            if ($check_eof && (\feof($socket) || !\is_resource($socket) || $buffer === false)) {
                $this->destroy();
                return;
            }
        } else {
            /** 更新已读取长度 ，更新暂存区数据 */
            $this->bytesRead += \strlen($buffer);
            $this->_recvBuffer .= $buffer;
        }
        /** 获取这个链接的通信协议 */
        if ($this->protocol !== null) {
            /** 获取链接的协议， */
            $parser = $this->protocol;
            /** 如果有数据 */
            while ($this->_recvBuffer !== '' && !$this->_isPaused) {

                if ($this->_currentPackageLength) {

                    /** 如果当前包的长度大于暂存区数据长度，说明还没有接收完数据，请继续接收数据 */
                    if ($this->_currentPackageLength > \strlen($this->_recvBuffer)) {

                        break;
                    }
                } else {
                    try {
                        /** 检查包的完整性 如果是rtmp协议，那么这里返回了0 */
                        $this->_currentPackageLength = $parser::input($this->_recvBuffer, $this);
                    } catch (\Exception $e) {

                    }
                    /** 如果返回的是0 则需要读取更多的数据，就是客户端数据还没有发送完，还不知道包的长度是多少 */
                    if ($this->_currentPackageLength === 0) {/** rtmp协议，已经截获了数据 ，返回0 程序走到这里就，数据被WMBufferStream触发ondata事件来处理 */

                        break;
                        /** 包长度小于 系统设定最大包长度 */
                    } elseif ($this->_currentPackageLength > 0 && $this->_currentPackageLength <= $this->maxPackageSize) {
                        /** 还没有接收完数据 */
                        if ($this->_currentPackageLength > \strlen($this->_recvBuffer)) {

                            break;
                        }
                    }
                    else {
                        /** 错误的包 */
                        logger()->error('Error package. package_length=' . \var_export($this->_currentPackageLength, true));
                        $this->destroy();
                        return;
                    }
                }

                /** 更新请求数 */
                ++self::$statistics['total_request'];
                /** 当前读取的数据长度和客户端传输数据长度一致 清空暂存区 */
                if (\strlen($this->_recvBuffer) === $this->_currentPackageLength) {
                    $one_request_buffer = $this->_recvBuffer;
                    $this->_recvBuffer  = '';
                } else {
                    /** 处理的数据 只截取包的长度 ，更新暂存区内容 */
                    $one_request_buffer = \substr($this->_recvBuffer, 0, $this->_currentPackageLength);
                    $this->_recvBuffer = \substr($this->_recvBuffer, $this->_currentPackageLength);
                }

                /** 还原包的长度 */
                $this->_currentPackageLength = 0;
                /** 如果未定义消息处理回调函数 */
                if (!$this->onMessage) {

                    continue;
                }
                try {
                    /** 先对数据解码 调用这个协议定义的回调事件处理逻辑 实际上在建立链接的时候就定义了数据处理回调函数 flv数据将会调用HttpWMServer的onHttpRequest函数处理数据 */
                    \call_user_func($this->onMessage, $this, $parser::decode($one_request_buffer, $this));
                } catch (\Exception $e) {
                    logger()->error($e->getMessage());
                }
            }
            return;
        }

        /** 如果没有接收到数据 或者暂停接收链接的数据则结束 */
        if ($this->_recvBuffer === '' || $this->_isPaused) {
            return;
        }

        /** 以下是没有设置协议的流程  */

        /** 请求数 +1 */
        ++self::$statistics['total_request'];
        /** 如果没有设置数据处理回调函数，则程序结束 并且清空暂存区 */
        if (!$this->onMessage) {
            $this->_recvBuffer = '';
            return;
        }
        /** 如果设置了数据处理回调函数，则处理业务逻辑，没有定义协议则不校验数据包长度，不对原始数据解码 */
        try {
            \call_user_func($this->onMessage, $this, $this->_recvBuffer);
        } catch (\Exception $e) {
            logger()->error($e->getMessage());
        }
        /** 清空暂存区  */
        $this->_recvBuffer = '';
    }

    /**
     * 发送数据业务逻辑
     * @return void|bool
     * @comment 此方法用于io多路复用模型的可写事件调用
     */
    public function baseWrite()
    {
        /** 屏蔽异常 */
        \set_error_handler(function(){});
        /** 如果是ssl加密 直接发送8192个字节 */
        if ($this->transport === 'ssl') {
            $len = @\fwrite($this->_socket, $this->_sendBuffer, 8192);
        } else {
            /** 否则一次全部发送 */
            $len = @\fwrite($this->_socket, $this->_sendBuffer);
        }
        /** 还原异常处理 */
        \restore_error_handler();
        /** 如果已经全部发送完毕 */
        if ($len === \strlen($this->_sendBuffer)) {
            /** 更新已发数据长度 */
            $this->bytesWritten += $len;
            /** 删除写事件 */
            RtmpDemo::instance()->del($this->_socket, EventInterface::EV_WRITE);
            /** 清空暂存区 */
            $this->_sendBuffer = '';
            /** 出发暂存区空闲事件 */
            if ($this->onBufferDrain) {
                try {
                    \call_user_func($this->onBufferDrain, $this);
                } catch (\Exception $e) {

                    logger()->error($e->getMessage());
                }
            }
            /** 如果链接已经关闭，则销毁链接 */
            if ($this->_status === self::STATUS_CLOSING) {
                $this->destroy();
            }
            return true;
        }
        /** 只发送了部分数据 */
        if ($len > 0) {
            /** 更新已发送数据长度 并且更新暂存区数据 */
            $this->bytesWritten += $len;
            $this->_sendBuffer = \substr($this->_sendBuffer, $len);
        } else {
            /** 发送失败，直接销毁链接 */
            ++self::$statistics['send_fail'];
            $this->destroy();
        }
    }

    /**
     * ssl握手
     * @param resource $socket
     * @return bool
     */
    public function doSslHandshake($socket){
        return true;
    }

    /**
     * 读取数据流中的数据，然后发送到目的客户端
     * @param self $dest 目的客户端
     * @return void
     * @comment  转发请求
     */
    public function pipe(self $dest)
    {
        $source              = $this;
        /** 当本服务器接收到数据后 */
        $this->onMessage     = function ($source, $data) use ($dest) {
            /** 发送数据给目标客户端 */
            $dest->send($data);
        };
        /** 本服务关闭 */
        $this->onClose       = function ($source) use ($dest) {
            /** 关闭目标客户端 */
            $dest->close();
        };
        /** 目标客户端暂存区已满 */
        $dest->onBufferFull  = function ($dest) use ($source) {
            /** 本服务器暂停接收数据 */
            $source->pauseRecv();
        };
        /** 客户端暂存区空闲 */
        $dest->onBufferDrain = function ($dest) use ($source) {
            /** 本服务器恢复接收数据 */
            $source->resumeRecv();
        };
    }

    /**
     * 更新暂存区数据
     *
     * @param int $length
     * @return void
     */
    public function consumeRecvBuffer($length)
    {
        $this->_recvBuffer = \substr($this->_recvBuffer, $length);
    }

    /**
     * 关闭链接
     * @param mixed $data
     * @param bool $raw
     * @return void
     */
    public function close($data = null, $raw = false)
    {
        /** 关闭中直接销毁 */
        if($this->_status === self::STATUS_CONNECTING){
            $this->destroy();
            return;
        }

        if ($this->_status === self::STATUS_CLOSING || $this->_status === self::STATUS_CLOSED) {
            return;
        }

        /** 发送最后的数据 */
        if ($data !== null) {
            $this->send($data, $raw);
        }

        $this->_status = self::STATUS_CLOSING;

        if ($this->_sendBuffer === '') {
            $this->destroy();
        } else {
            $this->pauseRecv();
        }
    }

    /**
     * 获取socket链接
     *
     * @return resource
     */
    public function getSocket()
    {
        return $this->_socket;
    }

    /**
     * 检查发送暂存区数据是否已满
     *
     * @return void
     */
    protected function checkBufferWillFull()
    {
        if ($this->maxSendBufferSize <= \strlen($this->_sendBuffer)) {
            if ($this->onBufferFull) {
                try {
                    \call_user_func($this->onBufferFull, $this);
                } catch (\Exception $e) {

                    logger()->error($e->getMessage());
                }
            }
        }
    }

    /**
     * 检查暂存区是否溢出
     *
     * @return bool
     */
    protected function bufferIsFull()
    {
        /** 暂存区已满，但是还有数据需要发送 ，数据包被丢弃 */
        if ($this->maxSendBufferSize <= \strlen($this->_sendBuffer)) {
            if ($this->onError) {
                try {
                    \call_user_func($this->onError, $this, 2, 'send buffer full and drop package');
                    //logger()->error('拒绝抛出异常，暂存区已满，send buffer full and drop package');
                } catch (\Exception $e) {
                    logger()->error($e->getMessage());
                }
            }
            return true;
            //return false;
        }
        return false;
    }

    /**
     * 暂存区是否为空
     *
     * @return bool
     */
    public function bufferIsEmpty()
    {
        return empty($this->_sendBuffer);
    }

    /**
     * 销毁链接
     *
     * @return void
     */
    public function destroy()
    {
        if ($this->_status === self::STATUS_CLOSED) {
            return;
        }
        /** 移除所有的监听事件 */
        RtmpDemo::instance()->del($this->_socket, EventInterface::EV_READ);
        RtmpDemo::instance()->del($this->_socket, EventInterface::EV_WRITE);
        unset(RtmpDemo::$allSocket[(int)$this->_socket]);
        /** 关闭链接socket */
        try {
            @\fclose($this->_socket);
        } catch (\Exception $e) {
            logger()->error($e->getMessage());
        }
        /** 修改链接状态为已关闭 */
        $this->_status = self::STATUS_CLOSED;
        /** 出发关闭回调函数 */
        if ($this->onClose) {
            try {
                \call_user_func($this->onClose, $this);
            } catch (\Exception $e) {

                logger()->error($e->getMessage());
            }
        }
        /** 触发协议的关闭事件 */
        if ($this->protocol && \method_exists($this->protocol, 'onClose')) {
            try {
                \call_user_func(array($this->protocol, 'onClose'), $this);
            } catch (\Exception $e) {
                logger()->error($e->getMessage());

            }
        }
        /** 清空暂存区 和相关状态 */
        $this->_sendBuffer = $this->_recvBuffer = '';
        $this->_currentPackageLength = 0;
        $this->_isPaused = $this->_sslHandshakeCompleted = false;
        if ($this->_status === self::STATUS_CLOSED) {
            /** 清空所有回调函数 */
            $this->onMessage = $this->onClose = $this->onError = $this->onBufferFull = $this->onBufferDrain = null;
            /** 删除这个链接的信息 */
            if ($this->worker) {
                unset($this->worker->connections[$this->_id]);
            }
            /** 释放链接 */
            unset(RtmpDemo::$allSocket[(int)$this->_socket]);
        }
    }

    /**
     * Destruct.
     *
     * @return void
     */
    public function __destruct()
    {
        self::$statistics['connection_count']--;
    }
}
