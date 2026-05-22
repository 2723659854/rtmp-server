<?php

namespace MediaServer\HLS;

use MediaServer\Flv\Flv;
use MediaServer\MediaReader\AudioFrame;
use MediaServer\MediaReader\AVCPacket;
use MediaServer\MediaReader\VideoFrame;

class FLVToHLSConverter13
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

    public function __construct(string $streamId, array $config = [])
    {
        $this->streamId = $streamId;
        $this->streamDir = dirname(__DIR__, 2) . "/hls/{$streamId}/";
        if (!is_dir($this->streamDir)) mkdir($this->streamDir, 0777, true);
        if (isset($config['segmentDuration'])) $this->segmentDuration = (int)$config['segmentDuration'];
    }

    public function processFrame($frame): void
    {
        if (!$frame instanceof VideoFrame && !$frame instanceof AudioFrame) return;

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
            if (($ts - $this->segmentStartTs) >= $this->segmentDuration * 1000) {
                $this->closeSegment();
                $this->startSegment($ts);
            }
        }

        $cts = $avc['compositionTime'] ?? 0;
        if ($cts & 0x800000) $cts -= 0x1000000;

        $dts = (int)($ts * 90);
        $pts = $dts + (int)($cts * 90);

        $annexb = $this->avccToAnnexB($avc['data']);

        // ====================== 修复 1：关键帧必须带 SPS/PPS ======================
        if ($isKey && $this->spsPpsData) {
            $annexb = $this->spsPpsData . $annexb;
        }

        // ====================== 修复 2：PES 头长度填 0（解决 PES size mismatch） ======================
        $pes = $this->createPES(0xE0, $annexb, $pts, $dts);
        $this->writeTSPackets($this->videoPid, $pes, true, $dts);
    }

    private function processAudio(AudioFrame $frame, int $ts): void
    {
        if (!$this->audioSequenceHeader) return;
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
        $pts = (int)($ts * 90);

        $pes = $this->createPES(0xC0, $payload, $pts, null);
        $this->writeTSPackets($this->audioPid, $pes);
    }

    private function parseAVCDecoderConfigurationRecord(string $data): void
    {
        $offset = 5;
        $numSps = ord($data[$offset]) & 0x1F;
        $offset++;
        $spsPps = '';

        for ($i = 0; $i < $numSps; $i++) {
            $len = unpack('n', substr($data, $offset, 2))[1];
            $offset += 2;
            $spsPps .= "\x00\x00\x00\x01" . substr($data, $offset, $len);
            $offset += $len;
        }

        $numPps = ord($data[$offset]);
        $offset++;
        for ($i = 0; $i < $numPps; $i++) {
            $len = unpack('n', substr($data, $offset, 2))[1];
            $offset += 2;
            $spsPps .= "\x00\x00\x00\x01" . substr($data, $offset, $len);
            $offset += $len;
        }

        $this->spsPpsData = $spsPps;
    }

    private function avccToAnnexB(string $data): string
    {
        $offset = 0;
        $res = '';
        while ($offset + 4 <= strlen($data)) {
            $len = unpack('N', substr($data, $offset, 4))[1];
            $offset += 4;
            if ($offset + $len > strlen($data)) break;
            $res .= "\x00\x00\x00\x01" . substr($data, $offset, $len);
            $offset += $len;
        }
        return $res;
    }

    private function createADTSHeader(int $aacLen): string
    {
        $asc = $this->audioSequenceHeader;
        $b1 = ord($asc[0]);
        $b2 = ord($asc[1]);

        $profile = (($b1 >> 3) & 0x1F) - 1;
        $freqIdx = (($b1 & 7) << 1) | (($b2 >> 7) & 1);
        $chanCfg = ($b2 >> 3) & 0x0F;
        if ($profile < 0) $profile = 1;

        $frameLen = $aacLen + 7;
        return pack('CCCCCCC',
            0xFF, 0xF1,
            ($profile << 6) | ($freqIdx << 2) | ($chanCfg >> 2),
            (($chanCfg & 3) << 6) | ($frameLen >> 11),
            ($frameLen >> 3) & 0xFF,
            (($frameLen & 7) << 5) | 0x1F,
            0xFC
        );
    }

    // ====================== 修复核心：PES 包头长度必须写 0x0000 ======================
    private function createPES(int $sid, string $payload, int $pts, ?int $dts): string
    {
        $header = "\x00\x00\x01" . chr($sid);

        // 🔥 终极修复：这里必须写 0，不写长度！解决 PES packet size mismatch
        $header .= "\x00\x00";

        $ptsData = $this->encodeTimestamp($dts !== null ? 0x03 : 0x02, $pts);
        $flags = 0x80;
        $extra = $ptsData;

        if ($dts !== null && $dts !== $pts) {
            $flags |= 0x40;
            $extra .= $this->encodeTimestamp(0x01, $dts);
        }

        $header .= chr(0x80) . chr($flags) . chr(strlen($extra)) . $extra;
        return $header . $payload;
    }

    private function encodeTimestamp(int $f, int $t): string
    {
        return pack('CCCCC',
            ($f << 4) | ((($t >> 30) & 7) << 1) | 1,
            ($t >> 22) & 0xFF,
            ((($t >> 15) & 0x7F) << 1) | 1,
            ($t >> 7) & 0xFF,
            (($t & 0x7F) << 1) | 1
        );
    }

    private function encodePCR(int $pcr): string
    {
        $ext = 0;
        return pack('CCCCCC',
            ($pcr >> 25) & 0xFF,
            ($pcr >> 17) & 0xFF,
            ($pcr >> 9) & 0xFF,
            ($pcr >> 1) & 0xFF,
            ((($pcr & 1) << 7) | 0x7E),
            $ext
        );
    }

    private function writeTSPackets(int $pid, string $payload, bool $pcr = false, int $pcrVal = 0): void
    {
        $cc = &$this->continuityCounters[$pid];
        $cc ??= 0;
        $offset = 0;
        $len = strlen($payload);
        $first = true;

        while ($offset < $len) {
            $ts = chr(0x47);
            $ts .= chr((($first ? 1 : 0) << 6) | (($pid >> 8) & 0x1F));
            $ts .= chr($pid & 0xFF);

            $adapt = '';
            $ctrl = 1;
            $chunkSize = min(184, $len - $offset);

            if ($pcr && $first) {
                $ctrl = 3;
                $adapt = chr(7) . chr(0x10) . $this->encodePCR($pcrVal);
                $chunkSize = 184 - 8;
            }

            if ($chunkSize < 184) {
                $ctrl = 3;
                $pad = 184 - $chunkSize;
                $adapt = $pad === 1 ? chr(0) : (chr($pad - 1) . chr(0) . str_repeat("\xFF", $pad - 2));
            }

            $ts .= chr(($ctrl << 4) | ($cc & 0x0F));
            $cc = ($cc + 1) & 0x0F;

            $chunk = substr($payload, $offset, $chunkSize);
            $ts .= $adapt . $chunk;
            $ts = str_pad($ts, 188, "\xFF");

            fwrite($this->tsHandle, $ts);
            $offset += $chunkSize;
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
        if ($this->tsHandle) {
            fclose($this->tsHandle);
            $this->tsHandle = null;
            $this->updatePlaylist();
        }
    }

    private function writePAT(): void
    {
        $sec = "\x00\xB0\x0D\x00\x01\xC1\x00\x00" . pack('n', 0xE000 | $this->pmtPid);
        $sec .= pack('N', $this->crc32mpeg($sec));
        $this->writeTSPackets(0, "\x00" . $sec);
    }

    private function writePMT(): void
    {
        $sec = "\x02\xB0\x17\x00\x01\xC1\x00\x00" . pack('n', 0xE000 | $this->videoPid) . "\xF0\x00";
        $sec .= chr(0x1B) . pack('n', 0xE000 | $this->videoPid) . "\xF0\x00";
        $sec .= chr(0x0F) . pack('n', 0xE000 | $this->audioPid) . "\xF0\x00";
        $sec .= pack('N', $this->crc32mpeg($sec));
        $this->writeTSPackets($this->pmtPid, "\x00" . $sec);
    }

    private function crc32mpeg(string $d): int
    {
        $crc = 0xFFFFFFFF;
        for ($i = 0; $i < strlen($d); $i++) {
            $crc ^= ord($d[$i]) << 24;
            for ($j = 0; $j < 8; $j++) {
                $crc = $crc & 0x80000000 ? (($crc << 1) ^ 0x04C11DB7) & 0xFFFFFFFF : ($crc << 1) & 0xFFFFFFFF;
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