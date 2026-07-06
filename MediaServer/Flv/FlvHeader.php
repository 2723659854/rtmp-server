<?php


namespace MediaServer\Flv;


/**
 * @purpose flv 数据包头
 * @author yanglong
 */
class FlvHeader
{
    public $signature;
    public $version;
    public $typeFlags;
    public $dataOffset;
    public $hasAudio;
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
