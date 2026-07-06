<?php

namespace MediaServer\MediaReader;


use MediaServer\Utils\BitReader;

/**
 * @purpose 音频参数设置
 * @author yanglong
 */
class AACSequenceParameterSet extends BitReader
{
    /**
    AAC 主体编码档次，决定基础解码特性：
    1：AAC Main
    2：AAC LC（LC-AAC，直播 99% 在用，OBS / 手机默认） 后面转码hls统一使用这个格式，否则很难处理
    3：AAC SSR
    4：AAC LTP
    5：SBR（HE-AAC v1）
    29：PS（HE-AAC v2）
     * 这个是后面转码hls的核心，必须小心处理，否则错一个bit就全部错了，天坑呢
     */
    public $objType;
    /** 采样率索引 */
    public $sampleIndex;
    /** 采样率 */
    public $sampleRate;
    /** 声道数 */
    public $channels;
    /** SBR 是高频压缩增强技术，相同音质码率减半，播放器必须启用 HE-AAC 解码器，播放器使用LC-AAC解码无法播放 */
    public $sbr;
    /** 立体声压缩，极低码率下把立体声压缩成单声道 + 参数还原双声道，多用于语音、低清短视频 */
    public $ps;
    /** 当流包含 SBR/PS 扩展层时，存储扩展 AAC 类型 普通 LC-AAC 固定为 0，HE-AAC 会存入 5/29，用来区分基础层和增强层编码。*/
    public $extObjectType;

    /**
     * 读取数据
     * @param $data
     */
    public function __construct($data)
    {
        parent::__construct($data);
        $this->readData();
    }

    /**
     * 获取音频资源文件
     * @return string
     */
    public function getAACProfileName()
    {
        switch ($this->objType) {
            case 1:
                return 'Main';
            case 2:
                if ($this->ps > 0) {
                    return 'HEv2';
                }
                if ($this->sbr > 0) {
                    return 'HE';
                }
                return 'LC';
            case 3:
                return 'SSR';
            case 4:
                return 'LTP';
            case 5:
                return 'SBR';
            default:
                return '';
        }
    }

    /**
     * aac序列帧解码
     * @return void
     */
    public function readData()
    {
        /** aac编码格式 */
        $objectType = ($objectType = $this->getBits(5)) === 31 ? ($this->getBits(6) + 32) : $objectType;
        $this->objType = $objectType;
        /** 采样率 */
        $sampleRate = ($sampleIndex = $this->getBits(4)) === 0x0f ? $this->getBits(24) : AACPacket::AAC_SAMPLE_RATE[$sampleIndex];
        $this->sampleIndex = $sampleIndex;
        $this->sampleRate = $sampleRate;
        /** 声道数 */
        $channelConfig = $this->getBits(4);
        if ($channelConfig < count(AACPacket::AAC_CHANNELS)) {
            $channels = AACPacket::AAC_CHANNELS[$channelConfig];
            $this->channels = $channels;
        }

        /** 高压缩率，双声道压缩 */
        $this->sbr = -1;
        $this->ps = -1;
        if ($objectType == 5 || $objectType == 29) {
            if ($objectType == 29) {
                $this->ps = 1;
            }
            $this->extObjectType = 5;
            $this->sbr = 1;
            $this->sampleRate = ($sampleIndex = $this->getBits(4)) === 0x0f ? $this->getBits(24) : AACPacket::AAC_SAMPLE_RATE[$sampleIndex];
            $this->sampleIndex = $sampleIndex;
            $this->objType = ($objectType = $this->getBits(5)) === 31 ? ($this->getBits(6) + 32) : $objectType;
        }


    }


}