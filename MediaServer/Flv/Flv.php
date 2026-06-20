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
     * 读取脚本数据
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
     * 设置tag点
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
        $preTagLen = 11 +$tag->dataSize;
        $packet = pack("Ca3a3Ca3a{$tag->dataSize}N",
            $tag->type,                                       //type
            pack("N", $tag->dataSize << 8),     //dataSize
            pack("N", $tag->timestamp << 8),    //timeStamp
            $tag->timestamp >> 24,                            //timeStampExt
            pack("N", $tag->streamId<< 8),     //streamId
            $tag->data,                                       //data
            $preTagLen                                          //preTagLen
        );

        return $packet;
    }

    const SCRIPT_TAG = 18;
    const AUDIO_TAG = 8;
    const VIDEO_TAG = 9;

    const VIDEO_FRAME_TYPE_KEY_FRAME = 1;
    const VIDEO_FRAME_TYPE_INTER_FRAME = 2;
    const VIDEO_FRAME_TYPE_DISPOSABLE_INTER_FRAME = 3;
    const VIDEO_FRAME_TYPE_GENERATED_KEY_FRAME = 4;
    const VIDEO_FRAME_TYPE_VIDEO_INFO_FRAME = 5;

    const VIDEO_CODEC_ID_JPEG = 1;
    const VIDEO_CODEC_ID_H263 = 2;
    const VIDEO_CODEC_ID_SCREEN = 3;
    const VIDEO_CODEC_ID_VP6_FLV = 4;
    const VIDEO_CODEC_ID_VP6_FLV_ALPHA = 5;
    const VIDEO_CODEC_ID_SCREEN_V2 = 6;
    const VIDEO_CODEC_ID_AVC = 7;

    const AVC_PACKET_TYPE_SEQUENCE_HEADER = 0;
    const AVC_PACKET_TYPE_NALU = 1;
    const AVC_PACKET_TYPE_END_SEQUENCE = 2;


    const SOUND_FORMAT_ACC = 10;

    const ACC_PACKET_TYPE_SEQUENCE_HEADER = 0;
    const ACC_PACKET_TYPE_RAW = 1;
}
