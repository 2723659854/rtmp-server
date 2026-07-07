<?php


namespace MediaServer\Flv;


/**
 * @purpose flv 基本数据包
 * @author yanglong
 * @comment 里面包含了音频数据或者视频数据或者脚本命令
 */
class FlvTag
{
    public $type;
    public $dataSize;
    public $timestamp;
    public $streamId = 0;
    public $data;

}
