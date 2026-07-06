<?php


namespace MediaServer\Flv;


/**
 * @purpose flv 数据包头
 * @author yanglong
 */
class FlvHeader
{
    /** 签名 */
    public $signature;
    /** 版本号 */
    public $version;
    /** 类型 */
    public $typeFlags;
    /** 偏移指针 */
    public $dataOffset;
    /** 是否包含音频 */
    public $hasAudio;
    /** 是否包含视频 */
    public $hasVideo;

    /**
     * 数据包解码
     * @param $data
     */
    public function __construct($data)
    {

        $data = unpack("a3signature/Cversion/CtypeFlags/NdataOffset", $data);
        $this->signature = $data['signature'];
        $this->version = $data['version'];
        $this->typeFlags = $data['typeFlags'];
        $this->dataOffset = $data['dataOffset'];
        $this->hasAudio = $this->typeFlags & 4 ? true : false;
        $this->hasVideo = $this->typeFlags & 1 ? true : false;
    }
}
