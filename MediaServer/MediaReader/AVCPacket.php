<?php
namespace MediaServer\MediaReader;

use MediaServer\Utils\BinaryStream;

/**
 * @purpose 视频帧数据包
 * @author yanglong
 */
class AVCPacket
{

    /** 视频序列帧 包含sps,pps等解码信息，没有这个帧，播放器就无法解码黑屏 */
    const AVC_PACKET_TYPE_SEQUENCE_HEADER = 0;
    /** 实际音视频 NALU 数据 */
    const AVC_PACKET_TYPE_NALU = 1;
    /** 流结束标记，编码器推流断开前可选发送 ，一般推流工具直接断开tcp连接，不会发送这个包 */
    const AVC_PACKET_TYPE_END_SEQUENCE = 2;



    /** avc视频帧类型 */
    public $avcPacketType;
    /** 构建包的时间 */
    public $compositionTime;
    /** 数据流 */
    public $stream;

    /**
     * 视频数据包初始化
     * AVCPacket constructor.
     * @param $stream BinaryStream
     */
    public function __construct($stream)
    {
        $this->stream=$stream;
        /** 视频数据包编码格式 */
        $this->avcPacketType=$stream->readTinyInt();
        /** 获取包创建时间 */
        $this->compositionTime=$stream->readInt24();
    }


    /**
     * @var AVCSequenceParameterSet 视频序列帧
     */
    protected $avcSequenceParameterSet;

    /**
     * 获取视频序列帧的参数
     * @return AVCSequenceParameterSet
     */
    public function getAVCSequenceParameterSet(){

        if(!$this->avcSequenceParameterSet){
            $this->avcSequenceParameterSet=new AVCSequenceParameterSet($this->stream->readRaw());
        }
        return $this->avcSequenceParameterSet;
    }
}