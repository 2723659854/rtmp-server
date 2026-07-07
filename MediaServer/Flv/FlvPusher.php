<?php

namespace MediaServer\Flv;

use MediaServer\MediaReader\MediaFrame;
use MediaServer\MediaServer;
use Xiaosongshu\Flv2mp4\Flv\FlvSinglePusher;

/**
 * @purpose flv转播适配器
 * @author yanglong
 * @note 使用Xiaosongshu\Flv2mp4\Flv\FlvSinglePusher客户端，推送数据后，立即刷新暂存区，解决数据损毁的情况
 */
class FlvPusher
{
    /** 节目路径 */
    public $playPath;

    /** 推流目标地址组 */
    protected $pushUrls = [];

    /** 转播客户端组 */
    protected $pushers = [];

    /** 是否已发送flv头 */
    public $isFlvHeader = false;

    /** 是否已关闭转播 */
    public $closed = false;

    /**
     * 初始化转播器
     * @param string $playPath
     * @param $pushUrl
     */
    public function __construct(string $playPath, $pushUrl)
    {
        /** 节目地址 */
        $this->playPath = $playPath;
        
        if (is_array($pushUrl)) {
            $this->pushUrls = $pushUrl;
        } else {
            $this->pushUrls = [$pushUrl];
        }

        /** 注册转播客户端 */
        foreach ($this->pushUrls as $url) {
            $pusher = new FlvSinglePusher($playPath, $url);
            $this->pushers[$url] = $pusher;
            
            // 立即尝试连接
            if (!$pusher->connect()) {
                logger()->warning('flv pusher connect failed on init for: ' . $url);
            }
        }

        logger()->info('flv pusher init success: {path} -> {count} targets', ['path' => $playPath, 'count' => count($this->pushUrls)]);
    }

    /**
     * 开始推流
     * @param string $path
     * @return void
     */
    public function startPlay(string $path)
    {
        $publishStream = MediaServer::getPublishStream($path);

        logger()->info('flv pusher start play, path: ' . $path);

        // 检查连接状态，如果断开则重连
        foreach ($this->pushers as $url => $pusher) {
            if ($pusher->isClosed()) {
                logger()->info('flv pusher reconnecting for: ' . $url);
                if (!$pusher->connect()) {
                    logger()->error('flv pusher reconnect failed for: ' . $url);
                }
            }
        }

        /** 发送flv头 */
        if (!$this->isFlvHeader) {
            $typeFlags = 0;
            if ($publishStream->hasAudio()) {
                $typeFlags |= 0x04;
            }
            if ($publishStream->hasVideo()) {
                $typeFlags |= 0x01;
            }
            $flvHeader = "FLV\x01" . chr($typeFlags) . pack('N', 9);
            $this->write($flvHeader);
            $this->write(pack('N', 0));  // PreviousTagSize 0
            $this->isFlvHeader = true;
        }

        /** 发送meta帧 */
        if ($publishStream->isMetaData()) {
            $metaDataFrame = $publishStream->getMetaDataFrame();
            $this->sendMetaDataFrame($metaDataFrame);
        }

        /** 发送avc序列帧 */
        if ($publishStream->isAVCSequence()) {
            $avcFrame = $publishStream->getAVCSequenceFrame();
            $this->sendVideoFrame($avcFrame);
        }

        /** 发送aac序列帧 */
        if ($publishStream->isAACSequence()) {
            $aacFrame = $publishStream->getAACSequenceFrame();
            $this->sendAudioFrame($aacFrame);
        }

        /** 发送gop */
        foreach ($publishStream->getGopCacheQueue() as $frame) {
            $this->frameSend($frame);
        }
    }

    /**
     * 发送meta帧
     * @param $metaDataFrame
     * @return mixed
     */
    public function sendMetaDataFrame($metaDataFrame)
    {
        $tag = new FlvTag();
        $tag->type = Flv::SCRIPT_TAG;
        $tag->timestamp = 0;
        $tag->data = (string)$metaDataFrame;
        $tag->dataSize = strlen($tag->data);
        $chunks = Flv::createFlvTag($tag);
        $this->write($chunks);
    }

    /**
     * 发送音频帧
     * @param $audioFrame
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
     * @param $videoFrame
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
     * 转发数据
     * @param $data
     * @return void
     */
    public function write($data)
    {
        if ($this->closed) {
            return;
        }

        foreach ($this->pushers as $url => $pusher) {
            try {
                $pusher->write($data);
                /** 这一行代码价值10w，很关键的，缺少这一步复制流进程的播放器拉流后无法解码 */
                $pusher->flush();
            } catch (\Exception $e) {
                logger()->error('flv pusher write error for ' . $url . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * 转播器入口
     * @param $frame
     * @return void|null
     */
    public function frameSend($frame)
    {
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
     * 清空暂存区
     * @return void
     */
    public function flush()
    {
        if ($this->closed) {
            return;
        }

        foreach ($this->pushers as $url => $pusher) {
            try {
                $pusher->flush();
            } catch (\Exception $e) {
                logger()->error('flv pusher flush error for ' . $url . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * 关闭转播器
     * @return void
     */
    public function close()
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        foreach ($this->pushers as $url => $pusher) {
            try {
                $pusher->close();
            } catch (\Exception $e) {}
        }

        logger()->info('flv pusher closed: {path}', ['path' => $this->playPath]);
    }

    /**
     * 销毁转播器
     */
    public function __destruct()
    {
        $this->close();
    }
}


