<?php


namespace MediaServer\Rtmp;


use MediaServer\MediaReader\AudioFrame;
use MediaServer\MediaReader\MediaFrame;
use MediaServer\MediaReader\MetaDataFrame;
use MediaServer\MediaReader\VideoFrame;
use MediaServer\MediaServer;

/**
 * @purpose 播放器
 * @author yanglong
 */
trait RtmpPlayerTrait
{
    public $isPlayerIdling = true;

    /**
     * 客户端是出于活动状态还是空闲状态，播放的时候为活动状态
     * @return bool
     */
    public function isPlayerIdling()
    {
        return $this->isPlayerIdling;
    }

    public function isEnableAudio()
    {
        return true;
    }

    public function isEnableVideo()
    {
        return true;
    }

    public function isEnableGop()
    {
        return true;
    }

    public function setEnableAudio($status)
    {
    }

    public function setEnableVideo($status)
    {
    }

    public function setEnableGop($status)
    {
    }



    /**
     * 播放开始
     * @return mixed
     * @comment 发送开始播放命令，通知客户端元数据meta，视频参数，音频参数
     */
    public function startPlay()
    {

        //各种发送数据包
        /** 获取播放路径 */
        $path = $this->getPlayPath();
        /** 通过路径获取流媒体资源 */
        $publishStream = MediaServer::getPublishStream($path);
        /**
         * 发送流媒体的meta数据给客户端
         * meta data send
         */
        if ($publishStream->isMetaData()) {
            /** 获取流媒体资源的meta数据 */
            $metaDataFrame = $publishStream->getMetaDataFrame();
            /** 发送给客户端 */
            $this->sendMetaDataFrame($metaDataFrame);
        }

        /**
         * 发送avc序列 就是高级视频编码
         * avc sequence send
         */
        if ($publishStream->isAVCSequence()) {
            /** 获取avc视频编码信息 */
            $avcFrame = $publishStream->getAVCSequenceFrame();
            /** 发送视频编码信息 */
            $this->sendVideoFrame($avcFrame);
        }


        /**
         * 发送aac音频编码数据
         * aac sequence send
         */
        if ($publishStream->isAACSequence()) {
            /** 获取音频编码数据 */
            $aacFrame = $publishStream->getAACSequenceFrame();
            /** 发送音频编码数据 */
            $this->sendAudioFrame($aacFrame);
        }

        //gop 发送
        /** 连续帧图像 发送 */
        if ($this->enableGop) {
            /** 获取连续帧 */
            foreach ($publishStream->getGopCacheQueue() as &$frame) {
                /** 发送 */
                $this->frameSend($frame);
            }
        }
        /** 标记当前帧为未播放 */
        $this->isPlayerIdling = false;
        /** 更新播放状态为正在播放 */
        $this->isPlaying = true;
    }

    /**
     * 发送帧数据
     * @param $frame MediaFrame
     * @return mixed
     */
    public function frameSend($frame)
    {
        switch ($frame->FRAME_TYPE) {
            /**  视频 */
            case MediaFrame::VIDEO_FRAME:
                /** 发送视频帧 */
                return $this->sendVideoFrame($frame);
                /** 发送音频帧 */
            case MediaFrame::AUDIO_FRAME:
                return $this->sendAudioFrame($frame);
                /** 发送meta数据帧 */
            case MediaFrame::META_FRAME:
                return $this->sendMetaDataFrame($frame);
        }
    }

    /**
     * 发送meta数据帧
     * @param $metaDataFrame MetaDataFrame|MediaFrame
     * @return mixed
     */
    public function sendMetaDataFrame($metaDataFrame)
    {
        /** 将数据打包 */
        $packet = new RtmpPacket();
        $packet->chunkType = RtmpChunk::CHUNK_TYPE_0;
        $packet->chunkStreamId = RtmpChunk::CHANNEL_DATA;
        $packet->type = RtmpPacket::TYPE_DATA;
        $packet->payload = (string)$metaDataFrame;
        $packet->length = strlen($packet->payload);
        $packet->streamId = $this->playStreamId;
        /** 将数据切片 */
        $chunks = $this->rtmpChunksCreate($packet);
        /** 发送给客户端 */
        $this->write($chunks);
    }

    /**
     * 发送音频帧
     * @param $audioFrame AudioFrame|MediaFrame
     * @return mixed
     */
    public function sendAudioFrame($audioFrame)
    {
        /** 将音频数据打包 */
        $packet = new RtmpPacket();
        /** 发一个大包数据 */
        $packet->chunkType = RtmpChunk::CHUNK_TYPE_0;
        /** 本分片的资源id 音频  */
        $packet->chunkStreamId = RtmpChunk::CHANNEL_AUDIO;
        /** 包的类型是：音频 */
        $packet->type = RtmpPacket::TYPE_AUDIO;
        /** 数据 */
        $packet->payload = (string)$audioFrame;
        /** 时间 */
        $packet->timestamp = $audioFrame->timestamp;
        /** 数据长度 */
        $packet->length = strlen($packet->payload);
        /** 资源id */
        $packet->streamId = $this->playStreamId;
        /** 把这个包切边分隔 */
        $chunks = $this->rtmpChunksCreate($packet);
        /** 发给客户端 */
        $this->write($chunks);
    }

    /**
     * 画面帧发送
     * @param $videoFrame VideoFrame|MediaFrame
     * @return mixed
     */
    public function sendVideoFrame($videoFrame)
    {
        /** 将数据打包 */
        $packet = new RtmpPacket();
        /** 弄一个大包 装数据 */
        $packet->chunkType = RtmpChunk::CHUNK_TYPE_0;
        /** 这一帧是视频 */
        $packet->chunkStreamId = RtmpChunk::CHANNEL_VIDEO;
        /** 类型视频 */
        $packet->type = RtmpPacket::TYPE_VIDEO;
        /** 添加数据 */
        $packet->payload = (string)$videoFrame;
        /** 长度 */
        $packet->length = strlen($packet->payload);
        /**  资源id */
        $packet->streamId = $this->playStreamId;
        /** 时间戳 */
        $packet->timestamp = $videoFrame->timestamp;
        /** 分片 */
        $chunks = $this->rtmpChunksCreate($packet);
        /** 发送给客户端 */
        $this->write($chunks);
    }

    /**
     * 关闭播放
     * @return mixed
     */
    public function playClose()
    {
        $this->stop();
        //$this->input->close();
    }

    /**
     * 获取当前路径
     * @return string
     */
    public function getPlayPath()
    {
        return $this->playStreamPath;
    }
}
