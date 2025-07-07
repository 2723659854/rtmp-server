<?php
namespace MediaServer\HLS;

use MediaServer\Flv\Flv;
use MediaServer\MediaReader\AudioFrame;
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
 * 终极修复版FLV转HLS转换器
 * 确保生成的TS切片在VLC中完美播放音视频，且符合MPEG-TS标准
 * 音视频流均100%标准封装，ffmpeg检测无误
 * @version 1.0.6
 * @note 当前版本符合mpegts规范，当前版本可以生成能播放的ts切片，可以转码为可以播放的mp4文件
 * @command ffprobe -v error -show_format -show_streams segment_1.ts
 * @command ffmpeg -i segment_1.ts -c copy test.mp4
 */
class FLVToHLSConverter6
{
    // 配置参数
    private $segmentDuration = 4;
    private $maxSegments = 10;
    private $streamId;
    private $streamDir;

    // 状态变量
    private $sequenceNumber = 0;
    private $currentSegmentFile;
    private $segmentStartTime = 0;
    private $firstTimestamp = null;
    private $lastKeyframeTimestamp = 0;

    // TS流参数
    private $videoPid = 0x100;
    private $pmtPid = 0x10;
    private $patPid = 0;
    private $audioPid = 0x101;

    // 编解码器数据
    private $videoSequenceHeader = null;
    private $audioSequenceHeader = null;

    // 视频元数据
    private $videoCodecId = null;       // 视频编码ID（仅处理H.264）

    // 文件句柄
    private $tsFileHandle = null;
    private $segmentDurations = [];

    // 连续计数器，key是PID
    private $continuityCounters = [];

    public function __construct($streamId, $config = [])
    {
        $this->streamId = $streamId;
        $this->streamDir = dirname(__DIR__, 3) . "/hls/{$streamId}/";

        $this->segmentDuration = isset($config['segmentDuration']) ? (int)$config['segmentDuration'] : 4;
        $this->maxSegments = isset($config['maxSegments']) ? (int)$config['maxSegments'] : 10000;

        if (!is_dir($this->streamDir)) {
            mkdir($this->streamDir, 0777, true);
        }
    }

    public function processFrame(mixed $frame)
    {
        if (!$frame instanceof VideoFrame && !$frame instanceof AudioFrame) {
            return;
        }

        if ($frame instanceof VideoFrame && $this->firstTimestamp === null) {
            $videoData = Flv::videoFrameDataRead((string)$frame);
            if (empty($videoData)) {
                return;
            }
            if ($videoData['frameType'] == Flv::VIDEO_FRAME_TYPE_KEY_FRAME){
                $this->firstTimestamp = $frame->timestamp;
            }
        }

        if ($this->firstTimestamp === null){
            if ($frame instanceof AudioFrame) {
                $audioData = Flv::audioFrameDataRead((string)$frame);
                if ($audioData['soundFormat'] != Flv::SOUND_FORMAT_ACC) {
                    return;
                }
                $aacData = Flv::accPacketDataRead($audioData['data']);
                if ($aacData['accPacketType'] == Flv::ACC_PACKET_TYPE_SEQUENCE_HEADER) {
                    $this->audioSequenceHeader = $aacData['data'];
                    return;
                }
            }
            if ($frame instanceof VideoFrame) {
                $videoData = Flv::videoFrameDataRead((string)$frame);
                if (empty($videoData)) {
                    return;
                }
                $this->videoCodecId = $videoData['codecId'];
                if ($this->videoCodecId != Flv::VIDEO_CODEC_ID_AVC) {
                    throw new \RuntimeException("仅支持H.264编码，当前编码ID: {$this->videoCodecId}");
                }
                $avcData = Flv::avcPacketRead($videoData['data']);
                if (empty($avcData)) {
                    return;
                }
                if ($avcData['avcPacketType'] == Flv::AVC_PACKET_TYPE_SEQUENCE_HEADER) {
                    $this->videoSequenceHeader = $avcData['data'];
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
        if ($audioData['soundFormat'] != Flv::SOUND_FORMAT_ACC) {
            return;
        }
        $aacData = Flv::accPacketDataRead($audioData['data']);
        if ($aacData['accPacketType'] == Flv::ACC_PACKET_TYPE_SEQUENCE_HEADER) {
            $this->audioSequenceHeader = $aacData['data'];
            return;
        }
        if (
            $this->tsFileHandle
            && $this->audioSequenceHeader
            && $aacData['accPacketType'] == Flv::ACC_PACKET_TYPE_RAW
        ) {
            $this->writeAudioToTS($aacData['data'], $relativeTime);
        }
    }

    private function writeAudioToTS($aacData, $timestamp)
    {
        $pts = (int)($timestamp / 1000 * 90000);
        $adtsHeader = $this->createADTSHeader(strlen($aacData));
        $frameWithAdts = $adtsHeader . $aacData;

        $pesData = $this->createPESPacket(
            0xC0,
            $frameWithAdts,
            $pts,
            $pts
        );

        $this->writeTSPackets($this->audioPid, $pesData);
    }

    private function createADTSHeader(int $aacDataLength)
    {
        if ($this->audioSequenceHeader === null) {
            return "";
        }
        $asc = $this->audioSequenceHeader;
        if (strlen($asc) < 2) {
            return "";
        }

        $asc1 = ord($asc[0]);
        $asc2 = ord($asc[1]);

        $audioObjectType = (($asc1 >> 3) & 0x1F);
        $samplingFreqIdx = (($asc1 & 0x07) << 1) | (($asc2 >> 7) & 0x01);
        $channelConfig = (($asc2 >> 3) & 0x0F);

        $adtsTotalLength = 7 + $aacDataLength;
        if ($adtsTotalLength > 0x1FFF) {
            throw new \RuntimeException("AAC帧过长，超过ADTS支持的最大长度");
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

    private function processVideoFrame(VideoFrame $frame, $relativeTime)
    {
        $videoData = Flv::videoFrameDataRead((string)$frame);
        if (empty($videoData) || $videoData['codecId'] != Flv::VIDEO_CODEC_ID_AVC) {
            return;
        }

        $avcData = Flv::avcPacketRead($videoData['data']);
        if (empty($avcData)) {
            return;
        }

        if ($avcData['avcPacketType'] == Flv::AVC_PACKET_TYPE_SEQUENCE_HEADER) {
            $this->videoSequenceHeader = $avcData['data'];
            return;
        }

        if ($this->videoSequenceHeader === null) {
            return;
        }

        $isKeyFrame = ($videoData['frameType'] == Flv::VIDEO_FRAME_TYPE_KEY_FRAME);

        if ($isKeyFrame) {
            $timeDiff = $relativeTime - $this->lastKeyframeTimestamp;
            if ($timeDiff >= $this->segmentDuration * 1000) {
                $this->startNewSegment($relativeTime);
                $this->lastKeyframeTimestamp = $relativeTime;
                /** 为了保证每一个ts文件第一帧是关键帧，那么我在这里写入关键帧 */
                $this->writeVideoToTS($this->toAnnexB($this->videoSequenceHeader) . $this->toAnnexB($avcData['data']), $relativeTime, true);
                return;
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

        $this->writePAT();
        $this->writePMT();
    }

    private function updateM3U8Playlist()
    {
        $m3u8Path = "{$this->streamDir}index.m3u8";
        $segments = glob("{$this->streamDir}segment_*.ts");
        sort($segments, SORT_NATURAL);

        if (count($segments) > $this->maxSegments) {
            $toDelete = array_slice($segments, 0, count($segments) - $this->maxSegments);
            foreach ($toDelete as $file) {
                unlink($file);
            }
            $segments = array_slice($segments, -$this->maxSegments);
        }

        $m3u8Content = "#EXTM3U\n";
        $m3u8Content .= "#EXT-X-VERSION:3\n";
        $m3u8Content .= "#EXT-X-TARGETDURATION:{$this->segmentDuration}\n";
        $m3u8Content .= "#EXT-X-MEDIA-SEQUENCE:" . max(1, $this->sequenceNumber - count($segments) + 1) . "\n";

        foreach ($segments as $segment) {
            $seq = intval(pathinfo($segment, PATHINFO_FILENAME));
            $duration = $this->segmentDurations[$seq] ?? $this->segmentDuration;
            $m3u8Content .= "#EXTINF:{$duration},\n";
            $m3u8Content .= basename($segment) . "\n";
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
        $pmt = pack('C', 0x02);
        $pmt .= pack('C', 0xB0);
        $pmt .= pack('C', 0x18);
        $pmt .= pack('n', 0x0001);
        $pmt .= pack('C', 0xC1);
        $pmt .= pack('C', 0x00);
        $pmt .= pack('n', 0x1FFF & $this->videoPid);
        $pmt .= pack('n', 0x0000);

        // 视频流描述 (H.264)
        $pmt .= pack('C', 0x1B);
        $pmt .= pack('n', 0xE000 | $this->videoPid);
        $pmt .= pack('n', 0x0000);

        // 音频流描述 (AAC)
        $pmt .= pack('C', 0x0F);
        $pmt .= pack('n', 0xE000 | $this->audioPid);
        $pmt .= pack('n', 0x0000);

        $crc = $this->crc32mpeg(substr($pmt, 0, 20));
        $pmt .= pack('N', $crc);

        $this->writeTSPackets($this->pmtPid, $pmt);
    }

    private function writeVideoToTS($videoData, $timestamp, $isKeyFrame)
    {
        $pts = (int)($timestamp / 1000 * 90000);
        $dts = $pts;

        $pesData = $this->createPESPacket(
            0xE0,
            $videoData,
            $pts,
            $dts
        );

        $currentPCR = $pts * 300;
        $this->writeTSPackets($this->videoPid, $pesData, $isKeyFrame, true, $currentPCR);
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

        $pesHeaderLength = 1 + 2 + 1 + $headerDataLength;
        $totalLength = $pesHeaderLength + strlen($payload);

        $packetLength = ($totalLength <= 0xFFFF) ? $totalLength : 0;

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
        return pack('C', ($flag << 4) | (($ts >> 30 & 0x07) << 1) | 1)
            . pack('n', (($ts >> 15) & 0x7FFF) << 1 | 1)
            . pack('n', ($ts & 0x7FFF) << 1 | 1);
    }

    /**
     * 将数据写入TS包，支持多包拆分，维护连续计数器
     * @param int $pid
     * @param string $payload
     * @param bool $isKeyFrame
     * @param bool $isVideo
     * @param int|null $pcrBase 27MHz单位的PCR基准
     */
    private function writeTSPackets($pid, $payload, $isKeyFrame = false, $isVideo = false, $pcrBase = null)
    {
        $packetSize = 188;

        if (!isset($this->continuityCounters[$pid])) {
            $this->continuityCounters[$pid] = 0;
        }
        $continuityCounter = &$this->continuityCounters[$pid];

        $payloadUnitStartIndicator = true;
        $offset = 0;
        $payloadLen = strlen($payload);

        while ($offset < $payloadLen) {
            $adaptationFieldControl = 1; // 默认仅payload
            $adaptationField = '';

            $maxPayloadSize = $packetSize - 4; // TS头4字节

            $packetPayloadSize = $payloadLen - $offset;

            // 如果是第一包且是视频且有PCR，插入适配字段带PCR
            if ($payloadUnitStartIndicator && $isVideo && $pcrBase !== null) {
                $adaptationFieldControl = 3; // 适配字段+有效载荷

                // 适配字段长度固定7字节
                $adaptationFieldLen = 7;
                $maxPayloadSize = $packetSize - 4 - $adaptationFieldLen;

                if ($packetPayloadSize > $maxPayloadSize) {
                    $packetPayloadSize = $maxPayloadSize;
                }

                $paddingSize = $packetSize - 4 - $packetPayloadSize - $adaptationFieldLen;
                if ($paddingSize < 0) {
                    $paddingSize = 0;
                }

                // 适配字段长度
                $adaptationField = chr($adaptationFieldLen);

                // 适配字段标志，PCR有效
                $adaptationField .= chr(0x10);

                // PCR 6字节，基于27MHz计时器
                $pcrBaseVal = $pcrBase;
                $pcrExtension = 0;

                // PCR字段结构
                // PCR base: 33 bits, PCR extension: 9 bits
                // 按MPEG-TS标准打包
                $pcrBytes = pack('N', ($pcrBaseVal >> 1) & 0xFFFFFFFF);
                $pcrBytes .= pack('N', (($pcrBaseVal & 1) << 31) | ($pcrExtension << 16));

                $adaptationField .= substr($pcrBytes, 0, 6);

                // 填充字节
                $adaptationField .= str_repeat("\xFF", $paddingSize);
            } else {
                // 非PCR包或非第一包，只payload
                if ($packetPayloadSize > $packetSize - 4) {
                    $packetPayloadSize = $packetSize - 4;
                }
            }

            // 构建TS包头
            $header = chr(0x47); // 同步字节

            $header .= chr(
                0x40 * (int)$payloadUnitStartIndicator
                | (($pid >> 8) & 0x1F)
            );
            $header .= chr($pid & 0xFF);

            $header .= chr(
                ($adaptationFieldControl << 4)
                | ($continuityCounter & 0x0F)
            );

            $continuityCounter = ($continuityCounter + 1) & 0x0F;
            $payloadUnitStartIndicator = false;

            $packetPayload = substr($payload, $offset, $packetPayloadSize);

            if ($adaptationFieldControl & 0x2) {
                $tsPacket = $header . $adaptationField . $packetPayload;
            } else {
                $tsPacket = $header . $packetPayload;
            }

            // 补足188字节，标准要求
            $tsPacketLen = strlen($tsPacket);
            if ($tsPacketLen < $packetSize) {
                $tsPacket .= str_repeat("\xFF", $packetSize - $tsPacketLen);
            }

            fwrite($this->tsFileHandle, $tsPacket);

            $offset += $packetPayloadSize;
        }
    }

    /**
     * MPEG-TS标准CRC32计算
     */
    private function crc32mpeg($data)
    {
        $crc = 0xFFFFFFFF;
        $length = strlen($data);
        for ($i = 0; $i < $length; $i++) {
            $crc ^= (ord($data[$i]) << 24);
            for ($j = 0; $j < 8; $j++) {
                if (($crc & 0x80000000) !== 0) {
                    $crc = (($crc << 1) ^ 0x04C11DB7) & 0xFFFFFFFF;
                } else {
                    $crc = ($crc << 1) & 0xFFFFFFFF;
                }
            }
        }
        return $crc;
    }

    /**
     * 获取HLS播放地址
     * @return string 相对路径
     */
    public function getHlsUrl()
    {
        return "/hls/{$this->streamId}/index.m3u8";
    }


    /**
     * 关闭资源（结束流时调用）
     */
    public function close()
    {
        if ($this->tsFileHandle) {
            fclose($this->tsFileHandle);
            $this->tsFileHandle = null;

            // 更新播放列表并添加流结束标记
            $m3u8Path = "{$this->streamDir}index.m3u8";
            $m3u8Content = file_get_contents($m3u8Path);
            if ($m3u8Content !== false) {
                $m3u8Content .= "#EXT-X-ENDLIST\n";
                file_put_contents($m3u8Path, $m3u8Content);
            }
        }
    }

    /**
     * 析构函数（确保资源释放）
     */
    public function __destruct()
    {
        $this->close();
    }
}
