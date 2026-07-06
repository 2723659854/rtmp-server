<?php


namespace MediaServer\Rtmp;


use MediaServer\Utils\BinaryStream;


/**
 * 处理数据包
 * Trait RtmpPacketTrait
 * @package MediaServer\Rtmp
 */
trait RtmpPacketTrait
{

    public function onPacketHandler()
    {
        /**
         * 读物二进制流
         * @var $stream BinaryStream
         */
        $stream = $this->buffer;


        /** @var RtmpPacket $p */
        $p = $this->currentPacket;
        /** 判断包状态 */
        switch ($p->state) {
            /** 数据包开始位置 头部没有扩展时间戳 https://blog.csdn.net/xfc_1939/article/details/129801890 */
            case RtmpPacket::PACKET_STATE_MSG_HEADER:
                //base header + message header
                /** 如果数据长度满足头部长度 */
                if ($stream->has($p->msgHeaderLen)) {
                    /** 判断分包类型 */
                    switch ($p->chunkType) {
                        /** 微型数据包 */
                        case RtmpChunk::CHUNK_TYPE_3:
                            // all same
                            break;
                            /** 小型数据包 */
                        case RtmpChunk::CHUNK_TYPE_2:
                            //new timestamp delta, 3bytes
                            /** 读取时间戳 */
                            $p->timestamp = $stream->readInt24();
                            break;
                            /** 中数据包 */
                        case RtmpChunk::CHUNK_TYPE_1:
                            //new timestamp delta, length,type 7bytes
                            /** 处理时间戳，长度，类型 */
                            $p->timestamp = $stream->readInt24();
                            $p->length = $stream->readInt24();
                            $p->type = $stream->readTinyInt();
                            break;
                            /** 大数据包 */
                        case RtmpChunk::CHUNK_TYPE_0:
                            /** 读取时间戳，长度，类型，流媒体id */
                            //all different, 11bytes
                            $p->timestamp = $stream->readInt24();
                            $p->length = $stream->readInt24();
                            $p->type = $stream->readTinyInt();
                            $p->streamId = $stream->readInt32LE();
                            break;
                    }
                    /** 大数据包 */
                    if ($p->chunkType == RtmpChunk::CHUNK_TYPE_0) {
                        //当前时间是绝对时间
                        $p->hasAbsTimestamp = true;
                    }

                    /** 更新数据包状态 加入了extended time stamp */
                    $p->state = RtmpPacket::PACKET_STATE_EXT_TIMESTAMP;

                    //logger()->info("chunk header fin");
                } else {
                    //长度不够，等待下个数据包
                    return false;
                }
                /** 数据包接收完整了 */
            case RtmpPacket::PACKET_STATE_EXT_TIMESTAMP:
                /** 数据包的时间戳 == 数据包的最大时间戳 */
                if ($p->timestamp === RtmpPacket::MAX_TIMESTAMP) {
                    /** 是否有4个字节呢，读取扩展时间戳  */
                    if ($stream->has(4)) {
                        /** 读32位 */
                        $extTimestamp = $stream->readInt32();
                        logger()->info("chunk has ext timestamp {$extTimestamp}");
                        $p->hasExtTimestamp = true;
                    } else {
                        //当前长度不够，等待下个数据包
                        return false;
                    }
                } else {
                    $extTimestamp = $p->timestamp;
                }
                /** 已读数据为0 */
                //判断当前包是不是有数据
                if ($p->bytesRead == 0) {
                    /** 给数据包添加时间 */
                    if ($p->chunkType == RtmpChunk::CHUNK_TYPE_0) {
                        $p->clock = $extTimestamp;
                    } else {
                        $p->clock += $extTimestamp;
                    }

                }
                /** 有数据啦 */
                $p->state = RtmpPacket::PACKET_STATE_PAYLOAD;
            case RtmpPacket::PACKET_STATE_PAYLOAD:
                /** 需要读取的数据大小   是完整包的长度 ，包长度-已读长度 ，如果一样大则读取完整包，如果小于包长度，则说明读取剩余包的数据 */
                $size = min(
                    $this->inChunkSize, //读取完整的包
                    $p->length - $p->bytesRead  //当前剩余的数据
                );

                /** 还需要读取数据 */
                if ($size > 0) {
                    /** 有足够长度的数据 */
                    if ($stream->has($size)) {
                        //数据拷贝
                        $p->payload .= $stream->readRaw($size);
                        /** 标记已读取的长度 */
                        $p->bytesRead += $size;
                        //logger()->info("packet csid {$p->chunkStreamId} stream {$p->streamId} payload  size {$size} payload size: {$p->length} bytesRead {$p->bytesRead}");
                    } else {
                        //长度不够，等待下个数据包
                        //logger()->info("packet csid  {$p->chunkStreamId} stream {$p->streamId} payload  size {$size} payload size: {$p->length} bytesRead {$p->bytesRead} buffer ") . " not enough.");
                        return false;
                    }
                }
                /** 如果包的数据 已经读完了*/
                if ($p->isReady()) {//$this->bytesRead == $this->length
                    //开始读取下一个包
                    $this->chunkState = RtmpChunk::CHUNK_STATE_BEGIN;
                    /** 处理包数据 */
                    $this->rtmpHandler($p);

                    //当前包已经读取完成数据，释放当前包
                    $p->free();
                } elseif (0 === $p->bytesRead % $this->inChunkSize) {
                    //当前chunk已经读取完成
                    $this->chunkState = RtmpChunk::CHUNK_STATE_BEGIN;
                }
        }
    }
}
