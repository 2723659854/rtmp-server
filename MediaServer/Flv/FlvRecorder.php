<?php

namespace MediaServer\Flv;

use MediaServer\MediaReader\AudioFrame;
use MediaServer\MediaReader\MediaFrame;
use MediaServer\MediaReader\MetaDataFrame;
use MediaServer\MediaReader\VideoFrame;
use MediaServer\MediaServer;

/**
 * flv录屏
 * @purpose 增加flv录屏能力
 * @author yanglong
 * @time 2026年6月4日16:55:00
 */
class FlvRecorder
{
    /** 播放路径 */
    public $playPath ;
    /** flv文件句柄 */
    protected $flvFileHandle = null;
    /** flv保存路径 */
    protected $flvFilePath = '';


    /**
     * 初始化转码器
     * @param string $playPath
     */
    public function __construct(string $playPath)
    {
        $this->playPath = $path =$playPath;
        $dirname = app_path("/flv".$path) ;
        if (!is_dir($dirname)) {
            mkdir($dirname, 0777, true);
        }
        $this->flvFilePath = $dirname . "/index.flv";
        $this->flvFileHandle = fopen($this->flvFilePath, 'wb');
        logger()->info('flv recorder init success:{path} ',['path' => $path]);
    }

    /** 是否发送了flv头 */
    public  $isFlvHeader = false;

    /**
     * 开始转换格式
     * @param string $path
     * @return void
     */
    public  function startPlay(string $path)
    {
        /** 获取推流的资源 */
        $publishStream = MediaServer::getPublishStream($path);

        logger()->info('flv start to record, path: ' . $path);
        /** 还没有发送flv协议头 */
        if (!$this->isFlvHeader) {
            /** 组装flv头部 */
            $flvHeader = "FLV\x01\x00" . pack('NN', 9, 0);
            /** 组装音频参数编码 */
            if ( $publishStream->hasAudio()) {
                $flvHeader[4] = chr(ord($flvHeader[4]) | 4);
            }
            /** 视频参数编码 */
            if ($publishStream->hasVideo()) {
                $flvHeader[4] = chr(ord($flvHeader[4]) | 1);
            }
            /** 发送flv协议头部 数据 */
            $this->write($flvHeader);
            /** 标记已发送flv头部 */
            $this->isFlvHeader = true;
        }

        /**
         * 发送meta元数据 就是基本参数
         * meta data send
         */
        if ($publishStream->isMetaData()) {
            $metaDataFrame = $publishStream->getMetaDataFrame();
            $this->sendMetaDataFrame($metaDataFrame);
        }

        /**
         * 发送视频avc数据
         * avc sequence send
         */
        if ($publishStream->isAVCSequence()) {
            $avcFrame = $publishStream->getAVCSequenceFrame();
            $this->sendVideoFrame($avcFrame);
        }

        /**
         * 发送音频aac数据
         * aac sequence send
         */
        if ($publishStream->isAACSequence()) {
            $aacFrame = $publishStream->getAACSequenceFrame();
            $this->sendAudioFrame($aacFrame);
        }

        //gop 发送
        /**
         * 发送关键帧
         */
        foreach ($publishStream->getGopCacheQueue() as &$frame) {
            $this->frameSend($frame);
        }
    }

    /**
     * 发送元数据
     * @param $metaDataFrame MetaDataFrame|MediaFrame
     * @return mixed
     */
    public function sendMetaDataFrame($metaDataFrame)
    {
        /** 组装数据 */
        $tag = new FlvTag();
        $tag->type = Flv::SCRIPT_TAG;
        $tag->timestamp = 0;
        $tag->data = (string)$metaDataFrame;
        $tag->dataSize = strlen($tag->data);
        /** 将数据打包编码 */
        $chunks = Flv::createFlvTag($tag);
        /** 发送 */
        $this->write($chunks, $tag->timestamp);
    }

    /**
     * 发送音频帧
     * @param $audioFrame AudioFrame|MediaFrame
     * @return mixed
     */
    public function sendAudioFrame($audioFrame)
    {
        $tag = new FlvTag();
        $tag->type = Flv::AUDIO_TAG;
        $tag->timestamp = $audioFrame->timestamp;
        $tag->data = (string)$audioFrame;
        $tag->dataSize = strlen($tag->data);
        $chunks = Flv::createFlvTag($tag);
        $this->write($chunks, $tag->timestamp);
    }

    /**
     * 发送视频帧
     * @param $videoFrame VideoFrame|MediaFrame
     * @return mixed
     */
    public function sendVideoFrame($videoFrame)
    {
        $tag = new FlvTag();
        $tag->type = Flv::VIDEO_TAG;
        $tag->timestamp = $videoFrame->timestamp;
        $tag->data = (string)$videoFrame;
        $tag->dataSize = strlen($tag->data);
        $chunks = Flv::createFlvTag($tag);
        $this->write($chunks, $tag->timestamp);
    }

    /**
     * 发送数据
     * @param $data
     * @param int $timestamp 时间戳（毫秒）
     * @return null
     */
    public function write($data, $timestamp = 0)
    {
        if ($this->flvFileHandle) {
            fwrite($this->flvFileHandle, (string)$data);
        }
    }

    /**
     * 发送数据到客户端
     * @param $frame MediaFrame
     * @return mixed
     * @comment 发送音频，视频，元数据
     */
    public function frameSend($frame)
    {
        // 继续向客户端发送数据
        switch ($frame->FRAME_TYPE) {
            case MediaFrame::VIDEO_FRAME:
                return $this->sendVideoFrame($frame);
            case MediaFrame::AUDIO_FRAME:
                return $this->sendAudioFrame($frame);
            case MediaFrame::META_FRAME:
                return $this->sendMetaDataFrame($frame);
        }
    }

    /** 是否关闭了转码 */
    public $closed = false;

    /**
     * 关闭链接并移除所有监听事件
     * @return void
     */
    public function close()
    {
        logger()->info('stop flv to record');
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        if ($this->flvFileHandle) {
            fclose($this->flvFileHandle);
            $this->flvFileHandle = null;
            logger()->info('flv recorder closed success :{path} ',['path' => $this->flvFilePath]);
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}