<?php

namespace MediaServer\HLS;

use MediaServer\Flv\Flv;
use MediaServer\MediaReader\AudioFrame;
use MediaServer\MediaReader\MediaFrame;
use MediaServer\MediaReader\VideoFrame;

/**
 * rtmp转码hls
 * @author yanglong
 * @note 这一个版本vlc可以播放，hls.js也可以播放，但是存在一个问题：比如vlc进度条显示9秒，实际上播放进度条走到8秒，就播放结束了，差了1秒，这是一个问题
 * @note 这是当前最正确的一个版本
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

    private function handleVideoFrame(VideoFrame $frame): void
    {
        $videoData = Flv::videoFrameDataRead((string)$frame);
        if (!$videoData) return;

        $avc = Flv::avcPacketRead($videoData['data']);
        if (!$avc) return;

        if ($avc['avcPacketType'] == Flv::AVC_PACKET_TYPE_SEQUENCE_HEADER) {
            $this->parseAVCDecoderConfigurationRecord($avc['data']);
            return;
        }

        if ($avc['avcPacketType'] != Flv::AVC_PACKET_TYPE_NALU) return;

        $isKeyFrame = ($videoData['frameType'] == Flv::VIDEO_FRAME_TYPE_KEY_FRAME);

        if ($this->baseTimestamp === null) {
            if (!$isKeyFrame) return;
            $this->baseTimestamp = $frame->timestamp;
            $this->segmentStartTime = 0;
            $this->startSegment();
        }

        $relativeTime = $frame->timestamp - $this->baseTimestamp;

        // 切分判断
        if (
            $isKeyFrame &&
            ($relativeTime - $this->segmentStartTime) >= ($this->segmentDuration * 1000)
        ) {
            $this->closeSegment($relativeTime);
            $this->segmentStartTime = $relativeTime;
            $this->startSegment();
        }

        $this->currentSegmentLastTime = $relativeTime;

        // 【关键】切片内相对时间（毫秒）
        $sliceTime = $relativeTime - $this->segmentStartTime;

        $cts = $avc['compositionTime'] ?? 0;
        if ($cts & 0x800000) {
            $cts -= 0x1000000;
        }

        // PTS/DTS 基于切片内时间（90kHz）
        $dts = (int)($sliceTime * 90);
        $pts = (int)(($sliceTime + $cts) * 90);

        if ($pts < $dts) {
            $pts = $dts;
        }

        $annexb = $this->avccToAnnexB($avc['data']);

        if ($isKeyFrame && $this->spsPpsData !== '') {
            $annexb = $this->spsPpsData . $annexb;
        }

        $pes = $this->createPES(0xE0, $annexb, $pts, ($pts != $dts) ? $dts : null);
        $this->writeTSPackets($this->videoPid, $pes, true, $dts);
    }

    private function handleAudioFrame(AudioFrame $frame): void
    {
        $raw = (string)$frame;
        if (strlen($raw) < 2) return;

        $soundFormat = (ord($raw[0]) >> 4) & 0x0F;
        if ($soundFormat != 10) return;

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

        $relativeTime = $frame->timestamp - $this->baseTimestamp;

        // 【关键】切片内相对时间
        $sliceTime = $relativeTime - $this->segmentStartTime;
        $pts = (int)($sliceTime * 90);

        $adts = $this->createADTSHeader(strlen($aacRaw));
        $payload = $adts . $aacRaw;

        $pes = $this->createPES(0xC0, $payload, $pts, null);
        $this->writeTSPackets($this->audioPid, $pes, false, 0);
    }

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
     * 【新增】简单的 TS 包写入 - PAT/PMT 专用
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

    public function processFrame(MediaFrame $frame): void
    {
        if ($frame instanceof AudioFrame) {
            $this->handleAudioFrame($frame);
            return;
        }
        if ($frame instanceof VideoFrame) {
            $this->handleVideoFrame($frame);
            return;
        }
    }

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

    private function createPES(int $streamId, string $payload, int $pts, ?int $dts): string
    {
        $ptsDtsFlags = ($dts !== null && $dts != $pts) ? 0xC0 : 0x80;

        $headerData = $this->encodeTimestamp(($dts !== null && $dts != $pts) ? 0x03 : 0x02, $pts);
        if ($dts !== null && $dts != $pts) {
            $headerData .= $this->encodeTimestamp(0x01, $dts);
        }

        $headerLength = strlen($headerData);
        $packetLength = strlen($payload) + 3 + $headerLength;

        // 视频 PES 包长度可以是 0
//        if ($streamId == 0xE0) {
//            $packetLength = 0;
//        }

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
//                    $currentLen = ord($adaptationField[0]);
//                    $adaptationField[0] = chr($currentLen + $stuffing);

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

    private function startSegment(): void
    {
        $this->sequenceNumber++;
        $this->continuityCounters = [];
        $file = $this->streamDir . "segment_{$this->sequenceNumber}.ts";
        $this->tsHandle = fopen($file, 'wb');

        $this->writePAT();
        $this->writePMT();

        // 每个切片开头写入 SPS/PPS
        // 确保浏览器解码器能获取到 extradata
        if ($this->spsPpsData !== '') {
            // 使用切片内时间 0 写入 SPS/PPS
            $spsPpsPes = $this->createPES(
                0xE0,                          // video stream
                $this->spsPpsData,             // SPS/PPS AnnexB 数据
                0,                             // PTS = 0
                0                              // DTS = 0
            );
            $this->writeTSPackets($this->videoPid, $spsPpsPes, true, 0);
        }
    }


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

    private function closeSegment2(int $endTime = 0): void
    {
        if ($this->tsHandle) {
            fclose($this->tsHandle);
            $this->tsHandle = null;

            // 计算实际时长（秒）
            $actualDuration = ($endTime - $this->segmentStartTime) / 1000.0;
            $actualDuration = max(0.001, round($actualDuration, 3));
            $this->segmentDurations[$this->sequenceNumber] = $actualDuration;

            $this->updatePlaylist();
        }
    }

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

        // 【新增】添加 CODECS 声明
        // avc1.64001f = H.264 High Profile, mp4a.40.2 = AAC-LC
        $lines[] = '#EXT-X-INDEPENDENT-SEGMENTS';

        for ($i = 1; $i <= $this->sequenceNumber; $i++) {
            $duration = $this->segmentDurations[$i] ?? $this->segmentDuration;
            // 在 EXTINF 中添加 CODECS 信息
            // 格式: #EXTINF:<duration>,<title>
            // 或者通过 EXT-X-MAP 等方式
            $lines[] = "#EXTINF:" . number_format($duration, 3, '.', '') . ",";
            $lines[] = "segment_{$i}.ts";
        }

        $m3u8Content = implode("\n", $lines) . "\n";

        $m3u8Path = $this->streamDir . "index.m3u8";
        $tmpPath = $m3u8Path . '.tmp';
        file_put_contents($tmpPath, $m3u8Content);
        rename($tmpPath, $m3u8Path);
    }

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

    public function getHlsUrl(): string
    {
        return "/hls/{$this->streamId}/index.m3u8";
    }
}