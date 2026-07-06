<?php


namespace MediaServer\Rtmp;


use MediaServer\Utils\BinaryStream;

/**
 * @purpose rtmp握手
 * @author yanglong
 */
trait RtmpHandshakeTrait
{

    /**
     * 握手事件
     * @note 这个很复杂的，这里面涉及到的读取字节，采用的sub_str 分隔字符的方式，而不是逐个字节读取
     * @note 握手的时候客户端发送c0 c1 c2 ，服务端回复 s0 s1 s2,一共是6次握手，而rtmp本身是基于tcp协议，tcp本身是三次握手，那么一共是9次握手
     * 1. c0和s0很简单就是一个byte的版本号，为’\x03’
     *
     * 2. c1和s1是4个bytes的time,4个bytes的0,1528bytes的random data
     *
     * 3. c2和s2是4个bytes的time,4个bytes的time2,1528个bytes的random data(RFC有详细的规定)
     *
     * 参考地址：https://www.jianshu.com/p/379e53e49e33
     *
     */
    public function onHandShake()
    {
        /**
         * 二进制流
         * @var $stream BinaryStream
         */
        $stream=$this->buffer;

        /** 判断握手状态 */
        switch ($this->handshakeState) {
            /** 未初始化  */
            case RtmpHandshake::RTMP_HANDSHAKE_UNINIT:
                if ($stream->has(1)) {
                    logger()->info('RTMP_HANDSHAKE_UNINIT');
                    //read c0
                    /** 读取一个字节 c0和s0很简单就是一个byte的版本号，为’\x03’ 直接丢弃c0的数据 */
                    $stream->readByte();
                    // goto c0
                    $this->handshakeState = RtmpHandshake::RTMP_HANDSHAKE_C0;
                } else {
                    break;
                }
                /** 接收到客户端的c0 固定为0x03*/
            case RtmpHandshake::RTMP_HANDSHAKE_C0:
                /** 判断是否有 1536 个字节 */
                if ($stream->has(1536)) {
                    logger()->info('RTMP_HANDSHAKE_C0');
                    /** 读取c1 c1和s1是4个bytes的time,4个bytes的0,1528bytes的random data 一共是  4 + 4 + 1528 = 1536个bytes */
                    $c1=$stream->readRaw(1536);
                    /** 通过c1 生成 s0s1s2 ,发送给客户端 */
                    //向客户端发送 s0s1s2
                    /** 生成s0s1s2 这里使用c1客户端的随机数生成s0s1s2 */
                    $s0s1s2 = RtmpHandshake::handshakeGenerateS0S1S2($c1);
                    /** 发送客户端 就是如果收到了c1之后，就必须返回给客户端s0 s1 s2 */
                    /** 就是普通的tcp通信，对数据编码，然后发送给客户端 */
                    $this->write($s0s1s2);
                    /** 修改状态为 已接收到c1 */
                    $this->handshakeState = RtmpHandshake::RTMP_HANDSHAKE_C1;
                    /** 这里就有一个问题，就是，服务端在收到c1的时候，就一次性返回s0s1s2*/
                } else {
                    break;
                }
                /** c1 固定格式：| 4字节时间戳time | 4字节模式串 | 1528字节复杂二进制串 |*/
            case RtmpHandshake::RTMP_HANDSHAKE_C1:
                /** 已完成了c1握手处理 ，是否有1536个字符 */
                if ($stream->has(1536)) {
                    logger()->info('RTMP_HANDSHAKE_C1');
                    /** 读取1536 实际作用是移动指针 ，下次从后面1536后面读了 */
                    /** 读取c2 : c2和s2是4个bytes的time,4个bytes的time2,1528个bytes的random data(RFC有详细的规定) */
                    /** 这里读取的c2是被丢了 ，没有用的 */
                    $stream->readRaw(1536);
                    /** 更新状态为接收到c2状态 */
                    $this->handshakeState = RtmpHandshake::RTMP_HANDSHAKE_C2;
                    /** 更新数据分片状态为开始 */
                    $this->chunkState = RtmpChunk::CHUNK_STATE_BEGIN;
                } else {
                    break;
                }
                /** 更新为已接收了c2 不作处理 */
            case RtmpHandshake::RTMP_HANDSHAKE_C2:
        }

    }
}
