<?php

namespace MediaServer\Http;

use Evenement\EventEmitter;
use Root\rtmp\TcpConnection;

/**
 * HTTP POST 推流适配器
 * 用于桥接 TCP 连接和 FlvPublisherStream
 */
class HttpPublishAdapter extends EventEmitter
{
    /**
     * @var TcpConnection
     */
    private $connection;
    
    /**
     * @var string 初始接收到的数据（HTTP 请求体）
     */
    private $initialData;
    
    /**
     * @var bool 是否已发送初始数据
     */
    private $initialDataSent = false;
    
    /**
     * @var bool 是否已关闭
     */
    private $closed = false;

    public function __construct(TcpConnection $connection, string $initialData = '')
    {
        $this->connection = $connection;
        $this->initialData = $initialData;
        
        // 注册连接事件
        $connection->onMessage = [$this, 'onData'];
        $connection->onError = [$this, 'onError'];
        $connection->onClose = [$this, 'onClose'];
    }
    
    /**
     * 开始处理数据流
     * 发送初始数据并触发 'data' 事件
     */
    public function start()
    {
        if ($this->closed) {
            return;
        }
        
        // 发送初始数据
        if (!empty($this->initialData) && !$this->initialDataSent) {
            $this->emit('data', [$this->initialData]);
            $this->initialDataSent = true;
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
        
        // 跳过初始数据（HTTP 头部分），只处理后续数据
        if ($this->initialDataSent || empty($this->initialData)) {
            $this->emit('data', [$data]);
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
