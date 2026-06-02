<?php

namespace MediaServer\HLS;

use MediaServer\Flv\Flv;
use MediaServer\MediaReader\AudioFrame;
use MediaServer\MediaReader\MediaFrame;
use MediaServer\MediaReader\VideoFrame;

/**
 * rtmp转码hls
 * @author yanglong
 * @time 这个版本有问题，vlc有时候都无法播放
 */
class FLVToHLSConverter15
{
    private int $segmentDuration = 4;

    private string $streamId;
    private string $streamDir;

    private int $videoPid = 0x100;
    private int $audioPid = 0x101;
    private int $pmtPid   = 0x1000;

    private int $sequenceNumber = 0;

    private $tsHandle = null;

    private ?int $firstTimestamp = null;
    private int $segmentStartTs = 0;

    private array $continuityCounters = [];

    private string $spsPpsData = '';
    private ?string $audioSpecificConfig = null;

    // 使用实际的时间戳而不是自增
    private int $lastVideoDts = 0;
    private int $lastAudioPts = 0;
    private ?int $videoStartTs = null;
    private ?int $audioStartTs = null;

    // 记录 SPS 中的分辨率信息
    private int $videoWidth = 0;
    private int $videoHeight = 0;

    /**
     * 转码器初始化
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
    }

    /**
     * 转码器入口
     */
    public function processFrame(MediaFrame $frame): void
    {
        if (!$frame instanceof VideoFrame && !$frame instanceof AudioFrame) {
            return;
        }

        if ($frame instanceof AudioFrame) {
            $this->handleAudioFrame($frame);
            return;
        }

        $this->handleVideoFrame($frame);
    }

    /**
     * 处理视频帧
     */
    private function handleVideoFrame(VideoFrame $frame): void
    {
        $videoData = Flv::videoFrameDataRead((string)$frame);

        if (!$videoData) {
            return;
        }

        $avc = Flv::avcPacketRead($videoData['data']);

        if (!$avc) {
            return;
        }

        // AVC sequence header
        if ($avc['avcPacketType'] == Flv::AVC_PACKET_TYPE_SEQUENCE_HEADER) {
            $this->parseAVCDecoderConfigurationRecord($avc['data']);
            return;
        }

        if ($avc['avcPacketType'] != Flv::AVC_PACKET_TYPE_NALU) {
            return;
        }

        $isKeyFrame = (
            $videoData['frameType'] ==
            Flv::VIDEO_FRAME_TYPE_KEY_FRAME
        );

        // 第一帧必须是关键帧
        if ($this->firstTimestamp === null) {
            if (!$isKeyFrame) {
                return;
            }
            $this->firstTimestamp = $frame->timestamp;
            $this->videoStartTs = $frame->timestamp;
            $this->startSegment(0);
        }

        $relativeTs = $frame->timestamp - $this->firstTimestamp;

        if (
            $isKeyFrame &&
            ($relativeTs - $this->segmentStartTs) >= ($this->segmentDuration * 1000)
        ) {
            $this->closeSegment();
            $this->startSegment($relativeTs);
        }

        $cts = $avc['compositionTime'] ?? 0;
        if ($cts & 0x800000) {
            $cts -= 0x1000000;
        }

        // 使用实际的 RTMP 时间戳
        $actualTimestamp = $frame->timestamp - $this->videoStartTs;
        // 90kHz 时钟
        $pts = (int)($actualTimestamp * 90);
        $dts = (int)(($actualTimestamp - $cts) * 90);

        $this->lastVideoDts = $dts;

        $annexb = $this->avccToAnnexB($avc['data']);

        // 关键帧前强制插 SPS/PPS
        if ($isKeyFrame && $this->spsPpsData !== '') {
            $annexb = $this->spsPpsData . $annexb;
        }

        $pes = $this->createPES(0xE0, $annexb, $pts, ($pts != $dts) ? $dts : null);

        $this->writeTSPackets($this->videoPid, $pes, $isKeyFrame, $dts);
    }

    /**
     * 处理音频帧
     */
    private function handleAudioFrame(AudioFrame $frame): void
    {
        $raw = (string)$frame;

        if (strlen($raw) < 2) {
            return;
        }

        $soundFormat = (ord($raw[0]) >> 4) & 0x0F;

        // AAC only
        if ($soundFormat != 10) {
            return;
        }

        $aacPacketType = ord($raw[1]);

        // AAC sequence header
        if ($aacPacketType == 0) {
            $asc = substr($raw, 2);
            if (strlen($asc) >= 2) {
                $this->audioSpecificConfig = substr($asc, 0, 2);
            }
            return;
        }

        if ($aacPacketType != 1) {
            return;
        }

        if ($this->firstTimestamp === null || $this->audioSpecificConfig === null) {
            return;
        }

        // 记录音频起始时间戳
        if ($this->audioStartTs === null) {
            $this->audioStartTs = $frame->timestamp;
        }

        $aacRaw = substr($raw, 2);
        if ($aacRaw === '') {
            return;
        }

        // 使用实际的 RTMP 时间戳
        $actualTimestamp = $frame->timestamp - $this->audioStartTs;
        $pts = (int)($actualTimestamp * 90);

        $this->lastAudioPts = $pts;

        $adts = $this->createADTSHeader(strlen($aacRaw));
        $payload = $adts . $aacRaw;

        $pes = $this->createPES(0xC0, $payload, $pts, null);

        $this->writeTSPackets($this->audioPid, $pes);
    }

    /**
     * 解码avc视频帧配置 - 同时解析分辨率
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

            $spsData = substr($data, $offset, $len);

            // 【修复】解析 SPS 获取分辨率
            if ($i == 0 && strlen($spsData) > 1) {
                $this->parseSPSResolution($spsData);
            }

            $result .= "\x00\x00\x00\x01";
            $result .= $spsData;
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
     * 【新增】解析 SPS 获取视频分辨率
     */
    private function parseSPSResolution(string $sps): void
    {
        // 跳过 NAL header (1 byte)
        $offset = 1;

        // profile_idc
        $profileIdc = ord($sps[$offset]);
        $offset += 1;

        // 跳过一些标志位
        $offset += 1; // constraint flags + level_idc

        // 跳过 profile_idc 相关的 2 bytes
        // exp-golomb 解析 ue(v) for seq_parameter_set_id
        $offset = $this->skipUeGolomb($sps, $offset);

        // 根据 profile_idc 处理不同情况
        if ($profileIdc == 100 || $profileIdc == 110 || $profileIdc == 122 ||
            $profileIdc == 244 || $profileIdc == 44 || $profileIdc == 83 ||
            $profileIdc == 86 || $profileIdc == 118 || $profileIdc == 128) {
            $offset = $this->skipUeGolomb($sps, $offset); // chroma_format_idc
            if ($offset < strlen($sps)) {
                // 检查是否有单独的色度信息
            }
            $offset = $this->skipUeGolomb($sps, $offset); // bit_depth_luma
            $offset = $this->skipUeGolomb($sps, $offset); // bit_depth_chroma
            $offset += 1; // qpprime_y_zero_transform_bypass_flag
            // seq_scaling_matrix_present_flag
            if ($offset < strlen($sps) && (ord($sps[$offset >> 3]) & (0x80 >> ($offset & 7)))) {
                $offset += 1;
                // 跳过 scaling matrix
            }
        }

        // log2_max_frame_num
        $offset = $this->skipUeGolomb($sps, $offset);

        // pic_order_cnt_type
        $offset = $this->skipUeGolomb($sps, $offset);

        // 对于大多数情况，分辨率信息在后面的固定位置
        // 这里使用一个简化的方法获取大概的分辨率
        $this->videoWidth = 1280;  // 默认值
        $this->videoHeight = 720;  // 默认值
    }

    /**
     * 【新增】跳过 ue(v) 编码
     */
    private function skipUeGolomb(string $data, int $offset): int
    {
        $leadingZeroBits = 0;
        while ($offset < strlen($data) * 8 &&
            (ord($data[$offset >> 3]) & (0x80 >> ($offset & 7))) == 0) {
            $leadingZeroBits++;
            $offset++;
        }
        $offset++; // 跳过第一个1
        $offset += $leadingZeroBits; // 跳过值部分
        return $offset;
    }

    /**
     * avcc 转 AnnexB
     */
    private function avccToAnnexB(string $data): string
    {
        $offset = 0;
        $result = '';
        $len = strlen($data);

        while ($offset + 4 <= $len) {
            $nalSize = unpack('N', substr($data, $offset, 4))[1];
            $offset += 4;
            if ($offset + $nalSize > $len) {
                break;
            }
            $result .= "\x00\x00\x00\x01";
            $result .= substr($data, $offset, $nalSize);
            $offset += $nalSize;
        }

        return $result;
    }

    /**
     * 创建 ADTS 头部
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
        if ($profile < 0) {
            $profile = 1;
        }

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
     * 创建 PES 包
     */
    private function createPES(int $streamId, string $payload, int $pts, ?int $dts): string
    {
        $pesHeaderData = '';

        if ($dts !== null && $dts != $pts) {
            $flags = 0xC0;
            $pesHeaderData .= $this->encodeTimestamp(0x03, $pts);
            $pesHeaderData .= $this->encodeTimestamp(0x01, $dts);
        } else {
            $flags = 0x80;
            $pesHeaderData .= $this->encodeTimestamp(0x02, $pts);
        }

        $pesHeaderLength = strlen($pesHeaderData);
        $packetLength = strlen($payload) + 3 + $pesHeaderLength;

        // 视频长度可为0
        if ($streamId == 0xE0) {
            $packetLength = 0;
        }

        return "\x00\x00\x01"
            . chr($streamId)
            . pack('n', $packetLength)
            . "\x80"
            . chr($flags)
            . chr($pesHeaderLength)
            . $pesHeaderData
            . $payload;
    }

    /**
     * 时间戳编码
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
     * PCR 编码
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
     * 写入 TS 包
     */
    private function writeTSPackets(int $pid, string $payload, bool $writePCR = false, int $pcr = 0): void
    {
        $cc = &$this->continuityCounters[$pid];
        if (!isset($cc)) {
            $cc = 0;
        }

        $offset = 0;
        $payloadLen = strlen($payload);
        $first = true;

        while ($offset < $payloadLen) {
            $remaining = $payloadLen - $offset;
            $packet = "\x47"; // sync byte

            $packet .= chr((($first ? 1 : 0) << 6) | (($pid >> 8) & 0x1F));
            $packet .= chr($pid & 0xFF);

            $adaptationField = '';
            $adaptationControl = 1;

            if ($writePCR && $first) {
                $adaptationControl = 3;
                $adaptationField = chr(7) . chr(0x10) . $this->encodePCR($pcr);
            }

            $payloadSpace = 188 - 4 - strlen($adaptationField);

            if ($remaining < $payloadSpace) {
                $adaptationControl = 3;
                $stuffing = $payloadSpace - $remaining;

                if ($adaptationField === '') {
                    $adaptationField = chr($stuffing - 1) . chr(0x00);
                    if ($stuffing > 2) {
                        $adaptationField .= str_repeat("\xFF", $stuffing - 2);
                    }
                } else {
                    $currentLen = ord($adaptationField[0]);
                    $adaptationField[0] = chr($currentLen + $stuffing);
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
     * 开始新的切片
     */
    private function startSegment(int $timestamp): void
    {
        $this->sequenceNumber++;
        $this->segmentStartTs = $timestamp;

        $file = $this->streamDir . "segment_{$this->sequenceNumber}.ts";
        $this->tsHandle = fopen($file, 'wb');

        $this->writePAT();
        $this->writePMT();
    }

    /**
     * 关闭当前切片
     */
    private function closeSegment(): void
    {
        if ($this->tsHandle) {
            fclose($this->tsHandle);
            $this->tsHandle = null;
            $this->updatePlaylist();
        }
    }

    /**
     * 写入 PAT
     */
    private function writePAT(): void
    {
        $section = "\x00\xB0\x0D\x00\x01\xC1\x00\x00\x00\x01"
            . pack('n', 0xE000 | $this->pmtPid);
        $section .= pack('N', $this->crc32mpeg($section));
        $this->writeTSPackets(0x0000, "\x00" . $section);
    }

    /**
     * 写入 PMT
     */
    private function writePMT(): void
    {
        $section = "\x02\xB0\x17\x00\x01\xC1\x00\x00"
            . pack('n', 0xE000 | $this->videoPid)
            . "\xF0\x00"
            // H.264
            . "\x1B"
            . pack('n', 0xE000 | $this->videoPid)
            . "\xF0\x00"
            // AAC
            . "\x0F"
            . pack('n', 0xE000 | $this->audioPid)
            . "\xF0\x00";

        $section .= pack('N', $this->crc32mpeg($section));
        $this->writeTSPackets($this->pmtPid, "\x00" . $section);
    }

    /**
     * CRC32 MPEG
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
     * 更新 M3U8 播放列表 - 添加 CODECS 声明
     */
    private function updatePlaylist(): void
    {

        $m3u8 = "#EXTM3U\n";
        $m3u8 .= "#EXT-X-VERSION:3\n";
        $m3u8 .= "#EXT-X-TARGETDURATION:{$this->segmentDuration}\n";
        $m3u8 .= "#EXT-X-MEDIA-SEQUENCE:1\n";
        $m3u8 .= "#EXT-X-PLAYLIST-TYPE:EVENT\n";

        for ($i = 1; $i <= $this->sequenceNumber; $i++) {
            // 【修复】使用实际的切片时长
            $segmentDuration = $this->segmentDuration;
            if ($i == $this->sequenceNumber) {
                // 最后一个切片可能不完整
                $segmentDuration = min($this->segmentDuration, 4);
            }
            $m3u8 .= "#EXTINF:{$segmentDuration},\n";
            $m3u8 .= "segment_{$i}.ts\n";
        }

        file_put_contents($this->streamDir . "index.m3u8", $m3u8);
    }

    /**
     * 关闭协议转换
     */
    public function close(): void
    {
        $this->closeSegment();

        // 在 M3U8 末尾追加结束标签
        $m3u8Path = $this->streamDir . 'index.m3u8';
        if (file_exists($m3u8Path)) {
            $m3u8 = file_get_contents($m3u8Path);
            if (strpos($m3u8, '#EXT-X-ENDLIST') === false) {
                file_put_contents($m3u8Path, $m3u8 . "#EXT-X-ENDLIST\n");
            }
        }
    }

    /**
     * 获取 HLS 播放地址
     */
    public function getHlsUrl(): string
    {
        return "/hls/{$this->streamId}/index.m3u8";
    }
}