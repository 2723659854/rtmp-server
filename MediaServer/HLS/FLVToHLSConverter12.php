<?php

namespace MediaServer\HLS;

use MediaServer\Flv\Flv;
use MediaServer\MediaReader\AudioFrame;
use MediaServer\MediaReader\AVCPacket;
use MediaServer\MediaReader\MediaFrame;
use MediaServer\MediaReader\VideoFrame;
use function file_put_contents;
use function fclose;
use function fopen;
use function fwrite;
use function glob;
use function is_dir;
use function mkdir;
use function ord;
use function pack;
use function strlen;
use function substr;
use function unlink;

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
                if (!$videoData) return;
                $avc = Flv::avcPacketRead($videoData['data']);
                if (!$avc) return;
                if ($avc['avcPacketType'] == Flv::AVC_PACKET_TYPE_SEQUENCE_HEADER) {
                    $this->videoSequenceHeader = $avc['data'];
                    $this->parseAVCDecoderConfigurationRecord($this->videoSequenceHeader);
                    return;
                }
                if ($videoData['frameType'] == Flv::VIDEO_FRAME_TYPE_KEY_FRAME) {
                    $this->firstTimestamp = $frame->timestamp;
                    $this->startSegment(0);
                }
            }
            if ($frame instanceof AudioFrame) {
                $audioData = Flv::audioFrameDataRead((string)$frame);
                if ($audioData['soundFormat'] != Flv::SOUND_FORMAT_ACC) return;
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
        if (!$videoData) return;
        $avc = Flv::avcPacketRead($videoData['data']);
        if (!$avc) return;
        if ($avc['avcPacketType'] == Flv::AVC_PACKET_TYPE_SEQUENCE_HEADER) {
            $this->videoSequenceHeader = $avc['data'];
            $this->parseAVCDecoderConfigurationRecord($this->videoSequenceHeader);
            return;
        }
        if ($avc['avcPacketType'] != Flv::AVC_PACKET_TYPE_NALU) return;

        $isKey = ($videoData['frameType'] == Flv::VIDEO_FRAME_TYPE_KEY_FRAME);
        if ($isKey) {
            if (($ts - $this->segmentStartTs) >= ($this->segmentDuration * 1000)) {
                $this->closeSegment();
                $this->startSegment($ts);
            }
        }

        $cts = $avc['compositionTime'] ?? 0;
        if ($cts & 0x800000) $cts -= 0x1000000;
        $dts90k = (int)($ts * 90);
        $pts90k = (int)(($ts + $cts) * 90);

        $annexb = $this->avccToAnnexB($avc['data']);
        if ($isKey) {
            // 在每个关键帧前添加 AUD 和完整的 SPS/PPS，确保解码器能正确初始化
            $annexb = "\x00\x00\x00\x01\x09\x10" . $this->spsPpsData . $annexb;
        }

        $pes = $this->createPES(0xE0, $annexb, $pts90k, $dts90k);
        $this->writeTSPackets($this->videoPid, $pes, true, $dts90k);
    }

    private function processAudio(AudioFrame $frame, int $ts): void
    {
        if ($this->audioSequenceHeader === null) return;
        $audioData = Flv::audioFrameDataRead((string)$frame);
        if ($audioData['soundFormat'] != Flv::SOUND_FORMAT_ACC) return;
        $aac = Flv::accPacketDataRead($audioData['data']);
        if ($aac['accPacketType'] == Flv::ACC_PACKET_TYPE_SEQUENCE_HEADER) {
            $this->audioSequenceHeader = $aac['data'];
            return;
        }
        if ($aac['accPacketType'] != Flv::ACC_PACKET_TYPE_RAW) return;

        $adts = $this->createADTSHeader(strlen($aac['data']));
        $payload = $adts . $aac['data'];
        $pts90k = (int)($ts * 90);
        $pes = $this->createPES(0xC0, $payload, $pts90k, null);
        $this->writeTSPackets($this->audioPid, $pes);
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
            if ($offset + $len > strlen($data)) break;
            $result .= "\x00\x00\x00\x01" . substr($data, $offset, $len);
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
        $freqIdx = (($b1 & 0x07) << 1) | (($b2 >> 7) & 0x01);
        $chanCfg = ($b2 >> 3) & 0x0F;
        $frameLen = $aacLength + 7;
        return
            chr(0xFF) . chr(0xF1) .
            chr(($profile << 6) | ($freqIdx << 2) | ($chanCfg >> 2)) .
            chr((($chanCfg & 3) << 6) | ($frameLen >> 11)) .
            chr(($frameLen >> 3) & 0xFF) .
            chr((($frameLen & 7) << 5) | 0x1F) .
            chr(0xFC);
    }

    private function createPES(int $streamId, string $payload, int $pts, ?int $dts): string
    {
        $header = "\x00\x00\x01" . chr($streamId);
        $ptsBytes = $this->encodeTimestamp(($dts !== null && $dts != $pts) ? 0x03 : 0x02, $pts);
        $flags = 0x80;
        $ext = $ptsBytes;
        if ($dts !== null && $dts != $pts) {
            $flags |= 0x40;
            $ext .= $this->encodeTimestamp(0x01, $dts);
        }
        $header .= pack('n', 0);                    // PES_packet_length = 0 (unbounded for video)
        $header .= chr(0x80);                       // marker bits + scrambling_control + priority
        $header .= chr($flags);                     // PTS_DTS_flags
        $header .= chr(strlen($ext));
        $header .= $ext;
        return $header . $payload;
    }

    private function encodeTimestamp(int $flag, int $ts): string
    {
        // PTS/DTS 33-bit timestamp encoding
        return
            chr(($flag << 4) | ((($ts >> 30) & 0x07) << 1) | 1) .
            chr(($ts >> 22) & 0xFF) .
            chr(((($ts >> 15) & 0x7F) << 1) | 1) .
            chr(($ts >> 7) & 0xFF) .
            chr((($ts & 0x7F) << 1) | 1);
    }

    private function encodePCR(int $pcrBase): string
    {
        // PCR 33-bit base + 6-bit reserved + 9-bit extension
        $ext = 0;
        return
            chr(($pcrBase >> 25) & 0xFF) .
            chr(($pcrBase >> 17) & 0xFF) .
            chr(($pcrBase >> 9) & 0xFF) .
            chr(($pcrBase >> 1) & 0xFF) .
            chr((($pcrBase & 1) << 7) | 0x7E | (($ext >> 8) & 1)) .
            chr($ext & 0xFF);
    }

    private function writeTSPackets(int $pid, string $payload, bool $withPCR = false, int $pcrBase = 0): void
    {
        if (!isset($this->continuityCounters[$pid])) {
            $this->continuityCounters[$pid] = 0;
        }
        $cc = &$this->continuityCounters[$pid];

        $offset = 0;
        $len = strlen($payload);
        $first = true;

        while ($offset < $len) {
            $header = chr(0x47);
            $header .= chr((($first ? 1 : 0) << 6) | (($pid >> 8) & 0x1F));
            $header .= chr($pid & 0xFF);

            $afc = 1;           // no adaptation field
            $af = '';
            $remaining = $len - $offset;
            $payloadSize = 184; // default max payload

            if ($withPCR && $first) {
                $afc = 3;       // adaptation + payload
                $pcrBytes = $this->encodePCR($pcrBase);
                // adaptation field: 1 byte length + 1 byte flags + 6 bytes PCR
                $afLen = 8;     // total adaptation field length
                if ($remaining < (184 - $afLen)) {
                    // need stuffing
                    $stuffing = 184 - $remaining - $afLen;
                    $afLen += $stuffing;
                    $payloadSize = $remaining;
                    $af = chr($afLen - 1) . chr(0x10) . $pcrBytes . str_repeat("\xFF", $stuffing);
                } else {
                    $payloadSize = 184 - $afLen;
                    $af = chr(7) . chr(0x10) . $pcrBytes;
                }
            } else {
                if ($remaining < 184) {
                    // need stuffing to fill packet
                    $afc = 3;
                    $stuffing = 184 - $remaining;
                    if ($stuffing == 1) {
                        $af = chr(0);       // length = 0, no flags
                    } else {
                        $af = chr($stuffing - 1) . chr(0x00) . str_repeat("\xFF", $stuffing - 2);
                    }
                    $payloadSize = $remaining;
                }
            }

            $header .= chr(($afc << 4) | ($cc & 0x0F));
            $cc = ($cc + 1) & 0x0F;

            $dataChunk = substr($payload, $offset, $payloadSize);
            $packet = $header . $af . $dataChunk;
            if (strlen($packet) < 188) {
                $packet .= str_repeat("\xFF", 188 - strlen($packet));
            }
            fwrite($this->tsHandle, $packet);
            $offset += $payloadSize;
            $first = false;
        }
    }

    private function startSegment(int $ts): void
    {
        $this->sequenceNumber++;
        $this->segmentStartTs = $ts;
        $file = $this->streamDir . "segment_{$this->sequenceNumber}.ts";
        $this->tsHandle = fopen($file, 'wb');

        $this->writePAT();
        $this->writePMT();
    }

    private function closeSegment(): void
    {
        if (!$this->tsHandle) return;
        fclose($this->tsHandle);
        $this->tsHandle = null;
        $this->updatePlaylist();
    }

    private function writePAT(): void
    {
        $section = "\x00"; // table_id
        // section_length: 从下一个字节到 CRC 之前的字节数
        // 结构: transport_stream_id (2) + version/current_next/section/last (2) + program_number (2) + 2字节保留 + program_map_PID (2) + CRC (4)
        $section .= "\xB0\x09"; // section_length = 9
        $section .= "\x00\x01"; // transport_stream_id
        $section .= "\xC1\x00\x00"; // version, section_number, last_section_number
        $section .= "\x00\x01"; // program_number = 1
        $section .= pack('n', 0xE000 | $this->pmtPid); // program_map_PID
        $crc = $this->crc32mpeg($section);
        $section .= pack('N', $crc);

        // PAT 需要 pointer_field = 0
        $payload = "\x00" . $section;
        $this->writeTSPackets(0x00, $payload);
    }

    private function writePMT(): void
    {
        // 基础部分: program_number (2) + version/current_next/section/last (2) + PCR_PID (2) + program_info_length (2) = 8 字节
        // 视频流: stream_type (1) + elementary_PID (2) + ES_info_length (2) = 5 字节
        // 音频流: stream_type (1) + elementary_PID (2) + ES_info_length (2) = 5 字节
        // 总长度 = 8 + 5 + 5 = 18 字节，再加上 4 字节 CRC = 22 字节
        // section_length 从下一个字节开始，到 CRC 之前结束，所以 section_length = 18 (0x12)
        $pmt = "\x02";                          // table_id
        $pmt .= "\xB0\x12";                     // section_syntax_indicator + section_length = 18
        $pmt .= "\x00\x01";                     // program_number
        $pmt .= "\xC1\x00\x00";                 // version, section_number, last_section_number
        $pmt .= pack('n', 0xE000 | $this->videoPid) . "\xF0\x00"; // PCR_PID + program_info_length=0

        // 视频流
        $pmt .= chr(0x1B);                      // stream_type: H.264
        $pmt .= pack('n', 0xE000 | $this->videoPid);
        $pmt .= "\xF0\x00";                     // ES_info_length = 0

        // 音频流
        $pmt .= chr(0x0F);                      // stream_type: AAC
        $pmt .= pack('n', 0xE000 | $this->audioPid);
        $pmt .= "\xF0\x00";                     // ES_info_length = 0

        $crc = $this->crc32mpeg($pmt);
        $pmt .= pack('N', $crc);

        $payload = "\x00" . $pmt;               // pointer_field = 0
        $this->writeTSPackets($this->pmtPid, $payload);
    }



    private function crc32mpeg(string $data): int
    {
        $crc = 0xFFFFFFFF;
        for ($i = 0; $i < strlen($data); $i++) {
            $crc ^= (ord($data[$i]) << 24);
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
        $m3u8 = "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:{$this->segmentDuration}\n#EXT-X-MEDIA-SEQUENCE:1\n";
        for ($i = 1; $i <= $this->sequenceNumber; $i++) {
            $m3u8 .= "#EXTINF:{$this->segmentDuration},\nsegment_{$i}.ts\n";
        }
        file_put_contents($this->streamDir . "index.m3u8", $m3u8);
    }

    public function close(): void
    {
        $this->closeSegment();
    }

    public function getHlsUrl()
    {
        return "/hls/{$this->streamId}/index.m3u8";
    }
}