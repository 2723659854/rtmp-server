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
use function ord;
use function pack;
use function strlen;
use function substr;
use function unlink;

/**
 * FLV到HLS转换器（支持音视频）
 * 功能：将FLV格式的H.264视频流和AAC音频流转换为HLS格式
 */
class FLVToHLSConverter5
{
    // 配置参数
    private $segmentDuration = 4;       // 切片时长(秒)
    private $maxSegments = 10;          // 最大保留切片数
    private $streamId;                  // 流ID
    private $streamDir;                 // 流目录

    // 状态变量
    private $sequenceNumber = 0;        // 切片序号
    private $currentSegmentFile;        // 当前切片文件路径
    private $segmentStartTime = 0;      // 当前切片开始时间（毫秒）
    private $firstTimestamp = null;     // 首个帧时间戳
    private $lastKeyframeTimestamp = 0; // 上一个关键帧时间戳（毫秒）

    // TS流参数
    private $videoPid = 0x100;          // 视频PID
    private $pmtPid = 0x10;             // PMT表PID
    private $patPid = 0;                // PAT表PID
    private $audioPid = 0x101;          // 音频PID

    // 视频元数据
    private $videoCodecId = null;       // 视频编码ID
    private $videoSequenceHeader = null;// 视频序列头

    // 音频元数据
    private $audioCodecId = null;       // 音频编码ID
    private $audioSequenceHeader = null;// 音频序列头

    // 文件句柄
    private $tsFileHandle = null;       // 当前TS文件句柄

    // 新增状态标记
    private $isFirstSegment = true;     // 是否为第一个切片
    private $pendingAudioFrames = [];   // 暂存的音频帧
    private $segmentDurations = [];     // 切片时长记录
    private $lastPCR = 0;               // 最后一个PCR值

    public function __construct($streamId, $config = [])
    {
        $this->streamId = $streamId;
        $this->streamDir = dirname(__DIR__, 2) . "/hls/{$streamId}/";

        // 应用配置
        if (isset($config['segmentDuration']) && $config['segmentDuration'] > 0) {
            $this->segmentDuration = (int)$config['segmentDuration'];
        }
        if (isset($config['maxSegments']) && $config['maxSegments'] > 0) {
            $this->maxSegments = (int)$config['maxSegments'];
        }

        // 初始化目录
        if (!is_dir($this->streamDir)) {
            if (!mkdir($this->streamDir, 0777, true)) {
                throw new \RuntimeException("无法创建流目录: {$this->streamDir}");
            }
        }
    }

    public function processFrame($frame)
    {
        if (!$frame instanceof VideoFrame && !$frame instanceof AudioFrame) {
            return;
        }

        // 初始化首个时间戳
        if ($this->firstTimestamp === null) {
            $this->firstTimestamp = $frame->timestamp;
        }

        $relativeTime = $frame->timestamp - $this->firstTimestamp;

        if ($frame instanceof VideoFrame) {
            $this->processVideoFrame($frame, $relativeTime);
        } elseif ($frame instanceof AudioFrame) {
            if ($this->isFirstSegment && $this->segmentStartTime === 0) {
                $this->pendingAudioFrames[] = [
                    'frame' => $frame,
                    'relativeTime' => $relativeTime
                ];
                return;
            }
            $this->processAudioFrame($frame, $relativeTime);
        }
    }

    private function processAudioFrame(AudioFrame $frame, $relativeTime)
    {
        $audioData = Flv::audioFrameDataRead((string)$frame);
        if ($audioData['soundFormat'] != Flv::SOUND_FORMAT_ACC) {
            return;
        }

        $aacData = Flv::accPacketDataRead($audioData['data']);
        if ($aacData['accPacketType'] == Flv::ACC_PACKET_TYPE_SEQUENCE_HEADER) {
            $this->audioSequenceHeader = $aacData['data'];
            return;
        }

        if ($this->audioSequenceHeader && $aacData['accPacketType'] == Flv::ACC_PACKET_TYPE_RAW) {
            $adtsHeader = $this->createADTSHeader(strlen($aacData['data']));
            $frameWithAdts = $adtsHeader . $aacData['data'];

            $pts = (int)($relativeTime / 1000 * 90000);
            $pesData = $this->createPESPacket(0xC0, $frameWithAdts, $pts, $pts);
            $this->writeTSPacket($this->audioPid, $pesData, false, false);
        }
    }

    private function createADTSHeader($aacDataLength)
    {
        if ($this->audioSequenceHeader === null) {
            throw new \RuntimeException("缺少音频序列头");
        }

        $asc = $this->audioSequenceHeader;
        if (strlen($asc) < 2) {
            throw new \RuntimeException("无效的音频序列头");
        }
        $asc1 = ord($asc[0]);
        $asc2 = ord($asc[1]);

        $audioObjectType = (($asc1 >> 3) & 0x1F);
        $samplingFreqIdx = (($asc1 & 0x07) << 1) | (($asc2 >> 7) & 0x01);
        $channelConfig = (($asc2 >> 3) & 0x0F);

        $adtsTotalLength = 7 + $aacDataLength;
        if ($adtsTotalLength > 0x1FFF) {
            throw new \RuntimeException("AAC帧过长");
        }

        $adts = chr(0xFF);
        $adts .= chr(0xF1);
        $adts .= chr(
            (($audioObjectType - 1) << 6) |
            ($samplingFreqIdx << 2) |
            (($channelConfig >> 2) & 0x01)
        );
        $adts .= chr(
            (($channelConfig & 0x03) << 6) |
            (($adtsTotalLength >> 11) & 0x03)
        );
        $adts .= chr(($adtsTotalLength >> 3) & 0xFF);
        $adts .= chr((($adtsTotalLength & 0x07) << 5) | 0x1F);
        $adts .= chr(0xFC);

        return $adts;
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

    private function processVideoFrame(VideoFrame $frame, $relativeTime)
    {
        $videoData = Flv::videoFrameDataRead((string)$frame);
        if (empty($videoData)) {
            throw new \RuntimeException("无法解析视频帧数据");
        }

        $this->videoCodecId = $videoData['codecId'];
        if ($this->videoCodecId != Flv::VIDEO_CODEC_ID_AVC) {
            throw new \RuntimeException("仅支持H.264编码");
        }

        $avcData = Flv::avcPacketRead($videoData['data']);
        if (empty($avcData)) {
            throw new \RuntimeException("无法解析AVC帧数据");
        }

        if ($avcData['avcPacketType'] == Flv::AVC_PACKET_TYPE_SEQUENCE_HEADER) {
            $this->videoSequenceHeader = $avcData['data'];
            return;
        }

        if ($this->videoSequenceHeader === null) {
            throw new \RuntimeException("未获取视频序列头");
        }

        $isKeyFrame = ($videoData['frameType'] == Flv::VIDEO_FRAME_TYPE_KEY_FRAME);

        if ($isKeyFrame) {
            $timeDiff = $relativeTime - $this->lastKeyframeTimestamp;
            if ($timeDiff >= $this->segmentDuration * 1000) {
                $this->startNewSegment($relativeTime);
                if ($this->isFirstSegment) {
                    $this->isFirstSegment = false;
                    foreach ($this->pendingAudioFrames as $audioFrameData) {
                        $this->processAudioFrame($audioFrameData['frame'], $audioFrameData['relativeTime']);
                    }
                    $this->pendingAudioFrames = [];
                }
            }
        }

        if ($this->tsFileHandle) {
            $videoPayload = $isKeyFrame
                ? $this->toAnnexB($this->videoSequenceHeader) . $this->toAnnexB($avcData['data'])
                : $this->toAnnexB($avcData['data']);

            $this->writeVideoToTS($videoPayload, $relativeTime, $isKeyFrame);
        }
    }

    private function startNewSegment($timestamp)
    {
        if ($this->tsFileHandle) {
            fclose($this->tsFileHandle);
            $this->tsFileHandle = null;

            $duration = ($timestamp - $this->segmentStartTime) / 1000;
            $this->segmentDurations[$this->sequenceNumber] = round($duration, 3);
            $this->updateM3U8Playlist();
        }

        $this->sequenceNumber++;
        $this->currentSegmentFile = "{$this->streamDir}segment_{$this->sequenceNumber}.ts";
        $this->tsFileHandle = fopen($this->currentSegmentFile, 'wb');
        if ($this->tsFileHandle === false) {
            throw new \RuntimeException("无法创建TS文件: {$this->currentSegmentFile}");
        }

        $this->segmentStartTime = $timestamp;
        $this->lastKeyframeTimestamp = $timestamp;
        $this->lastPCR = 0;

        $this->writePAT();
        $this->writePMT();
    }

    private function updateM3U8Playlist()
    {
        $m3u8Path = "{$this->streamDir}index.m3u8";
        $segments = glob("{$this->streamDir}segment_*.ts");
        sort($segments, SORT_NATURAL);

        // 清理旧切片
        while (count($segments) > $this->maxSegments) {
            $oldest = array_shift($segments);
            unlink($oldest);
        }

        $m3u8Content = "#EXTM3U\n";
        $m3u8Content .= "#EXT-X-VERSION:3\n";
        $m3u8Content .= "#EXT-X-TARGETDURATION:{$this->segmentDuration}\n";
        $m3u8Content .= "#EXT-X-MEDIA-SEQUENCE:" . max(1, $this->sequenceNumber - count($segments)) . "\n";

        foreach ($segments as $segment) {
            $seq = intval(substr(basename($segment), 8, -3));
            $duration = $this->segmentDurations[$seq] ?? $this->segmentDuration;
            $m3u8Content .= "#EXTINF:{$duration},\n";
            $m3u8Content .= basename($segment) . "\n";
        }

        file_put_contents($m3u8Path, $m3u8Content);
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
        $pat = pack('C', 0x00);                  // 表ID
        $pat .= pack('C', 0xB0);                 // 标志位
        $pat .= pack('C', 0x0D);                 // 段长度
        $pat .= pack('n', 0x0001);               // 节目号
        $pat .= pack('C', 0xC1);                 // 版本号
        $pat .= pack('C', 0x00);                 // 段号
        $pat .= pack('n', 0xE000 | $this->pmtPid); // PMT的PID
        $crc = $this->crc32mpeg(substr($pat, 0, 8));
        $pat .= pack('N', $crc);

        return $pat;
    }

    private function createPMT()
    {
        $pmt = pack('C', 0x02);                  // 表ID
        $pmt .= pack('C', 0xB0);                 // 标志位
        $pmt .= pack('C', 0x1C);                 // 段长度
        $pmt .= pack('n', 0x0001);               // 节目号
        $pmt .= pack('C', 0xC1);                 // 版本号
        $pmt .= pack('C', 0x00);                 // 段号
        $pmt .= pack('n', 0x1FFF & $this->videoPid); // PCR PID
        $pmt .= pack('n', 0x0000);               // 节目信息长度

        // 视频流描述
        $pmt .= pack('C', 0x1B);                 // 流类型
        $pmt .= pack('n', 0xE000 | $this->videoPid); // 视频PID
        $pmt .= pack('n', 0x0000);               // 描述符长度

        // 音频流描述
        $pmt .= pack('C', 0x0F);                 // 流类型
        $pmt .= pack('n', 0xE000 | $this->audioPid); // 音频PID
        $pmt .= pack('n', 0x0000);               // 描述符长度

        $crc = $this->crc32mpeg(substr($pmt, 0, 24));
        $pmt .= pack('N', $crc);

        return $pmt;
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

        $currentPCR = intval($pts * 300);
        $this->lastPCR = $currentPCR;

        $this->writeTSPacket(
            $this->videoPid,
            $pesData,
            $isKeyFrame,
            true,
            $currentPCR
        );
    }

    private function createPESPacket($streamId, $payload, $pts, $dts, $isKeyFrame = false)
    {
        $pesHeaderStart = "\x00\x00\x01" . chr($streamId);

        $ptsData = $this->encodeTimestamp(0x02, $pts);
        $headerData = $ptsData;

        if ($dts !== $pts) {
            $dtsData = $this->encodeTimestamp(0x01, $dts);
            $headerData = $ptsData . $dtsData;
        }

        $headerDataLength = strlen($headerData);
        $flags = 0x80; // PTS标志

        if ($dts !== $pts) {
            $flags |= 0x40; // DTS标志
        }

        $pesHeader = $pesHeaderStart
            . pack('n', 0) // 包长度设为0（可变长度）
            . chr(0x80)    // 标志位1
            . chr($flags)  // 标志位2
            . chr($headerDataLength) // 头部数据长度
            . $headerData;  // 时间戳数据

        return $pesHeader . $payload;
    }

    private function encodeTimestamp($flag, $ts)
    {
        return pack('C', ($flag << 4) | (($ts >> 30 & 0x07) << 1) | 1)
            . pack('n', (($ts >> 15) & 0x7FFF) << 1 | 1)
            . pack('n', ($ts & 0x7FFF) << 1 | 1);
    }

    private function writeTSPacket($pid, $payload, $isKeyFrame = false, $isVideo = false, $pcrBase = null)
    {
        static $continuityCounters = [];
        if (!isset($continuityCounters[$pid])) {
            $continuityCounters[$pid] = 0;
        }

        $tsPacketSize = 188;
        $payloadOffset = 0;
        $payloadLength = strlen($payload);
        $firstPacket = true;

        while ($payloadOffset < $payloadLength) {
            $adaptationFieldLength = 0;
            $adaptationField = '';

            if ($isVideo && $firstPacket) {
                // 视频关键帧或需要PCR时添加适配域
                $adaptationFieldLength = 8;
                $pcr = $pcrBase ?? $this->lastPCR;

                $adaptationField = chr(7); // 适配域长度
                $adaptationField .= chr(0x10); // PCR标志
                $adaptationField .= pack('N', ($pcr >> 16) & 0xFFFFFFFF);
                $adaptationField .= pack('n', $pcr & 0xFFFF);
            }

            $maxPayload = $tsPacketSize - 4 - $adaptationFieldLength;
            $currentPayloadLength = min($maxPayload, $payloadLength - $payloadOffset);
            $currentPayload = substr($payload, $payloadOffset, $currentPayloadLength);
            $payloadOffset += $currentPayloadLength;

            // 构建TS头
            $syncByte = 0x47;
            $payloadUnitStart = $firstPacket ? 0x40 : 0x00;
            $tsHeader1 = $payloadUnitStart | (($pid >> 8) & 0x1F);
            $tsHeader2 = $pid & 0xFF;
            $adaptationFieldCtrl = ($adaptationFieldLength > 0) ? 0x30 : 0x10;
            $tsHeader3 = $adaptationFieldCtrl | ($continuityCounters[$pid] % 16);

            // 组装TS包
            $tsPacket = chr($syncByte) . chr($tsHeader1) . chr($tsHeader2) . chr($tsHeader3);
            if ($adaptationFieldLength > 0) {
                $tsPacket .= $adaptationField;
            }
            $tsPacket .= $currentPayload;

            // 填充剩余空间
            $remaining = $tsPacketSize - strlen($tsPacket);
            if ($remaining > 0) {
                $tsPacket .= str_repeat("\xFF", $remaining);
            }

            if ($this->tsFileHandle) {
                fwrite($this->tsFileHandle, $tsPacket);
            }

            $continuityCounters[$pid]++;
            $firstPacket = false;
        }
    }

    private function crc32mpeg($data)
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
            if (file_exists($m3u8Path)) {
                $m3u8Content = file_get_contents($m3u8Path);
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