<?php

namespace MediaServer\Flv;

use Exception;
use MediaServer\Utils\BinaryStream;
use Xiaosongshu\Flv2mp4\SabreAMF\AMF0\SabreAMF_AMF0_Deserializer;
use Xiaosongshu\Flv2mp4\SabreAMF\SabreAMF_InputStream;
use Xiaosongshu\Flv2mp4\SabreAMF\SabreAMF_OutputStream;
use Xiaosongshu\Flv2mp4\SabreAMF\SabreAMF_Serializer;
use function ord;

/**
 * @purpose flv数据包
 * @author yanglong
 */
class Flv
{


    /**
     * pre tag len 转数字
     * @param $preLen
     * @return mixed
     */
    static function preTagLenRead($preLen)
    {
        $up = unpack('N', $preLen);
        return $up[0];
    }

    /**
     * 读取tag数据
     * @param $tagData
     * @return array
     */
    static function tagDataRead($tagData)
    {
        return [
            'type' => ord($tagData[0]),
            'dataSize' => strlen($tagData) - 11,
            'timestamp' => (ord($tagData[7]) << 24) | (ord($tagData[4]) << 16) | (ord($tagData[5]) << 8) | ord($tagData[6]),
            'streamId' => (ord($tagData[8]) << 16) | (ord($tagData[9]) << 8) | ord($tagData[10]),
            //'data'=>substr($tagData,11),
            'data' => substr($tagData, 11)
        ];
    }

    /**
     * 读取脚本数据，读取meta帧
     * @param $scriptData
     * @return null[]
     * @throws Exception
     */
    static function scriptFrameDataRead($scriptData)
    {
        static $scriptMetaDataCode = [
            'onMetaData' => ['dataObj']
        ];
        /** 初始化输入数据流 */
        $stream = new SabreAMF_InputStream($scriptData);
        /** 数据解码 */
        $deserializer = new SabreAMF_AMF0_Deserializer($stream);
        /** 初始化结果 */
        $result = [
            'cmd' => null,
        ];
        /** 读取amf数据包命令 */
        if ($cmd = @$deserializer->readAMFData()) {
            $result['cmd'] = $cmd;
            /** 解码命令相关参数 */
            if (isset($scriptMetaDataCode[$cmd])) {
                foreach ($scriptMetaDataCode[$cmd] as $k) {
                    $result[$k] = $deserializer->readAMFData();
                }
            } else {
                logger()->warning('AMF Unknown command {cmd}', $result);
            }
        } else {
            logger()->warning('AMF read data error');
        }
        return $result;
    }

    /**
     * 视频数据
     * @param $videoData
     * @return array
     */
    static function videoFrameDataRead($videoData)
    {
        $firstByte = ord($videoData[0]);
        return [
            'frameType' => $firstByte >> 4,
            'codecId' => $firstByte & 15,
            'data' => substr($videoData, 1),
        ];
    }

    /**
     * 视频数据
     * @param $avcPacket
     * @return array
     */
    static function avcPacketRead($avcPacket)
    {
        return [
            'avcPacketType' => ord($avcPacket[0]), //if codecId == 7 ,0 avc sequence header,1 avc nalus
            'compositionTime' => (ord($avcPacket[1]) << 16) | (ord($avcPacket[2]) << 8) | ord($avcPacket[3]),
            'data' => substr($avcPacket, 4)
        ];
    }

    /**
     * 音频数据
     * @param $audioData
     * @return array
     */
    static function audioFrameDataRead($audioData)
    {
        $firstByte = ord($audioData[0]);
        return [
            'soundFormat' => $firstByte >> 4,
            'soundRate' => $firstByte >> 2 & 3,
            'soundSize' => $firstByte >> 1 & 1,
            'soundType' => $firstByte & 1,
            'data' => substr($audioData, 1)
        ];
    }

    /**
     * 音频数据
     * @param $accData
     * @return array
     */
    static function accPacketDataRead($accData)
    {
        return [
            'accPacketType' => ord($accData[0]), //0 = AAC sequence header，1 = AAC raw
            'data' => substr($accData, 1)
        ];
    }


    /**
     * 创建flv数据包
     *  $analysis = unpack("CtagType/a3tagSize/a3timestamp/CtimestampEx/a3streamId/a{$dataSize}data", $data);
     * $tag = [
     * 'type' => $analysis['tagType'],
     * 'dataSize' => $dataSize,
     * 'timestamp' => ($analysis['timestampEx'] << 24) | (\ord($analysis['timestamp'][0]) << 16) | (\ord($analysis['timestamp'][1]) << 8) | \ord($analysis['timestamp'][2]),
     * 'streamId' => (\ord($analysis['streamId'][0]) << 16) | (\ord($analysis['streamId'][1]) << 8) | \ord($analysis['streamId'][2]),
     * 'data' => $analysis['data']
     * ];
     *
     * @param $tag FlvTag
     * @return string
     */
    static function createFlvTag($tag)
    {
        $preTagLen = 11 + $tag->dataSize;
        
        // FLV Tag Header: type(1) + dataSize(3) + timestamp(3) + timestampExt(1) + streamId(3)
        $tagHeader = pack("C", $tag->type);
        $tagHeader .= substr(pack("N", $tag->dataSize), 1);  // 3 bytes big-endian
        $tagHeader .= substr(pack("N", $tag->timestamp), 1);  // 3 bytes big-endian
        $tagHeader .= pack("C", ($tag->timestamp >> 24) & 0xFF);  // timestamp extension
        $tagHeader .= substr(pack("N", $tag->streamId), 1);  // 3 bytes big-endian (always 0)
        
        $packet = $tagHeader . $tag->data;
        $packet .= pack("N", $preTagLen);  // PreviousTagSize (4 bytes big-endian)
        
        return $packet;
    }

    /** meta帧，用于控制视频，音频解码参数 ，也叫脚本帧 */
    const SCRIPT_TAG = 18;
    /** 音频帧 */
    const AUDIO_TAG = 8;
    /** 视频帧 */
    const VIDEO_TAG = 9;

    /** 视频关键帧 I帧 */
    const VIDEO_FRAME_TYPE_KEY_FRAME = 1;
    /** P帧，参考前一帧解码 */
    const VIDEO_FRAME_TYPE_INTER_FRAME = 2;
    /** B帧，参考前后帧解码，高压缩率 */
    const VIDEO_FRAME_TYPE_DISPOSABLE_INTER_FRAME = 3;
    /** 虚拟生成关键帧 */
    const VIDEO_FRAME_TYPE_GENERATED_KEY_FRAME = 4;
    /** 视频信息帧 */
    const VIDEO_FRAME_TYPE_VIDEO_INFO_FRAME = 5;

    /** 静态图片帧，直播废弃 */
    const VIDEO_CODEC_ID_JPEG = 1;
    /** 早期 Flash 老编码，画质差淘汰 */
    const VIDEO_CODEC_ID_H263 = 2;
    /** 一代屏幕录制编码 */
    const VIDEO_CODEC_ID_SCREEN = 3;
    /** 当年 Flash 主推编码，有专利 */
    const VIDEO_CODEC_ID_VP6_FLV = 4;
    /** 带透明通道 VP6 动画 */
    const VIDEO_CODEC_ID_VP6_FLV_ALPHA = 5;
    /** 二代录屏编码 */
    const VIDEO_CODEC_ID_SCREEN_V2 = 6;
    /** 现在直播唯一主流编码 */
    const VIDEO_CODEC_ID_AVC = 7;

    /** 视频序列帧 */
    const AVC_PACKET_TYPE_SEQUENCE_HEADER = 0;
    /** 视频原始数据帧 */
    const AVC_PACKET_TYPE_NALU = 1;
    /** 视频结束序列帧 */
    const AVC_PACKET_TYPE_END_SEQUENCE = 2;

    /** 音频编码器 */
    const SOUND_FORMAT_ACC = 10;
    /** 音频序列帧 */
    const ACC_PACKET_TYPE_SEQUENCE_HEADER = 0;
    /** aac原始数据帧 */
    const ACC_PACKET_TYPE_RAW = 1;
}
