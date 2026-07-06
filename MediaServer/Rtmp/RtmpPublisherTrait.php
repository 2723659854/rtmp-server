<?php


namespace MediaServer\Rtmp;


/**
 * @purpose 直播流发布
 * @author yanglong
 */
trait RtmpPublisherTrait
{
    /**
     * 获取当前推流路径
     * @return string
     */
    public function getPublishPath()
    {
        return $this->publishStreamPath;
    }

    /** 是否aac音频序列 */
    public function isAACSequence()
    {
        return $this->isAACSequence;
    }

    /** 获取aac音频序列帧 */
    public function getAACSequenceFrame()
    {
        return $this->aacSequenceHeaderFrame;
    }

    /** 是否avc视频序列 */
    public function isAVCSequence()
    {
        return $this->isAVCSequence;
    }

    /** 获取avc视频帧 */
    public function getAVCSequenceFrame()
    {
        return $this->avcSequenceHeaderFrame;
    }

    /** 是否meta元数据*/
    public function isMetaData()
    {
        return $this->isMetaData;
    }

    /** 获取元数据 */
    public function getMetaDataFrame()
    {
        return $this->metaDataFrame;
    }

    /** 判断有音频吗 */
    public function hasAudio(){
        return $this->isAACSequence();
    }

    /** 判断是否有视频 */
    public function hasVideo(){
        return $this->isAVCSequence();
    }

    /** 获取连续帧 */
    public function getGopCacheQueue(){
        return $this->gopCacheQueue;
    }

    /** 获取推流信息 */
    public function getPublishStreamInfo()
    {
        return [
            "id"=>$this->id,
            /** 已读 */
            "bytesRead"=>$this->bytesRead,
            /** 比特率 每一秒采样率 */
            "bytesReadRate"=>$this->bytesReadRate,
            /** 开始时间 */
            "startTimestamp"=>$this->startTimestamp,
            /** 当前时间 */
            "currentTimestamp"=>timestamp(),
            /** 推流地址 */
            "publishStreamPath"=>$this->publishStreamPath,
            /** 视频宽度 */
            "videoWidth"=>$this->videoWidth,
            /** 视频高度 */
            "videoHeight"=>$this->videoHeight,
            /** 帧率，每一秒帧数 */
            "videoFps"=> $this->videoFps,
            /** 视频编码名称 */
            "videoCodecName"=>$this->videoCodecName,
            /** 视频资源名称 */
            "videoProfileName"=>$this->videoProfileName,
            /** 视屏等级 */
            "videoLevel"=>$this->videoLevel,
            /** 音频采样率 */
            "audioSamplerate"=>$this->audioSamplerate,
            /** 音频声道信息 */
            "audioChannels"=>$this->audioChannels,
            /** 音频编码信息 */
            "audioCodecName"=>$this->audioCodecName,
        ];
    }

}
