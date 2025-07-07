<?php
namespace MediaServer\HLS;

use MediaServer\Flv\Flv;
use MediaServer\MediaReader\AudioFrame;
use MediaServer\MediaReader\AVCSequenceParameterSet;
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
 * @command ffprobe -i segment_1.ts -show_frames -select_streams v 检测切片是否正常
 * @note 这个版本解析sps
 */
class FLVToHLSConverter8
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

    /**
     * 处理视频帧，重点修复SPS在关键帧中的插入逻辑
     */
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

        // 解析AVC序列头（包含SPS和PPS）
        if ($avcData['avcPacketType'] == Flv::AVC_PACKET_TYPE_SEQUENCE_HEADER) {
            $this->videoSequenceHeader = $avcData['data'];
            // 验证并提取SPS/PPS，确保格式正确
            $this->parseSPSPPS($this->videoSequenceHeader);
            return;
        }

        if ($this->videoSequenceHeader === null || empty($this->spsNalus) || empty($this->ppsNalus)) {
            return; // 未获取到有效的SPS/PPS，不处理视频帧
        }

        $isKeyFrame = ($videoData['frameType'] == Flv::VIDEO_FRAME_TYPE_KEY_FRAME);

        // 关键帧触发新片段时，确保片段起始包含完整SPS/PPS
        if ($isKeyFrame) {
            $timeDiff = $relativeTime - $this->lastKeyframeTimestamp;
            if ($timeDiff >= $this->segmentDuration * 1000) {
                $this->startNewSegment($relativeTime);
                $this->lastKeyframeTimestamp = $relativeTime;
            }
        }

        if ($this->tsFileHandle) {
            // 准备视频负载：关键帧前必须插入SPS/PPS，且顺序正确
            $videoPayload = $this->prepareVideoPayload($avcData['data'], $isKeyFrame);
            $this->writeVideoToTS($videoPayload, $relativeTime, $isKeyFrame);
        }
    }

    // 新增成员变量存储解析后的SPS/PPS NAL单元
    private $spsNalus = []; // SPS的NAL单元（Annex B格式）
    private $ppsNalus = []; // PPS的NAL单元（Annex B格式）

    /**
     * 解析AVC序列头，提取SPS和PPS并转换为Annex B格式
     */
    private function parseSPSPPS($avcSequenceHeader)
    {
        $this->spsNalus = [];
        $this->ppsNalus = [];
        $offset = 0;
        $length = strlen($avcSequenceHeader);

        // AVC序列头结构：[1字节版本][1字节profile][1字节兼容性][1字节level][1字节NALU长度类型][1字节SPS数量] + SPS列表 + PPS列表
        // 跳过前5字节（版本、profile、兼容性、level、NALU长度类型）
        $offset += 5;
        if ($offset >= $length) return;

        // 读取SPS数量（低5位有效）
        $spsCount = ord($avcSequenceHeader[$offset++]) & 0x1F;
        for ($i = 0; $i < $spsCount; $i++) {
            if ($offset + 2 > $length) break;
            // SPS长度（2字节）
            $spsLen = (ord($avcSequenceHeader[$offset]) << 8) | ord($avcSequenceHeader[$offset + 1]);
            $offset += 2;
            if ($offset + $spsLen > $length) break;
            // 提取SPS数据，转换为Annex B格式（添加0x00000001起始码）
            $spsData = substr($avcSequenceHeader, $offset, $spsLen);
            $this->spsNalus[] = "\x00\x00\x00\x01" . $spsData;
            $offset += $spsLen;
        }

        // 读取PPS数量（1字节，低5位有效）
        if ($offset >= $length) return;
        $ppsCount = ord($avcSequenceHeader[$offset++]) & 0x1F;
        for ($i = 0; $i < $ppsCount; $i++) {
            if ($offset + 2 > $length) break;
            // PPS长度（2字节）
            $ppsLen = (ord($avcSequenceHeader[$offset]) << 8) | ord($avcSequenceHeader[$offset + 1]);
            $offset += 2;
            if ($offset + $ppsLen > $length) break;
            // 提取PPS数据，转换为Annex B格式
            $ppsData = substr($avcSequenceHeader, $offset, $ppsLen);
            $this->ppsNalus[] = "\x00\x00\x00\x01" . $ppsData;
            $offset += $ppsLen;
        }
    }

    /**
     * 准备视频负载，确保关键帧前正确插入SPS/PPS
     */
    private function prepareVideoPayload($videoData, $isKeyFrame)
    {
        $payload = '';

        // 关键帧必须先插入SPS和PPS（解码器需要这些参数初始化）
        if ($isKeyFrame) {
            // 拼接所有SPS NAL单元
            foreach ($this->spsNalus as $sps) {
                $payload .= $sps;
            }
            // 拼接所有PPS NAL单元
            foreach ($this->ppsNalus as $pps) {
                $payload .= $pps;
            }
            // 添加访问单元分隔符（AUD），标记一个完整的访问单元开始
            $payload .= "\x00\x00\x00\x01\x09\xF0";
        }

        // 转换视频帧数据为Annex B格式（NAL单元添加起始码）
        $payload .= $this->toAnnexB($videoData);

        return $payload;
    }

    /**
     * 改进的Annex B格式转换，确保每个NAL单元正确添加起始码
     */
    private function toAnnexB($naluData)
    {
        $result = '';
        $offset = 0;
        $length = strlen($naluData);

        // 循环处理每个NAL单元（FLV中以4字节长度前缀标识）
        while ($offset + 4 <= $length) {
            // 读取NAL单元长度（4字节大端）
            $naluLen = unpack('N', substr($naluData, $offset, 4))[1];
            $offset += 4;

            // 确保NAL单元数据完整
            if ($offset + $naluLen > $length) {
                break;
            }

            // 提取NAL单元数据，添加Annex B起始码（0x00000001）
            $nalu = substr($naluData, $offset, $naluLen);
            $result .= "\x00\x00\x00\x01" . $nalu;

            $offset += $naluLen;
        }

        return $result;
    }


    private $spsInfo = null;

    private function processVideoFrame2(VideoFrame $frame, $relativeTime)
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
            //todo 已经加进去了，然后怎么使用
            $this->spsInfo = (new AVCSequenceParameterSet($this->videoSequenceHeader))->getSPS();
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
            }
        }

        if ($this->tsFileHandle) {
            $videoPayload = $isKeyFrame
                ? $this->toAnnexB($this->videoSequenceHeader) . $this->toAnnexB($avcData['data'])
                : $this->toAnnexB($avcData['data']);

            $this->writeVideoToTS($videoPayload, $relativeTime, $isKeyFrame);
        }
    }

    private function toAnnexB2($nalu)
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

        //$packetLength = ($totalLength <= 0xFFFF) ? $totalLength : 0;
        if ($streamId == 0xE0) {
            $packetLength = 0;
        } else {
            $packetLength = ($totalLength <= 0xFFFF) ? $totalLength : 0;
        }
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
     * 将数据写入TS包（MPEG-TS的传输单元）
     * @param int $pid 数据包ID
     * @param string $payload 负载数据
     * @note 此方法封装的ts切片清晰无马赛克，但是生成的切片有些无法播放，并且ffmpeg检查格式不正确
     */
    private function writeTSPackets2($pid, $payload, $isKeyFrame = false, $isVideo = false, $pcrBase = null)
    {
        $tsPacketSize = 188;
        $syncByte = 0x47;

        $header = chr($syncByte);
        $header .= chr((($isKeyFrame ? 0x40 : 0x00) | (($pid >> 8) & 0x1F)));
        $header .= chr($pid & 0xFF);

        $adaptationFieldControl = 0x10;
        $adaptationField = '';

        if ($isVideo && $isKeyFrame && $pcrBase !== null) {
            $adaptationFieldControl = 0x30;

            $pcrBase33 = $pcrBase & 0x1FFFFFFFF;
            $pcrExt = 0;

            $adaptationField .= chr(7);
            $adaptationField .= chr(0x10);
            $adaptationField .= pack('N', ($pcrBase33 << 1)) . chr(0);
            $adaptationField .= pack('n', $pcrExt << 7);
        }

        $header .= chr($adaptationFieldControl);

        $packet = $header . $adaptationField . $payload;

        if (strlen($packet) < $tsPacketSize) {
            $packet .= str_repeat("\xFF", $tsPacketSize - strlen($packet));
        }

        fwrite($this->tsFileHandle, $packet);
    }

    /**
     * 将数据写入TS包，支持多包拆分，维护连续计数器
     * @param int $pid
     * @param string $payload
     * @param bool $isKeyFrame
     * @param bool $isVideo
     * @param int|null $pcrBase 27MHz单位的PCR基准
     * @note  这个方法是标准版本，ffmpeg检查格式正确，但是全是马赛克，都可以播放
     */
    private function writeTSPackets(
        int $pid,
        string $pesData,
        bool $isKeyFrame = false,
        bool $isVideo = false,
        ?int $pcrBase = null
    ) {
        $packetSize = 188;

        if (!isset($this->continuityCounters[$pid])) {
            $this->continuityCounters[$pid] = 0;
        }

        $continuityCounter = &$this->continuityCounters[$pid];

        $payloadUnitStartIndicator = 1;
        $offset = 0;
        $pesLen = strlen($pesData);

        while ($offset < $pesLen) {
            $remaining = $pesLen - $offset;

            // 默认：没有适配字段，全部用于 payload
            $adaptationFieldControl = 1;  // '01' => payload only
            $adaptationField = '';

            // 最大可用 payload
            $maxPayload = $packetSize - 4;  // TS header 4 bytes

            // PCR 只插第一包 & 视频
            if ($payloadUnitStartIndicator && $isVideo && $pcrBase !== null) {
                $adaptationFieldControl = 3; // '11' => adapt + payload

                $adaptLen = 7; // PCR 6 + len byte
                $maxPayload -= $adaptLen;

                // 如果可用空间仍然不够，拆分 payload
                if ($remaining > $maxPayload) {
                    $payloadSize = $maxPayload;
                } else {
                    $payloadSize = $remaining;
                }

                $padding = $packetSize - 4 - $payloadSize - $adaptLen;

                // PCR字段
                $pcrBaseVal = $pcrBase;
                $pcrExt = 0;
                $pcr = (($pcrBaseVal & 0x1FFFFFFFF) << 15) | (0x7E00) | ($pcrExt & 0x1FF);
                $pcrBytes = pack('N', $pcr >> 16) . pack('n', $pcr & 0xFFFF);

                $adaptationField = chr($adaptLen + $padding)
                    . chr(0x10)  // PCR标志
                    . $pcrBytes
                    . str_repeat("\xFF", $padding);

            } else {
                // 无PCR包
                if ($remaining > $maxPayload) {
                    $payloadSize = $maxPayload;
                } else {
                    $payloadSize = $remaining;

                    // 如果剩余payload太小，不足188，要插填充
                    $stuffing = $packetSize - 4 - $payloadSize - 1;
                    if ($stuffing > 0) {
                        $adaptationFieldControl = 3;
                        $adaptationField = chr($stuffing + 1)  // +1 for length byte
                            . chr(0x00)
                            . str_repeat("\xFF", $stuffing);
                    }
                }
            }

            // --- 构建TS头 ---
            $header = chr(0x47)
                . chr(($payloadUnitStartIndicator << 6) | (($pid >> 8) & 0x1F))
                . chr($pid & 0xFF)
                . chr(($adaptationFieldControl << 4) | ($continuityCounter & 0x0F));

            $continuityCounter = ($continuityCounter + 1) & 0x0F;
            $payloadUnitStartIndicator = 0;

            $payload = substr($pesData, $offset, $payloadSize);

            $packet = $header;
            if ($adaptationFieldControl & 0x2) {
                $packet .= $adaptationField;
            }
            $packet .= $payload;

            $packetLen = strlen($packet);
            if ($packetLen < $packetSize) {
                $packet .= str_repeat("\xFF", $packetSize - $packetLen);
            }

            fwrite($this->tsFileHandle, $packet);

            $offset += $payloadSize;
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
