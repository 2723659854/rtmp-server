<?php

namespace Root\rtmp;

/**
 * @purpose 协议接口类
 * @author yanglong
 */
interface ProtocolInterface
{
    /**
     * 检查包的完整性，需要返回包的长度，如果返回0则表示需要读取更多的数据，如果发送了错误，那么返回false，客户端链接会被关闭
     * @param string              $recv_buffer
     * @param ConnectionInterface $connection
     * @return int|false
     */
    public static function input($recv_buffer, ConnectionInterface $connection);

    /**
     * 在接收到数据后，需要解码，调用回调onMessage函数来处理业务逻辑，
     * @param string              $recv_buffer
     * @param ConnectionInterface $connection
     * @return mixed
     */
    public static function decode($recv_buffer, ConnectionInterface $connection);

    /**
     * 向客户端发送数据的时候需要先编码
     * @param mixed               $data
     * @param ConnectionInterface $connection
     * @return string
     */
    public static function encode($data, ConnectionInterface $connection);
}
