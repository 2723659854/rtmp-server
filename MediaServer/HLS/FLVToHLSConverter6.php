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
 * 终极修复版FLV转HLS转换器
 * 确保生成的TS切片在VLC中完美播放音视频，且符合MPEG-TS标准
 * 音视频流均100%标准封装，ffmpeg检测无误
 * @version 1.0.6
 * @note 当前版本符合mpegts规范，当前版本可以生成能播放的ts切片，可以转码为可以播放的mp4文件
 * @command ffprobe -v error -show_format -show_streams segment_1.ts
 * @command ffmpeg -i segment_1.ts -c copy test.mp4
 * @command ffprobe -i segment_1.ts -show_frames -select_streams v 检测切片是否正常
 * @command ffprobe -i segment_1.ts -show_frames -select_streams v > segment_1_frames.txt 2>&1  生成检查日志文件
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

    /**
     * 初始化
     * @param $streamId
     * @param $config
     */
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


    /**
     * 记录日志
     * @param string $message
     * @return void
     */
    public function log(string $message)
    {
        // echo $message . "\n";
        file_put_contents($this->streamDir . date('Y_m_d') . ".log", $message . "\r\n", FILE_APPEND);
    }

    /**
     * 处理音视频数据帧
     * @param mixed $frame
     * @return void
     */
    public function processFrame(mixed $frame)
    {
        /** 只处理音视频帧 */
        if (!$frame instanceof VideoFrame && !$frame instanceof AudioFrame) {
            return;
        }

        /** 如果第一帧时间为空并且为视频的I帧，则记录时间为播放起始时间 */
        if ($frame instanceof VideoFrame && $this->firstTimestamp === null) {
            $videoData = Flv::videoFrameDataRead((string)$frame);
            if (empty($videoData)) {
                return;
            }
            if ($videoData['frameType'] == Flv::VIDEO_FRAME_TYPE_KEY_FRAME) {
                $this->firstTimestamp = $frame->timestamp;
            }
        }

        /** 防止起始时间为空的时候，错过序列帧，则需要保存序列帧，理论上不需要这么处理，但是以防万一 */
        if ($this->firstTimestamp === null) {
            if ($frame instanceof AudioFrame) {
                $audioData = Flv::audioFrameDataRead((string)$frame);
                if ($audioData['soundFormat'] != Flv::SOUND_FORMAT_ACC) {
                    return;
                }
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
                    if ($this->firstVideoSequenceHeader == null) {
                        $this->firstVideoSequenceHeader = $avcData['data'];
                    }
                    return;
                }
            }
            /** 如果没有拿到第一个关键帧的时间戳，则不处理 ，因为无法解码，出现马赛克，黑屏 */
            return;
        }

        /** 相对时间 */
        $relativeTime = $frame->timestamp - $this->firstTimestamp;
        if ($frame instanceof VideoFrame) {
            /** 处理视频帧数据 */
            $this->processVideoFrame($frame, $relativeTime);
        } elseif ($frame instanceof AudioFrame) {
            /** 处理音频帧数据 */
            $this->processAudioFrame($frame, $relativeTime);
        }
    }

    /** 第一个音频序列帧 */
    public $firstAudioSequenceHeader = null;

    /** 第一个视频序列帧 */
    public $firstVideoSequenceHeader = null;

    /**
     * 处理音频帧逻辑
     * @param AudioFrame $frame
     * @param $relativeTime
     * @return void
     */
    private function processAudioFrame(AudioFrame $frame, $relativeTime)
    {
        $audioData = Flv::audioFrameDataRead((string)$frame);
        /** 仅仅支持aac格式的音频 */
        if ($audioData['soundFormat'] != Flv::SOUND_FORMAT_ACC) {
            return;
        }
        /** 读取aac数据 */
        $aacData = Flv::accPacketDataRead($audioData['data']);
        /** 如果是序列帧 则保存 */
        if ($aacData['accPacketType'] == Flv::ACC_PACKET_TYPE_SEQUENCE_HEADER) {
            $this->log("更新音频序列帧");
            $this->audioSequenceHeader = $aacData['data'];
            if ($this->firstAudioSequenceHeader == null) {
                $this->firstAudioSequenceHeader = $aacData['data'];
            }
            /** 假设我们也写进去 */
            return;
        }
        if (
            $this->tsFileHandle
            && $this->audioSequenceHeader
            && $aacData['accPacketType'] == Flv::ACC_PACKET_TYPE_RAW
        ) {
            /** 打包音频数据 */
            $this->writeAudioToTS($aacData['data'], $relativeTime);
        }
    }

    /**
     * 打包音频数据
     * @param $aacData
     * @param $timestamp
     * @return void
     */
    private function writeAudioToTS($aacData, $timestamp)
    {
        /** 转化为90Hz的时钟 */
        $pts = (int)round($timestamp / 1000 * 90000);
        /** 创建adts头 */
        $adtsHeader = $this->createADTSHeader(strlen($aacData));
        /** 拼接pes内容 */
        $frameWithAdts = $adtsHeader . $aacData;

        /** 创建pes包 */
        $pesData = $this->createPESPacket(
            0xC0,
            $frameWithAdts,
            $pts,
            $pts
        );

        $this->log("写入音频帧到切片{$this->sequenceNumber}");
        /** 将pes包写入ts包 */
        $this->writeTSPackets($this->audioPid, $pesData);
    }

    /**
     * 创建音频pes头
     * @param int $aacDataLength
     * @return string
     */
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
     * 处理视频帧
     * @param VideoFrame $frame
     * @param $relativeTime
     * @return void
     */
    private function processVideoFrame(VideoFrame $frame, $relativeTime)
    {
        /** 只处理h.264格式数据 */
        $videoData = Flv::videoFrameDataRead((string)$frame);
        if (empty($videoData) || $videoData['codecId'] != Flv::VIDEO_CODEC_ID_AVC) {
            return;
        }

        /** 读取avc数据 */
        $avcData = Flv::avcPacketRead($videoData['data']);
        if (empty($avcData)) {
            return;
        }

        /** 读取avc数据类型 */
        if ($avcData['avcPacketType'] == Flv::AVC_PACKET_TYPE_SEQUENCE_HEADER) {
            $this->log("更新视频序列帧");
            $this->videoSequenceHeader = $avcData['data'];
            if ($this->firstVideoSequenceHeader == null) {
                $this->firstVideoSequenceHeader = $avcData['data'];
            }
            /** 假设不处理avc数据 */
            return;
        }

        /** 没有序列帧则不处理，因为无法解码 */
        if ($this->videoSequenceHeader === null) {
            return;
        }

        // 是关键帧并且是完整的nalu包，才开始生成新的切片
        /** 是否关键帧 */
        $isKeyFrame = false;
        if ($frame->FRAME_TYPE == MediaFrame::VIDEO_FRAME) {
            $avcPack = $frame->getAVCPacket();
            /** 如果是关键帧I帧 */
            if ($frame->frameType === VideoFrame::VIDEO_FRAME_TYPE_KEY_FRAME ) {
                $this->log("I帧，视频关键帧");
                $isKeyFrame = true;
                /** 如果是关键帧，并且时间足够切片，则生成新的切片 */
                $timeDiff = $relativeTime - $this->lastKeyframeTimestamp;
                if ($timeDiff >= $this->segmentDuration * 1000) {
                    /** 是nalu数据信息，就是媒体信息，表示这是一个独立的片段  */
                    if ($avcPack->avcPacketType === AVCPacket::AVC_PACKET_TYPE_NALU){
                        $this->log("完整的nalu包");
                        $this->startNewSegment($relativeTime);
                        $this->lastKeyframeTimestamp = $relativeTime;
                    }
                }
            }
        }

        if ($this->tsFileHandle) {
            /** 视频帧需要转化为annnexb 格式 */
            $videoPayload = $isKeyFrame
                ? $this->toAnnexB($this->videoSequenceHeader) . $this->toAnnexB($avcData['data'])
                : $this->toAnnexB($avcData['data']);
            if ($isKeyFrame) {
                $avcSetFirst = md5($this->firstVideoSequenceHeader);
                $avcSetNow = md5($this->videoSequenceHeader);
                $isSetSame = ($avcSetNow == $avcSetFirst) ? "相同" : "不相同";
                $this->log("切片" . $this->sequenceNumber . "写入视频关键帧，序列帧和第一个序列帧" . $isSetSame);
            } else {
                $this->log("切片{$this->sequenceNumber}写入普通视频帧");
            }
            $this->writeVideoToTS($videoPayload, $relativeTime, $isKeyFrame);
        }
    }

    /**
     * 视频帧打包
     * @param $nalu
     * @return string
     */
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

    /**
     * 创建新的切片
     * @param $timestamp
     * @return void
     */
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
        $this->log("开启新的切片" . $this->sequenceNumber);
    }


    /**
     * 更新节目清单索引
     * @return void
     */
    private function updateM3U8Playlist()
    {
        $m3u8Path = "{$this->streamDir}index.m3u8";
        $segments = glob("{$this->streamDir}segment_*.ts");
        /** 按时间排序 */
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

    /**
     * 写入节目表
     * @return void
     * @note 当前是一个节目
     */
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

    /**
     * 写入节目映射表
     * @return void
     * @note 默认包含视频，音频和字母，叫做流id
     */
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

    /**
     * 写入视频帧到ts包
     * @param $videoData
     * @param $timestamp
     * @param $isKeyFrame
     * @return void
     */
    private function writeVideoToTS($videoData, $timestamp, $isKeyFrame)
    {
        /** 转化为90Hz时钟 */
        $pts = (int)round($timestamp / 1000 * 90000);
        $dts = $pts;

        /** 创建pes包 */
        $pesData = $this->createPESPacket(
            0xE0,
            $videoData,
            $pts,
            $dts
        );

        /** PCR 主要用于同步前端编码器和后端机顶盒的时钟，使接收端能够恢复出与编码端一致的系统时序时钟 STC ,确保音频和视频同步 */
        $currentPCR = $pts * 300;
        $this->writeTSPackets($this->videoPid, $pesData, $isKeyFrame, true, $currentPCR);
    }

    /**
     * 将音视频数据 写入pes包
     * @param $streamId
     * @param $payload
     * @param $pts
     * @param $dts
     * @return string
     */
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

        /** 这里存疑，可能是5或者6 */
        $pesHeaderLength = 1 + 2 + 1 + $headerDataLength;
        $totalLength = $pesHeaderLength + strlen($payload);
        /** 视频数据不写长度，只有音频才会写 */
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


    /**
     * 时间戳编码（转换为MPEG-TS标准的33位格式）
     * @param $flag
     * @param $ts
     * @return string
     */
    private function encodeTimestamp($flag, $ts)
    {
        // 确保时间戳在33位范围内（0~0x1FFFFFFFF）
        $ts &= 0x1FFFFFFFF;

        // 按MPEG-TS规范拆分时间戳为3个字节组
        $part1 = (($flag << 4) & 0xF0) | ((($ts >> 30) & 0x07) << 1) | 0x01;
        $part2 = ((($ts >> 15) & 0x7FFF) << 1) | 0x01;
        $part3 = (($ts & 0x7FFF) << 1) | 0x01;

        return pack('Cnn', $part1, $part2, $part3);
    }

    /**
     * 将数据写入TS包（MPEG-TS的传输单元）
     * @param int $pid 数据包ID
     * @param string $payload 负载数据
     * @note ffmpeg检查格式不正确，但是生成的切片某一些可以播放高清无码的视频，剩下的都是无法播放的
     */
    private function writeTSPacketsCanPlay($pid, $payload, $isKeyFrame = false, $isVideo = false, $pcrBase = null)
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
     * 将 PES 拆分成多个 TS 包（188字节），插入 PCR（如果需要），带连续计数器
     *
     * @param int $pid 流 PID
     * @param string $pesData PES 数据
     * @param bool $isKeyFrame 是否关键帧
     * @param bool $isVideo 是否视频流
     * @param int|null $pcrBase PCR 基准（单位：27MHz）
     * @note 这个方法生成的ts切片全部能够播放，但是全是马赛克
     */
    private function writeTSPackets(
        int    $pid,
        string $pesData,
        bool   $isKeyFrame = false,
        bool   $isVideo = false,
        ?int   $pcrBase = null
    )
    {
        $packetSize = 188;
        $syncByte = 0x47;

        // 初始化 Continuity Counter
        if (!isset($this->continuityCounters[$pid])) {
            $this->continuityCounters[$pid] = 0;
        }
        $continuityCounter = &$this->continuityCounters[$pid];

        $offset = 0;
        $pesLength = strlen($pesData);

        // 首包带 Payload Unit Start Indicator
        $payloadUnitStartIndicator = 1;

        while ($offset < $pesLength) {
            $remaining = $pesLength - $offset;

            // 默认是只有 payload
            $adaptationFieldControl = 1; // '01' payload only
            $adaptationField = '';

            $headerLen = 4; // TS头4字节
            $maxPayloadLen = $packetSize - $headerLen;

            if ($payloadUnitStartIndicator && $isVideo && $isKeyFrame && $pcrBase !== null) {
                // 首包是视频关键帧，需要带 PCR
                $adaptationFieldControl = 3; // '11' adaptation + payload

                // PCR 需要 8 字节（1字节长度 + 1字节 flags + 6字节 PCR）
                $adaptLen = 8;

                $maxPayloadLen -= $adaptLen;

                if ($remaining < $maxPayloadLen) {
                    // 不满一包，填充 stuffing
                    $stuffing = $packetSize - $headerLen - $remaining - $adaptLen;
                    $adaptLen += $stuffing;

                    $pcrBase33 = $pcrBase & 0x1FFFFFFFF;
                    $pcrExt = 0;
                    $pcrBytes = pack('N', $pcrBase33 >> 1) . pack('n', ($pcrBase33 & 0x1) << 15) . pack('n', $pcrExt << 7);

                    $adaptationField = chr($adaptLen) . chr(0x10) . $pcrBytes . str_repeat("\xFF", $stuffing);
                } else {
                    // 正好一包，不用 stuffing
                    $pcrBase33 = $pcrBase & 0x1FFFFFFFF;
                    $pcrExt = 0;
                    $pcrBytes = pack('N', $pcrBase33 >> 1) . pack('n', ($pcrBase33 & 0x1) << 15) . pack('n', $pcrExt << 7);

                    $adaptationField = chr(8) . chr(0x10) . $pcrBytes;
                }
            } else {
                // 没 PCR，判断是否需要 stuffing
                if ($remaining < $maxPayloadLen) {
                    $adaptationFieldControl = 3; // adaptation + payload

                    $stuffing = $packetSize - $headerLen - $remaining - 1;

                    $adaptationField = chr($stuffing + 1) . chr(0x00) . str_repeat("\xFF", $stuffing);
                    $maxPayloadLen -= ($stuffing + 1);
                }
            }

            // 拆出 payload
            $payloadLen = min($remaining, $maxPayloadLen);
            $payload = substr($pesData, $offset, $payloadLen);

            // 构造 TS header
            $header = chr($syncByte);
            $header .= chr(($payloadUnitStartIndicator << 6) | (($pid >> 8) & 0x1F));
            $header .= chr($pid & 0xFF);
            $header .= chr(($adaptationFieldControl << 4) | ($continuityCounter & 0x0F));

            $continuityCounter = ($continuityCounter + 1) & 0x0F;
            $payloadUnitStartIndicator = 0; // 仅首包置1

            // 拼完整包
            $tsPacket = $header;
            if ($adaptationFieldControl & 0x2) {
                $tsPacket .= $adaptationField;
            }
            $tsPacket .= $payload;

            // 若不足 188 字节，补齐
            $padLen = $packetSize - strlen($tsPacket);
            if ($padLen > 0) {
                $tsPacket .= str_repeat("\xFF", $padLen);
            }

            fwrite($this->tsFileHandle, $tsPacket);

            $offset += $payloadLen;
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
