<?php

namespace MediaServer\HLS;

use MediaServer\Flv\Flv;
use MediaServer\MediaReader\AudioFrame;
use MediaServer\MediaReader\VideoFrame;
use MediaServer\Utils\BinaryStream;
use function count;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function fopen;
use function fwrite;
use function glob;
use function implode;
use function is_dir;
use function mkdir;
use function ord;
use function pack;
use function preg_match;
use function sprintf;
use function strlen;
use function substr;
use function time;

class FLVToHLSConverter2
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
    private $patPid = 0;

    private $videoCodecId = null;
    private $audioCodecId = null;
    private $videoSequenceHeader = null;
    private $audioSequenceHeader = null;

    private $tsFileHandle;
    private $sps = null;
    private $pps = null;

    public function __construct($streamId, $config = [])
    {
        $this->streamId = $streamId;
        $this->streamDir = dirname(__DIR__,2) . "/hls/{$streamId}/";

        if (isset($config['segmentDuration'])) {
            $this->segmentDuration = $config['segmentDuration'];
        }
        if (isset($config['maxSegments'])) {
            $this->maxSegments = $config['maxSegments'];
        }

        if (!is_dir($this->streamDir)) {
            mkdir($this->streamDir, 0777, true);
        }
    }

    public function processFrame($frame)
    {
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

    private function processVideoFrame(VideoFrame $frame, $relativeTime)
    {
        $videoData = Flv::videoFrameDataRead((string)$frame);

        if ($this->videoCodecId === null) {
            $this->videoCodecId = $videoData['codecId'];
        }

        if ($videoData['codecId'] == Flv::VIDEO_CODEC_ID_AVC) {
            $avcData = Flv::avcPacketRead($videoData['data']);

            if ($avcData['avcPacketType'] == Flv::AVC_PACKET_TYPE_SEQUENCE_HEADER) {
                $this->videoSequenceHeader = $avcData['data'];
                $this->parseSpsPps($avcData['data']);
                return;
            }

            $isKeyFrame = ($videoData['frameType'] == Flv::VIDEO_FRAME_TYPE_KEY_FRAME);

            // 确保第一个关键帧创建切片
            if ($isKeyFrame && !$this->tsFileHandle) {
                $this->startNewSegment($relativeTime);
            }

            if ($isKeyFrame && ($relativeTime - $this->lastKeyframeTimestamp) >= ($this->segmentDuration * 1000)) {
                $this->startNewSegment($relativeTime);
                $this->lastKeyframeTimestamp = $relativeTime;
            }

            if ($this->tsFileHandle) {
                $this->writeVideoToTS(
                    $avcData['data'],
                    $relativeTime,
                    $isKeyFrame
                );
            }
        }
    }

    private function parseSpsPps($avcSequenceHeader)
    {
        $this->sps = null;
        $this->pps = null;

        $stream = new BinaryStream($avcSequenceHeader);
        $stream->readRaw(5); // 跳过前5字节

        $spsCount = $stream->readTinyInt() & 0x1F;
        if ($spsCount > 0) {
            $spsLength = $stream->readInt16();
            $this->sps = $stream->readRaw($spsLength);
        }

        $ppsCount = $stream->readTinyInt();
        if ($ppsCount > 0) {
            $ppsLength = $stream->readInt16();
            $this->pps = $stream->readRaw($ppsLength);
        }
    }

    private function writeSpsPps($timestamp)
    {
        if (!$this->sps || !$this->pps) return;

        $pts = ($timestamp / 1000) * 90000;
        $nalData = "\x00\x00\x00\x01".$this->sps."\x00\x00\x00\x01".$this->pps;

        $this->writeVideoToTS($nalData, $timestamp, true);
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

    private function startNewSegment($timestamp)
    {
        if ($this->tsFileHandle) {
            fclose($this->tsFileHandle);
            $this->updateM3U8Playlist();
        }

        $this->sequenceNumber++;
        $this->currentSegmentFile = "{$this->streamDir}segment_{$this->sequenceNumber}.ts";
        $this->tsFileHandle = fopen($this->currentSegmentFile, 'wb');
        $this->segmentStartTime = $timestamp;

        $this->writePAT();
        $this->writePMT();

        if ($this->sps && $this->pps) {
            $this->writeSpsPps($timestamp);
        }
    }

    private function writePAT()
    {
        $patData = $this->createPAT();
        $this->writeTSPacket($this->patPid, $patData);
    }

    private function createPAT()
    {
        return pack('CCnCCnN',
            0x00, 0xB0, 13,       // 表头
            0x00, 0x01,            // 节目号
            0xE000 | $this->pmtPid, // PMT PID
            0                       // CRC32
        );
    }

    private function writePMT()
    {
        $pmtData = $this->createPMT();
        $this->writeTSPacket($this->pmtPid, $pmtData);
    }

    private function createPMT()
    {
        return pack('CCnCCnnCnCnN',
            0x02, 0xB0, 19,        // 表头
            0x00, 0x01,            // 节目号
            0xE000 | $this->videoPid, // PCR PID
            0,                     // 节目信息长度
            0x1B,                  // 视频流类型(H.264)
            0xE000 | $this->videoPid, // 视频PID
            0,                     // 视频描述符长度
            0x0F,                  // 音频流类型(AAC)
            0xE000 | $this->audioPid, // 音频PID
            0,                     // 音频描述符长度
            0                      // CRC32
        );
    }

    private function writeVideoToTS($videoData, $timestamp, $isKeyFrame)
    {
        $pts = ($timestamp / 1000) * 90000;

        if (substr($videoData, 0, 4) !== "\x00\x00\x00\x01") {
            $videoData = "\x00\x00\x00\x01".$videoData;
        }

        $pesData = $this->createPESPacket(
            0xE0,
            $videoData,
            $pts,
            $pts,
            $isKeyFrame
        );

        $this->writeTSPacket($this->videoPid, $pesData);
    }

    private function writeAudioToTS($audioData, $timestamp)
    {
        $pts = ($timestamp / 1000) * 90000;

        $pesData = $this->createPESPacket(
            0xC0,
            $audioData,
            $pts,
            $pts
        );

        $this->writeTSPacket($this->audioPid, $pesData);
    }

    private function createPESPacket($streamId, $payload, $pts, $dts, $isKeyFrame = false)
    {
        $pesHeader = pack('CCCC', 0x00, 0x00, 0x01, $streamId);
        $pesHeader .= pack('n', 0); // PES包长度(0表示可变长度)

        $flags1 = 0x80;
        $flags2 = 0x80; // 有PTS
        if ($dts != $pts) {
            $flags2 |= 0x40; // 有DTS
        }

        $pesHeader .= pack('CC', $flags1, $flags2);
        $pesHeader .= chr(5); // PES头长度(只有PTS)

        $pesHeader .= $this->encodeTimestamp(0x20 | (($pts >> 30) & 0x0E), $pts);

        return $pesHeader.$payload;
    }

    private function encodeTimestamp($prefix, $timestamp)
    {
        return pack('CCCCC',
            $prefix,
            ($timestamp >> 22) & 0xFF,
            (($timestamp >> 15) & 0xFF) | 0x01,
            ($timestamp >> 7) & 0xFF,
            (($timestamp << 1) & 0xFF) | 0x01
        );
    }

    private function writeTSPacket($pid, $payload)
    {
        $tsPacketSize = 188;
        $maxPayloadPerPacket = 184;
        $payloadLength = strlen($payload);

        static $continuityCounters = [0 => 0, 0x10 => 0, 0x100 => 0, 0x101 => 0];

        $numPackets = ceil($payloadLength / $maxPayloadPerPacket);

        for ($i = 0; $i < $numPackets; $i++) {
            $packetPayload = substr($payload, $i * $maxPayloadPerPacket, $maxPayloadPerPacket);
            $payloadLen = strlen($packetPayload);

            $tsHeader = pack('C', 0x47);
            $flags = ($i == 0) ? 0x40 : 0x00;
            $tsHeader .= pack('C', $flags);
            $tsHeader .= pack('n', $pid | 0x4000);

            $transportControl = 0x01;
            $adaptationFieldLength = 0;

            if ($payloadLen < $maxPayloadPerPacket) {
                $adaptationFieldLength = $maxPayloadPerPacket - $payloadLen;
                if ($adaptationFieldLength > 0) {
                    $transportControl |= 0x02;
                    if ($payloadLen == 0) {
                        $transportControl &= ~0x01;
                    }
                }
            }

            $tsHeader .= pack('C', ($transportControl << 4) | ($continuityCounters[$pid]++ % 16));

            if ($transportControl & 0x02) {
                $adaptationField = pack('C', $adaptationFieldLength);
                $adaptationField .= pack('C', 0x00);
                if ($adaptationFieldLength > 1) {
                    $adaptationField .= str_repeat("\x00", $adaptationFieldLength - 1);
                }
                $tsHeader .= $adaptationField;
            }

            $tsPacket = $tsHeader.$packetPayload;
            if (strlen($tsPacket) < $tsPacketSize) {
                $tsPacket .= str_repeat("\xFF", $tsPacketSize - strlen($tsPacket));
            }

            fwrite($this->tsFileHandle, $tsPacket);
        }
    }

    private function updateM3U8Playlist()
    {
        $m3u8Path = "{$this->streamDir}index.m3u8";
        $segments = glob("{$this->streamDir}segment_*.ts");
        sort($segments);

        $m3u8Content = "#EXTM3U\n";
        $m3u8Content .= "#EXT-X-VERSION:3\n";
        $m3u8Content .= "#EXT-X-TARGETDURATION:{$this->segmentDuration}\n";
        $m3u8Content .= "#EXT-X-MEDIA-SEQUENCE:1\n";

        foreach ($segments as $segment) {
            $m3u8Content .= "#EXTINF:{$this->segmentDuration},\n";
            $m3u8Content .= basename($segment)."\n";
        }

        file_put_contents($m3u8Path, $m3u8Content);
        $this->cleanupOldSegments();
    }

    private function cleanupOldSegments()
    {
        $segments = glob("{$this->streamDir}segment_*.ts");
        sort($segments);

        if (count($segments) > $this->maxSegments) {
            $oldSegments = array_slice($segments, 0, count($segments) - $this->maxSegments);
            foreach ($oldSegments as $segment) {
                unlink($segment);
            }
        }
    }

    public function getHlsUrl()
    {
        return "/hls/{$this->streamId}/index.m3u8";
    }
}