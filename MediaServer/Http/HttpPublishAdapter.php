<?php

namespace MediaServer\Http;

use Evenement\EventEmitter;
use Root\rtmp\TcpConnection;

/**
 * @purpose HTTP POST 推流适配器
 * @note 用于桥接 TCP 连接和 FlvPublisherStream
 * @author yanglong
 * @note 可以使用php客户端或者ffmpeg用http-flv推流，普通的浏览器或者api工具无法完成推流，因为他们带有content-length字段
 */
class HttpPublishAdapter extends EventEmitter
{
    /**
     * 标准tcp连接
     * @var TcpConnection
     */
    private $connection;
    
    /**
     * @var string 初始接收到的数据（HTTP 请求体）
     */
    private $initialData = '';
    
    /**
     * @var bool 是否使用chunked编码
     */
    private $chunkedTransfer = false;
    
    /**
     * @var string chunked编码的剩余数据
     */
    private $chunkedBuffer = '';
    
    /**
     * @var bool 是否已关闭
     */
    private $closed = false;

    public function __construct(TcpConnection $connection, string $initialData = '')
    {
        $this->connection = $connection;
        $this->initialData = $initialData;
        
        // 检查是否使用chunked编码
        if (isset($connection->context->chunkedTransfer)) {
            $this->chunkedTransfer = $connection->context->chunkedTransfer;
        }
        
        // 注册连接事件
        $connection->onMessage = [$this, 'onData'];
        $connection->onError = [$this, 'onError'];
        $connection->onClose = [$this, 'onClose'];
    }
    
    /**
     * 开始处理数据流
     */
    public function start()
    {
        if ($this->closed) {
            return;
        }
        
        // 标记第一个请求已处理完成，后续数据直接流式传输
        if (!isset($this->connection->context)) {
            $this->connection->context = new \stdClass();
        }
        $this->connection->context->streamingMode = true;
        $this->connection->context->firstRequestProcessed = true;
        
        // 处理初始数据（HTTP请求体的第一部分）
        if (!empty($this->initialData)) {
            $this->processData($this->initialData);
        }
    }
    
    /**
     * 处理接收到的数据
     */
    public function onData(TcpConnection $connection, $data)
    {
        if ($this->closed) {
            return;
        }
        
        $this->processData($data);
    }
    
    /**
     * 处理数据（支持chunked编码）
     */
    private function processData($data)
    {
        if ($this->chunkedTransfer) {
            // 使用chunked编码，需要解析
            $this->chunkedBuffer .= $data;
            
            while ($this->chunkedBuffer !== '') {
                list($decoded, $remaining, $isComplete) = ExtHttpProtocol::parseChunkedData($this->chunkedBuffer);
                
                if ($decoded !== '') {
                    $this->emit('data', [$decoded]);
                }
                
                $this->chunkedBuffer = $remaining;
                
                if ($isComplete) {
                    // chunked数据传输完成
                    $this->finish();
                    return;
                }
                
                if ($remaining !== '') {
                    // 还需要更多数据
                    break;
                }
                
                // 数据已处理完，继续等待
                return;
            }
        } else {
            // 普通数据，直接发送
            $this->emit('data', [$data]);
        }
    }
    
    /**
     * 完成数据接收
     */
    public function finish()
    {
        if ($this->closed) {
            return;
        }
        
        $this->emit('complete');
        
        // 标记流式处理完成，但不要关闭适配器
        // 保持推流资源活跃，供播放器使用
        if (isset($this->connection->context)) {
            $this->connection->context->firstRequestProcessed = true;
        }
    }
    
    /**
     * 处理错误
     */
    public function onError(TcpConnection $connection, $code, $message)
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->emit('error', [new \Exception("Connection error: $message (code: $code)")]);
        $this->emit('close');
    }
    
    /**
     * 处理关闭
     */
    public function onClose(TcpConnection $connection)
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->emit('close');
    }
    
    /**
     * 关闭流
     */
    public function close()
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        
        // 移除连接事件监听
        if ($this->connection) {
            $this->connection->onMessage = null;
            $this->connection->onError = null;
            $this->connection->onClose = null;
        }
        
        $this->emit('close');
    }
    
    /**
     * 检查是否已关闭
     */
    public function isClosed(): bool
    {
        return $this->closed;
    }
    
    /**
     * 获取底层连接
     */
    public function getConnection(): TcpConnection
    {
        return $this->connection;
    }
}
