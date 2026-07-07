<?php


namespace MediaServer\PushServer;


use Evenement\EventEmitterInterface;
use MediaServer\MediaReader\AudioFrame;
use MediaServer\MediaReader\MediaFrame;
use MediaServer\MediaReader\MetaDataFrame;
use MediaServer\MediaReader\VideoFrame;

/**
 * @purpose 播放接口
 * @author yanglong
 */
interface PlayStreamInterface extends EventEmitterInterface
{

    /**
     * 播放器是否处于空闲状态
     * @return bool
     */
    public function isPlayerIdling();

    /**
     * 播放开始
     * @return mixed
     */
    public function startPlay();

    /**
     * 发送媒体帧
     * @param $frame MediaFrame
     * @return mixed
     */
    public function frameSend($frame);

    /**
     * 关闭播放器
     * @return mixed
     */
    public function playClose();

    /**
     * 获取当前路径
     * @return string
     */
    public function getPlayPath();

    /**
     * 是否启用音频
     * @return bool
     */
    public function isEnableAudio();

    /**
     * 是否启用视频
     * @return bool
     */
    public function isEnableVideo();


    /**
     * 是否启用gop，关闭能降低延迟
     * @return bool
     */
    public function isEnableGop();


    /**
     * 音频开关
     * @param $status bool
     * @return mixed
     */
    public function setEnableAudio($status);

    /**
     * 视频开关
     * @param $status bool
     * @return mixed
     */
    public function setEnableVideo($status);

    /**
     * gop开关
     * @param $status bool
     * @return mixed
     */
    public function setEnableGop($status);
}
