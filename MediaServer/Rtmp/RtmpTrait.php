<?php


namespace MediaServer\Rtmp;

use \Exception;


/**
 * @purpose rtmp业务处理
 * @author yanglong
 */
trait RtmpTrait
{

    use RtmpControlHandlerTrait,/** 协议控制信息处理流程 */
        RtmpEventHandlerTrait,/** rtmp事件 */
        RtmpAudioHandlerTrait,/** 音频处理 */
        RtmpVideoHandlerTrait,/** 视频处理 */
        RtmpInvokeHandlerTrait,/** 调用处理 */
        RtmpDataHandlerTrait,/** 数据处理 */
        RtmpAuthorizeTrait;/** 权限处理 */

    /**
     * 消息处理
     * @param RtmpPacket $p
     * @return int|mixed|void
     * @throws Exception
     * @note 需要知道流程
     */
    public function rtmpHandler(RtmpPacket $p)
    {
        //根据 msg type 进入处理流程
        // logger()->info("[packet] {$p->type}");
        //$b = memory_get_usage();
        /** 判断包类型 */
        switch ($p->type) {
            /** 分包大小 */
            case RtmpPacket::TYPE_SET_CHUNK_SIZE:
                /** 终止 */
            case RtmpPacket::TYPE_ABORT:
                /** ack */
            case RtmpPacket::TYPE_ACKNOWLEDGEMENT:
                /** ack大小 */
            case RtmpPacket::TYPE_WINDOW_ACKNOWLEDGEMENT_SIZE:
                /** 设置宽带信息 */
            case RtmpPacket::TYPE_SET_PEER_BANDWIDTH:
                //上面的类型全部进入协议控制信息处理流程
                0 === $this->rtmpControlHandler() ? -1 : 0;
                break;
            case RtmpPacket::TYPE_EVENT:
                /** 查看代码发现，没有任何处理呢 */
                //event 信息进入event 处理流程，不处理 event 信息
                0 === $this->rtmpEventHandler() ? -1 : 0;
                break;
            case RtmpPacket::TYPE_AUDIO:
                //audio 信息进入 audio 处理流程
                /** 这里调用了MediaServer 分发数据 */
                $this->rtmpAudioHandler();
                break;
            case RtmpPacket::TYPE_VIDEO:
                //video 信息进入 video 处理流程
                /** 调用mediaServer分发数据 */
                $this->rtmpVideoHandler();
                break;
                /** 发送的操作命令，就是客户端发送的操作命令给服务端 */
            case RtmpPacket::TYPE_FLEX_MESSAGE:
            case RtmpPacket::TYPE_INVOKE:
                //上面信息进入invoke  引援？处理流程
                /** 解析命令  并执行相关操作  */
                $this->rtmpInvokeHandler();
                break;
                /** RTMP 流中，帧类型 18 表示 AMF0 类型，15 表示 AMF3 类型。 */
            case RtmpPacket::TYPE_FLEX_STREAM: // AMF3
            case RtmpPacket::TYPE_DATA: // AMF0
                //其他rtmp信息处理
                /** 就是幀率設置 */
                $this->rtmpDataHandler();
                break;
        }
        //logger()->info("[memory] memory add:" . (memory_get_usage() - $b));
    }


    /**
     * 关闭资源
     * @return void
     */
    public function stop()
    {

        /** 如果已开启 */
        if ($this->isStarting) {
            $this->isStarting = false;
            /** 删除资源 */
            if ($this->playStreamId > 0) {
                $this->onDeleteStream(['streamId' => $this->playStreamId]);
            }
            /** 删除资源 */
            if ($this->publishStreamId > 0) {
                $this->onDeleteStream(['streamId' => $this->publishStreamId]);
            }
        }
        /** 触发关闭事件  */
        $this->emit('on_close');

        logger()->info("[rtmp disconnect] id={$this->id}");


    }


}
