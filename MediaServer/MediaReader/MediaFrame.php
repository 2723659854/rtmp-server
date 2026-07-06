<?php


namespace MediaServer\MediaReader;


/**
 * @purpose 媒体帧接口类
 * @author yanglong
 * @property $FRAME_TYPE
 */
interface MediaFrame
{
    /** 视频帧 */
    const   VIDEO_FRAME = 1;
    /** 音频帧 */
    const   AUDIO_FRAME = 2;
    /** 媒体帧，也叫脚本命令 */
    const   META_FRAME = 0;

}