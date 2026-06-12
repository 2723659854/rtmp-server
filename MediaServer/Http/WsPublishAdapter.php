<?php

namespace MediaServer\Http;

use Evenement\EventEmitter;
use Root\rtmp\TcpConnection;

/**
 * WebSocket 推流适配器
 * 用于桥接 WebSocket 连接和 FlvPublisherStream
 */
class WsPublishAdapter extends EventEmitter
{
    /**
     * @var TcpConnection
     */
    private $connection;
    
    /**
     * @var bool 是否已关闭
     */
    private $closed = false;

    public function __construct(TcpConnection $connection)
    {
        $this->connection = $connection;
        
        // 注册连接事件
        $connection->onMessage = [$this, 'onData'];
        $connection->onClose = [$this, 'onClose'];
    }
    
    /**
     * 处理接收到的数据
     */
    public function onData(TcpConnection $connection, $data)
    {
        if ($this->closed) {
            return;
        }
        
        $this->emit('data', [$data]);
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
