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
    const CHANNEL_PROTOCOL = 2;
    const CHANNEL_INVOKE = 3;
    const CHANNEL_AUDIO = 4;
    const CHANNEL_VIDEO = 5;
    const CHANNEL_DATA = 6;





}
