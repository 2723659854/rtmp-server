<?php

namespace MediaServer\HLS;

use MediaServer\Flv\Flv;
use MediaServer\MediaReader\AudioFrame;
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

    private int $videoFrameCounter = 0;
    private int $audioFrameCounter = 0;
    private string $logDir;

    public function __construct(string $streamId, array $config = [])
    {
        $this->streamId = $streamId;
        $this->streamDir = dirname(__DIR__, 2) . "/hls/{$streamId}/";
        $this->logDir = $this->streamDir . "debug_logs/";

        if (!is_dir($this->streamDir)) mkdir($this->streamDir, 0777, true);
        if (!is_dir($this->logDir)) mkdir($this->logDir, 0777, true);
        if (isset($config['segmentDuration'])) $this->segmentDuration = (int)$config['segmentDuration'];
    }

    private function saveHexDump(string $prefix, int $counter, string $data, string $suffix = ""): void
    {
        $filename = sprintf("%sframe_%s_%04d%s.hex", $this->logDir, $prefix, $counter, $suffix);
        $hex = '';
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            if ($i > 0 && $i % 16 == 0) $hex .= "\n";
            elseif ($i > 0 && $i % 8 == 0) $hex .= " ";
            $hex .= sprintf("%02X ", ord($data[$i]));
        }
        $hex .= "\n\n# Total bytes: {$len}\n";
        file_put_contents($filename, $hex);
    }

    private function saveFrameLog(string $prefix, int $counter, array $meta): void
    {
        $filename = sprintf("%sframe_%s_%04d_meta.json", $this->logDir, $prefix, $counter);
        file_put_contents($filename, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
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
                    $this->saveHexDump("video", 0, $this->videoSequenceHeader, "_sequence_header");
                    return;
                }
                if ($videoData['frameType'] == Flv::VIDEO_FRAME_TYPE_KEY_FRAME) {
                    $this->firstTimestamp = $frame->timestamp;
                    $this->startSegment(0);
                }
            }

            if ($frame instanceof AudioFrame) {
                $this->handleAudioSequenceHeader($frame);
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

    private function handleAudioSequenceHeader(AudioFrame $frame): void
    {
        $raw = (string)$frame;
        if (strlen($raw) < 2) return;
        $soundFormat = (ord($raw[0]) >> 4) & 0x0F;
        if ($soundFormat != 10) return;

        $accPacketType = ord($raw[1]);
        if ($accPacketType == 0) {
            // AudioSpecificConfig 对于 AAC-LC 正常只有 2 字节，这里强制截取前 2 字节
            $asc = substr($raw, 2, 2);
            if (strlen($asc) == 2) {
                $this->audioSequenceHeader = $asc;
                $this->saveHexDump("audio", 0, $this->audioSequenceHeader, "_sequence_header");
            }
        }
    }

    private function processVideo(VideoFrame $frame, int $ts): void
    {
        $this->videoFrameCounter++;

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
        $cts = $avc['compositionTime'] ?? 0;
        if ($cts & 0x800000) $cts -= 0x1000000;

        $dts = (int)($ts * 90);
        $pts = $dts + (int)($cts * 90);

        $this->saveHexDump("video", $this->videoFrameCounter, $avc['data'], "_avcc_original");

        $annexb = $this->avccToAnnexB($avc['data']);

        // 关键帧已自带 SPS/PPS，无需手动添加
        $this->saveHexDump("video", $this->videoFrameCounter, $annexb, "_annexb_ts_payload");

        $this->saveFrameLog("video", $this->videoFrameCounter, [
            'frameIndex' => $this->videoFrameCounter,
            'timestamp_ms' => $ts,
            'isKey' => $isKey,
            'cts_raw' => $avc['compositionTime'] ?? 0,
            'cts_adjusted' => $cts,
            'dts_90khz' => $dts,
            'pts_90khz' => $pts,
            'pts_dts_diff' => $pts - $dts,
            'original_avcc_size' => strlen($avc['data']),
            'annexb_payload_size' => strlen($annexb),
            'frameType_raw' => $videoData['frameType'],
            'codecId' => $videoData['codecId'],
            'avcPacketType' => $avc['avcPacketType'],
            'segmentNumber' => $this->sequenceNumber,
        ]);

        if ($isKey) {
            if (($ts - $this->segmentStartTs) >= $this->segmentDuration * 1000) {
                $this->closeSegment();
                $this->startSegment($ts);
            }
        }

        $pes = $this->createPES(0xE0, $annexb, $pts, $dts);
        $this->writeTSPackets($this->videoPid, $pes, true, $dts);
    }

    private function processAudio(AudioFrame $frame, int $ts): void
    {
        $this->audioFrameCounter++;
        if (!$this->audioSequenceHeader) return;

        $raw = (string)$frame;
        if (strlen($raw) < 2) return;
        $soundFormat = (ord($raw[0]) >> 4) & 0x0F;
        if ($soundFormat != 10) return;

        $accPacketType = ord($raw[1]);
        if ($accPacketType == 0) {
            // 再次收到序列头时也仅保存前2字节ASC
            $asc = substr($raw, 2, 2);
            if (strlen($asc) == 2) {
                $this->audioSequenceHeader = $asc;
            }
            return;
        }
        if ($accPacketType != 1) return;

        $aacData = substr($raw, 2);
        if (strlen($aacData) < 2) return;

        // 过滤明显无效的数据（包含大量可打印字符的帧）
        if (preg_match('/^[\x20-\x7E]{4,}/', $aacData)) {
            return;
        }

        $this->saveHexDump("audio", $this->audioFrameCounter, $aacData, "_aac_raw");

        $adts = $this->createADTSHeader(strlen($aacData));
        $payload = $adts . $aacData;

        $this->saveHexDump("audio", $this->audioFrameCounter, $payload, "_adts_aac_ts_payload");

        $pts = (int)($ts * 90);

        $this->saveFrameLog("audio", $this->audioFrameCounter, [
            'frameIndex' => $this->audioFrameCounter,
            'timestamp_ms' => $ts,
            'pts_90khz' => $pts,
            'raw_aac_size' => strlen($aacData),
            'adts_header_size' => 7,
            'ts_payload_size' => strlen($payload),
            'soundFormat' => $soundFormat,
            'soundRate' => (ord($raw[0]) >> 2) & 0x03,
            'soundSize' => (ord($raw[0]) >> 1) & 0x01,
            'soundType' => ord($raw[0]) & 0x01,
            'accPacketType' => $accPacketType,
            'segmentNumber' => $this->sequenceNumber,
        ]);

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
        $this->saveHexDump("video", 0, $spsPps, "_sps_pps_annexb");
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
        if (strlen($asc) < 2) {
            return pack('CCCCCCC', 0xFF, 0xF1, 0x4C, 0x80, 0x20, 0x1F, 0xFC);
        }

        $b1 = ord($asc[0]);
        $b2 = ord($asc[1]);

        $profile = (($b1 >> 3) & 0x1F);
        $freqIdx = (($b1 & 0x07) << 1) | (($b2 >> 7) & 0x01);
        $chanCfg = ($b2 >> 3) & 0x0F;

        $adtsProfile = $profile - 1;
        if ($adtsProfile < 0) $adtsProfile = 0;

        $frameLen = $aacLen + 7;

        return pack('CCCCCCC',
            0xFF, 0xF1,
            ($adtsProfile << 6) | ($freqIdx << 2) | (($chanCfg >> 2) & 0x01),
            (($chanCfg & 0x03) << 6) | (($frameLen >> 11) & 0x03),
            ($frameLen >> 3) & 0xFF,
            (($frameLen & 0x07) << 5) | 0x1F,
            0xFC
        );
    }

    private function createPES(int $sid, string $payload, int $pts, ?int $dts): string
    {
        $header = "\x00\x00\x01" . chr($sid);
        $header .= ($sid == 0xC0) ? pack('n', strlen($payload)) : "\x00\x00";

        if ($dts !== null && $dts !== $pts) {
            $ptsData = $this->encodeTimestamp(0x02, $pts);
            $dtsData = $this->encodeTimestamp(0x01, $dts);
            $extra = $ptsData . $dtsData;
            $flags = 0xC0;
        } else {
            $ptsData = $this->encodeTimestamp(0x02, $pts);
            $extra = $ptsData;
            $flags = 0x80;
        }

        $header .= chr(0x80) . chr($flags) . chr(strlen($extra)) . $extra;
        return $header . $payload;
    }

    private function encodeTimestamp(int $flag, int $ts): string
    {
        $ts &= 0x1FFFFFFFF;
        return pack('CCCCC',
            (($flag << 4) & 0xF0) | ((($ts >> 30) & 0x07) << 1) | 0x01,
            ($ts >> 22) & 0xFF,
            ((($ts >> 15) & 0x7F) << 1) | 0x01,
            ($ts >> 7) & 0xFF,
            (($ts & 0x7F) << 1) | 0x01
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
            $ts  = chr(0x47);
            $ts .= chr((($first ? 1 : 0) << 6) | (($pid >> 8) & 0x1F));
            $ts .= chr($pid & 0xFF);

            $adapt = '';
            $ctrl = 1;
            $chunkSize = min(184, $len - $offset);

            if ($pcr && $first) {
                $ctrl = 3;
                $adaptContent = chr(0x10) . $this->encodePCR($pcrVal);
                $adapt = chr(7) . $adaptContent;
                $chunkSize = 184 - 8;
            }

            if ($chunkSize < 184 && $ctrl === 1) {
                $chunk = substr($payload, $offset, $chunkSize) . str_repeat("\xFF", 184 - $chunkSize);
                $ts .= chr(($ctrl << 4) | ($cc & 0x0F));
                $cc = ($cc + 1) & 0x0F;
                fwrite($this->tsHandle, $ts . $chunk);
                break;
            } elseif ($chunkSize < 184 && $ctrl === 3) {
                $pad = 184 - $chunkSize - strlen($adapt);
                if ($pad > 0) {
                    $adaptContent = chr(0x10) . $this->encodePCR($pcrVal);
                    $newLen = 7 + $pad;
                    $adapt = chr($newLen) . $adaptContent . str_repeat("\xFF", $pad);
                }
                $chunk = substr($payload, $offset, $chunkSize);
            } else {
                $chunk = substr($payload, $offset, $chunkSize);
            }

            $ts .= chr(($ctrl << 4) | ($cc & 0x0F));
            $cc = ($cc + 1) & 0x0F;
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
        $inner  = "\x00\x01";
        $inner .= "\xC1\x00\x00";
        $inner .= pack('n', 0xE000 | $this->pmtPid);
        $sectionLen = strlen($inner);

        $pat = "\x00" . chr(0xB0 | (($sectionLen >> 8) & 0x0F)) . chr($sectionLen & 0xFF) . $inner;
        $pat .= pack('N', $this->crc32mpeg($pat));

        $this->writeTSPackets(0, "\x00" . $pat);
    }

    private function writePMT(): void
    {
        $audioDesc = '';
        if (!empty($this->audioSequenceHeader)) {
            // 只使用正确的 2 字节 ASC
            $asc = $this->audioSequenceHeader;
            $audioDesc = chr(0x2B) . chr(2) . $asc;   // descriptor_tag 0x2B, length=2, ASC
        }

        $videoStream = chr(0x1B) . pack('n', 0xE000 | $this->videoPid) . "\xF0\x00";
        $audioStream = chr(0x0F) . pack('n', 0xE000 | $this->audioPid)
            . chr(0xF0 | ((strlen($audioDesc) >> 8) & 0x0F))
            . chr(strlen($audioDesc) & 0xFF)
            . $audioDesc;

        $inner  = "\x00\x01";
        $inner .= "\xC1\x00\x00\x00";
        $inner .= pack('n', 0xE000 | $this->videoPid);
        $inner .= "\xF0\x00";
        $inner .= $videoStream;
        $inner .= $audioStream;

        $sectionLen = strlen($inner);
        $pmt = "\x02" . chr(0xB0 | (($sectionLen >> 8) & 0x0F)) . chr($sectionLen & 0xFF) . $inner;
        $pmt .= pack('N', $this->crc32mpeg($pmt));

        $this->writeTSPackets($this->pmtPid, "\x00" . $pmt);
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

    public function close(): void { $this->closeSegment(); }
    public function getHlsUrl() { return "/hls/{$this->streamId}/index.m3u8"; }
}