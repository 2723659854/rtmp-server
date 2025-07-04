<?php

namespace MediaServer\HLS;


use MediaServer\Flv\Flv;
use MediaServer\MediaReader\AudioFrame;
use MediaServer\MediaReader\VideoFrame;
use function count;
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

/**
 * @purpose FLV到HLS转换器（视频+音频）
 * @command 检查切片是否正常的命令 ffprobe -v error -show_format -show_streams segment_1.ts
 */
class FLVToHLSConverter3
{
    private $segmentDuration = 4;
    private $maxSegments = 10;
    private $streamId;
    private $streamDir;

    private $sequenceNumber = 0;
    private $currentSegmentFile;
    private $segmentStartTime = 0;
    private $firstTimestamp = null;
    private $lastKeyframeTimestamp = 0;

    private $videoPid = 0x100;
    private $audioPid = 0x101;
    private $pmtPid = 0x10;
    private $patPid = 0x00;

    private $videoCodecId = null;
    private $videoSequenceHeader = null;

    private $audioCodecId = null;
    private $audioSequenceHeader = null;

    private $tsFileHandle = null;

    public function __construct($streamId, $config = [])
    {
        $this->streamId = $streamId;
        $this->streamDir = dirname(__DIR__, 2) . "/hls/{$streamId}/";

        if (isset($config['segmentDuration'])) {
            $this->segmentDuration = (int)$config['segmentDuration'];
        }
        if (isset($config['maxSegments'])) {
            $this->maxSegments = (int)$config['maxSegments'];
        }

        if (!is_dir($this->streamDir)) {
            mkdir($this->streamDir, 0777, true);
        }
    }

    public function processFrame($frame)
    {
        if (!$frame instanceof VideoFrame && !$frame instanceof AudioFrame) {
            return;
        }

        if ($this->firstTimestamp === null) {
            $this->firstTimestamp = $frame->timestamp;
        }

        $relativeTime = $frame->timestamp - $this->firstTimestamp;

        if ($frame instanceof VideoFrame) {
            $this->processVideoFrame($frame, $relativeTime);
        } elseif ($frame instanceof AudioFrame) {
            $this->processAudioFrame($frame, $relativeTime);
        }
    }

    private function processAudioFrame(AudioFrame $frame, $relativeTime)
    {
        $audioData = Flv::audioFrameDataRead((string)$frame);
        if ($this->audioCodecId === null) {
            $this->audioCodecId = $audioData['soundFormat'];
        }

        if ($audioData['soundFormat'] == Flv::SOUND_FORMAT_ACC) {
            $aacData = Flv::accPacketDataRead($audioData['data']);

            if ($aacData['accPacketType'] == Flv::ACC_PACKET_TYPE_SEQUENCE_HEADER) {
                $this->audioSequenceHeader = $aacData['data'];
                return;
            }

            if ($this->tsFileHandle) {
                $this->writeAudioToTS(
                    $aacData['data'],
                    $relativeTime
                );
            }
        }
    }

    private function writeAudioToTS($audioData, $timestamp)
    {
        // 通常需要在这里补 ADTS 头（示例中假设已有）
        $pts = ($timestamp / 1000) * 90000;

        $pesData = $this->createPESPacket(
            0xC0, // 音频流ID
            $audioData,
            $pts,
            $pts,
            false // 音频不需要关键帧标记
        );

        $this->writeTSPacket($this->audioPid, $pesData);
    }

    private function processVideoFrame(VideoFrame $frame, $relativeTime)
    {
        $videoData = Flv::videoFrameDataRead((string)$frame);
        $this->videoCodecId = $videoData['codecId'];

        if ($this->videoCodecId != Flv::VIDEO_CODEC_ID_AVC) {
            throw new \RuntimeException("仅支持H.264编码");
        }

        $avcData = Flv::avcPacketRead($videoData['data']);
        if ($avcData['avcPacketType'] == Flv::AVC_PACKET_TYPE_SEQUENCE_HEADER) {
            $this->videoSequenceHeader = $avcData['data'];
            return;
        }

        if ($this->videoSequenceHeader === null) {
            throw new \RuntimeException("未获取SPS/PPS");
        }

        $isKeyFrame = ($videoData['frameType'] == Flv::VIDEO_FRAME_TYPE_KEY_FRAME);

        if ($isKeyFrame) {
            $timeDiff = $relativeTime - $this->lastKeyframeTimestamp;
            if ($timeDiff >= $this->segmentDuration * 1000) {
                $this->startNewSegment($relativeTime);
                $this->lastKeyframeTimestamp = $relativeTime;
            }
        }

        if ($this->tsFileHandle) {
            $videoPayload = $isKeyFrame
                ? $this->toAnnexB($this->videoSequenceHeader) . $this->toAnnexB($avcData['data'])
                : $this->toAnnexB($avcData['data']);
            $this->writeVideoToTS($videoPayload, $relativeTime, $isKeyFrame);
        }
    }

    private function toAnnexB($nalu)
    {
        $offset = 0;
        $result = '';
        while ($offset + 4 <= strlen($nalu)) {
            $naluLen = unpack('N', substr($nalu, $offset, 4))[1];
            $offset += 4;
            $result .= "\x00\x00\x00\x01" . substr($nalu, $offset, $naluLen);
            $offset += $naluLen;
        }
        return $result;
    }

    private function startNewSegment($timestamp)
    {
        if ($this->tsFileHandle) {
            fclose($this->tsFileHandle);
            $this->tsFileHandle = null;
            $this->updateM3U8Playlist();
        }

        $this->sequenceNumber++;
        $this->currentSegmentFile = "{$this->streamDir}segment_{$this->sequenceNumber}.ts";
        $this->tsFileHandle = fopen($this->currentSegmentFile, 'wb');

        $this->segmentStartTime = $timestamp;

        $this->writePAT();
        $this->writePMT();
    }

    private function writePAT()
    {
        $patData = $this->createPAT();
        $this->writeTSPacket($this->patPid, $patData);
    }

    private function writePMT()
    {
        $pmtData = $this->createPMT();
        $this->writeTSPacket($this->pmtPid, $pmtData);
    }

    private function createPAT()
    {
        $pat = pack('C', 0x00);
        $pat .= pack('C', 0xB0);
        $pat .= pack('C', 0x0D);
        $pat .= pack('n', 0x0001);
        $pat .= pack('C', 0xC1);
        $pat .= pack('C', 0x00);
        $pat .= pack('n', 0xE000 | $this->pmtPid);
        $crc = $this->calculateCRC32(substr($pat, 0, 8));
        $pat .= pack('N', $crc);
        return $pat;
    }

    private function createPMT()
    {
        $pmt = pack('C', 0x02);
        $pmt .= pack('C', 0xB0);
        $pmt .= pack('C', 0x1A); // 视频+音频描述符
        $pmt .= pack('n', 0x0001);
        $pmt .= pack('C', 0xC1);
        $pmt .= pack('C', 0x00);
        $pmt .= pack('n', 0xE000 | $this->videoPid);
        $pmt .= pack('n', 0x0000);

        $pmt .= pack('C', 0x1B);
        $pmt .= pack('n', 0xE000 | $this->videoPid);
        $pmt .= pack('n', 0x0000);

        $pmt .= pack('C', 0x0F);
        $pmt .= pack('n', 0xE000 | $this->audioPid);
        $pmt .= pack('n', 0x0000);

        $crc = $this->calculateCRC32(substr($pmt, 0, 21));
        $pmt .= pack('N', $crc);
        return $pmt;
    }

    private function calculateCRC32($data)
    {
        $crc = 0xFFFFFFFF;
        $length = strlen($data);
        for ($i = 0; $i < $length; $i++) {
            $crc ^= (ord($data[$i]) << 24);
            for ($j = 0; $j < 8; $j++) {
                if ($crc & 0x80000000) {
                    $crc = (($crc << 1) & 0xFFFFFFFF) ^ 0x04C11DB7;
                } else {
                    $crc = ($crc << 1) & 0xFFFFFFFF;
                }
            }
        }
        return $crc ^ 0xFFFFFFFF;
    }

    private function writeVideoToTS($videoData, $timestamp, $isKeyFrame)
    {
        $pts = (int)($timestamp / 1000 * 90000);
        $dts = $pts;

        $pesData = $this->createPESPacket(
            0xE0,
            $videoData,
            $pts,
            $dts,
            $isKeyFrame
        );

        $this->writeTSPacket($this->videoPid, $pesData);
    }

    private function createPESPacket($streamId, $payload, $pts, $dts, $isKeyFrame = false)
    {
        $isAudio = ($streamId & 0xE0) === 0xC0;

        $pes = pack('CCC', 0x00, 0x00, 0x01);
        $pes .= pack('C', $streamId);
        $pes .= pack('n', 0); // 0表示未知长度
        $pes .= pack('C', 0x80);
        $pes .= pack('C', $isAudio ? 0x80 : 0xC0);
        $pes .= pack('C', $isAudio ? 0x05 : 0x0A);

        $pes .= $this->encodeTimestamp(0x2, $pts);
        if (!$isAudio) {
            $pes .= $this->encodeTimestamp(0x1, $dts);
        }

        return $pes . $payload;
    }

    private function encodeTimestamp($prefix, $ts)
    {
        return pack('C', ($prefix << 4) | (($ts >> 30 & 0x07) << 1) | 1)
            . pack('C', ($ts >> 22) & 0xFF)
            . pack('C', (($ts >> 15) & 0x7F) << 1 | 1)
            . pack('C', ($ts >> 7) & 0xFF)
            . pack('C', (($ts & 0x7F) << 1) | 1);
    }

    private function writeTSPacket($pid, $payload)
    {
        static $continuityCounters = [
            0x00 => 0,
            0x10 => 0,
            0x100 => 0,
            0x101 => 0
        ];

        $tsPacketSize = 188;
        $maxPayloadPerPacket = 184;
        $numPackets = ceil(strlen($payload) / $maxPayloadPerPacket);

        for ($i = 0; $i < $numPackets; $i++) {
            $packetPayload = substr($payload, $i * $maxPayloadPerPacket, $maxPayloadPerPacket);

            $tsHeader = pack('C', 0x47);

            $flags = ($i == 0) ? 0x40 : 0x00;
            $tsHeader .= pack('C', $flags);
            $tsHeader .= pack('n', $pid | 0x4000);

            $continuityCounter = $continuityCounters[$pid]++ % 16;

            if (strlen($packetPayload) < $maxPayloadPerPacket) {
                $adaptationLength = $maxPayloadPerPacket - strlen($packetPayload);
                $tsHeader .= pack('C', 0x30 | $continuityCounter);
                $tsHeader .= pack('C', $adaptationLength);
                $tsHeader .= pack('C', 0x00);
                $tsHeader .= str_repeat("\xFF", $adaptationLength - 1);
            } else {
                $tsHeader .= pack('C', 0x10 | $continuityCounter);
            }

            $tsPacket = $tsHeader . $packetPayload;
            $tsPacket = str_pad($tsPacket, $tsPacketSize, "\xFF");
            fwrite($this->tsFileHandle, $tsPacket);
        }
    }

    private function updateM3U8Playlist()
    {
        $m3u8Path = "{$this->streamDir}index.m3u8";
        $segments = glob("{$this->streamDir}segment_*.ts");
        sort($segments, SORT_NATURAL);

        $m3u8Content = "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:{$this->segmentDuration}\n";
        $m3u8Content .= "#EXT-X-MEDIA-SEQUENCE:{$this->sequenceNumber}\n";

        foreach ($segments as $segment) {
            $m3u8Content .= "#EXTINF:{$this->segmentDuration},\n" . basename($segment) . "\n";
        }

        file_put_contents($m3u8Path, $m3u8Content);
        $this->cleanupOldSegments();
    }

    private function cleanupOldSegments()
    {
        $segments = glob("{$this->streamDir}segment_*.ts");
        sort($segments, SORT_NATURAL);
        if (count($segments) > $this->maxSegments) {
            $oldSegments = array_slice($segments, 0, count($segments) - $this->maxSegments);
            foreach ($oldSegments as $s) {
                unlink($s);
            }
        }
    }

    public function close()
    {
        if ($this->tsFileHandle) {
            fclose($this->tsFileHandle);
            $this->tsFileHandle = null;

            $m3u8Path = "{$this->streamDir}index.m3u8";
            $m3u8Content = file_get_contents($m3u8Path);
            if ($m3u8Content) {
                $m3u8Content .= "#EXT-X-ENDLIST\n";
                file_put_contents($m3u8Path, $m3u8Content);
            }
        }
    }

    public function __destruct()
    {
        $this->close();
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
