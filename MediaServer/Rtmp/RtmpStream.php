<?php


namespace MediaServer\Rtmp;


use Evenement\EventEmitter;
use MediaServer\MediaReader\AudioFrame;
use MediaServer\MediaReader\MediaFrame;
use MediaServer\MediaReader\MetaDataFrame;
use MediaServer\MediaReader\VideoFrame;
use MediaServer\PushServer\DuplexMediaStreamInterface;
use MediaServer\PushServer\VerifyAuthStreamInterface;
use MediaServer\Utils\WMBufferStream;



/**
 * @purpose rtmp直播流
 * @author yanglong
 * DuplexMediaStreamInterface 推流和播放的接口
 * VerifyAuthStreamInterface 鉴权接口
 *
 */
class RtmpStream extends EventEmitter implements DuplexMediaStreamInterface, VerifyAuthStreamInterface
{

    use RtmpHandshakeTrait,/** 握手 */
        RtmpChunkHandlerTrait,/** 分包 */
        RtmpPacketTrait,/** 打包 */
        RtmpTrait,/** rtmp工具 */
        RtmpPublisherTrait,/** 推流 */
        RtmpPlayerTrait;/** 播放 */

    /**
     * 握手状态
     * @var int handshake state
     */
    public int $handshakeState;

    /** 直播流ID */
    public $id;

    /** ip地址 */
    public $ip;

    /** 端口 */
    public $port;

    /** 分包头部长度 */
    protected int $chunkHeaderLen = 0;
    /** 分片状态 */
    protected int $chunkState;

    /**
     * 所有的包
     * @var RtmpPacket[]
     */
    protected $allPackets = [];

    /**
     * @var int 接收数据时的  chunk size
     */
    protected    $inChunkSize = 128;
    /**
     * @var int 发送数据时的 chunk size
     */
    protected $outChunkSize = 60000;


    /**
     * 当前的包
     * @var RtmpPacket
     */
    protected $currentPacket;
    /** 启动时间戳 */
    public $startTimestamp;
    /** 编码 */
    public $objectEncoding;

    /** 流编号 */
    public $streams = 0;
    /** 播放资源ID */
    public $playStreamId = 0;
    /** 播放路径 */
    public $playStreamPath = '';
    /** 播放参数 */
    public $playArgs = [];
    /** 是否已开始 */
    public $isStarting = false;
    /** 连接命令 */
    public $connectCmdObj = null;
    /** app名称 */
    public $appName = '';
    /** 是否收到音频数据 */
    public $isReceiveAudio = true;
    /** 是否收到视频数据 */
    public $isReceiveVideo = true;


    /**
     * 心跳定时器
     * @var int
     */
    public $pingTimer;

    /**
     * 心跳间隔
     * @var int ping interval
     */
    public $pingTime = 60;
    /** 比特率缓存 在 RTMP 协议中，音频头的前 4 个字节包含了一些信息，其中可能包括 bitrateCache。
     * 这些信息用于描述音频数据的特征和参数，以便在传输和播放过程中进行正确的处理 ，比特率缓存可以帮助优化流媒体的性能，减少卡顿和缓冲时间。
     */
    public $bitrateCache;


    /** 推流路径 */
    public $publishStreamPath;
    /** 推流参数 */
    public $publishArgs;
    /** 推流资源id */
    public $publishStreamId;


    /**
     * @var int 发送ack的长度
     */
    protected $ackSize = 0;

    /**
     * @var int 当前size统计
     */
    protected $inAckSize = 0;
    /**
     * @var int 上次ack的size
     */
    protected $inLastAck = 0;

    /** 是否媒体帧 */
    public $isMetaData = false;

    /**
     * @var MetaDataFrame 媒体帧数据
     */
    public $metaDataFrame;


    /** 视频宽度 */
    public $videoWidth = 0;
    /** 视频高度 */
    public $videoHeight = 0;
    /** fps，就是每一秒中画面张数 ，比如fps=15表示每一秒15张画面 */
    public $videoFps = 0;
    /** 视频帧计数器 */
    public $videoCount = 0;
    /** fps计算定时器 ，不建议手动计算，因为浪费时间，可能导致卡顿 */
    public $videoFpsCountTimer;
    /** 视频的profile ，一般有Baseline（老旧手机，摄像头等硬件） / Main（baseline和high的过度产物，加入B帧） / High（加入大量B帧，高压缩率） */
    public $videoProfileName = '';
    /** 视频等级 */
    public $videoLevel = 0;
    /** 视频编码器 */
    public $videoCodec = 0;
    /** 视频编码器名称 */
    public $videoCodecName = '';
    /** 是否收到视频序列帧 */
    public $isAVCSequence = false;
    /**
     * @var VideoFrame 视频解码序列帧，视频解码的关键帧，没有此帧则会黑屏
     */
    public $avcSequenceHeaderFrame;

    /** 音频编码器 */
    public $audioCodec = 0;
    /** 音频解码器名称 */
    public $audioCodecName = '';
    /** 音频采样率 */
    public $audioSamplerate = 0;
    /** 音频声道 */
    public $audioChannels = 1;
    /** 是否收到音频序列帧 */
    public $isAACSequence = false;
    /**
     * @var AudioFrame 音频解码序列帧，没有此帧，则没有声音，声音无法解码
     */
    public $aacSequenceHeaderFrame;
    /** 音频的profile */
    public $audioProfileName = '';
    /** 是否在推流 */
    public $isPublishing = false;
    /** 是否在播放 */
    public $isPlaying = false;
    /** 是否开启gop缓存 */
    public $enableGop = true;

    /**
     * @var MediaFrame[] gop关键帧，用于视频解码用，有利于播放器快速解码完整画面
     */
    public $gopCacheQueue = [];


    /**
     * @var WMBufferStream 缓存
     */
    protected $buffer;

    /**
     * 初始化流媒体
     * PlayerStream constructor.
     * @param $bufferStream WMBufferStream 媒体资源 是tcp协议也是事件
     */
    public function __construct(WMBufferStream $bufferStream)
    {
        //先随机生成个id
        $this->id = generateNewSessionID();
        /** 先标记为握手还未初始化 */
        $this->handshakeState = RtmpHandshake::RTMP_HANDSHAKE_UNINIT;
        /** 推流端ip */
        $this->ip = $bufferStream->connection->getRemoteIp()??"127.0.0.1";
        /** 推流端端口 */
        $this->port = $bufferStream->connection->getRemotePort()??1935;
        /** 开启了啊 */
        $this->isStarting = true;
        /** 存媒体数据 */
        $this->buffer = $bufferStream;
        /** 绑定接收到数据的事件  */
        $bufferStream->on('onData',[$this,'onStreamData']);
        /** 绑定错误事件 */
        $bufferStream->on('onError',[$this,'onStreamError']);
        /** 绑定关闭事件 */
        $bufferStream->on('onClose',[$this,'onStreamClose']);
    }

    /** 定时器 */
    public $dataCountTimer;
    /** 已传递的帧数 */
    public $frameCount = 0;
    /** 传输帧数的时间 */
    public $frameTimeCount = 0;
    /** 已读字节数 */
    public $bytesRead = 0;
    /** 比特率 = 已读数据/耗时 */
    public $bytesReadRate = 0;

    /**
     * 接收到数据
     * @return void
     * @comment 这个方法因为一直在接收数据，所以一直在被不停的调用
     */
    public function onStreamData()
    {
        //若干秒后没有收到数据断开
        $b = microtime(true);

        /** 如果握手没有完成 ，则执行握手 */
        if ($this->handshakeState < RtmpHandshake::RTMP_HANDSHAKE_C2) {
            /** 处理握手 */
            $this->onHandShake();
        }

        /** 如果已经握手成功 */
        /** 这里是处理客户端发送的命令，然后发送数据的 */
        if ($this->handshakeState === RtmpHandshake::RTMP_HANDSHAKE_C2) {
            /** 数据分片 */
            $this->onChunkData();
            /** 计算当前已读数据长度  */
            $this->inAckSize += strlen($this->buffer->recvSize());
            /** 如果长度大于15 */
            if ($this->inAckSize >= 0xf0000000) {
                $this->inAckSize = 0;
                $this->inLastAck = 0;
            }
            /** 长度大于ack */
            if ($this->ackSize > 0 && $this->inAckSize - $this->inLastAck >= $this->ackSize) {
                //每次收到的数据超过ack设的值，上一次ack位置变更为本次的结尾位置
                $this->inLastAck = $this->inAckSize;
                /** 发送ack */
                $this->sendACK($this->inAckSize);
            }
        }
        /** 累加帧计时 */
        $this->frameTimeCount += microtime(true) - $b;
        /** 累加收到的帧数  */
        $this->frameCount++;


        //logger()->info("[rtmp on data] per sec handler times: ".(1/($end?:1)));
    }


    /** 如果资源关闭 则关闭这个连接 */
    public function onStreamClose()
    {
        $this->stop();
    }


    /** 发生了错误，关闭连接 */
    public function onStreamError()
    {
        $this->stop();
    }

    /** 发送数据 最终是通过tcp发送的 */
    public function write($data)
    {
        if ($this->buffer->connection){
            return $this->buffer->connection->send($data,true);
        }
    }

/*    public function __destruct()
    {
        logger()->info("[RtmpStream __destruct] id={$this->id}");
    }*/

    /**
     * 获取客户端ip
     * @return string
     */
    public function getClientIp(): string
    {
        return $this->ip ?? '';
    }

    /**
     * 获取app
     * @return string
     */
    public function getAppName(): string
    {
        return $this->appName ?? '';
    }

    /**
     * 是否在推流中
     * @return bool
     */
    public function isPublishing(): bool
    {
        return $this->isPublishing ?? false;
    }

    /**
     * 是否在拉流
     * @return bool
     */
    public function isPlaying(): bool
    {
        return $this->isPlaying ?? false;
    }

    /**
     * 获取推流参数
     * @return array
     */
    public function getPublishArgs(): array
    {
        return $this->publishArgs ?? [];
    }

    /**
     * 获取拉流参数
     * @return array
     */
    public function getPlayArgs(): array
    {
        return $this->playArgs ?? [];
    }

    /**
     * 获取推流路径
     * @return string
     */
    public function getPublishStreamPath(): string
    {
        return $this->publishStreamPath ?? '';
    }

    /**
     * 获取拉流路径
     * @return string
     */
    public function getPlayStreamPath(): string
    {
        return $this->playStreamPath ?? '';
    }

}
