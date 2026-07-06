<?php


namespace MediaServer\Rtmp;

/**
 * @purpose rtmp协议控制信息处理流程
 * @author yanglong
 */
trait RtmpControlHandlerTrait
{
    /**
     * 处理rtmp
     * @return void
     */
    public function rtmpControlHandler()
    {
        /** 获取当前时间戳 */
        $b = microtime(true);
        /** 获取当前的数据包 */
        $p = $this->currentPacket;
        /** 判断类型 从下面的代码可以知道：只处理了分片大小，ack大小，至于终止，ack确认，设置宽带都忽略了，没有处理，不能自动适配网络 */
        switch ($p->type) {
            /** 设置数据分包大小 */
            case RtmpPacket::TYPE_SET_CHUNK_SIZE:
                /** 解码，设置分片大小 */
                list(, $this->inChunkSize) = unpack("N", $p->payload);
                logger()->debug('set inChunkSize ' . $this->inChunkSize);
                break;
                /** 终止 */
            case RtmpPacket::TYPE_ABORT:
                break;
                /** ack确认 */
            case RtmpPacket::TYPE_ACKNOWLEDGEMENT:
                break;
                /** ack大小 */
            case RtmpPacket::TYPE_WINDOW_ACKNOWLEDGEMENT_SIZE:
                list(, $this->ackSize) = unpack("N", $p->payload);
                logger()->debug('set ack Size ' . $this->ackSize);
                break;
                /** 设置宽带 */
            case RtmpPacket::TYPE_SET_PEER_BANDWIDTH:
                break;
        }

        //logger()->info("rtmpControlHandler use:" . ((microtime(true) - $b) * 1000) . 'ms');
    }
}