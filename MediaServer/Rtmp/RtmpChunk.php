<?php


namespace MediaServer\Rtmp;

/**
 * @purpose rtmp 数据分片
 * @author yanglong
 * @note 因为rtmp是数据流，为了适配网络环境，需要将数据分割打包传输
 */
class RtmpChunk
{

    /** 定义数据分片状态 */
    const CHUNK_STATE_BEGIN = 0; //Chunk state begin
    /** 分片准备完毕 */
    const CHUNK_STATE_HEADER_READY = 1; //Chunk state header ready
    /** 分片完成 */
    const CHUNK_STATE_CHUNK_READY = 2; //Chunk state chunk date ready

    /**
     * 资源ID分块 基本表头长度
     * chunk stream id length base header length
     */
    const BASE_HEADER_SIZES = [3, 4];

    /**
     * fmt消息头部长度
     * fmt message header size
     */
    const MSG_HEADER_SIZES = [11, 7, 3, 0];


    /** 分块类型 */
    /** 大数据 */
    const CHUNK_TYPE_0 = 0; //Large type
    /** 中数据包 */
    const CHUNK_TYPE_1 = 1; //Medium
    /** 小数据包 */
    const CHUNK_TYPE_2 = 2;    //Small
    /** 微型数据包 */
    const CHUNK_TYPE_3 = 3; //Minimal


    /**
     * 默认分包类型
     * chunk type default chunk stream id
     */

    # 协议控制消息 Set Chunk Size (1), Abort Message (2), Acknowledgement (3), Window Acknowledgement Size (5), Set Peer Bandwidth (6)
    const CHANNEL_PROTOCOL = 2;
    # 命令/调用消息 connect, createStream, publish, play, onStatus (响应) 等 NetConnection / NetStream 的 RPC 命令及其应答
    const CHANNEL_INVOKE = 3;
    # 音频数据 音频帧（AAC、Speex 等编码）
    const CHANNEL_AUDIO = 4;
    # 视频数据 视频帧（H.264/H.265 等，含序列头、关键帧、非关键帧）
    const CHANNEL_VIDEO = 5;
    # 数据/元数据消息 onMetaData（流信息）、onCuePoint（提示点）等 AMF 编码的用户数据，以及 SetDataFrame
    const CHANNEL_DATA = 6;
}
