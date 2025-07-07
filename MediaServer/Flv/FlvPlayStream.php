<?php

namespace MediaServer\Flv;

use Evenement\EventEmitter;
use MediaServer\HLS\FLVToHLSConverter4;
use MediaServer\HLS\FLVToHLSConverter5;
use MediaServer\HLS\FLVToHLSConverter2;
use MediaServer\HLS\FLVToHLSConverter3;
use MediaServer\HLS\FLVToHLSConverter6;
use MediaServer\HLS\FLVToHLSConverter7;
use MediaServer\HLS\FLVToHLSConverter8;
use MediaServer\MediaReader\AudioFrame;
use MediaServer\MediaReader\MediaFrame;
use MediaServer\MediaReader\MetaDataFrame;
use MediaServer\MediaReader\VideoFrame;
use MediaServer\MediaServer;
use MediaServer\PushServer\PlayStreamInterface;
use MediaServer\Utils\WMChunkStreamInterface;
use MediaServer\Utils\WMHttpChunkStream;
use function chr;
use function ord;

/**
 * @purpose flv播放资源
 */
class FlvPlayStream extends EventEmitter implements PlayStreamInterface
{
    protected $playPath = '';
    /**
     * @var WMHttpChunkStream
     */
    protected $input;

    protected $isPlayerIdling = true;
    protected $isPlaying = false;

    protected $isFlvHeader = false;

    protected $closed = false;

    private $hlsConverter;

    /**
     * FlvPlayStream constructor.
     * @param $input WMChunkStreamInterface
     * @param $playPath
     */
    public function __construct($input, $playPath)
    {
        $this->input = $input;
        /** 给当前链接绑定 error事件 */
        $input->on('error', [$this, 'onStreamError']);
        /** 绑定close事件 */
        $input->on('close', [$this, 'close']);
        /** 绑定播放路径 */
        $this->playPath = $playPath;
        // 创建HLS转换器
        $this->hlsConverter = new FLVToHLSConverter6($playPath, [
            'segmentDuration' => 4,  // 4秒切片
            'maxSegments' => 100      // 保留最新的5个切片
        ]);
    }

    public function __destruct()
    {
        logger()->info("player flv stream {path} destruct", ['path' => $this->playPath]);
    }

    /**
     * 触发错误回调
     * @param \Exception $e
     * @internal
     */
    public function onStreamError(\Exception $e)
    {
        logger()->info($e->getMessage());
        $this->close();
    }

    /**
     * 关闭链接并移除所有监听事件
     * @return void
     */
    public function close()
    {
        var_dump("关闭flv");
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->input->close();
        $this->emit('on_close');
        $this->removeAllListeners();
        $this->hlsConverter->close();
    }

    /**
     * 播放器是否空闲
     * @return bool|mixed
     */
    public function isPlayerIdling()
    {
        return $this->isPlayerIdling;
    }

    /**
     * 发送数据
     * @param $data
     * @return null
     */
    public function write($data)
    {
        return $this->input->write($data);
    }

    /**
     * 开启声音
     * @return true
     */
    public function isEnableAudio()
    {
        return true;
    }

    /**
     * 开启视频
     * @return true
     */
    public function isEnableVideo()
    {
        return true;
    }

    /**
     * 是否重要关键帧
     * @return true
     */
    public function isEnableGop()
    {
        return true;
    }

    public function setEnableAudio($status)
    {
    }

    public function setEnableVideo($status)
    {
    }

    public function setEnableGop($status)
    {
    }

    /**
     * 开始播放
     * @return void
     */
    public function startPlay()
    {
        //各种发送数据包
        $path = $this->getPlayPath();
        /** 获取推流的资源 */
        $publishStream = MediaServer::getPublishStream($path);
        logger()->info('flv play stream start play');
        /** 还没有发送flv协议头 */
        if (!$this->isFlvHeader) {
            /** 组装flv头部 */
            $flvHeader = "FLV\x01\x00" . pack('NN', 9, 0);
            /** 组装音频参数编码 */
            if ($this->isEnableAudio() && $publishStream->hasAudio()) {
                $flvHeader[4] = chr(ord($flvHeader[4]) | 4);
            }
            /** 视频参数编码 */
            if ($this->isEnableVideo() && $publishStream->hasVideo()) {
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
            // 将元数据传递给HLS转换器
            try {
                $this->hlsConverter->processFrame($metaDataFrame);
            }catch (\Exception $e){
                logger()->info($e->getMessage());
            }

        }

        /**
         * 发送视频avc数据
         * avc sequence send
         */
        if ($publishStream->isAVCSequence()) {
            $avcFrame = $publishStream->getAVCSequenceFrame();
            $this->sendVideoFrame($avcFrame);
            // 将序列头传递给HLS转换器

            try {
                $this->hlsConverter->processFrame($avcFrame);
            }catch (\Exception $e){
                logger()->info($e->getMessage());
            }
        }

        /**
         * 发送音频aac数据
         * aac sequence send
         */
        if ($publishStream->isAACSequence()) {
            $aacFrame = $publishStream->getAACSequenceFrame();
            $this->sendAudioFrame($aacFrame);
            // 将音频配置传递给HLS转换器

            try {
                $this->hlsConverter->processFrame($aacFrame);
            }catch (\Exception $e){
                logger()->info($e->getMessage());
            }
        }

        //gop 发送
        /**
         * 发送关键帧
         */
        if ($this->isEnableGop()) {
            foreach ($publishStream->getGopCacheQueue() as &$frame) {
                $this->frameSend($frame);
                try {
                    $this->hlsConverter->processFrame($frame);
                }catch (\Exception $e){
                    logger()->info($e->getMessage());
                }
            }
        }
        /** 更新播放器状态为非空闲 */
        $this->isPlayerIdling = false;
        /** 更新为正在播放 */
        $this->isPlaying = true;
    }

    /**
     * 发送数据到客户端
     * @param $frame MediaFrame
     * @return mixed
     * @comment 发送音频，视频，元数据
     */
    public function frameSend2($frame)
    {
        // 转换为HLS
        $this->hlsConverter->processFrame($frame);
        //   logger()->info("send ".get_class($frame)." timestamp:".($frame->timestamp??0));
        switch ($frame->FRAME_TYPE) {
            case MediaFrame::VIDEO_FRAME:
                return $this->sendVideoFrame($frame);
            case MediaFrame::AUDIO_FRAME:
                return $this->sendAudioFrame($frame);
            case MediaFrame::META_FRAME:
                return $this->sendMetaDataFrame($frame);
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
        try {
            // 转换为HLS
            $this->hlsConverter->processFrame($frame);
        } catch (\Exception $e) {
            // 记录HLS转换错误，但不中断向客户端发送数据
            logger()->error("HLS转换错误: " . $e->getMessage());
        }

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


    /**
     * 关闭拉流
     * @return void
     */
    public function playClose()
    {
        $this->input->close();
    }

    /**
     * 获取播放资源地址
     * @return mixed|string
     */
    public function getPlayPath()
    {
        return $this->playPath;
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
        $this->write($chunks);
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
        $this->write($chunks);
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
        $this->write($chunks);
    }

    /**
     * 获取HLS播放地址
     * @return string
     */
    public function getHlsUrl()
    {
        return $this->hlsConverter->getHlsUrl();
    }
}