<?php

namespace MediaServer\MP4;

/**
 * @purpose flv文件录制
 * @author yanglong
 */
class FlvRecorder
{
    protected $flvFileHandle = null;
    protected $flvFilePath = '';

    /**
     * 初始化
     * @param string $path
     */
    public function __construct(string $path)
    {
        $dirname = dirname(__DIR__, 2) . "/flv".$path;
        if (!is_dir($dirname)) {
            mkdir($dirname, 0777, true);
        }
        $this->flvFilePath = $dirname . "/".date('YmdHis').uniqid() . ".flv";
        $this->flvFileHandle = fopen($this->flvFilePath, 'wb');
        logger()->info('flv recorder init success:{path} ',['path' => $path]);
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * 写入数据
     * @param $flv
     * @return void
     */
    public function write($flv)
    {
        if ($this->flvFileHandle) {
            fwrite($this->flvFileHandle, (string)$flv);
        }
    }

    /**
     * 关闭
     * @return void
     */
    public function close()
    {
        if ($this->flvFileHandle) {
            fclose($this->flvFileHandle);
            $this->flvFileHandle = null;
            logger()->info('flv recorder closed success :{path} ',['path' => $this->flvFilePath]);
        }
    }
}