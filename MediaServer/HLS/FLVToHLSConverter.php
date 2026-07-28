<?php

namespace MediaServer\HLS;

use MediaServer\MediaReader\MediaFrame;
use Xiaosongshu\Flv2mp4\Manage\Flv2Hls;

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
        // 清理并规范化路径
        $cleanPath = trim($path, '/');

        // 生成输出目录
        $outputDir = $config['outputDir'] ?? app_path("/hls/{$cleanPath}/");

        // 确保输出目录存在
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        // 清理输出目录（可选，根据需要）
        $this->clearOutputDirectory($outputDir);

        // 合并配置
        $this->hlsConverter = new Flv2Hls(
            $path,
            array_merge($config, ['outputDir' => $outputDir])
        );
    }

    /**
     * 清空输出目录
     */
    private function clearOutputDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = glob($dir . '*');
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            } elseif (is_dir($file)) {
                // 递归删除子目录（谨慎使用）
                $this->removeDirectory($file);
            }
        }
    }

    /**
     * 递归删除目录
     */
    private function removeDirectory(string $dir): void
    {
        $files = glob($dir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            } elseif (is_dir($file)) {
                $this->removeDirectory($file);
            }
        }
        @rmdir($dir);
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