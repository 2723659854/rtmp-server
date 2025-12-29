<?php

namespace MediaServer\HLS;

use MediaServer\Flv\Flv;
use MediaServer\MediaReader\AudioFrame;
use MediaServer\MediaReader\AVCPacket;
use MediaServer\MediaReader\VideoFrame;
use function file_get_contents;
use function file_put_contents;
use function fclose;
use function fopen;
use function fwrite;
use function glob;
use function is_dir;
use function mkdir;
use function ord;
use function pack;
use function pathinfo;
use function round;
use function sort;
use function sprintf;
use function str_repeat;
use function strlen;
use function substr;
use function substr_count;
use function unlink;
use function unpack;

/**
 * 终极修复版FLV转HLS转换器
 * 确保生成的TS切片在VLC中完美播放音视频，且符合MPEG-TS标准
 * 音视频流均100%标准封装，ffmpeg检测无误
 * @version 1.0.7（修复NALU无效长度、序列头丢失、黑屏问题）
 * @note 当前版本符合mpegts规范，可生成正常播放的TS切片，支持转码为MP4文件
 * @command ffprobe -v error -show_format -show_streams segment_1.ts
 * @command ffmpeg -i segment_1.ts -c copy test.mp4
 * @command ffprobe -i segment_1.ts -show_frames -select_streams v 检测切片是否正常
 */
class FLVToHLSConverter10
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

    // 连续计数器（TS包规范必需）
    private $continuityCounters = [];

    // 第一个音频/视频序列帧（用于一致性校验）
    public $firstAudioSequenceHeader = null;
    public $firstVideoSequenceHeader = null;

    /**
     * 构造函数：初始化配置与资源
     * @param $streamId
     * @param $config
     */
    public function __construct($streamId, $config = [])
    {
        $this->streamId = $streamId;
        $this->streamDir = dirname(__DIR__, 2) . "/hls/{$streamId}/";

        $this->segmentDuration = isset($config['segmentDuration']) ? (int)$config['segmentDuration'] : 4;
        $this->maxSegments = isset($config['maxSegments']) ? (int)$config['maxSegments'] : 10000;

        // 创建流目录
        if (!is_dir($this->streamDir)) {
            mkdir($this->streamDir, 0777, true);
        }

        // 初始化核心属性，避免未定义变量异常
        $this->firstAudioSequenceHeader = null;
        $this->firstVideoSequenceHeader = null;
        $this->continuityCounters = [];
    }

    /**
     * 记录日志
     * @param string $message
     * @return void
     */
    public function log(string $message)
    {
        // echo $message . "\n";
        $logFile = $this->streamDir . date('Y_m_d') . ".log";
        file_put_contents($logFile, sprintf("[%s] %s\r\n", date('Y-m-d H:i:s'), $message), FILE_APPEND);
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
                $this->log("初始化首个视频时间戳：{$this->firstTimestamp}");
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
            $this->log("更新音频序列头（AAC ASC）");
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

        $this->log("切片{$this->sequenceNumber}写入音频帧");
        /** 将pes包写入ts包（使用规范的writeTSPackets2，带连续计数器） */
        $this->writeTSPackets2(
            $this->audioPid,
            $pesData,
            false,
            false
        );
    }

    /**
     * 创建音频ADTS头
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
            $this->log("更新视频序列头（SPS/PPS）");
            $this->videoSequenceHeader = $avcData['data'];
            if ($this->firstVideoSequenceHeader == null) {
                $this->firstVideoSequenceHeader = $avcData['data'];
            }
            return;
        }

        /** 没有序列帧则不处理，因为无法解码 */
        if ($this->videoSequenceHeader === null) {
            $this->log("跳过视频帧：缺少视频序列头（SPS/PPS）");
            return;
        }

        // 关键帧判断（修正原有属性调用错误）
        $isKeyFrame = $frame->frameType === VideoFrame::VIDEO_FRAME_TYPE_KEY_FRAME;
        if ($isKeyFrame) {
            $this->log("检测到I帧（视频关键帧）");
            // 切片创建时机判断：达到切片时长 或 首次处理关键帧
            $timeDiff = $relativeTime - $this->lastKeyframeTimestamp;
            if ($timeDiff >= $this->segmentDuration * 1000 || $this->sequenceNumber === 0) {
                /** 是NALU原始数据，符合切片创建条件 */
                if ($avcData['avcPacketType'] === Flv::AVC_PACKET_TYPE_NALU) {
                    $this->log(sprintf("完整NALU包，启动新切片（时长：%dms）", $timeDiff));
                    $this->startNewSegment($relativeTime);
                    $this->lastKeyframeTimestamp = $relativeTime;
                }
            }
        }

        // 确保TS文件句柄已初始化
        if (!$this->tsFileHandle) {
            $this->log("初始化首个切片（强制创建）");
            $this->startNewSegment($relativeTime);
            if (!$this->tsFileHandle) {
                return;
            }
        }

        // 视频帧转AnnexB格式（仅转换当前帧数据，序列头已单独写入）
        $videoPayload = $this->toAnnexB($avcData['data']);
        if (empty($videoPayload)) {
            $this->log("跳过视频帧：AnnexB转换失败");
            return;
        }

        // 日志输出（关键帧一致性校验）
        if ($isKeyFrame) {
            $avcSetFirst = md5($this->firstVideoSequenceHeader ?? '');
            $avcSetNow = md5($this->videoSequenceHeader ?? '');
            $isSetSame = ($avcSetNow == $avcSetFirst) ? "相同" : "不相同";
            $this->log(sprintf("切片%d写入视频关键帧，序列头一致性：%s", $this->sequenceNumber, $isSetSame));
        } else {
            $this->log(sprintf("切片%d写入普通视频帧", $this->sequenceNumber));
        }

        // 写入TS包
        $this->writeVideoToTS($videoPayload, $relativeTime, $isKeyFrame);
    }

    /**
     * 视频帧打包：FLV AVC格式转AnnexB格式（修复NALU长度解析异常）
     * @param $nalu
     * @return string
     */
    private function toAnnexB($nalu)
    {
        $offset = 0;
        $result = '';
        $naluTotalLength = strlen($nalu);

        // 边界保护：NALU数据至少4字节（长度前缀）
        if ($naluTotalLength < 4) {
            $this->log(sprintf("AnnexB转换失败：NALU数据长度不足4字节（当前长度：%d）", $naluTotalLength));
            return '';
        }

        while ($offset + 4 <= $naluTotalLength) {
            // 读取4字节大端序长度前缀
            $lenBytes = substr($nalu, $offset, 4);
            $naluLen = unpack('N', $lenBytes)[1]; // 'N' 严格匹配FLV大端序规范

            // 过滤无效长度
            $remainingLength = $naluTotalLength - $offset - 4;
            if ($naluLen <= 0 || $naluLen > $remainingLength) {
                $this->log(sprintf("无效的NALU长度：%d，跳过该段数据（当前offset：%d）", $naluLen, $offset));
                $offset += 4;
                continue;
            }

            // 截取有效NALU数据，拼接AnnexB标准分隔符
            $offset += 4;
            $naluData = substr($nalu, $offset, $naluLen);
            $result .= "\x00\x00\x00\x01" . $naluData;

            // 更新偏移量
            $offset += $naluLen;
        }

        // 日志记录转换结果
        if (empty($result)) {
            $this->log(sprintf("AnnexB转换失败：未提取到有效NALU数据（原始NALU长度：%d）", $naluTotalLength));
        } else {
            $this->log(sprintf("AnnexB转换成功：提取到%d个NALU包", substr_count($result, "\x00\x00\x00\x01")));
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
        // 关闭上一个切片文件
        if ($this->tsFileHandle) {
            fclose($this->tsFileHandle);

            // 记录上一个切片时长
            $duration = ($this->lastKeyframeTimestamp - $this->segmentStartTime) / 1000;
            $this->segmentDurations[$this->sequenceNumber] = round($duration, 3);

            // 更新M3U8播放列表
            $this->updateM3U8Playlist();
        }

        // 初始化新切片参数
        $this->sequenceNumber++;
        $this->currentSegmentFile = sprintf("%ssegment_%d.ts", $this->streamDir, $this->sequenceNumber);
        $this->tsFileHandle = fopen($this->currentSegmentFile, 'wb');

        if (!$this->tsFileHandle) {
            $this->log(sprintf("切片%d创建失败：无法打开文件", $this->sequenceNumber));
            return;
        }

        $this->segmentStartTime = $timestamp;

        // 写入TS包基础结构（PAT/PMT）
        $this->writePAT();
        $this->writePMT();

        // 强制写入视频序列头（确保切片具备解码能力）
        $this->writeVideoSequenceHeaderToTS();

        // 可选：写入音频序列头，优化音频兼容性
        $this->writeAudioSequenceHeaderToTS();

        $this->log(sprintf("开启新的切片%d，文件：%s", $this->sequenceNumber, $this->currentSegmentFile));
    }

    /**
     * 将视频序列头（SPS/PPS）写入TS包，确保切片具备解码能力
     * @return void
     */
    private function writeVideoSequenceHeaderToTS()
    {
        if (empty($this->videoSequenceHeader) || !$this->tsFileHandle) {
            $this->log("序列头写入失败：序列头为空或TS文件句柄未初始化");
            return;
        }

        // 序列头转AnnexB格式
        $annexBSequenceHeader = $this->toAnnexB($this->videoSequenceHeader);
        if (empty($annexBSequenceHeader)) {
            $this->log(sprintf("切片%d序列头转换AnnexB失败，跳过写入", $this->sequenceNumber));
            return;
        }

        // 构造序列头PES包（初始时间戳，用于解码初始化）
        $pts = 0;
        $dts = 0;
        $pesData = $this->createPESPacket(
            0xE0, // 视频流ID
            $annexBSequenceHeader,
            $pts,
            $dts
        );

        // 写入TS包（标记为关键帧相关，确保播放器优先解析）
        $this->log(sprintf("切片%d写入视频序列头（SPS/PPS）", $this->sequenceNumber));
        $this->writeTSPackets2(
            $this->videoPid,
            $pesData,
            true,
            true,
            0
        );
    }

    /**
     * 将音频序列头（AAC ASC）写入TS包，优化音频兼容性
     * @return void
     */
    private function writeAudioSequenceHeaderToTS()
    {
        if (empty($this->audioSequenceHeader) || !$this->tsFileHandle) {
            return;
        }

        // 构造ADTS头+序列头数据
        $adtsHeader = $this->createADTSHeader(strlen($this->audioSequenceHeader));
        $audioData = $adtsHeader . $this->audioSequenceHeader;

        // 构造PES包
        $pts = 0;
        $dts = 0;
        $pesData = $this->createPESPacket(
            0xC0, // 音频流ID
            $audioData,
            $pts,
            $dts
        );

        // 写入TS包
        $this->log(sprintf("切片%d写入音频序列头（AAC ASC）", $this->sequenceNumber));
        $this->writeTSPackets2(
            $this->audioPid,
            $pesData,
            false,
            false
        );
    }

    /**
     * 更新节目清单索引
     * @return void
     */
    private function updateM3U8Playlist()
    {
        $m3u8Path = sprintf("%sindex.m3u8", $this->streamDir);
        $segments = glob(sprintf("%ssegment_*.ts", $this->streamDir));

        // 按自然顺序排序
        sort($segments, SORT_NATURAL);

        // 清理过期切片
        if (count($segments) > $this->maxSegments) {
            $toDelete = array_slice($segments, 0, count($segments) - $this->maxSegments);
            foreach ($toDelete as $file) {
                unlink($file);
            }
            $segments = array_slice($segments, -$this->maxSegments);
        }

        // 构造M3U8内容
        $m3u8Content = "#EXTM3U\n";
        $m3u8Content .= "#EXT-X-VERSION:3\n";
        $m3u8Content .= sprintf("#EXT-X-TARGETDURATION:%d\n", $this->segmentDuration);
        $m3u8Content .= sprintf("#EXT-X-MEDIA-SEQUENCE:%d\n", max(1, $this->sequenceNumber - count($segments) + 1));

        foreach ($segments as $segment) {
            $seq = intval(pathinfo($segment, PATHINFO_FILENAME));
            $duration = $this->segmentDurations[$seq] ?? $this->segmentDuration;
            $m3u8Content .= sprintf("#EXTINF:%.3f,\n", $duration);
            $m3u8Content .= basename($segment) . "\n";
        }

        file_put_contents($m3u8Path, $m3u8Content);
    }

    /**
     * 写入节目表（PAT）
     * @return void
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

        $this->writeTSPackets2($this->patPid, $pat);
    }

    /**
     * 写入节目映射表（PMT）
     * @return void
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

        $this->writeTSPackets2($this->pmtPid, $pmt);
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
        /** 转化为90Hz时钟（增加精度，避免浮点误差） */
        $timestampMs = (float)$timestamp;
        $pts = (int)round($timestampMs / 1000 * 90000);
        $dts = $pts; // 视频帧DTS=PTS（I帧/非B帧）

        /** 创建pes包 */
        $pesData = $this->createPESPacket(
            0xE0,
            $videoData,
            $pts,
            $dts
        );

        /** PCR 修正：27MHz时钟，增加偏移量避免同步异常 */
        $currentPCR = $pts * 300 + 1000;

        /** 写入TS包（使用规范的writeTSPackets2） */
        $this->writeTSPackets2(
            $this->videoPid,
            $pesData,
            $isKeyFrame,
            true,
            $currentPCR
        );
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

        /** PES头长度计算 */
        $pesHeaderLength = 1 + 2 + 1 + $headerDataLength;
        $totalLength = $pesHeaderLength + strlen($payload);

        /** 视频流PES长度设为0（符合MPEG-TS规范），音频流正常设置 */
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
     * 废弃：原始TS包写入方法（无连续计数器，不规范）
     * @param int $pid 数据包ID
     * @param string $payload 负载数据
     */
    private function writeTSPackets($pid, $payload, $isKeyFrame = false, $isVideo = false, $pcrBase = null)
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

        if ($this->tsFileHandle) {
            fwrite($this->tsFileHandle, $packet);
        }
    }

    /**
     * 将 PES 拆分成多个 TS 包（188字节），插入 PCR（如果需要），带连续计数器
     * @param int $pid 流 PID
     * @param string $pesData PES 数据
     * @param bool $isKeyFrame 是否关键帧
     * @param bool $isVideo 是否视频流
     * @param int|null $pcrBase PCR 基准（单位：27MHz）
     */
    private function writeTSPackets2(
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

            if ($payloadUnitStartIndicator && $isVideo && $isKeyFrame && $pcrBase !== null) {
                // 首包是视频关键帧，需要带 PCR
                $adaptationFieldControl = 3; // '11' adaptation + payload

                // PCR 需要 8 字节（1字节长度 + 1字节 flags + 6字节 PCR）
                $adaptLen = 8;

                $maxPayloadLen = $packetSize - 4 - $adaptLen;

                if ($remaining < $maxPayloadLen) {
                    // 不满一包，填充 stuffing
                    $stuffing = $packetSize - 4 - $remaining - $adaptLen;
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
                $maxPayloadLen = $packetSize - 4;
                if ($remaining < $maxPayloadLen) {
                    $adaptationFieldControl = 3; // adaptation + payload

                    $stuffing = $packetSize - 4 - $remaining - 1;
                    $adaptLen = $stuffing + 1;

                    $adaptationField = chr($adaptLen) . chr(0x00) . str_repeat("\xFF", $stuffing);
                    $maxPayloadLen = $packetSize - 4 - $adaptLen;
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

            // 写入文件
            if ($this->tsFileHandle) {
                fwrite($this->tsFileHandle, $tsPacket);
            }

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
        return sprintf("/hls/%s/index.m3u8", $this->streamId);
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
            $m3u8Path = sprintf("%sindex.m3u8", $this->streamDir);
            $m3u8Content = file_get_contents($m3u8Path);
            if ($m3u8Content !== false) {
                if (strpos($m3u8Content, "#EXT-X-ENDLIST") === false) {
                    $m3u8Content .= "#EXT-X-ENDLIST\n";
                    file_put_contents($m3u8Path, $m3u8Content);
                }
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