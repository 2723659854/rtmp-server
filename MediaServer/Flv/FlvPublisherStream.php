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
 * @purpose 推流资源
 * @author yanglong
 * @note flv推流设备
 */
class FlvPublisherStream extends EventEmitter implements PublishStreamInterface
{
    /** 初始状态：正在处理 FLV 文件头 */
    const FLV_STATE_FLV_HEADER = 0;
    /** 正在处理一个 FLV Tag 的固定头部（PreviousTagSize + Tag Header） */
    const FLV_STATE_TAG_HEADER = 1;
    /** 正在读取当前 Tag 的实际音视频数据 */
    const FLV_STATE_TAG_DATA = 2;

    /** 当前播放器ID */
    public $id;

    /**
     * @var EventEmitter|ReadableStreamInterface 链接
     */
    private $input;

    /** 推流是否已关闭 */
    private $closed = false;


    /**
     * @var BinaryStream 暂存区数据
     */
    protected $buffer;

    /** flv头 */
    public $flvHeader;

    /** 是否收到flv头 */
    public $hasFlvHeader = false;

    /** 是否包含音频 */
    public $hasAudio = false;
    /** 是否包含视频 */
    public $hasVideo = false;

    /** 音频解码器 */
    public $audioCodec = 0;

    /** 音频解码器名称 */
    public $audioCodecName = '';

    /** 音频采样率 */
    public $audioSamplerate = 0;

    /** 音频声道 默认单声道 */
    public $audioChannels = 1;

    /** 是否收到aac音频序列帧 */
    public $isAACSequence = false;

    /**
     * @var AudioFrame 音频序列帧
     */
    public $aacSequenceHeaderFrame;
    public $audioProfileName = '';


    /** 是否收到meta帧 */
    public $isMetaData = false;
    /**
     * @var MetaDataFrame meta帧
     */
    public $metaDataFrame;

    /** 是否收到avc视频序列帧 */
    public $isAVCSequence = false;
    /**
     * @var VideoFrame avc视频序列帧
     */
    public $avcSequenceHeaderFrame;

    /** 视频宽度 */
    public $videoWidth = 0;

    /** 视频高度 */
    public $videoHeight = 0;

    /** fps 每一秒的视频帧数 */
    public $videoFps = 0;

    /** 视频帧合计 */
    public $videoCount = 0;

    /** 视频帧计数器 */
    public $videoFpsCountTimer;

    /** 视频编码等级名称 */
    public $videoProfileName = '';

    /** 编码等级 */
    public $videoLevel = 0;

    /** 编码器编号 */
    public $videoCodec = 0;

    /** 编码器名称 */
    public $videoCodecName = '';

    /** 开播时间戳 */
    public $startTimestamp;


    /**
     * @var string 节目地址
     */
    public $publishPath;

    /** 是否复制流，此参数仅用于多进程复制流 */
    public $isCopy = false;

    /**
     * @var MediaFrame[] gop关键帧
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
     * @var FlvTag 当前帧
     */
    protected $currentTag;

    /** 推流初始状态：尚未收到完整flv头 */
    protected $steamStatus = self::FLV_STATE_FLV_HEADER;


    /**
     * 数据处理
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
     * @note 往复循环处理每一帧
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
                    //进入等待下一帧的header流程
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
                    /** 统计视频帧fps */
                    $this->videoCount++;
                    if (($_cost  = (timestamp() - $this->startTimestamp) )>= 5000) {
                        $this->videoFps = ceil($this->videoCount/($_cost/1000));
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
                        //var_dump("接收到avc序列帧");
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
                    }else{
                        //var_dump("接收到avc帧");
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
//                    if ($aacPack->aacPacketType === AACPacket::AAC_PACKET_TYPE_SEQUENCE_HEADER) {
                    if ($aacPack->aacPacketType === AACPacket::AAC_PACKET_TYPE_SEQUENCE_HEADER && !$this->isAACSequence) {

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
