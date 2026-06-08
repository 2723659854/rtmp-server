<?php

namespace MediaServer\MP4;

use MediaServer\Flv\Flv;
use MediaServer\Flv\FlvTag;
use MediaServer\MediaReader\AudioFrame;
use MediaServer\MediaReader\MediaFrame;
use MediaServer\MediaReader\MetaDataFrame;
use MediaServer\MediaReader\VideoFrame;
use MediaServer\MediaServer;
use Xiaosongshu\Flv2mp4\Manage\LiveFlvToMp4;

/**
 * mp4转码器
 * @purpose 将rtmp数据转码为mp4
 * @author yanglong
 * @time 2026年5月30日 18点30分30秒
 */
class Mp4Converter
{

    /** 播放路径 */
    public $playPath ;

    /** 混合切片转码器 */
    public $transcoder;

    /** 分离切片转码器（音视频分开） */
    public $transcoderSeparate;

    /**
     * 初始化转码器
     * @param string $playPath
     */
    public function __construct(string $playPath)
    {
        $this->playPath = $playPath;
        
        // 创建基础目录
        $baseDir = app_path('/mp4/' . trim($playPath, "/")) ;
        $mergeDir = $baseDir . '/output_merge';      // 混合切片目录
        $separateDir = $baseDir . '/output_separate'; // 音视频分开切片目录
        
        if (!is_dir($baseDir)) {
            mkdir($baseDir, 0777, true);
        }
        if (!is_dir($mergeDir)) {
            mkdir($mergeDir, 0777, true);
        }
        if (!is_dir($separateDir)) {
            mkdir($separateDir, 0777, true);
        }

        // 1. 音视频混合切片转码器
        $this->transcoder = new LiveFlvToMp4([
            'isLive' => true,
            'streamPath' => $playPath,
            'segmentDir' => $mergeDir,
            'maxSegmentSize' => 10 * 1024 * 1024,
            'minMediaBufferSize' => 64 * 1024,
            'minSegmentInterval' => 1000,
            'generateMetaJson' => true,
            'mixedBufferSize'=>20,
        ]);

        $this->transcoder->onInitSegment = function($data) {
            logger()->info("混合 init mp4 success：" . strlen($data) . " bytes");
        };

        $this->transcoder->onMediaSegment = function($data) {
//            static $index = 0;
//            $index++;
//            logger()->info("混合媒体分片 #{$index}: " . strlen($data) . " bytes");
        };

        $this->transcoder->onMediaInfo = function($mediaInfo, $tracks) {
            if (!empty($mediaInfo)) {
                logger()->info("媒体信息: 分辨率 {$mediaInfo->width}x{$mediaInfo->height}, 帧率 {$mediaInfo->fps}");
            }
        };

        // 2. 音视频分离切片转码器
        $this->transcoderSeparate = new LiveFlvToMp4([
            'isLive' => true,
            'streamPath' => $playPath,
            'segmentDir' => $separateDir,
            'maxSegmentSize' => 10 * 1024 * 1024,
            'generateMetaJson' => true,
            'separateTracks' => true,
            'audioBufferSize'=>30,
            'videoBufferSize'=>30,
        ]);

        $this->transcoderSeparate->onAudioInitSegment = function($data, $meta) {
            // audio_init.mp4 由 LiveFlvToMp4 自动保存
            logger()->info("分离 audio init mp4 success：" . strlen($data) . " bytes");
        };

        $this->transcoderSeparate->onVideoInitSegment = function($data, $meta) {
            // video_init.mp4 由 LiveFlvToMp4 自动保存
            logger()->info("分离 video init mp4 success：" . strlen($data) . " bytes");
        };

        $this->transcoderSeparate->onAudioSegment = function($data, $value) {
            // audio_*.m4s 由 LiveFlvToMp4 自动保存
        };

        $this->transcoderSeparate->onVideoSegment = function($mediaInfo, $tracks) {
            // video_*.m4s 由 LiveFlvToMp4 自动保存
        };
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

        logger()->info('flv start to transfer to mp4, path: ' . $path);
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
        // 1. 处理混合切片转码器
        if ($this->transcoder) {
            $this->transcoder->processFlvData($data, $timestamp);
        }

        // 2. 处理分离切片转码器（音视频分开）
        if ($this->transcoderSeparate) {
            $this->transcoderSeparate->processFlvData($data, $timestamp);
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
        logger()->info('stop flv to mp4');
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        // 2. 处理混合切片转码器 - 合并成完整MP4
        if ($this->transcoder){
            // 当关闭播放器时，立即合成完整的 MP4 文件
            $mergedFile = $this->transcoder->finalize(null, false);
            if ($mergedFile) {
                logger()->info("Mp4Converter: Successfully merged MP4 file: {$mergedFile}");
            } else {
                logger()->info("Mp4Converter: Failed to merge MP4 file for {$this->playPath}");
            }
            $this->transcoder->cleanup();
            $this->transcoder = null;
        }

        // 3. 处理分离切片转码器（音视频分开）- 不需要合并，meta.json 已在初始化时生成
        if ($this->transcoderSeparate){
            $this->transcoderSeparate->cleanup();
            $this->transcoderSeparate = null;
        }

    }
}