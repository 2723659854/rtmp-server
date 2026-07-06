<?php


namespace MediaServer\Rtmp;

use \Exception;
use MediaServer\Utils\BinaryStream;

/**
 * @purpose rtmp数据分片处理
 * @author yanglong
 */
trait RtmpChunkHandlerTrait
{

    /**
     * 数据分包
     * @note 记录一下，websocket协议传输数据用掩码，是为了防止链路层抓包数据。链路层完成从 IP 地址到 MAC 地址的转换。ARP 请求以广播形式发送，网络上的主机可以自主发送 ARP 应答消息，
     * @note 网络层级：https://blog.csdn.net/qq_31347869/article/details/107433744
     * @note 这里rtmp也用了掩码
     */
    public function onChunkData()
    {
        /**
         * 首先获取二进制流
         * @var $stream BinaryStream
         */
        $stream = $this->buffer;
        /** 判断分包状态 */
        switch ($this->chunkState) {
            /** 开始分包 握手完成后就更新分包状态为开始 参照分包工具rtmphandshaketrait */
            /** 分包第一步就是，读取分片的长度 */
            case RtmpChunk::CHUNK_STATE_BEGIN:
                /** 读取第一位 */
                if ($stream->has(1)) {
                    /** 标记一下当前位置 */
                    $stream->tag();
                    /** 读取一个字节转化为无符号数据 读取掩码 */
                    $header = $stream->readTinyInt();
                    /** 回滚到上面标记的位置，还原数据的指针 */
                    $stream->rollBack();
                    /** 读取头部的长度 基本信息头部大小 */
                    $chunkHeaderLen = RtmpChunk::BASE_HEADER_SIZES[$header & 0x3f] ?? 1; //base header size
                    //logger()->info('base header size ' . $chunkHeaderLen);
                    /** 读取消息长度 消息头部长度，获取低六位 */
                    $chunkHeaderLen += RtmpChunk::MSG_HEADER_SIZES[$header >> 6]; //messaege header size
                    //logger()->info('base + msg header size ' . $chunkHeaderLen);
                    /** 包长度 分包header头部的长度 */
                    //base header + message header =base + msg
                    $this->chunkHeaderLen = $chunkHeaderLen;
                    /** 修改分包状态为准备完毕 */
                    $this->chunkState = RtmpChunk::CHUNK_STATE_HEADER_READY;
                    /** header被分为三个部分：参考地址  https://blog.csdn.net/xfc_1939/article/details/129801890 */
                } else {
                    break;
                }
                /** 数据分包准备完毕状态 */
            case RtmpChunk::CHUNK_STATE_HEADER_READY:
                /** 这里是初始化包的基本数据 */
                /** 判断是否有指定长度的数据 */
                if ($stream->has($this->chunkHeaderLen)) {
                    /** 读取头部 */
                    //get base header + message header
                    $header = $stream->readTinyInt();
                    /** 获取格式 */
                    $fmt = $header >> 6;
                    /** 通过头部确定对方是大端存储还是小端存储 ，数据解码从前往后，还是从后往前 */
                    switch ($csId = $header & 0x3f) {
                        /** 大端存储 */
                        case 0:
                            $csId = $stream->readTinyInt() + 64;
                            break;
                        case 1:
                            //小端
                            /** 小端存储 */
                            $csId = 64 + $stream->readInt16LE();
                            break;
                    }
                    /** 参考地址 https://blog.csdn.net/qq_24283329/article/details/72790146 csid表示分片id */
                    //logger()->info("header ready fmt {$fmt}  csid {$csId}");
                    //找出当前的流所属的包
                    /** 如果没有当前流所属的包 没有这个分片的包 */
                    if (!isset($this->allPackets[$csId])) {
                        logger()->info("new packet csid {$csId}");
                        /** 实例化rtmp数据包 */
                        $p = new RtmpPacket();
                        /** 数据流分包id */
                        $p->chunkStreamId = $csId;
                        /** 数据包长度 */
                        $p->baseHeaderLen = RtmpChunk::BASE_HEADER_SIZES[$csId] ?? 1;
                        /** 保存数据包 */
                        $this->allPackets[$csId] = $p;
                    } else {
                        //logger()->info("old packet csid {$csId}");
                        $p = $this->allPackets[$csId];
                    }

                    /** 设置编码格式 */
                    //set fmt
                    $p->chunkType = $fmt;
                    //更新长度数据
                    $p->chunkHeaderLen = $this->chunkHeaderLen;

                    //base header 长度不变
                    //$p->baseHeaderLen = RtmpPacket::$BASEHEADERSIZE[$csId] ?? 1;
                    /** chunk = basic + base */
                    /** 计算头部长度，应该是去掉头部前面符号 ，比如ws协议前面有W等字符 */
                    $p->msgHeaderLen = $p->chunkHeaderLen - $p->baseHeaderLen;

                    //logger()->info("packet chunkheaderLen  {$p->chunkHeaderLen}  msg header len {$p->msgHeaderLen}");
                    //当前包
                    $this->currentPacket = $p;
                    /** 更新状态为分包完成 */
                    $this->chunkState = RtmpChunk::CHUNK_STATE_CHUNK_READY;
                    /** 如果是微型数据包 */
                    if ($p->chunkType === RtmpChunk::CHUNK_TYPE_3) {
                        //直接进入判断是否需要读取扩展时间戳的流程
                        /** 如果 是微型数据包，那么就要加入扩展时间戳 会加4字节保存时间戳 https://blog.csdn.net/xfc_1939/article/details/129801890 */
                        $p->state = RtmpPacket::PACKET_STATE_EXT_TIMESTAMP;
                    } else {
                        //当前包的状态初始化
                        /** 标准的数据包头，没有扩展时间戳 0字节 参考 https://blog.csdn.net/xfc_1939/article/details/129801890 */
                        $p->state = RtmpPacket::PACKET_STATE_MSG_HEADER;

                    }
                } else {
                    break;
                }
            case RtmpChunk::CHUNK_STATE_CHUNK_READY:
                /** 处理数据包 */
                if (false === $this->onPacketHandler()) {
                    break;
                }
            default:
                //跑一下看看剩余的数据够不够
                $this->onChunkData();
                break;
        }


    }


    /**
     * rtmp数据包分片
     * @param $packet
     * @return string
     */
    public function rtmpChunksCreate(&$packet)
    {
        $baseHeader = $this->rtmpChunkBasicHeaderCreate($packet->chunkType, $packet->chunkStreamId);
        $baseHeader3 = $this->rtmpChunkBasicHeaderCreate(RtmpChunk::CHUNK_TYPE_3, $packet->chunkStreamId);

        $msgHeader = $this->rtmpChunkMessageHeaderCreate($packet);

        $useExtendedTimestamp = $packet->timestamp >= RtmpPacket::MAX_TIMESTAMP;

        $timestampBin = pack('N', $packet->timestamp);
        $out = $baseHeader . $msgHeader;
        if ($useExtendedTimestamp) {
            $out .= $timestampBin;
        }

        //读取payload
        $readOffset = 0;
        $chunkSize = $this->outChunkSize;
        while ($remain = $packet->length - $readOffset) {

            $size = min($remain, $chunkSize);
            //logger()->debug("rtmpChunksCreate remain {$remain} size {$size}");
            $out .= substr($packet->payload, $readOffset, $size);
            $readOffset += $size;
            if ($readOffset < $packet->length) {
                //payload 还没读取完
                $out .= $baseHeader3;
                if ($useExtendedTimestamp) {
                    $out .= $timestampBin;
                }
            }

        }

        return $out;
    }


    /**
     * 创建chunk分片basic header 数据
     * @param $fmt
     * @param $cid
     * @comment 从函数可以知道，basic header 包含 编码格式和分片id
     */
    public function rtmpChunkBasicHeaderCreate($fmt, $cid)
    {
        if ($cid >= 64 + 255) {
            //cid 小端字节序
            return pack('CS', $fmt << 6 | 1, $cid - 64);
        } elseif ($cid >= 64) {
            return pack('CC', $fmt << 6 | 0, $cid - 64);
        } else {
            return pack('C', $fmt << 6 | $cid);
        }
    }


    /**
     * 创建chunk message header
     * @param $packet RtmpPacket
     * @comment 从函数可以知道，传入的是分片后的数据包
     */
    public function rtmpChunkMessageHeaderCreate($packet)
    {
        $out = "";
        /** 小数据包 偏移1位 只取了3位 */
        if ($packet->chunkType <= RtmpChunk::CHUNK_TYPE_2) {
            //timestamp
            $out .= substr(pack('N', $packet->timestamp >= RtmpPacket::MAX_TIMESTAMP ? RtmpPacket::MAX_TIMESTAMP : $packet->timestamp), 1, 3);
        }

        /** 中数据包 数据包含了流媒体格式 */
        if ($packet->chunkType <= RtmpChunk::CHUNK_TYPE_1) {
            //payload len and stream type
            $out .= substr(pack('N', $packet->length), 1, 3);
            //stream type
            $out .= pack('C', $packet->type);
        }

        /** 大数据包 只包含流媒体id */
        if ($packet->chunkType == RtmpChunk::CHUNK_TYPE_0) {
            //stream id  小端字节序
            $out .= pack('L', $packet->streamId);
        }
        /** 微型数据包不编码 */
        //logger()->debug("rtmpChunkMessageHeaderCreate " . bin2hex($out));

        return $out;
    }


    /**
     * 发送 长度ack
     * @param $size
     * @return void
     * @comment  告诉客户端已接收到的长度
     */
    public function sendACK($size)
    {
        $buf = hex2bin('02000000000004030000000000000000');
        $buf = substr_replace($buf, pack('N', $size), 12);
        $this->write($buf);
    }

    /**
     * 发送 窗口ack
     * @param $size
     * @return void
     * @comment 这个是在服务端接收到客户端发送的链接命令的时候，服务端触发这个函数的，
     * @note 这个工具类里面的方法差不多都是处理客户端命令的
     */
    public function sendWindowACK($size)
    {
        $buf = hex2bin('02000000000004050000000000000000');
        $buf = substr_replace($buf, pack('N', $size), 12);
        $this->write($buf);
    }

    /**
     * 设置宽带信息
     * @param $size
     * @param $type
     * @return void
     * @comment 在 RTMP（Real Time Messaging Protocol，实时消息传输协议）中，PeerBand 指的是客户端和服务器之间的带宽协商信息。它用于确定双方在数据传输过程中能够使用的最大带宽。
     * 在 RTMP 连接建立后，客户端和服务器会通过交换消息来协商带宽。这个过程通常包括客户端向服务器发送带宽请求，服务器根据自身的资源和网络状况回复带宽限制或建议。
     * 通过协商带宽，RTMP 可以实现自适应流媒体传输，根据网络条件和客户端的带宽能力，动态调整视频的码率和质量，以提供最佳的观看体验。
     * 需要注意的是，具体的带宽协商机制和实现可能因使用的 RTMP 库或服务而有所不同。在实际应用中，还需要考虑网络延迟、拥塞控制等因素来确保稳定和高效的数据传输。
     * 如果你需要更详细和准确的信息，建议参考相关的 RTMP 规范文档或所使用的具体 RTMP 实现的文档。
     */
    public function setPeerBandwidth($size, $type)
    {
        $buf = hex2bin('0200000000000506000000000000000000');
        $buf = substr_replace($buf, pack('NC', $size, $type), 12);
        $this->write($buf);

    }

    /**
     * 设置分片大小信息
     * @param $size
     * @return void
     */
    public function setChunkSize($size)
    {
        $buf = hex2bin('02000000000004010000000000000000');
        $buf = substr_replace($buf, pack('N', $size), 12);
        $this->write($buf);
    }


}
