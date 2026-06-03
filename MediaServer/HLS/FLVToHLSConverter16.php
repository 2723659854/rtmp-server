<?php

namespace MediaServer\HLS;

use MediaServer\Flv\Flv;
use MediaServer\Flv\FlvTag;
use MediaServer\MediaReader\AudioFrame;
use MediaServer\MediaReader\MediaFrame;
use MediaServer\MediaReader\MetaDataFrame;
use MediaServer\MediaReader\VideoFrame;

/**
 * rtmp转码hls
 * @author yanglong
 */
class FLVToHLSConverter16
{
    private int $segmentDuration = 4;
    private string $streamId;
    private string $streamDir;
    private int $videoPid = 0x100;
    private int $audioPid = 0x101;
    private int $pmtPid   = 0x1000;
    private int $sequenceNumber = 0;
    private $tsHandle = null;

    // 时间戳基准
    private ?int $baseTimestamp = null;      // 第一个帧的绝对时间戳
    private int $segmentStartTime = 0;        // 当前切片开始时间（毫秒）
    private array $continuityCounters = [];
    private string $spsPpsData = '';
    private ?string $audioSpecificConfig = null;

    // 记录每个切片的实际时长
    private array $segmentDurations = [];
    private int $currentSegmentLastTime = 0;

    /**
     * 初始化hls协议
     * @param string $streamId 直播路径
     * @param array $config 配置
     */
    public function __construct(string $streamId, array $config = [])
    {
        $streamId = rtrim($streamId, "/");
        $streamId = ltrim($streamId, "/");
        $this->streamId = $streamId;
        $this->streamDir = dirname(__DIR__, 2) . "/hls/{$streamId}/";
        if (!is_dir($this->streamDir)) {
            mkdir($this->streamDir, 0777, true);
        }
        if (isset($config['segmentDuration'])) {
            $this->segmentDuration = (int)$config['segmentDuration'];
        }
        $this->ensureInitialPlaylist();
    }

    /**
     * 确保在第一个切片生成前，m3u8 文件已包含正确头部
     * @return void
     */
    private function ensureInitialPlaylist(): void
    {
        $m3u8Path = $this->streamDir . 'index.m3u8';
        if (!file_exists($m3u8Path)) {
            $lines = [
                '#EXTM3U',
                '#EXT-X-VERSION:3',
                '#EXT-X-TARGETDURATION:' . $this->segmentDuration,
                '#EXT-X-MEDIA-SEQUENCE:1',
                '#EXT-X-INDEPENDENT-SEGMENTS',
                // 不包含任何片段，播放器会轮询等待
            ];
            file_put_contents($m3u8Path, implode("\n", $lines) . "\n");
        }
    }

    /**
     * rtmp 数据入口
     * @param $frame
     * @return mixed|void
     * @note 这里是接入的框架rtmp数据包，传递过来的原始数据包，使用这个方法将rtmp数据包转码为flv包，此方法和框架深度绑定
     */
    public function processFrame($frame)
    {
        // 继续向客户端发送数据
        switch ($frame->FRAME_TYPE) {
            //case MediaFrame::VIDEO_FRAME:
            case 1:
                return $this->sendVideoFrame($frame);
            //case MediaFrame::AUDIO_FRAME:
            case 2:
                return $this->sendAudioFrame($frame);
            //case MediaFrame::META_FRAME:
            case 0:
                return $this->sendMetaDataFrame($frame);
        }
    }



    /**
     * 构建flv包
     * @param $tag
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

    /**
     * 发送元数据
     * @param $metaDataFrame MetaDataFrame|MediaFrame
     * @return mixed
     */
    public function sendMetaDataFrame($metaDataFrame)
    {
        /** 组装数据 */
        $tag = new \stdClass();
        $tag->streamId = 0;
        $tag->type = 18;
        $tag->timestamp = 0;
        $tag->data = (string)$metaDataFrame;
        $tag->dataSize = strlen($tag->data);

        /** 将数据打包编码 */
        $chunks = self::createFlvTag($tag);
        /** 发送 */
        $this->write($chunks);
    }

    /**
     * 发送音频帧
     * @param $audioFrame AudioFrame|MediaFrame
     * @return mixed
     */
    public function sendAudioFrame($audioFrame)
    {
        $tag = new \stdClass();
        $tag->streamId = 0;
        $tag->type = 8;
        $tag->timestamp = $audioFrame->timestamp;
        $tag->data = (string)$audioFrame;
        $tag->dataSize = strlen($tag->data);

        $chunks = self::createFlvTag($tag);
        $this->write($chunks);
    }

    /**
     * 发送视频帧
     * @param $videoFrame VideoFrame|MediaFrame
     * @return mixed
     */
    public function sendVideoFrame($videoFrame)
    {
        $tag = new \stdClass();
        $tag->streamId = 0;
        $tag->type = 9;
        $tag->timestamp = $videoFrame->timestamp;
        $tag->data = (string)$videoFrame;
        $tag->dataSize = strlen($tag->data);
        $chunks = self::createFlvTag($tag);
        $this->write($chunks);
    }

    /**
     * flv数据包入口
     * @param string $flvTagData
     * @return void
     * @note 这里flv裸数据，保留此方法，作为本框架接入点
     */
    public function write(string $flvTagData)
    {
        // 解析 FLV Tag 结构
        $offset = 0;
        $dataLen = strlen($flvTagData);
        // 至少需要 11 + 4 字节（Tag Header + PreviousTagSize）
        if ($dataLen < 15) return;

        // 1. Tag类型 (1字节)
        $type = ord($flvTagData[$offset]);
        $offset += 1;

        // 2. 数据大小 (3字节大端)
        $dataSize = (ord($flvTagData[$offset]) << 16) | (ord($flvTagData[$offset+1]) << 8) | ord($flvTagData[$offset+2]);
        $offset += 3;

        // 3. 时间戳低24位 (3字节大端)
        $timestampLow = (ord($flvTagData[$offset]) << 16) | (ord($flvTagData[$offset+1]) << 8) | ord($flvTagData[$offset+2]);
        $offset += 3;

        // 4. 时间戳高8位 (1字节) -> 组合成完整的32位时间戳(毫秒)
        $timestampExt = ord($flvTagData[$offset]);
        $offset += 1;
        $timestamp = ($timestampExt << 24) | $timestampLow;

        // 5. 流ID (3字节，通常为0，跳过)
        $offset += 3;

        // 6. 负载数据 (dataSize 字节)
        if ($offset + $dataSize > $dataLen) return; // 数据不完整
        $payload = substr($flvTagData, $offset, $dataSize);
        $offset += $dataSize;

        // 7. PreviousTagSize (4字节) 忽略

        // 根据类型分发
        if ($type === 9) {          // 视频
            $this->handleVideoFrame($timestamp, $payload);
        } elseif ($type === 8) {    // 音频
            $this->handleAudioFrame($timestamp, $payload);
        } elseif ($type === 18) {   // 脚本数据（Meta）
            // 忽略
        }
    }

    /**
     * 处理视频帧（修改后：接受时间戳和原始 Tag Data）
     */
    private function handleVideoFrame(int $timestamp, string $rawData): void
    {
        $videoData = self::videoFrameDataRead($rawData);
        if (!$videoData) return;

        $avc = self::avcPacketRead($videoData['data']);
        if (!$avc) return;

        if ($avc['avcPacketType'] == self::AVC_PACKET_TYPE_SEQUENCE_HEADER) {
            $this->parseAVCDecoderConfigurationRecord($avc['data']);
            return;
        }

        if ($avc['avcPacketType'] != self::AVC_PACKET_TYPE_NALU) return;

        $isKeyFrame = ($videoData['frameType'] == self::VIDEO_FRAME_TYPE_KEY_FRAME);

        // 首次收到关键帧，设置时间基准
        if ($this->baseTimestamp === null) {
            if (!$isKeyFrame) return;
            $this->baseTimestamp = $timestamp;
            $this->segmentStartTime = 0;
            $this->startSegment();
        }

        // 全局相对时间（毫秒）
        $relativeTime = $timestamp - $this->baseTimestamp;

        // 切片判断：使用全局时间差
        if (
            $isKeyFrame &&
            ($relativeTime - $this->segmentStartTime) >= ($this->segmentDuration * 1000)
        ) {
            $this->closeSegment($relativeTime);
            $this->segmentStartTime = $relativeTime;
            $this->startSegment();
        }

        $this->currentSegmentLastTime = $relativeTime;

        // 计算 PTS/DTS（90kHz）
        $cts = $avc['compositionTime'] ?? 0;
        if ($cts & 0x800000) {
            $cts -= 0x1000000;
        }

        $dts = (int)($relativeTime * 90);
        $pts = (int)(($relativeTime + $cts) * 90);
        if ($pts < $dts) {
            $pts = $dts;
        }

        // 构建 AnnexB
        $annexb = $this->avccToAnnexB($avc['data']);

        if ($isKeyFrame && $this->spsPpsData !== '') {
            $annexb = $this->spsPpsData . $annexb;
        }

        $pes = $this->createPES(0xE0, $annexb, $pts, ($pts != $dts) ? $dts : null);
        $this->writeTSPackets($this->videoPid, $pes, true, $dts);
    }

    /**
     * 处理音频帧（修改后：接受时间戳和原始 Tag Data）
     */
    private function handleAudioFrame(int $timestamp, string $rawData): void
    {
        $raw = $rawData;
        if (strlen($raw) < 2) return;

        $soundFormat = (ord($raw[0]) >> 4) & 0x0F;
        if ($soundFormat != 10) return; // 仅支持 AAC

        $aacPacketType = ord($raw[1]);

        if ($aacPacketType == 0) {
            $asc = substr($raw, 2);
            if (strlen($asc) >= 2) {
                $this->audioSpecificConfig = substr($asc, 0, 2);
            }
            return;
        }

        if ($aacPacketType != 1) return;
        if ($this->baseTimestamp === null || $this->audioSpecificConfig === null) return;

        $aacRaw = substr($raw, 2);
        if ($aacRaw === '') return;

        // 全局相对时间
        $relativeTime = $timestamp - $this->baseTimestamp;

        // 使用全局时间计算 PTS（90kHz）
        $pts = (int)($relativeTime * 90);

        $adts = $this->createADTSHeader(strlen($aacRaw));
        $payload = $adts . $aacRaw;

        $pes = $this->createPES(0xC0, $payload, $pts, null);
        $this->writeTSPackets($this->audioPid, $pes, false, 0);
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

    const VIDEO_FRAME_TYPE_KEY_FRAME = 1;

    const AVC_PACKET_TYPE_SEQUENCE_HEADER = 0;
    const AVC_PACKET_TYPE_NALU = 1;


    /**
     * Program Association Table，节目关联表
     * @return void
     */
    private function writePAT(): void
    {
        // table_id=0x00, section_syntax_indicator=1, section_length=13(0x0D)
        $section = "\x00\xB0\x0D"
            . "\x00\x01"     // transport_stream_id=1
            . "\xC1\x00\x00" // version=0, current_next=1, section=0, last_section=0
            . "\x00\x01"     // program_number=1
            . pack('n', 0xE000 | $this->pmtPid); // program_map_PID

        $section .= pack('N', $this->crc32mpeg($section));

        // PAT section 总共: 1 (pointer_field) + 17 (section + CRC) = 18 字节
        // 一个 TS 包 payload 最多 184 字节
        // 不需要填充，手动构建 188 字节的完整包
        $payload = "\x00" . $section;

        $this->writeTSPacketsRaw(0x0000, $payload);
    }

    /**
     * Program Map Table，节目映射表
     * @return void
     * @note 告知有两个节目h.264 + aac
     */
    private function writePMT(): void
    {
        // 构建 PMT body
        $body = "\x00\x01"     // program_number=1
            . "\xC1\x00\x00"   // version=0, current_next=1, section=0, last_section=0
            . pack('n', 0xE000 | $this->videoPid)  // PCR_PID
            . "\xF0\x00";       // program_info_length=0

        // 视频流: H.264
        $body .= "\x1B"       // stream_type=H.264
            . pack('n', 0xE000 | $this->videoPid)
            . "\xF0\x00";      // ES_info_length=0

        // 音频流: AAC
        $body .= "\x0F"       // stream_type=AAC
            . pack('n', 0xE000 | $this->audioPid)
            . "\xF0\x00";      // ES_info_length=0

        $sectionLength = strlen($body) + 4; // +4 for CRC32
        $section = "\x02"  // table_id=PMT
            . chr(0xB0 | (($sectionLength >> 8) & 0x0F))
            . chr($sectionLength & 0xFF)
            . $body;

        $section .= pack('N', $this->crc32mpeg($section));

        $payload = "\x00" . $section;

        $this->writeTSPacketsRaw($this->pmtPid, $payload);
    }

    /**
     * 简单的 TS 包写入 - PAT/PMT 专用
     * 不填充 adaptation field，直接用 0xFF 填满剩余空间
     */
    private function writeTSPacketsRaw(int $pid, string $payload): void
    {
        $cc = &$this->continuityCounters[$pid];
        if (!isset($cc)) $cc = 0;

        $offset = 0;
        $payloadLen = strlen($payload);
        $first = true;

        while ($offset < $payloadLen) {
            $remaining = $payloadLen - $offset;

            // 构建完整的 188 字节 TS 包
            $packet = "\x47";  // sync byte
            // payload_unit_start_indicator + PID
            $packet .= chr((($first ? 1 : 0) << 6) | (($pid >> 8) & 0x1F));
            $packet .= chr($pid & 0xFF);
            // adaptation_field_control=01 (payload only) + continuity_counter
            $packet .= chr(0x10 | ($cc & 0x0F));
            $cc = ($cc + 1) & 0x0F;

            // 写入 payload（最多 184 字节）
            $chunkSize = min($remaining, 184);
            $packet .= substr($payload, $offset, $chunkSize);

            // 用 0xFF 填充到 188 字节
            if (strlen($packet) < 188) {
                $packet = str_pad($packet, 188, "\xFF");
            }

            fwrite($this->tsHandle, $packet);
            $offset += $chunkSize;
            $first = false;
        }
    }

    /**
     * 解析sps配置
     * @param string $data
     * @return void
     * @note 播放器初始化页面需要宽高fps等基本信息，如果缺少sps则无法解码
     */
    private function parseAVCDecoderConfigurationRecord(string $data): void
    {
        $offset = 5;
        $numSps = ord($data[$offset]) & 0x1F;
        $offset++;
        $result = '';

        for ($i = 0; $i < $numSps; $i++) {
            $len = unpack('n', substr($data, $offset, 2))[1];
            $offset += 2;
            $result .= "\x00\x00\x00\x01";
            $result .= substr($data, $offset, $len);
            $offset += $len;
        }

        $numPps = ord($data[$offset]);
        $offset++;

        for ($i = 0; $i < $numPps; $i++) {
            $len = unpack('n', substr($data, $offset, 2))[1];
            $offset += 2;
            $result .= "\x00\x00\x00\x01";
            $result .= substr($data, $offset, $len);
            $offset += $len;
        }

        $this->spsPpsData = $result;
    }

    /**
     * 将avc数据转码AnnexB
     * @param string $data
     * @return string
     */
    private function avccToAnnexB(string $data): string
    {
        $offset = 0;
        $result = '';
        $len = strlen($data);

        while ($offset + 4 <= $len) {
            $nalSize = unpack('N', substr($data, $offset, 4))[1];
            $offset += 4;
            if ($offset + $nalSize > $len) break;
            $result .= "\x00\x00\x00\x01";
            $result .= substr($data, $offset, $nalSize);
            $offset += $nalSize;
        }

        return $result;
    }

    /**
     * 创建aac音频数据包的adts头
     * @param int $aacLength
     * @return string
     */
    private function createADTSHeader(int $aacLength): string
    {
        $asc = $this->audioSpecificConfig;
        $b1 = ord($asc[0]);
        $b2 = ord($asc[1]);

        $audioObjectType = ($b1 >> 3) & 0x1F;
        $freqIndex = (($b1 & 0x07) << 1) | (($b2 >> 7) & 0x01);
        $channelConfig = ($b2 >> 3) & 0x0F;

        $profile = $audioObjectType - 1;
        if ($profile < 0) $profile = 1;

        $frameLength = $aacLength + 7;

        return pack('CCCCCCC',
            0xFF, 0xF1,
            (($profile & 0x03) << 6) | (($freqIndex & 0x0F) << 2) | (($channelConfig >> 2) & 0x01),
            (($channelConfig & 0x03) << 6) | (($frameLength >> 11) & 0x03),
            ($frameLength >> 3) & 0xFF,
            (($frameLength & 0x07) << 5) | 0x1F,
            0xFC
        );
    }

    /**
     * 创建pes数据包
     * @param int $streamId
     * @param string $payload
     * @param int $pts
     * @param int|null $dts
     * @return string
     */
    private function createPES(int $streamId, string $payload, int $pts, ?int $dts): string
    {
        $ptsDtsFlags = ($dts !== null && $dts != $pts) ? 0xC0 : 0x80;

        $headerData = $this->encodeTimestamp(($dts !== null && $dts != $pts) ? 0x03 : 0x02, $pts);
        if ($dts !== null && $dts != $pts) {
            $headerData .= $this->encodeTimestamp(0x01, $dts);
        }

        $headerLength = strlen($headerData);
        $packetLength = strlen($payload) + 3 + $headerLength;


        if ($packetLength > 0xFFFF) {
            $packetLength = 0;
        }

        return "\x00\x00\x01"
            . chr($streamId)
            . pack('n', $packetLength)
            . "\x80"
            . chr($ptsDtsFlags)
            . chr($headerLength)
            . $headerData
            . $payload;
    }

    /**
     * 编码时间戳
     * @param int $type
     * @param int $ts
     * @return string
     */
    private function encodeTimestamp(int $type, int $ts): string
    {
        $ts &= 0x1FFFFFFFF;
        return pack('CCCCC',
            (($type << 4) & 0xF0) | ((($ts >> 30) & 0x07) << 1) | 1,
            ($ts >> 22) & 0xFF,
            ((($ts >> 15) & 0x7F) << 1) | 1,
            ($ts >> 7) & 0xFF,
            (($ts & 0x7F) << 1) | 1
        );
    }

    /**
     * 时钟
     * @param int $pcr
     * @return string
     */
    private function encodePCR(int $pcr): string
    {
        return pack('CCCCCC',
            ($pcr >> 25) & 0xFF,
            ($pcr >> 17) & 0xFF,
            ($pcr >> 9)  & 0xFF,
            ($pcr >> 1)  & 0xFF,
            (($pcr & 1) << 7) | 0x7E,
            0x00
        );
    }

    /**
     * 构建ts包
     * @param int $pid
     * @param string $payload
     * @param bool $writePCR
     * @param int $pcr
     * @return void
     */
    private function writeTSPackets(int $pid, string $payload, bool $writePCR = false, int $pcr = 0): void
    {
        $cc = &$this->continuityCounters[$pid];
        if (!isset($cc)) $cc = 0;

        $offset = 0;
        $payloadLen = strlen($payload);
        $first = true;

        while ($offset < $payloadLen) {
            $remaining = $payloadLen - $offset;

            // TS Header: sync_byte + PID
            $packet = "\x47";
            $packet .= chr((($first ? 1 : 0) << 6) | (($pid >> 8) & 0x1F));
            $packet .= chr($pid & 0xFF);

            $adaptationField = '';
            $adaptationControl = 1; // payload only

            if ($writePCR && $first) {
                $adaptationControl = 3; // adaptation + payload
                $adaptationField = chr(7) . chr(0x10) . $this->encodePCR($pcr);
            }

            $payloadSpace = 188 - 4 - strlen($adaptationField);

            // 填充
            if ($remaining < $payloadSpace) {
                $adaptationControl = 3;
                $stuffing = $payloadSpace - $remaining;

                if ($adaptationField === '') {
                    $adaptationField = chr($stuffing - 1) . chr(0x00);
                    if ($stuffing > 2) {
                        $adaptationField .= str_repeat("\xFF", $stuffing - 2);
                    }
                } else {

                    $newLen = min(
                        255,
                        ord($adaptationField[0]) + $stuffing
                    );

                    $adaptationField[0] = chr($newLen);

                    $adaptationField .= str_repeat("\xFF", $stuffing);
                }
                $payloadSpace = 188 - 4 - strlen($adaptationField);
            }

            $packet .= chr(($adaptationControl << 4) | ($cc & 0x0F));
            $cc = ($cc + 1) & 0x0F;
            $packet .= $adaptationField;
            $packet .= substr($payload, $offset, $payloadSpace);
            $packet = str_pad($packet, 188, "\xFF");

            fwrite($this->tsHandle, $packet);
            $offset += $payloadSpace;
            $first = false;
        }
    }

    /**
     * 创建新切片
     * @return void
     */
    private function startSegment(): void
    {
        $this->sequenceNumber++;
        $this->continuityCounters = [];
        $file = $this->streamDir . "segment_{$this->sequenceNumber}.ts";
        $this->tsHandle = fopen($file, 'wb');

        $this->writePAT();
        $this->writePMT();

        // 写入 SPS/PPS 时，PCR 必须使用切片开始时的全局时间
        if ($this->spsPpsData !== '') {
            // 切片开始时的全局时间（毫秒转 90kHz）
            $startPcr = (int)($this->segmentStartTime * 90);
            $spsPpsPes = $this->createPES(0xE0, $this->spsPpsData, $startPcr, $startPcr);
            $this->writeTSPackets($this->videoPid, $spsPpsPes, true, $startPcr);
        }
    }

    /**
     * 关闭当前切片，并更新索引列表
     * @param int $endTime
     * @return void
     */
    private function closeSegment(int $endTime = 0): void
    {
        if ($this->tsHandle) {
            fclose($this->tsHandle);
            $this->tsHandle = null;

            // 计算实际时长
            // segmentStartTime 已经是正确的切片开始时间
            $end = $endTime > 0 ? $endTime : $this->currentSegmentLastTime;
            $actualDuration = ($end - $this->segmentStartTime) / 1000.0;
            $actualDuration = max(0.001, round($actualDuration, 3));
            $this->segmentDurations[$this->sequenceNumber] = $actualDuration;

            $this->updatePlaylist();
        }
    }

    /**
     * pat,pmt末尾附加 4 字节的 CRC32 校验码
     * @param string $data
     * @return int
     * @note mpegts的标准
     */
    private function crc32mpeg(string $data): int
    {
        $crc = 0xFFFFFFFF;
        $len = strlen($data);

        for ($i = 0; $i < $len; $i++) {
            $crc ^= ord($data[$i]) << 24;
            for ($j = 0; $j < 8; $j++) {
                if ($crc & 0x80000000) {
                    $crc = (($crc << 1) ^ 0x04C11DB7) & 0xFFFFFFFF;
                } else {
                    $crc = ($crc << 1) & 0xFFFFFFFF;
                }
            }
        }

        return $crc;
    }

    /**
     * 更新索引
     * @return void
     */
    private function updatePlaylist(): void
    {
        $lines = [];
        $lines[] = "#EXTM3U";
        $lines[] = "#EXT-X-VERSION:3";

        $maxDuration = $this->segmentDuration;
        foreach ($this->segmentDurations as $duration) {
            $maxDuration = max($maxDuration, ceil($duration));
        }
        $lines[] = "#EXT-X-TARGETDURATION:" . (int)$maxDuration;
        $lines[] = "#EXT-X-MEDIA-SEQUENCE:1";

        // 添加 CODECS 声明
        // avc1.64001f = H.264 High Profile, mp4a.40.2 = AAC-LC
        $lines[] = '#EXT-X-INDEPENDENT-SEGMENTS';

        for ($i = 1; $i <= $this->sequenceNumber; $i++) {
            $duration = $this->segmentDurations[$i] ?? $this->segmentDuration;
            // 在 EXTINF 中添加 CODECS 信息
            // 格式: #EXTINF:<duration>,<title>
            // 或者通过 EXT-X-MAP 等方式
            $lines[] = "#EXT-X-DISCONTINUITY";
            $lines[] = "#EXTINF:" . number_format($duration, 3, '.', '') . ",";
            $lines[] = "segment_{$i}.ts";
        }

        $m3u8Content = implode("\n", $lines) . "\n";

        $m3u8Path = $this->streamDir . "index.m3u8";
        $tmpPath = $m3u8Path . '.tmp';
        file_put_contents($tmpPath, $m3u8Content);
        rename($tmpPath, $m3u8Path);
    }

    /**
     * 关闭推流
     * @return void
     */
    public function close(): void
    {
        $this->closeSegment($this->currentSegmentLastTime);

        $m3u8Path = $this->streamDir . 'index.m3u8';
        if (file_exists($m3u8Path)) {
            $m3u8 = rtrim(file_get_contents($m3u8Path)) . "\n";
            if (strpos($m3u8, '#EXT-X-ENDLIST') === false) {
                $m3u8 .= "#EXT-X-ENDLIST\n";
            }
            file_put_contents($m3u8Path, $m3u8);
        }
    }
}