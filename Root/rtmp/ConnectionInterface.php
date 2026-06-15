<?php

namespace Root\rtmp;

/**
 * @purpose 链接接口类
 */
#[\AllowDynamicProperties]
abstract class  ConnectionInterface
{
    /**
     * 状态命令
     * @var array
     */
    public static $statistics = array(
        'connection_count' => 0,
        'total_request'    => 0,
        'throw_exception'  => 0,
        'send_fail'        => 0,
    );

    const SEND_FAIL = 0;

    /**
     * 接收到消息回调事件
     * @var callable
     */
    public $onMessage = null;

    /**
     * 当发送tcp数据包的FIN的时候触发这个回调事件
     * @var callable
     */
    public $onClose = null;

    /**
     * 当客户端链接发生错误的时候触发这个回调
     * @var callable
     */
    public $onError = null;

    /**
     * 给客户端发送数据
     * @param mixed $send_buffer
     * @return void|boolean
     */
    abstract public function send($send_buffer);

    /**
     * 获取客户端IP
     * @return string
     */
    abstract public function getRemoteIp();

    /**
     * 获取客户端端口
     * @return int
     */
    abstract public function getRemotePort();

    /**
     * 获取客户端地址
     * @return string
     */
    abstract public function getRemoteAddress();

    /**
     * 获取本机IP
     * @return string
     */
    abstract public function getLocalIp();

    /**
     * 获取本机端口
     * @return int
     */
    abstract public function getLocalPort();

    /**
     * 获取本机地址
     * @return string
     */
    abstract public function getLocalAddress();

    /**
     * 是否ip4地址
     * @return bool
     */
    abstract public function isIPv4();

    /**
     * 是否IP6地址
     * @return bool
     */
    abstract public function isIPv6();

    /**
     * 关闭客户端
     * @param string|null $data
     * @return void
     */
    abstract public function close($data = null);
}
