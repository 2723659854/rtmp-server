<?php


namespace MediaServer\PushServer;


use Evenement\EventEmitterInterface;
use MediaServer\MediaReader\AudioFrame;
use MediaServer\MediaReader\MediaFrame;
use MediaServer\MediaReader\MetaDataFrame;
use MediaServer\MediaReader\VideoFrame;

/**
 * @purpose 推流接口
 * @property $is_on_frame 是否在处理媒体帧
 * @author yangong
 */
interface PublishStreamInterface extends EventEmitterInterface
{
    /**
     * 获取当前推流路径
     * @return string
     */
    public function getPublishPath();

    /**
     * 是否有meta帧
     * @return bool
     */
    public function isMetaData();

    /**
     * 获取meta帧（脚本帧）
     * @return MetaDataFrame
     */
    public function getMetaDataFrame();

    /**
     * 是否有aac序列帧
     * @return bool
     */
    public function isAACSequence();

    /**
     * 获取aac序列帧
     * @return AudioFrame
     */
    public function getAACSequenceFrame();

    /**
     * 是否有avc序列帧
     * @return bool
     */
    public function isAVCSequence();

    /**
     * 获取avc序列帧
     * @return VideoFrame
     */
    public function getAVCSequenceFrame();

    /**
     * 是否包含音频
     * @return bool
     */
    public function hasAudio();

    /**
     * 是否包含视频
     * @return mixed
     */
    public function hasVideo();

    /**
     * 获取gop
     * @return MediaFrame[]
     */
    public function getGopCacheQueue();


    /**
     * 获取推流信息
     * @return mixed
     */
    public function getPublishStreamInfo();

}
