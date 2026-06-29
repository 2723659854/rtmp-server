<?php

namespace MediaServer\Flv;

use MediaServer\MediaReader\MediaFrame;
use MediaServer\MediaServer;

/**
 * @purpose flv推流工具
 * @author yanglong
 */
class FlvPusher
{
    public $playPath;

    protected $pushUrls = [];

    protected $pushers = [];

    public $isFlvHeader = false;

    public $closed = false;

    public function __construct(string $playPath, $pushUrl)
    {
        $this->playPath = $playPath;
        
        if (is_array($pushUrl)) {
            $this->pushUrls = $pushUrl;
        } else {
            $this->pushUrls = [$pushUrl];
        }

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

        if ($publishStream->isMetaData()) {
            $metaDataFrame = $publishStream->getMetaDataFrame();
            $this->sendMetaDataFrame($metaDataFrame);
        }

        if ($publishStream->isAVCSequence()) {
            $avcFrame = $publishStream->getAVCSequenceFrame();
            $this->sendVideoFrame($avcFrame);
        }

        if ($publishStream->isAACSequence()) {
            $aacFrame = $publishStream->getAACSequenceFrame();
            $this->sendAudioFrame($aacFrame);
        }

        foreach ($publishStream->getGopCacheQueue() as &$frame) {
            $this->frameSend($frame);
        }
    }

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

    public function write($data)
    {
        if ($this->closed) {
            return;
        }

        foreach ($this->pushers as $url => $pusher) {
            try {
                $pusher->write($data);
            } catch (\Exception $e) {
                logger()->error('flv pusher write error for ' . $url . ': ' . $e->getMessage());
            }
        }
    }

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

    public function __destruct()
    {
        $this->close();
    }
}


