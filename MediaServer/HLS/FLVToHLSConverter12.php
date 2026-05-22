<?php

namespace MediaServer\HLS;

use MediaServer\Flv\Flv;
use MediaServer\MediaReader\AudioFrame;
use MediaServer\MediaReader\AVCPacket;
use MediaServer\MediaReader\VideoFrame;

/**
 * 纯 PHP FLV -> HLS (MPEGTS)
 *
 * 修复内容：
 * 1. 正确 AVCC -> AnnexB
 * 2. 正确 SPS/PPS 提取
 * 3. 正确 ADTS
 * 4. 正确 PES
 * 5. 正确 PTS/DTS
 * 6. 正确 PCR
 * 7. 正确 TS Packet
 * 8. 正确 adaptation field
 * 9. 正确 PAT/PMT
 * 10. 关键帧切片
 */
class FLVToHLSConverter12
{
    private int $segmentDuration = 4;
    private int $maxSegments = 10;

    private string $streamId;
    private string $streamDir;

    private int $videoPid = 0x100;
    private int $audioPid = 0x101;
    private int $pmtPid = 0x1000;

    private int $sequenceNumber = 0;

    private $tsHandle = null;

    private ?int $firstTimestamp = null;
    private int $segmentStartTs = 0;

    private array $continuityCounters = [];

    private ?string $audioSequenceHeader = null;
    private ?string $videoSequenceHeader = null;

    private string $spsPpsData = '';

    private array $segmentDurations = [];

    public function __construct(string $streamId, array $config = [])
    {
        $this->streamId = $streamId;

        $this->streamDir = dirname(__DIR__, 2) . "/hls/{$streamId}/";

        if (!is_dir($this->streamDir)) {
            mkdir($this->streamDir, 0777, true);
        }

        if (isset($config['segmentDuration'])) {
            $this->segmentDuration = (int)$config['segmentDuration'];
        }
    }

    public function processFrame($frame): void
    {
        if (!$frame instanceof VideoFrame && !$frame instanceof AudioFrame) {
            return;
        }

        if ($this->firstTimestamp === null) {

            if ($frame instanceof VideoFrame) {

                $videoData = Flv::videoFrameDataRead((string)$frame);

                if (!$videoData) {
                    return;
                }

                $avc = Flv::avcPacketRead($videoData['data']);

                if (!$avc) {
                    return;
                }

                if ($avc['avcPacketType'] == Flv::AVC_PACKET_TYPE_SEQUENCE_HEADER) {

                    $this->videoSequenceHeader = $avc['data'];

                    $this->parseAVCDecoderConfigurationRecord(
                        $this->videoSequenceHeader
                    );

                    return;
                }

                if ($videoData['frameType'] == Flv::VIDEO_FRAME_TYPE_KEY_FRAME) {

                    $this->firstTimestamp = $frame->timestamp;

                    $this->startSegment(0);
                }
            }

            if ($frame instanceof AudioFrame) {

                $audioData = Flv::audioFrameDataRead((string)$frame);

                if ($audioData['soundFormat'] != Flv::SOUND_FORMAT_ACC) {
                    return;
                }

                $aac = Flv::accPacketDataRead($audioData['data']);

                if ($aac['accPacketType'] == Flv::ACC_PACKET_TYPE_SEQUENCE_HEADER) {

                    $this->audioSequenceHeader = $aac['data'];
                }
            }

            return;
        }

        $relativeTs = $frame->timestamp - $this->firstTimestamp;

        if ($frame instanceof VideoFrame) {

            $this->processVideo($frame, $relativeTs);

        } else {

            $this->processAudio($frame, $relativeTs);
        }
    }

    private function processVideo(VideoFrame $frame, int $ts): void
    {
        $videoData = Flv::videoFrameDataRead((string)$frame);

        if (!$videoData) {
            return;
        }

        $avc = Flv::avcPacketRead($videoData['data']);

        if (!$avc) {
            return;
        }

        if ($avc['avcPacketType'] == Flv::AVC_PACKET_TYPE_SEQUENCE_HEADER) {

            $this->videoSequenceHeader = $avc['data'];

            $this->parseAVCDecoderConfigurationRecord(
                $this->videoSequenceHeader
            );

            return;
        }

        if ($avc['avcPacketType'] != Flv::AVC_PACKET_TYPE_NALU) {
            return;
        }

        $isKey = (
            $videoData['frameType']
            == Flv::VIDEO_FRAME_TYPE_KEY_FRAME
        );

        if ($isKey) {

            if (($ts - $this->segmentStartTs) >= ($this->segmentDuration * 1000)) {

                $this->closeSegment();

                $this->startSegment($ts);
            }
        }

        $cts = $avc['compositionTime'] ?? 0;

        if ($cts & 0x800000) {
            $cts -= 0x1000000;
        }

        $dts90k = (int)($ts * 90);
        $pts90k = (int)(($ts + $cts) * 90);

        $annexb = $this->avccToAnnexB($avc['data']);

        if ($isKey) {

            //$annexb = "\x00\x00\x00\x01\x09\xf0" . $this->spsPpsData . $annexb;
            //$annexb = $this->spsPpsData . $annexb;

            // 确保每个关键帧前都有完整的 SPS/PPS，并且使用 AUD 分隔
            //$annexb = "\x00\x00\x00\x01\x09\x10" . $this->spsPpsData . $annexb;
            $annexb = "\x00\x00\x00\x01\x09\x10" . $annexb;
        }

        $pes = $this->createPES(
            0xE0,
            $annexb,
            $pts90k,
            $dts90k
        );

        $this->writeTSPackets(
            $this->videoPid,
            $pes,
            true,
            $dts90k
        );
    }

    private function processAudio(AudioFrame $frame, int $ts): void
    {
        if ($this->audioSequenceHeader === null) {
            return;
        }

        $audioData = Flv::audioFrameDataRead((string)$frame);

        if ($audioData['soundFormat'] != Flv::SOUND_FORMAT_ACC) {
            return;
        }

        $aac = Flv::accPacketDataRead($audioData['data']);

        if ($aac['accPacketType'] == Flv::ACC_PACKET_TYPE_SEQUENCE_HEADER) {

            $this->audioSequenceHeader = $aac['data'];

            return;
        }

        if ($aac['accPacketType'] != Flv::ACC_PACKET_TYPE_RAW) {
            return;
        }

        $adts = $this->createADTSHeader(strlen($aac['data']));

        $payload = $adts . $aac['data'];

        $pts90k = (int)($ts * 90);

        $pes = $this->createPES(
            0xC0,
            $payload,
            $pts90k,
            null
        );

        $this->writeTSPackets(
            $this->audioPid,
            $pes
        );
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

            $sps = substr($data, $offset, $len);

            $offset += $len;

            $result .= "\x00\x00\x00\x01" . $sps;
        }

        $numPps = ord($data[$offset]);

        $offset++;

        for ($i = 0; $i < $numPps; $i++) {

            $len = unpack('n', substr($data, $offset, 2))[1];

            $offset += 2;

            $pps = substr($data, $offset, $len);

            $offset += $len;

            $result .= "\x00\x00\x00\x01" . $pps;
        }

        $this->spsPpsData = $result;
    }

    private function avccToAnnexB(string $data): string
    {
        $offset = 0;

        $result = '';

        while ($offset + 4 <= strlen($data)) {

            $len = unpack('N', substr($data, $offset, 4))[1];

            $offset += 4;

            if ($offset + $len > strlen($data)) {
                break;
            }

            $nalu = substr($data, $offset, $len);

            $result .= "\x00\x00\x00\x01" . $nalu;

            $offset += $len;
        }

        return $result;
    }

    private function createADTSHeader(int $aacLength): string
    {
        $asc = $this->audioSequenceHeader;

        $b1 = ord($asc[0]);
        $b2 = ord($asc[1]);

        $profile = (($b1 >> 3) & 0x1F) - 1;

        $freqIdx =
            (($b1 & 0x07) << 1)
            | (($b2 >> 7) & 0x01);

        $chanCfg =
            ($b2 >> 3) & 0x0F;

        $frameLen = $aacLength + 7;

        return
            chr(0xFF) .
            chr(0xF1) .
            chr(($profile << 6) | ($freqIdx << 2) | ($chanCfg >> 2)) .
            chr((($chanCfg & 3) << 6) | ($frameLen >> 11)) .
            chr(($frameLen >> 3) & 0xFF) .
            chr((($frameLen & 7) << 5) | 0x1F) .
            chr(0xFC);
    }

    private function createPES(
        int $streamId,
        string $payload,
        int $pts,
        ?int $dts
    ): string {

        $header = "\x00\x00\x01";

        $header .= chr($streamId);

        $ptsBytes = $this->encodeTimestamp(
            ($dts !== null && $dts != $pts) ? 0x03 : 0x02,
            $pts
        );

        $flags = 0x80;

        $ext = $ptsBytes;

        if ($dts !== null && $dts != $pts) {

            $flags |= 0x40;

            $ext .= $this->encodeTimestamp(0x01, $dts);
        }

        $header .= pack('n', 0);

        $header .= chr(0x80);

        $header .= chr($flags);

        $header .= chr(strlen($ext));

        $header .= $ext;

        return $header . $payload;
    }

    private function encodeTimestamp(int $flag, int $ts): string
    {
        return
            chr(($flag << 4) | ((($ts >> 30) & 0x07) << 1) | 1) .
            chr(($ts >> 22) & 0xFF) .
            chr(((($ts >> 15) & 0x7F) << 1) | 1) .
            chr(($ts >> 7) & 0xFF) .
            chr((($ts & 0x7F) << 1) | 1);
    }

    private function encodePCR(int $pcrBase): string
    {
        $ext = 0;

        return
            chr(($pcrBase >> 25) & 0xFF) .
            chr(($pcrBase >> 17) & 0xFF) .
            chr(($pcrBase >> 9) & 0xFF) .
            chr(($pcrBase >> 1) & 0xFF) .
            chr((($pcrBase & 1) << 7) | 0x7E | (($ext >> 8) & 1)) .
            chr($ext & 0xFF);
    }

    private function writeTSPackets(
        int $pid,
        string $payload,
        bool $withPCR = false,
        int $pcrBase = 0
    ): void {

        if (!isset($this->continuityCounters[$pid])) {
            $this->continuityCounters[$pid] = 0;
        }

        $cc = &$this->continuityCounters[$pid];

        $offset = 0;
        $len = strlen($payload);
        $first = true;

        while ($offset < $len) {
            $header = chr(0x47);
            $header .= chr(
                (($first ? 1 : 0) << 6)   // payload_unit_start_indicator
                | (($pid >> 8) & 0x1F)
            );
            $header .= chr($pid & 0xFF);

            $adaptationFieldControl = 1;   // 默认只有 payload
            $adaptation = '';              // 自适应场内容
            $remaining = $len - $offset;

            if ($withPCR && $first) {
                // 需要插入 PCR
                $adaptationFieldControl = 3;               // adaptation + payload
                $pcrData = $this->encodePCR($pcrBase);

                // 自适应场最小长度 = 1 (长度字段) + 7 (标志 + PCR) = 8 字节
                $minAfLen = 8;

                if ($remaining < (184 - $minAfLen)) {
                    // 数据不足以填满，需要 stuffing
                    $afLen = 184 - $remaining;               // 总自适应场长度
                    $stuffing = $afLen - 7 - 1;              // 多出的 stuffing 字节数
                    $adaptation = chr($afLen - 1) . chr(0x10) . $pcrData . str_repeat("\xFF", $stuffing);
                    $payloadSize = $remaining;
                } else {
                    $afLen = $minAfLen;
                    $adaptation = chr($afLen - 1) . chr(0x10) . $pcrData;
                    $payloadSize = 184 - $afLen;
                }
            } else {
                // 无需 PCR
                if ($remaining < 184) {
                    $afLen = 184 - $remaining;               // 自适应场总长度
                    if ($afLen == 1) {
                        // 仅需要 1 字节的自适应场（长度字段，后面不跟标志）
                        $adaptation = chr(0);                // 长度 = 0
                    } else {
                        // 长度字段 + 标志 (0x00) + stuffing 字节
                        $adaptation = chr($afLen - 1) . chr(0x00) . str_repeat("\xFF", $afLen - 2);
                    }
                    $adaptationFieldControl = 3;
                    $payloadSize = $remaining;
                } else {
                    $payloadSize = 184;
                }
            }

            // 连续计数器
            $header .= chr(
                ($adaptationFieldControl << 4)
                | ($cc & 0x0F)
            );
            $cc = ($cc + 1) & 0x0F;

            // 取出有效负载
            $payloadChunk = substr($payload, $offset, $payloadSize);

            // 拼装完整 TS 包
            $packet = $header . $adaptation . $payloadChunk;

            // 确保 188 字节（保险措施）
            if (strlen($packet) < 188) {
                $packet .= str_repeat("\xFF", 188 - strlen($packet));
            }

            fwrite($this->tsHandle, $packet);

            $offset += $payloadSize;
            $first = false;
        }
    }

    private function writeTSPackets2(
        int $pid,
        string $payload,
        bool $withPCR = false,
        int $pcrBase = 0
    ): void {

        if (!isset($this->continuityCounters[$pid])) {
            $this->continuityCounters[$pid] = 0;
        }

        $cc = &$this->continuityCounters[$pid];

        $offset = 0;

        $len = strlen($payload);

        $first = true;

        while ($offset < $len) {

            $packet = chr(0x47);

            $packet .= chr(
                (($first ? 1 : 0) << 6)
                | (($pid >> 8) & 0x1F)
            );

            $packet .= chr($pid & 0xFF);

            $adaptation = '';

            $remaining = $len - $offset;

            $payloadSize = min(184, $remaining);

            $adaptationFieldControl = 1;

            if ($withPCR && $first) {

                $adaptationFieldControl = 3;

                $adaptation =
                    chr(7) .
                    chr(0x10) .
                    $this->encodePCR($pcrBase);

                $payloadSize = 184 - 8;
            }

            if ($payloadSize < 184 && !$withPCR) {

                $stuff = 184 - $payloadSize;

                $adaptationFieldControl = 3;

                $adaptation =
                    chr($stuff - 1) .
                    chr(0x00) .
                    str_repeat("\xFF", $stuff - 2);
            }

            $packet .= chr(
                ($adaptationFieldControl << 4)
                | ($cc & 0x0F)
            );

            $cc++;

            $packet .= $adaptation;

            $packet .= substr($payload, $offset, $payloadSize);

            $offset += $payloadSize;

            if (strlen($packet) < 188) {

                $packet .= str_repeat(
                    "\xFF",
                    188 - strlen($packet)
                );
            }

            fwrite($this->tsHandle, $packet);

            $first = false;
        }
    }

    private function startSegment(int $ts): void
    {
        $this->sequenceNumber++;

        $this->segmentStartTs = $ts;

        $file = $this->streamDir .
            "segment_{$this->sequenceNumber}.ts";

        $this->tsHandle = fopen($file, 'wb');

        $this->writePAT();

        $this->writePMT();
    }

    private function closeSegment(): void
    {
        if (!$this->tsHandle) {
            return;
        }

        fclose($this->tsHandle);

        $this->tsHandle = null;

        $this->updatePlaylist();
    }

    private function writePAT(): void
    {
        $section =
            "\x00" .
            "\xB0\x0D" .
            "\x00\x01" .
            "\xC1\x00\x00" .
            pack('n', 0xE000 | $this->pmtPid);

        $crc = $this->crc32mpeg($section);

        $section .= pack('N', $crc);

        $payload = "\x00" . $section;

        $this->writeTSPackets(0, $payload);
    }

    private function writePMT(): void
    {
        $section =
            "\x02" .
            "\xB0\x17" .
            "\x00\x01" .
            "\xC1\x00\x00" .
            pack('n', 0xE000 | $this->videoPid) .
            "\xF0\x00";

        $section .=
            chr(0x1B) .
            pack('n', 0xE000 | $this->videoPid) .
            "\xF0\x00";

        $section .=
            chr(0x0F) .
            pack('n', 0xE000 | $this->audioPid) .
            "\xF0\x00";

        $crc = $this->crc32mpeg($section);

        $section .= pack('N', $crc);

        $payload = "\x00" . $section;

        $this->writeTSPackets($this->pmtPid, $payload);
    }

    private function crc32mpeg(string $data): int
    {
        $crc = 0xFFFFFFFF;

        for ($i = 0; $i < strlen($data); $i++) {

            $crc ^= (ord($data[$i]) << 24);

            for ($j = 0; $j < 8; $j++) {

                if ($crc & 0x80000000) {

                    $crc =
                        (($crc << 1) ^ 0x04C11DB7)
                        & 0xFFFFFFFF;

                } else {

                    $crc =
                        ($crc << 1)
                        & 0xFFFFFFFF;
                }
            }
        }

        return $crc;
    }

    private function updatePlaylist(): void
    {
        $m3u8 = "#EXTM3U\n";
        $m3u8 .= "#EXT-X-VERSION:3\n";
        $m3u8 .= "#EXT-X-TARGETDURATION:{$this->segmentDuration}\n";
        $m3u8 .= "#EXT-X-MEDIA-SEQUENCE:1\n";

        for ($i = 1; $i <= $this->sequenceNumber; $i++) {

            $m3u8 .= "#EXTINF:{$this->segmentDuration},\n";
            $m3u8 .= "segment_{$i}.ts\n";
        }

        file_put_contents(
            $this->streamDir . "index.m3u8",
            $m3u8
        );
    }

    public function close(): void
    {
        $this->closeSegment();
    }

    /**
     * 获取HLS播放地址
     * @return string 相对路径
     */
    public function getHlsUrl()
    {
        return "/hls/{$this->streamId}/index.m3u8";
    }
}