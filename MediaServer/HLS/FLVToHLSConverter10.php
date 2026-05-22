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

/**
 * FLV 转 HLS 转换器 - 最终稳定版
 * 音频使用 ADTS 封装，视频 Annex B 标准 TS 分包
 * @note 本方法有问题，可以识别出视频和音频数据，但是ffmpeg解析会有很多错误，播放显示马赛克
 */
class FLVToHLSConverter10
{
    private $segmentDuration = 4;
    private $maxSegments = 10000;
    private $streamId;
    private $streamDir;

    private $sequenceNumber = 0;
    private $currentSegmentFile;
    private $segmentStartTime = 0;
    private $firstTimestamp = null;
    private $lastKeyframeTimestamp = 0;

    private $videoPid = 0x100;
    private $pmtPid = 0x10;
    private $patPid = 0;
    private $audioPid = 0x101;

    private $videoSequenceHeader = null;
    private $audioSequenceHeader = null;

    private $videoCodecId = null;

    private $tsFileHandle = null;
    private $segmentDurations = [];

    private $continuityCounters = [];

    public $firstAudioSequenceHeader = null;
    public $firstVideoSequenceHeader = null;

    public function __construct($streamId, $config = [])
    {
        $this->streamId = $streamId;
        $this->streamDir = dirname(__DIR__, 2) . "/hls/{$streamId}/";

        $this->segmentDuration = isset($config['segmentDuration']) ? (int)$config['segmentDuration'] : 4;
        $this->maxSegments = isset($config['maxSegments']) ? (int)$config['maxSegments'] : 10000;

        if (!is_dir($this->streamDir)) {
            mkdir($this->streamDir, 0777, true);
        }
    }

    public function log(string $message)
    {
        file_put_contents($this->streamDir . date('Y_m_d') . ".log", $message . "\r\n", FILE_APPEND);
    }

    public function processFrame(mixed $frame)
    {
        if (!$frame instanceof VideoFrame && !$frame instanceof AudioFrame) {
            return;
        }

        if ($frame instanceof VideoFrame && $this->firstTimestamp === null) {
            $videoData = Flv::videoFrameDataRead((string)$frame);
            if (empty($videoData)) return;
            if ($videoData['frameType'] == Flv::VIDEO_FRAME_TYPE_KEY_FRAME) {
                $this->firstTimestamp = $frame->timestamp;
            }
        }

        if ($this->firstTimestamp === null) {
            if ($frame instanceof AudioFrame) {
                $audioData = Flv::audioFrameDataRead((string)$frame);
                if ($audioData['soundFormat'] != Flv::SOUND_FORMAT_ACC) return;
                $aacData = Flv::accPacketDataRead($audioData['data']);
                if ($aacData['accPacketType'] == Flv::ACC_PACKET_TYPE_SEQUENCE_HEADER) {
                    $this->audioSequenceHeader = $aacData['data'];
                    if ($this->firstAudioSequenceHeader == null) {
                        $this->firstAudioSequenceHeader = $aacData['data'];
                    }
                    return;
                }
            }
            if ($frame instanceof VideoFrame) {
                $videoData = Flv::videoFrameDataRead((string)$frame);
                if (empty($videoData)) return;
                $this->videoCodecId = $videoData['codecId'];
                if ($this->videoCodecId != Flv::VIDEO_CODEC_ID_AVC) {
                    throw new \RuntimeException("仅支持 H.264 编码");
                }
                $avcData = Flv::avcPacketRead($videoData['data']);
                if (empty($avcData)) return;
                if ($avcData['avcPacketType'] == Flv::AVC_PACKET_TYPE_SEQUENCE_HEADER) {
                    $this->videoSequenceHeader = $avcData['data'];
                    if ($this->firstVideoSequenceHeader == null) {
                        $this->firstVideoSequenceHeader = $avcData['data'];
                    }
                    return;
                }
            }
            return;
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
        if ($audioData['soundFormat'] != Flv::SOUND_FORMAT_ACC) return;
        $aacData = Flv::accPacketDataRead($audioData['data']);
        if ($aacData['accPacketType'] == Flv::ACC_PACKET_TYPE_SEQUENCE_HEADER) {
            $this->audioSequenceHeader = $aacData['data'];
            if ($this->firstAudioSequenceHeader == null) $this->firstAudioSequenceHeader = $aacData['data'];
            return;
        }
        if (!$this->tsFileHandle) return;
        if ($this->audioSequenceHeader === null) return;
        if ($aacData['accPacketType'] != Flv::ACC_PACKET_TYPE_RAW) return;
        $this->writeAudioToTS($aacData['data'], $relativeTime);
    }

    private function writeAudioToTS($aacData, $timestamp)
    {
        $pts = (int)round($timestamp / 1000 * 90000);
        // 添加 ADTS 头，使音频自包含参数信息
        $adtsHeader = $this->createADTSHeader(strlen($aacData));
        $frameWithAdts = $adtsHeader . $aacData;

        $pesData = $this->createPESPacket(0xC0, $frameWithAdts, $pts, $pts);
        $this->writeTSPackets($this->audioPid, $pesData);
    }

    private function createADTSHeader(int $aacDataLength)
    {
        if ($this->audioSequenceHeader === null || strlen($this->audioSequenceHeader) < 2) return "";
        $asc = $this->audioSequenceHeader;
        $asc1 = ord($asc[0]);
        $asc2 = ord($asc[1]);
        $audioObjectType = (($asc1 >> 3) & 0x1F);
        $samplingFreqIdx = (($asc1 & 0x07) << 1) | (($asc2 >> 7) & 0x01);
        $channelConfig = (($asc2 >> 3) & 0x0F);
        $adtsTotalLength = 7 + $aacDataLength;
        $adts = chr(0xFF) . chr(0xF1);
        $adts .= chr((($audioObjectType - 1) << 6) | ($samplingFreqIdx << 2) | (($channelConfig >> 2) & 0x01));
        $adts .= chr((($channelConfig & 0x03) << 6) | (($adtsTotalLength >> 11) & 0x03));
        $adts .= chr(($adtsTotalLength >> 3) & 0xFF);
        $adts .= chr((($adtsTotalLength & 0x07) << 5) | 0x1F);
        $adts .= chr(0xFC);
        return $adts;
    }

    private function processVideoFrame(VideoFrame $frame, $relativeTime)
    {
        $videoData = Flv::videoFrameDataRead((string)$frame);
        if (empty($videoData) || $videoData['codecId'] != Flv::VIDEO_CODEC_ID_AVC) return;
        $avcData = Flv::avcPacketRead($videoData['data']);
        if (empty($avcData)) return;
        if ($avcData['avcPacketType'] == Flv::AVC_PACKET_TYPE_SEQUENCE_HEADER) {
            $this->videoSequenceHeader = $avcData['data'];
            if ($this->firstVideoSequenceHeader == null) $this->firstVideoSequenceHeader = $avcData['data'];
            return;
        }
        if ($this->videoSequenceHeader === null) return;

        $isKeyFrame = ($videoData['frameType'] == Flv::VIDEO_FRAME_TYPE_KEY_FRAME);
        $cts = 0;
        if (method_exists($frame, 'getAVCPacket')) {
            $avcPack = $frame->getAVCPacket();
            if ($avcPack instanceof AVCPacket && property_exists($avcPack, 'compositionTime')) {
                $cts = $avcPack->compositionTime;
            }
        }
        if ($cts == 0 && isset($avcData['compositionTime'])) $cts = $avcData['compositionTime'];
        $dts = $relativeTime;
        $pts = $dts + $cts;

        if ($isKeyFrame) {
            $timeDiff = $relativeTime - $this->lastKeyframeTimestamp;
            if ($timeDiff >= $this->segmentDuration * 1000) {
                $avcPack = $frame->getAVCPacket();
                if ($avcPack->avcPacketType === AVCPacket::AVC_PACKET_TYPE_NALU) {
                    $this->startNewSegment($relativeTime);
                    $this->lastKeyframeTimestamp = $relativeTime;
                }
            }
        }

        if ($this->tsFileHandle) {
            $videoPayload = $isKeyFrame
                ? $this->toAnnexB($this->videoSequenceHeader) . $this->toAnnexB($avcData['data'])
                : $this->toAnnexB($avcData['data']);
            $this->writeVideoToTS($videoPayload, $pts, $dts, $isKeyFrame);
        }
    }

    private function toAnnexB($nalu)
    {
        $offset = 0;
        $result = '';
        while ($offset + 4 <= strlen($nalu)) {
            $naluLen = unpack('N', substr($nalu, $offset, 4))[1];
            $offset += 4;
            if ($offset + $naluLen > strlen($nalu)) break;
            $result .= "\x00\x00\x00\x01" . substr($nalu, $offset, $naluLen);
            $offset += $naluLen;
        }
        return $result;
    }

    private function startNewSegment($timestamp)
    {
        if ($this->tsFileHandle) {
            fclose($this->tsFileHandle);
            $duration = ($this->lastKeyframeTimestamp - $this->segmentStartTime) / 1000;
            $this->segmentDurations[$this->sequenceNumber] = round($duration, 3);
            $this->updateM3U8Playlist();
        }

        $this->sequenceNumber++;
        $this->currentSegmentFile = "{$this->streamDir}segment_{$this->sequenceNumber}.ts";
        $this->tsFileHandle = fopen($this->currentSegmentFile, 'wb');
        $this->segmentStartTime = $timestamp;
        $this->continuityCounters = [];

        $this->writePAT();
        $this->writePMT();
        $this->log("开启新切片 {$this->sequenceNumber}");
    }

    private function updateM3U8Playlist()
    {
        $m3u8Path = "{$this->streamDir}index.m3u8";
        $segments = glob("{$this->streamDir}segment_*.ts");
        sort($segments, SORT_NATURAL);
        if (count($segments) > $this->maxSegments) {
            $toDelete = array_slice($segments, 0, count($segments) - $this->maxSegments);
            foreach ($toDelete as $file) unlink($file);
            $segments = array_slice($segments, -$this->maxSegments);
        }

        $m3u8Content = "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:{$this->segmentDuration}\n";
        $m3u8Content .= "#EXT-X-MEDIA-SEQUENCE:" . max(1, $this->sequenceNumber - count($segments) + 1) . "\n";
        foreach ($segments as $segment) {
            $seq = intval(pathinfo($segment, PATHINFO_FILENAME));
            $duration = $this->segmentDurations[$seq] ?? $this->segmentDuration;
            $m3u8Content .= "#EXTINF:{$duration},\n" . basename($segment) . "\n";
        }
        file_put_contents($m3u8Path, $m3u8Content);
    }

    private function writePAT()
    {
        $pat = pack('C', 0x00);
        $pat .= pack('C', 0xB0);
        $pat .= pack('C', 0x0D);
        $pat .= pack('n', 0x0001);
        $pat .= pack('C', 0xC1);
        $pat .= pack('C', 0x00);
        $pat .= pack('n', 0xE000 | $this->pmtPid);
        $crc = $this->crc32mpeg(substr($pat, 0, 8));
        $pat .= pack('N', $crc);
        $this->writeTSPackets($this->patPid, $pat);
    }

    private function writePMT()
    {
        // 简单的 PMT，视频 H.264，音频 AAC，无额外描述符
        $pmt = pack('C', 0x02);
        $pmt .= pack('C', 0xB0);
        $pmt .= pack('C', 0x1D);           // section_length = 29
        $pmt .= pack('n', 0x0001);
        $pmt .= pack('C', 0xC1);
        $pmt .= pack('C', 0x00);
        $pmt .= pack('C', 0x00);
        $pmt .= pack('n', 0xE000 | $this->pmtPid);
        $pmt .= pack('n', 0xF000);         // program_info_length = 0
        // 视频流
        $pmt .= pack('C', 0x1B);
        $pmt .= pack('n', 0xE000 | $this->videoPid);
        $pmt .= pack('n', 0xF000);         // ES_info_length = 0
        // 音频流
        $pmt .= pack('C', 0x0F);
        $pmt .= pack('n', 0xE000 | $this->audioPid);
        $pmt .= pack('n', 0xF000);         // ES_info_length = 0
        $crc = $this->crc32mpeg(substr($pmt, 0, strlen($pmt)));
        $pmt .= pack('N', $crc);
        $this->writeTSPackets($this->pmtPid, $pmt);
    }

    private function writeVideoToTS($videoData, $pts, $dts, $isKeyFrame)
    {
        $pts90k = (int)round($pts / 1000 * 90000);
        $dts90k = (int)round($dts / 1000 * 90000);
        $pesData = $this->createPESPacket(0xE0, $videoData, $pts90k, $dts90k);
        $pcrBase = $pts90k * 300;
        $this->writeTSPackets($this->videoPid, $pesData, $isKeyFrame, true, $pcrBase);
    }

    private function createPESPacket($streamId, $payload, $pts, $dts)
    {
        $pesHeaderStart = "\x00\x00\x01" . chr($streamId);

        $ptsData = $this->encodeTimestamp(0x02, $pts);
        $headerData = $ptsData;
        $headerDataLength = strlen($headerData);

        if ($dts !== null && $dts !== $pts) {
            $dtsData = $this->encodeTimestamp(0x01, $dts);
            $headerData = $ptsData . $dtsData;
            $headerDataLength = strlen($headerData);
        }

        $flags = 0x80;
        if ($dts !== null && $dts !== $pts) {
            $flags |= 0x40;
        }

        $packetLength = 0; // 音视频均不声明长度

        $pesHeader = $pesHeaderStart
            . pack('n', $packetLength)
            . chr(0x80)
            . chr($flags)
            . chr($headerDataLength)
            . $headerData;

        return $pesHeader . $payload;
    }

    private function encodeTimestamp($flag, $ts)
    {
        $ts &= 0x1FFFFFFFF;
        $part1 = (($flag << 4) & 0xF0) | ((($ts >> 30) & 0x07) << 1) | 0x01;
        $part2 = ((($ts >> 15) & 0x7FFF) << 1) | 0x01;
        $part3 = (($ts & 0x7FFF) << 1) | 0x01;
        return pack('Cnn', $part1, $part2, $part3);
    }

    /**
     * 将 PES 数据分割成 188 字节 TS 包
     */
    private function writeTSPackets($pid, $payload, $isKeyFrame = false, $isVideo = false, $pcrBase = null)
    {
        $tsPacketSize = 188;
        $syncByte = 0x47;

        if (!isset($this->continuityCounters[$pid])) {
            $this->continuityCounters[$pid] = 0;
        }
        $cc = &$this->continuityCounters[$pid];

        $offset = 0;
        $len = strlen($payload);
        $first = true;

        while ($offset < $len) {
            $header = chr($syncByte);
            $pusi = $first ? 1 : 0;
            $header .= chr(($pusi << 6) | (($pid >> 8) & 0x1F));
            $header .= chr($pid & 0xFF);

            $afc = 1;
            $af = '';
            $maxPayload = $tsPacketSize - 4;

            if ($first && $isVideo && $isKeyFrame && $pcrBase !== null) {
                $afc = 3;
                $afLen = 8;
                $maxPayload -= $afLen;

                $stuffing = 0;
                $remaining = $len - $offset;
                if ($remaining < $maxPayload) {
                    $stuffing = $maxPayload - $remaining;
                    $afLen += $stuffing;
                    $maxPayload -= $stuffing;
                }

                $pcrBase33 = $pcrBase & 0x1FFFFFFFF;
                $pcrExt = 0;
                $af = chr($afLen);
                $af .= chr(0x10);
                $af .= pack('N', ($pcrBase33 << 1)) . chr(0);
                $af .= pack('n', $pcrExt << 7);
                $af .= str_repeat("\xFF", $stuffing);
            } else {
                $remaining = $len - $offset;
                if ($remaining < $maxPayload) {
                    $afc = 3;
                    $stuffing = $maxPayload - $remaining - 1;
                    $af = chr($stuffing + 1) . chr(0x00) . str_repeat("\xFF", $stuffing);
                    $maxPayload -= ($stuffing + 1);
                }
            }

            $cc = $cc & 0x0F;
            $header .= chr(($afc << 4) | $cc);
            $cc = ($cc + 1) & 0x0F;

            $chunkLen = min($len - $offset, $maxPayload);
            $chunk = substr($payload, $offset, $chunkLen);

            $packet = $header . $af . $chunk;

            if (strlen($packet) < $tsPacketSize) {
                $packet .= str_repeat("\xFF", $tsPacketSize - strlen($packet));
            }

            fwrite($this->tsFileHandle, $packet);
            $offset += $chunkLen;
            $first = false;
        }
    }

    private function crc32mpeg($data)
    {
        $crc = 0xFFFFFFFF;
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $crc ^= (ord($data[$i]) << 24);
            for ($j = 0; $j < 8; $j++) {
                if ($crc & 0x80000000) $crc = (($crc << 1) ^ 0x04C11DB7) & 0xFFFFFFFF;
                else $crc = ($crc << 1) & 0xFFFFFFFF;
            }
        }
        return $crc;
    }

    public function getHlsUrl()
    {
        return "/hls/{$this->streamId}/index.m3u8";
    }

    public function close()
    {
        if ($this->tsFileHandle) {
            fclose($this->tsFileHandle);
            $this->tsFileHandle = null;

            $m3u8Path = "{$this->streamDir}index.m3u8";
            $m3u8Content = file_get_contents($m3u8Path);
            if ($m3u8Content !== false) {
                $m3u8Content .= "#EXT-X-ENDLIST\n";
                file_put_contents($m3u8Path, $m3u8Content);
            }
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}