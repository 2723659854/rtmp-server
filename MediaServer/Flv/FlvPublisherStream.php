<?php


namespace MediaServer\Flv;


use Evenement\EventEmitter;
use Exception;
use MediaServer\MediaReader\AACPacket;
use MediaServer\MediaReader\AudioFrame;
use MediaServer\MediaReader\AVCPacket;
use MediaServer\MediaReader\MediaFrame;
use MediaServer\MediaReader\MetaDataFrame;
use MediaServer\MediaReader\VideoFrame;
use MediaServer\PushServer\PublishStreamInterface;
use MediaServer\Utils\BinaryStream;
use React\Stream\ReadableStreamInterface;

/**
 * @purpose 推流数据流
 */
class FlvPublisherStream extends EventEmitter implements PublishStreamInterface
{
    const FLV_STATE_FLV_HEADER = 0;
    const FLV_STATE_TAG_HEADER = 1;
    const FLV_STATE_TAG_DATA = 2;

    public $id;

    /**
     * @var EventEmitter|ReadableStreamInterface
     */
    private $input;
    private $closed = false;


    /**
     * @var BinaryStream
     */
    protected $buffer;


    public $flvHeader;
    public $hasFlvHeader = false;

    public $hasAudio = false;
    public $hasVideo = false;

    public $audioCodec = 0;
    public $audioCodecName = '';
    public $audioSamplerate = 0;
    public $audioChannels = 1;
    public $isAACSequence = false;

    /**
     * @var AudioFrame
     */
    public $aacSequenceHeaderFrame;
    public $audioProfileName = '';


    public $isMetaData = false;
    /**
     * @var MetaDataFrame
     */
    public $metaDataFrame;


    public $isAVCSequence = false;
    /**
     * @var VideoFrame
     */
    public $avcSequenceHeaderFrame;
    public $videoWidth = 0;
    public $videoHeight = 0;
    public $videoFps = 0;
    public $videoCount = 0;
    public $videoFpsCountTimer;

    public $videoProfileName = '';
    public $videoLevel = 0;

    public $videoCodec = 0;
    public $videoCodecName = '';


    public $startTimestamp;


    /**
     * @var string
     */
    public $publishPath;

    /**
     * @var MediaFrame[]
     */
    public $gopCacheQueue = [];

    public function __destruct()
    {
        logger()->info("publisher flv stream {path} destruct", ['path' => $this->publishPath]);
    }

    /**
     * 初始化
     * FlvStream constructor.
     * @param $input EventEmitter|ReadableStreamInterface
     * @param $path  string
     */
    public function __construct($input, $path)
    {
        //先随机生成个id
        $this->id = generateNewSessionID();
        $this->input = $input;
        /** 保存流媒体路径 */
        $this->publishPath = $path;
        $this->startTimestamp = timestamp();
        /** 绑定数据事件 */
        $input->on('data', [$this, 'onStreamData']);
        /** 绑定error事件 */
        $input->on('error', [$this, 'onStreamError']);
        /** 绑定close事件 */
        $input->on('close', [$this, 'onStreamClose']);
        $this->buffer = new BinaryStream();
    }


    /**
     * @var FlvTag
     */
    protected $currentTag;


    protected $steamStatus = self::FLV_STATE_FLV_HEADER;


    /**
     * @param $data
     * @throws Exception
     * @internal
     */
    public function onStreamData($data)
    {
        //若干秒后没有收到数据断开
        /** 将接收的数据追加到缓存区 */
        $this->buffer->push($data);
        /** 处理数据 */
        switch ($this->steamStatus) {
            case self::FLV_STATE_FLV_HEADER:
                /** 这里是比较关键的，这里实现了rtmp数据的转码 */
                if ($this->buffer->has(9)) {
                    /** 处理头部信息 */
                    $this->flvHeader = new FlvHeader($this->buffer->readRaw(9));
                    $this->hasFlvHeader = true;
                    $this->hasAudio = $this->flvHeader->hasAudio;
                    $this->hasVideo = $this->flvHeader->hasVideo;
                    /** 清空缓存区 */
                    $this->buffer->clear();
                    logger()->info("publisher {path} recv flv header.", ['path' => $this->publishPath]);
                    /** 触发事件on_publish_ready */
                    $this->emit("on_publish_ready");
                    $this->steamStatus = self::FLV_STATE_TAG_HEADER;
                } else {
                    break;
                }
            default:
                //进入tag flv 处理流程
                $this->flvTagHandler();
                break;
        }

    }

    /**
     * 处理flv数据帧
     * @throws Exception
     */
    public function flvTagHandler()
    {
        //若干秒后没有收到数据断开
        switch ($this->steamStatus) {
            case self::FLV_STATE_TAG_HEADER:
                /** 解析header帧 */
                if ($this->buffer->has(15)) {
                    //除去pre tag size 4byte
                    $this->buffer->skip(4);
                    $tag = new FlvTag();
                    $tag->type = $this->buffer->readTinyInt();
                    $tag->dataSize = $this->buffer->readInt24();
                    $tag->timestamp = $this->buffer->readInt24() | $this->buffer->readTinyInt() << 24;
                    $tag->streamId = $this->buffer->readInt24();
                    $this->currentTag = $tag;
                    //进入等待 Data
                    $this->steamStatus = self::FLV_STATE_TAG_DATA;
                } else {
                    break;
                }
                /** 解析数据 */
            case self::FLV_STATE_TAG_DATA:
                $curTag = $this->currentTag;
                if ($this->buffer->has($curTag->dataSize)) {
                    $curTag->data = $this->buffer->readRaw($curTag->dataSize);
                    /** 处理数据帧 */
                    //处理tag
                    $this->onTagEvent();
                    /** 清空缓冲区 */
                    $this->buffer->clear();
                    //进入等待header流程
                    $this->steamStatus = self::FLV_STATE_TAG_HEADER;
                } else {
                    break;
                }
            default:
                //跑一下看看剩余的数据够不够
                $this->flvTagHandler();
                break;
        }
    }


    /**
     * 处理帧数据
     * @throws Exception
     */
    public function onTagEvent()
    {
        $tag = $this->currentTag;
        switch ($tag->type) {
            /** 脚本数据 */
            case Flv::SCRIPT_TAG:
                /** 解析脚本命令 */
                $metaData = Flv::scriptFrameDataRead($tag->data);
                logger()->info("publisher {path} metaData: " . json_encode($metaData));
                /** 宽 */
                $this->videoWidth = $metaData['dataObj']['width'] ?? 0;
                /** 高 */
                $this->videoHeight = $metaData['dataObj']['height'] ?? 0;
                /** 比特率 */
                $this->videoFps = $metaData['dataObj']['framerate'] ?? 0;
                /** 音频采样率 每一秒钟采样和记录音频数据 */
                $this->audioSamplerate = $metaData['dataObj']['audiosamplerate'] ?? 0;
                /** 声道为立体声 */
                $this->audioChannels = $metaData['dataObj']['stereo'] ?? 1;
                /** 元数据帧 */
                $this->metaDataFrame = new MetaDataFrame($tag->data);
                $this->isMetaData = true;
                /** 触发on_frame事件  获取到帧数据 */
                $this->emit('on_frame', [$this->metaDataFrame, $this]);
                break;
            case Flv::VIDEO_TAG:
                //视频数据
                /** 解码视频帧 */
                $videoFrame = new VideoFrame($tag->data, $tag->timestamp);
                if ($this->videoCodec == 0) {
                    $this->videoCodec = $videoFrame->codecId;
                    /** 视频编码名称 */
                    $this->videoCodecName = $videoFrame->getVideoCodecName();
                }
                /** 如果帧率=0 */
                if ($this->videoFps === 0) {
                    //当前帧为第0
                    if ($this->videoCount++ === 0) {
                        /** 计算帧率 */
                    }
                }
                /** h264解码 */
                if ($videoFrame->codecId === VideoFrame::VIDEO_CODEC_ID_AVC) {
                    //h264
                    $avcPack = $videoFrame->getAVCPacket();

                    //read avc
                    /** 元数据 描述信息 */
                    if ($avcPack->avcPacketType === AVCPacket::AVC_PACKET_TYPE_SEQUENCE_HEADER) {
                        $this->isAVCSequence = true;
                        $this->avcSequenceHeaderFrame = $videoFrame;
                        $specificConfig = $avcPack->getAVCSequenceParameterSet();
                        $this->videoWidth = $specificConfig->width;
                        $this->videoHeight = $specificConfig->height;
                        $this->videoProfileName = $specificConfig->getAVCProfileName();
                        $this->videoLevel = $specificConfig->level;
                        logger()->info("publisher {path} recv avc sequence.", ['path' => $this->publishPath]);
                    }

                    if ($this->isAVCSequence) {
                        /** 清空关键帧 */
                        if ($videoFrame->frameType === VideoFrame::VIDEO_FRAME_TYPE_KEY_FRAME
                            &&
                            $avcPack->avcPacketType === AVCPacket::AVC_PACKET_TYPE_NALU) {
                            $this->gopCacheQueue = [];
                        }
                        /** 保存视频帧 */
                        if ($videoFrame->frameType === VideoFrame::VIDEO_FRAME_TYPE_KEY_FRAME
                            &&
                            $avcPack->avcPacketType === AVCPacket::AVC_PACKET_TYPE_SEQUENCE_HEADER) {
                            //skip avc sequence
                        } else {
                            $this->gopCacheQueue[] = $videoFrame;
                        }
                    }
                }

                //数据处理与数据发送
                $this->emit('on_frame', [$videoFrame, $this]);
                //销毁AVC
                $videoFrame->destroy();
                break;
            case Flv::AUDIO_TAG:
                //音频数据
                $audioFrame = new AudioFrame($tag->data, $tag->timestamp);
//                logger()->info("AUDIO DEBUG: soundFormat={sf}, dataLen={len}, hex={hex}", [
//                    'sf' => $audioFrame->soundFormat,
//                    'len' => strlen($tag->data),
//                    'hex' => bin2hex(substr($tag->data, 0, min(10, strlen($tag->data))))
//                ]);
                if ($this->audioCodec === 0) {
                    $this->audioCodec = $audioFrame->soundFormat;
                    /** 编码格式 */
                    $this->audioCodecName = $audioFrame->getAudioCodecName();
                    /** 采样率 */
                    $this->audioSamplerate = $audioFrame->getAudioSamplerate();
                    /** 声道 */
                    $this->audioChannels = ++$audioFrame->soundType;
                }
                /** 解码AAC音频数据 */
                if ($audioFrame->soundFormat === AudioFrame::SOUND_FORMAT_AAC) {
                    $aacPack = $audioFrame->getAACPacket();
                    if ($aacPack->aacPacketType === AACPacket::AAC_PACKET_TYPE_SEQUENCE_HEADER) {
                        $this->isAACSequence = true;
                        $this->aacSequenceHeaderFrame = $audioFrame;
                        $set = $aacPack->getAACSequenceParameterSet();
                        $this->audioProfileName = $set->getAACProfileName();
                        $this->audioSamplerate = $set->sampleRate;
                        $this->audioChannels = $set->channels;
                        logger()->info("publisher {path} recv acc sequence.", ['path' => $this->publishPath]);
                    }

                    if ($this->isAACSequence) {
                        if ($aacPack->aacPacketType == AACPacket::AAC_PACKET_TYPE_SEQUENCE_HEADER) {

                        } else {
                            //音频关键帧缓存
                            $this->gopCacheQueue[] = $audioFrame;
                        }
                    }


                }
                /** 触发meda sever上的on_frame事件 */
                $this->emit('on_frame', [$audioFrame, $this]);
                //logger()->info("rtmpAudioHandler");
                $audioFrame->destroy();
                break;
        }
    }


    /**
     * @param Exception $e
     * @internal
     */
    public function onStreamError(\Exception $e)
    {
        $this->emit('on_error', [$e]);
        $this->onStreamClose();
    }

    public function onStreamClose()
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        $this->buffer = null;
        $this->gopCacheQueue = [];
        $this->input->close();
        $this->emit('on_close');
        $this->removeAllListeners();
    }

    public function getPublishPath()
    {
        return $this->publishPath;
    }

    public function isMetaData()
    {
        return $this->isMetaData;
    }


    public function getMetaDataFrame()
    {
        return $this->metaDataFrame;
    }

    public function isAACSequence()
    {
        return $this->isAACSequence;
    }

    public function getAACSequenceFrame()
    {
        return $this->aacSequenceHeaderFrame;
    }

    public function isAVCSequence()
    {
        return $this->isAVCSequence;
    }

    public function getAVCSequenceFrame()
    {
        return $this->avcSequenceHeaderFrame;
    }

    public function hasAudio()
    {
        return $this->hasAudio;
    }

    public function hasVideo()
    {
        return $this->hasVideo;
    }

    public function getGopCacheQueue()
    {
        return $this->gopCacheQueue;
    }

    public function getPublishStreamInfo()
    {
        return [
            "id"=>$this->id,
            "startTimestamp"=>$this->startTimestamp,
            "publishStreamPath" => $this->publishPath,
            "videoWidth" => $this->videoWidth,
            "videoHeight" => $this->videoHeight,
            "videoFps" => $this->videoFps,
            "videoCodecName" => $this->videoCodecName,
            "videoProfileName" => $this->videoProfileName,
            "videoLevel" => $this->videoLevel,
            "audioSamplerate" => $this->audioSamplerate,
            "audioChannels" => $this->audioChannels,
            "audioCodecName" => $this->audioCodecName,
        ];
    }
}
