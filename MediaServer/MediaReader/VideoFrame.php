<?php


namespace MediaServer\MediaReader;


use MediaServer\Utils\BinaryStream;

/**
 * @purpose 视频帧包结构
 * @author yanglong
 */
class VideoFrame extends BinaryStream implements MediaFrame
{
    public $FRAME_TYPE=self::VIDEO_FRAME;

    /** 视频帧编码器名称 */
    const VIDEO_CODEC_NAME = [
        '',
        'Jpeg',
        'Sorenson-H263',
        'ScreenVideo',
        'On2-VP6',
        'On2-VP6-Alpha',
        'ScreenVideo2',
        'H264',
        '',
        '',
        '',
        '',
        'H265'
    ];

    /** rtmp视频帧类型 */

    /** I帧，独立帧，缓存了sps,pps解码信息，完整独立画面，不需要依赖前后帧就能解码出完整图像 */
    const VIDEO_FRAME_TYPE_KEY_FRAME = 1;
    /** P帧，依靠前一阵解码 只存画面变化部分，依赖前面的关键帧 / P 帧才能解码，就是说只记录了画面发生了变化的部分 */
    const VIDEO_FRAME_TYPE_INTER_FRAME = 2;
    /** B帧，可丢弃差值帧 ，双向压缩，依赖前一帧和后一帧解码 ，丢弃不影响播放，只会出现一点花屏 */
    const VIDEO_FRAME_TYPE_DISPOSABLE_INTER_FRAME = 3;
    /** 生成关键帧 一般是流媒体服务器二次生成的虚拟关键帧，或者截图、转码中间生成的 I 帧副本 */
    const VIDEO_FRAME_TYPE_GENERATED_KEY_FRAME = 4;
    /** 视频信息帧 比如画面分辨率、帧率、编码参数补充信息 几乎所有主流推流软件（OBS）都不会发送该类型，日常直播链路基本遇不到*/
    const VIDEO_FRAME_TYPE_VIDEO_INFO_FRAME = 5;

    /** flv编码格式 */

    /** JPEG 静态图片帧，早期 FLV 支持图片序列，直播几乎绝迹，现在完全不用 */
    const VIDEO_CODEC_ID_JPEG = 1;
    /** Sorenson H.263，Flash 早期默认视频编码 ，已淘汰 */
    const VIDEO_CODEC_ID_H263 = 2;
    /** Screen Video 1，Macromedia 自家屏幕录制编码，仅用于 Flash 录屏，不做直播。 */
    const VIDEO_CODEC_ID_SCREEN = 3;
    /** On2 VP6，当年 Flash 主推编码，画质比 H263 好，有版权收费；H.264 出来后全面淘汰。 */
    const VIDEO_CODEC_ID_VP6_FLV = 4;
    /** 带 Alpha 透明通道的 VP6，用于 Flash 透明动画，直播无关。 */
    const VIDEO_CODEC_ID_VP6_FLV_ALPHA = 5;
    /** 第二代屏幕录制编码，仅 Flash 录屏场景。 */
    const VIDEO_CODEC_ID_SCREEN_V2 = 6;
    /** AVC = H.264，现在直播唯一主流编码 */
    const VIDEO_CODEC_ID_AVC = 7;

    /** 帧类型 */
    public $frameType;
    /** 编码器ID */
    public $codecId;
    /** 帧时间戳 */
    public $timestamp = 0;

    public function __toString()
    {
        return $this->dump();
    }


    /** 获取视频编码名称 */
    public function getVideoCodecName()
    {
        return self::VIDEO_CODEC_NAME[$this->codecId];
    }


    /** 初始化视频编码格式 */
    public function __construct($data, $timestamp = 0)
    {
        parent::__construct($data);
        /** 记录时间戳 */
        $this->timestamp = $timestamp;
        /** 解码帧类型和编码器 */
        $firstByte = $this->readTinyInt();
        $this->frameType = $firstByte >> 4;
        $this->codecId = $firstByte & 15;
    }


    /**
     * @var AVCPacket 视频帧
     */
    protected $avcPacket;

    /**
     * 获取视频帧数据
     * @return AVCPacket
     */
    public function getAVCPacket()
    {
        if (!$this->avcPacket) {
            $this->avcPacket = new AVCPacket($this);
        }

        return $this->avcPacket;
    }

    public function destroy(){
        $this->avcPacket=null;
    }

}