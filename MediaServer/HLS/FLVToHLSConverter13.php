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

    // 日志计数器（音视频分开计数）
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

    /**
     * 将二进制数据保存为十六进制文本文件
     */
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

    /**
     * 保存JSON格式的帧元数据日志
     */
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
                $audioData = Flv::audioFrameDataRead((string)$frame);
                if ($audioData['soundFormat'] != Flv::SOUND_FORMAT_ACC) return;
                $aac = Flv::accPacketDataRead($audioData['data']);
                if ($aac['accPacketType'] == Flv::ACC_PACKET_TYPE_SEQUENCE_HEADER) {
                    $this->audioSequenceHeader = $aac['data'];
                    $this->saveHexDump("audio", 0, $this->audioSequenceHeader, "_sequence_header");
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

        // ============ 保存原始AVC数据（AVCC格式） ============
        $this->saveHexDump("video", $this->videoFrameCounter, $avc['data'], "_avcc_original");

        // ============ 转换为AnnexB格式 ============
        $annexb = $this->avccToAnnexB($avc['data']);

        // 【修复1】FLV原始数据中已经包含SPS/PPS，不需要手动添加
        // 删除这行：if ($isKey && $this->spsPpsData) { $annexb = $this->spsPpsData . $annexb; }

        // ============ 保存将要写入TS的AnnexB数据 ============
        $this->saveHexDump("video", $this->videoFrameCounter, $annexb, "_annexb_ts_payload");

        // ============ 保存帧元数据 ============
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
        $audioData = Flv::audioFrameDataRead((string)$frame);
        if ($audioData['soundFormat'] != Flv::SOUND_FORMAT_ACC) return;
        $aac = Flv::accPacketDataRead($audioData['data']);

        // ============ 保存原始AAC数据 ============
        $this->saveHexDump("audio", $this->audioFrameCounter, $aac['data'], "_aac_raw");

        if ($aac['accPacketType'] == Flv::ACC_PACKET_TYPE_SEQUENCE_HEADER) {
            $this->audioSequenceHeader = $aac['data'];
            return;
        }
        if ($aac['accPacketType'] != Flv::ACC_PACKET_TYPE_RAW) return;

        // 【修复2】正确创建 ADTS 头
        $adts = $this->createADTSHeader(strlen($aac['data']));
        $payload = $adts . $aac['data'];

        // ============ 保存ADTS+AAC数据 ============
        $this->saveHexDump("audio", $this->audioFrameCounter, $payload, "_adts_aac_ts_payload");

        $pts = (int)($ts * 90);

        // ============ 保存音频帧元数据 ============
        $this->saveFrameLog("audio", $this->audioFrameCounter, [
            'frameIndex' => $this->audioFrameCounter,
            'timestamp_ms' => $ts,
            'pts_90khz' => $pts,
            'raw_aac_size' => strlen($aac['data']),
            'adts_header_size' => 7,
            'ts_payload_size' => strlen($payload),
            'soundFormat' => $audioData['soundFormat'],
            'soundRate' => $audioData['soundRate'],
            'soundSize' => $audioData['soundSize'],
            'soundType' => $audioData['soundType'],
            'accPacketType' => $aac['accPacketType'],
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

    /**
     * 【修复2】正确创建 ADTS 头
     * 使用 AudioSpecificConfig (ASC) 计算正确的参数
     */
    private function createADTSHeader(int $aacLen): string
    {
        $asc = $this->audioSequenceHeader;
        if (strlen($asc) < 2) {
            // 默认参数：AAC LC, 48000Hz, 立体声
            return pack('CCCCCCC', 0xFF, 0xF1, 0x4C, 0x80, 0x20, 0x1F, 0xFC);
        }

        $b1 = ord($asc[0]);
        $b2 = ord($asc[1]);

        // 从 ASC 提取参数
        $profile = (($b1 >> 3) & 0x1F);           // 5 bits
        $freqIdx = (($b1 & 0x07) << 1) | (($b2 >> 7) & 0x01); // 4 bits
        $chanCfg = ($b2 >> 3) & 0x0F;             // 4 bits

        // ADTS 中 profile 需要减1
        $adtsProfile = $profile - 1;
        if ($adtsProfile < 0) $adtsProfile = 0;

        $frameLen = $aacLen + 7;  // AAC数据 + 7字节ADTS头

        return pack('CCCCCCC',
            0xFF, 0xF1,  // 同步字
            ($adtsProfile << 6) | ($freqIdx << 2) | (($chanCfg >> 2) & 0x01),  // 第3字节
            (($chanCfg & 0x03) << 6) | (($frameLen >> 11) & 0x03),             // 第4字节
            ($frameLen >> 3) & 0xFF,                                            // 第5字节
            (($frameLen & 0x07) << 5) | 0x1F,                                  // 第6字节
            0xFC                                                                  // 第7字节
        );
    }

    /**
     * 【修复3】正确创建 PES 包
     */
    private function createPES(int $sid, string $payload, int $pts, ?int $dts): string
    {
        $header = "\x00\x00\x01" . chr($sid);
        $header .= "\x00\x00";  // PES_packet_length = 0（视频无界，音频也设为0避免错误）

        // 判断是否有 DTS
        if ($dts !== null && $dts !== $pts) {
            // PTS + DTS
            $ptsData = $this->encodeTimestamp(0x02, $pts);  // PTS 标志 0x02
            $dtsData = $this->encodeTimestamp(0x01, $dts);  // DTS 标志 0x01
            $extra = $ptsData . $dtsData;
            $flags = 0xC0;  // PTS_DTS_flags = 11
        } else {
            // 只有 PTS
            $ptsData = $this->encodeTimestamp(0x02, $pts);
            $extra = $ptsData;
            $flags = 0x80;  // PTS_DTS_flags = 10
        }

        $header .= chr(0x80) . chr($flags) . chr(strlen($extra)) . $extra;
        return $header . $payload;
    }

    /**
     * 【修复3】正确编码 PTS/DTS 时间戳
     * @param int $flag 时间戳标志：0x02=PTS, 0x01=DTS
     * @param int $ts 33位时间戳值（90kHz）
     * @return string 5字节编码
     */
    private function encodeTimestamp(int $flag, int $ts): string
    {
        $ts &= 0x1FFFFFFFF;  // 确保33位
        return pack('CCCCC',
            (($flag << 4) & 0xF0) | ((($ts >> 30) & 0x07) << 1) | 0x01,  // 第1字节
            ($ts >> 22) & 0xFF,                                              // 第2字节
            ((($ts >> 15) & 0x7F) << 1) | 0x01,                             // 第3字节
            ($ts >> 7) & 0xFF,                                               // 第4字节
            (($ts & 0x7F) << 1) | 0x01                                       // 第5字节
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

    public function close(): void { $this->closeSegment(); }
    public function getHlsUrl() { return "/hls/{$this->streamId}/index.m3u8"; }
}