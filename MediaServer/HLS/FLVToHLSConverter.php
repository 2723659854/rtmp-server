<?php

namespace MediaServer\HLS;

use MediaServer\MediaReader\MediaFrame;
use Xiaosongshu\Flv2mp4\manage\Flv2Hls;

/**
 * rtmp转码hls
 * @author yanglong
 * @time 2026年6月3日16:14:49
 * @note 本转换器使用rtmp数据包转flv包，然后调用flv2hls切片。
 */
class FLVToHLSConverter
{

    /** hls协议转换器  */
    protected $hlsConverter;

    /**
     * 初始化构造方法
     * @param string $path
     * @param array $config
     */
    public function __construct(string $path, array $config = [])
    {
        $this->hlsConverter = new Flv2Hls($path, array_merge($config, ['outputDir'=>app_path( "/hls/".trim($path, "/")."/")]));
    }

    /**
     * 处理rtmp数据包
     * @param MediaFrame $frame
     * @return void
     */
    public function processFrame(MediaFrame $frame): void
    {
        if ($this->hlsConverter instanceof Flv2Hls) {
            $this->hlsConverter->processFrame($frame);
        }

    }

    /**
     * 关闭协议转换器
     * @return void
     */
    public function close()
    {
        if ($this->hlsConverter instanceof Flv2Hls) {
            $this->hlsConverter->close();
        }
        $this->hlsConverter = null;
    }

    /**
     * 销毁转换器
     */
    public function __destruct()
    {
        $this->close();
    }
}