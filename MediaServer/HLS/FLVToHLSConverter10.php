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
 * FLV -> HLS (MPEG-TS) 转换器 (修复版)
 * 主要修复：解决关键帧 SPS/PPS 重复插入导致无法解码的问题
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

    // TS 流参数
    private $videoPid = 0x100;
    private $pmtPid = 0x10;
    private $patPid = 0x0000;
    private $audioPid = 0x101;

    // 编解码器数据
    private $videoSequenceHeader = null; // AVC sequence header (AVCDecoderConfigurationRecord)
    private $audioSequenceHeader = null; // AAC AudioSpecificConfig

    // 视频元数据
    private $videoCodecId = null; // 仅处理 H.264

    // 文件句柄
    private $tsFileHandle = null;
    private $segmentDurations = [];

    // 连续计数器，key 为 PID
    private $continuityCounters = [];

    /**
     * 初始化
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

    public function log(string $message)
    {
        file_put_contents($this->streamDir . date('Y_m_d') . ".log", $message . "\r\n", FILE_APPEND);
    }

    /**
     * 处理外部送入的 MediaFrame（音频/视频）
     */
    public function processFrame(mixed $frame)
    {
        if (!$frame instanceof VideoFrame && !$frame instanceof AudioFrame) {
            return;
        }

        // 以第一个视频关键帧时间作为相对时间 0
        if ($frame instanceof VideoFrame && $this->firstTimestamp === null) {
            $videoData = Flv::videoFrameDataRead((string)$frame);
            if (empty($videoData)) {
                return;
            }
            if ($videoData['frameType'] == Flv::VIDEO_FRAME_TYPE_KEY_FRAME) {
                $this->firstTimestamp = $frame->timestamp;
            }
        }

        // 起始时间还没确定时，只缓存序列头，其他一律丢弃，避免黑屏/马赛克
        if ($this->firstTimestamp === null) {
            if ($frame instanceof AudioFrame) {
                $audioData = Flv::audioFrameDataRead((string)$frame);
                if ($audioData['soundFormat'] != Flv::SOUND_FORMAT_ACC) {
                    return;
                }
                $aacData = Flv::accPacketDataRead($audioData['data']);
                if ($aacData['accPacketType'] == Flv::ACC_PACKET_TYPE_SEQUENCE_HEADER) {
                    $this->audioSequenceHeader = $aacData['data'];
                    if ($this->firstAudioSequenceHeader === null) {
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
                    throw new \RuntimeException("仅支持 H.264 编码，当前编码 ID: {$this->videoCodecId}");
                }

                $avcData = Flv::avcPacketRead($videoData['data']);
                if (empty($avcData)) {
                    return;
                }
                if ($avcData['avcPacketType'] == Flv::AVC_PACKET_TYPE_SEQUENCE_HEADER) {
                    $this->videoSequenceHeader = $avcData['data'];
                    if ($this->firstVideoSequenceHeader === null) {
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

    /** 第一个音频序列帧 */
    public $firstAudioSequenceHeader = null;
    /** 第一个视频序列帧 */
    public $firstVideoSequenceHeader = null;

    /**
     * 处理音频帧
     */
    private function processAudioFrame(AudioFrame $frame, $relativeTime)
    {
        $audioData = Flv::audioFrameDataRead((string)$frame);
        if ($audioData['soundFormat'] != Flv::SOUND_FORMAT_ACC) {
            return;
        }

        $aacData = Flv::accPacketDataRead($audioData['data']);

        if ($aacData['accPacketType'] == Flv::ACC_PACKET_TYPE_SEQUENCE_HEADER) {
            $this->log("更新音频序列帧");
            $this->audioSequenceHeader = $aacData['data'];
            if ($this->firstAudioSequenceHeader === null) {
                $this->firstAudioSequenceHeader = $aacData['data'];
            }
            return;
        }

        if (
            $this->tsFileHandle &&
            $this->audioSequenceHeader &&
            $aacData['accPacketType'] == Flv::ACC_PACKET_TYPE_RAW
        ) {
            $this->writeAudioToTS($aacData['data'], $relativeTime);
        }
    }

    /**
     * 打包 AAC 到 TS（ADTS + PES + TS）
     */
    private function writeAudioToTS($aacData, $timestamp)
    {
        $pts = (int)round($timestamp / 1000 * 90000);

        $adtsHeader = $this->createADTSHeader(strlen($aacData));
        $frameWithAdts = $adtsHeader . $aacData;

        $pesData = $this->createPESPacket(
            0xC0,
            $frameWithAdts,
            $pts,
            $pts
        );

        $this->log("写入音频帧到切片{$this->sequenceNumber}");
        $this->writeTSPackets($this->audioPid, $pesData);
    }

    /**
     * 从 AAC AudioSpecificConfig 生成 ADTS 头
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
            throw new \RuntimeException("AAC 帧过长，超过 ADTS 支持的最大长度");
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
     * 处理视频帧 (已修复 SPS/PPS 重复问题)
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

        if ($avcData['avcPacketType'] == Flv::AVC_PACKET_TYPE_SEQUENCE_HEADER) {
            $this->log("更新视频序列帧");
            $this->videoSequenceHeader = $avcData['data'];
            // 调试：只在第一次写一个文件
            static $dumped = false;
            if (!$dumped) {
                $dumped = true;
                file_put_contents(
                    $this->streamDir . 'avc_seq_header.hex',
                    bin2hex($this->videoSequenceHeader)
                );
            }
            if ($this->firstVideoSequenceHeader === null) {
                $this->firstVideoSequenceHeader = $avcData['data'];
            }
            return;
        }

        if ($this->videoSequenceHeader === null) {
            return;
        }

        // 是否关键帧 + 是否需要切片
        $isKeyFrame = false;
        if ($frame->FRAME_TYPE == MediaFrame::VIDEO_FRAME) {
            $avcPack = $frame->getAVCPacket();
            if ($frame->frameType === VideoFrame::VIDEO_FRAME_TYPE_KEY_FRAME) {
                $this->log("I 帧，视频关键帧");
                $isKeyFrame = true;

                $timeDiff = $relativeTime - $this->lastKeyframeTimestamp;
                if ($timeDiff >= $this->segmentDuration * 1000) {
                    if ($avcPack->avcPacketType === AVCPacket::AVC_PACKET_TYPE_NALU) {
                        $this->log("完整的 nalu 包，开启新切片");
                        $this->startNewSegment($relativeTime);
                        $this->lastKeyframeTimestamp = $relativeTime;
                    }
                }
            }
        }

        if ($this->tsFileHandle) {
            $videoPayload = '';

            // 将 FLV 格式的 NALU 转换为 Annex-B 格式
            $currentFrameAnnexB = $this->toAnnexB($avcData['data']);

            if ($isKeyFrame) {
                // 【修复核心逻辑】
                // 1. 获取标准的 SPS/PPS
                $spsPpsAnnexB = $this->avcSequenceHeaderToAnnexB($this->videoSequenceHeader);

                // 2. 检查当前帧数据开头是否已经包含了 SPS/PPS
                // 如果当前帧数据以 0x00000001 67 (SPS) 或 0x00000001 68 (PPS) 开头，说明源数据里已经有了
                // 我们需要去掉这些重复的头部，防止拼接后变成 SPS+PPS+SPS+PPS+IDR
                $cleanFrameData = $this->stripLeadingSPSPps($currentFrameAnnexB);

                // 3. 最终载荷 = 标准 SPS/PPS + 清理后的帧数据
                $videoPayload = $spsPpsAnnexB . $cleanFrameData;
            } else {
                $videoPayload = $currentFrameAnnexB;
            }

            static $dumpedKeyframe = false;
            if ($isKeyFrame && !$dumpedKeyframe) {
                $dumpedKeyframe = true;
                file_put_contents(
                    $this->streamDir . 'keyframe_payload.hex',
                    bin2hex(substr($videoPayload, 0, 128))
                );
            }

            if ($isKeyFrame) {
                $avcSetFirst = md5($this->firstVideoSequenceHeader ?? '');
                $avcSetNow = md5($this->videoSequenceHeader ?? '');
                $isSetSame = ($avcSetNow == $avcSetFirst) ? "相同" : "不相同";
                $this->log("切片{$this->sequenceNumber}写入视频关键帧，序列帧与首序列帧{$isSetSame}");
            } else {
                $this->log("切片{$this->sequenceNumber}写入普通视频帧");
            }

            $this->writeVideoToTS($videoPayload, $relativeTime, $isKeyFrame);
        }
    }

    /**
     * 移除 Annex-B 数据流开头可能存在的 SPS (0x67) 和 PPS (0x68) NALU
     * 用于防止关键帧处理时重复插入
     */
    private function stripLeadingSPSPps(string $annexBData): string
    {
        $offset = 0;
        $len = strlen($annexBData);

        while ($offset + 4 <= $len) {
            // 检查起始码 0x00000001
            if (substr($annexBData, $offset, 4) !== "\x00\x00\x00\x01") {
                // 如果不是起始码，可能是 0x000001 (3 字节)，这里简化处理，假设都是 4 字节起始码
                // 如果遇到非预期数据，直接返回剩余部分
                break;
            }

            if ($offset + 5 > $len) {
                break;
            }

            $nalType = ord($annexBData[$offset + 4]) & 0x1F;

            // 如果是 SPS (7) 或 PPS (8)，跳过这个 NALU
            if ($nalType === 7 || $nalType === 8) {
                // 计算这个 NALU 的长度
                $nextStartCode = strpos($annexBData, "\x00\x00\x00\x01", $offset + 4);
                if ($nextStartCode === false) {
                    // 后面没有了，说明整个数据都是 SPS/PPS，返回空
                    return '';
                }
                // 移动 offset 到下一个 NALU 的开始
                $offset = $nextStartCode;
                continue;
            } else {
                // 遇到了非 SPS/PPS 的 NALU (如 IDR 帧)，返回从这里开始的所有数据
                return substr($annexBData, $offset);
            }
        }

        return $annexBData;
    }

    /**
     * AVCDecoderConfigurationRecord -> Annex‑B SPS/PPS（带容错扫描）
     */
    private function avcSequenceHeaderToAnnexB(string $seq): string
    {
        $len = strlen($seq);
        $result = '';

        if ($len < 7) {
            return $result;
        }

        // 1) 严格按规范解析一遍（成功就直接用）
        $offset = 0;
        $offset += 5; // configurationVersion + profile + compatibility + level + lengthSizeMinusOne
        if ($offset < $len) {
            $numOfSPS = ord($seq[$offset]) & 0x1F;
            $offset++;

            $strictResult = '';
            // SPS
            for ($i = 0; $i < $numOfSPS; $i++) {
                if ($offset + 2 > $len) {
                    $strictResult = '';
                    break;
                }
                $spsLength = (ord($seq[$offset]) << 8) | ord($seq[$offset + 1]);
                $offset += 2;

                if ($spsLength <= 0 || $offset + $spsLength > $len) {
                    $strictResult = '';
                    break;
                }
                $sps = substr($seq, $offset, $spsLength);
                $offset += $spsLength;

                $strictResult .= "\x00\x00\x00\x01" . $sps;
            }

            if ($strictResult !== '' && $offset < $len) {
                if ($offset + 1 <= $len) {
                    $numOfPPS = ord($seq[$offset]);
                    $offset++;

                    for ($i = 0; $i < $numOfPPS; $i++) {
                        if ($offset + 2 > $len) {
                            $strictResult = '';
                            break;
                        }
                        $ppsLength = (ord($seq[$offset]) << 8) | ord($seq[$offset + 1]);
                        $offset += 2;

                        if ($ppsLength <= 0 || $offset + $ppsLength > $len) {
                            $strictResult = '';
                            break;
                        }
                        $pps = substr($seq, $offset, $ppsLength);
                        $offset += $ppsLength;

                        $strictResult .= "\x00\x00\x00\x01" . $pps;
                    }
                }
            }

            if ($strictResult !== '') {
                return $strictResult;
            }
        }

        // 2) 如果严格解析失败，启用“扫描模式”：
        // 在整个 sequence header 里寻找所有 "2 字节长度 + NALU" 结构，
        // 只要 NALU 类型是 SPS(7) 或 PPS(8) 就认为是有效。
        for ($i = 0; $i + 2 <= $len; $i++) {
            $naluLen = (ord($seq[$i]) << 8) | ord($seq[$i + 1]);
            if ($naluLen <= 0 || $naluLen > $len - ($i + 2)) {
                continue;
            }
            $nalu = substr($seq, $i + 2, $naluLen);
            if ($nalu === '' || strlen($nalu) < 1) {
                continue;
            }
            $nalType = ord($nalu[0]) & 0x1F;
            if ($nalType === 7 || $nalType === 8) { // SPS / PPS
                $result .= "\x00\x00\x00\x01" . $nalu;
            }
        }
        return $result;
    }

    /**
     * 把 FLV 里的 length‑prefixed NALU 流转成 Annex‑B
     */
    private function toAnnexB($nalu)
    {
        $offset = 0;
        $result = '';
        $len = strlen($nalu);

        while ($offset + 4 <= $len) {
            $naluLen = unpack('N', substr($nalu, $offset, 4))[1];
            $offset += 4;

            if ($naluLen <= 0 || $offset + $naluLen > $len) {
                break;
            }

            $result .= "\x00\x00\x00\x01" . substr($nalu, $offset, $naluLen);
            $offset += $naluLen;
        }
        return $result;
    }

    /**
     * 开启新 TS 切片
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

        $this->log("开启新的切片{$this->sequenceNumber}");
    }

    /**
     * 更新 m3u8
     */
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

    /**
     * 写 PAT（带 pointer_field）
     */
    private function writePAT()
    {
        $table_id = 0x00;
        $section_syntax_indicator = 1;
        $section_length = 13; // 5(头) + 4(program) + 4(CRC)
        $transport_stream_id = 0x0001;
        $version_number = 0;
        $current_next_indicator = 1;
        $section_number = 0;
        $last_section_number = 0;
        $program_number = 0x0001;
        $program_map_PID = 0xE000 | $this->pmtPid;

        $section_header = chr($table_id);
        $section_header .= chr(
            (($section_syntax_indicator & 0x01) << 7) |
            (0 << 6) |
            (0x03 << 4) | // reserved '11'
            (($section_length >> 8) & 0x0F)
        );
        $section_header .= chr($section_length & 0xFF);
        $section_header .= pack('n', $transport_stream_id);
        $section_header .= chr((0x03 << 5) | (($version_number & 0x1F) << 1) | $current_next_indicator);
        $section_header .= chr($section_number);
        $section_header .= chr($last_section_number);

        $section_data = $section_header;
        $section_data .= pack('n', $program_number);
        $section_data .= pack('n', $program_map_PID);

        $crc = $this->crc32mpeg($section_data);
        $section_data .= pack('N', $crc);

        // pointer_field = 0
        $patPayload = chr(0x00) . $section_data;

        $this->writeTSPackets($this->patPid, $patPayload);
    }

    /**
     * 写 PMT（带 pointer_field，含 H.264 + AAC）
     */
    private function writePMT()
    {
        $table_id = 0x02;
        $section_syntax_indicator = 1;
        $program_number = 0x0001;
        $version_number = 0;
        $current_next_indicator = 1;
        $section_number = 0;
        $last_section_number = 0;
        $PCR_PID = $this->videoPid;
        $program_info_length = 0;

        $video_stream_type = 0x1B; // H.264
        $video_PID = 0xE000 | $this->videoPid;
        $video_ES_info_length = 0;

        $audio_stream_type = 0x0F; // AAC
        $audio_PID = 0xE000 | $this->audioPid;
        $audio_ES_info_length = 0;

        // section_length = 9(头) + 5(video) + 5(audio) + 4(CRC)
        $section_length = 9 + 5 + 5 + 4;

        $section_header = chr($table_id);
        $section_header .= chr(
            (($section_syntax_indicator & 0x01) << 7) |
            (0 << 6) |
            (0x03 << 4) | // reserved '11'
            (($section_length >> 8) & 0x0F)
        );
        $section_header .= chr($section_length & 0xFF);
        $section_header .= pack('n', $program_number);
        $section_header .= chr((0x03 << 5) | (($version_number & 0x1F) << 1) | $current_next_indicator);
        $section_header .= chr($section_number);
        $section_header .= chr($last_section_number);
        $section_header .= pack('n', 0xE000 | $PCR_PID);
        $section_header .= pack('n', 0xF000 | ($program_info_length & 0x0FFF));

        $section_data = $section_header;

        // Video
        $section_data .= chr($video_stream_type);
        $section_data .= pack('n', $video_PID);
        $section_data .= pack('n', 0xF000 | ($video_ES_info_length & 0x0FFF));

        // Audio
        $section_data .= chr($audio_stream_type);
        $section_data .= pack('n', $audio_PID);
        $section_data .= pack('n', 0xF000 | ($audio_ES_info_length & 0x0FFF));

        $crc = $this->crc32mpeg($section_data);
        $section_data .= pack('N', $crc);

        $pmtPayload = chr(0x00) . $section_data;

        $this->writeTSPackets($this->pmtPid, $pmtPayload);
    }

    /**
     * 写入视频 PES -> TS
     */
    private function writeVideoToTS($videoData, $timestamp, $isKeyFrame)
    {
        $pts = (int)round($timestamp / 1000 * 90000);
        $dts = $pts;

        $pesData = $this->createPESPacket(
            0xE0,
            $videoData,
            $pts,
            $dts
        );

        $currentPCR = $pts * 300; // 90kHz -> 27MHz
        $this->writeTSPackets($this->videoPid, $pesData, $isKeyFrame, true, $currentPCR);
    }

    /**
     * 创建 PES 包：packet_length 统统写 0（未指定长度）
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

        $packetLength = 0; // 对 TS 来说 0 是最安全的做法

        $pesHeader = $pesHeaderStart
            . pack('n', $packetLength)
            . chr(0x80)
            . chr($flags)
            . chr($headerDataLength)
            . $headerData;

        return $pesHeader . $payload;
    }

    /**
     * PTS/DTS 编码为 MPEG‑TS 33bit 时间戳
     */
    private function encodeTimestamp($flag, $ts)
    {
        $ts &= 0x1FFFFFFFF;

        $part1 = (($flag << 4) & 0xF0) | ((($ts >> 30) & 0x07) << 1) | 0x01;
        $part2 = ((($ts >> 15) & 0x7FFF) << 1) | 0x01;
        $part3 = (($ts & 0x7FFF) << 1) | 0x01;

        return pack('Cnn', $part1, $part2, $part3);
    }

    /**
     * 拆 PES 为 TS 包（188 字节），必要时写 PCR
     */
    private function writeTSPackets(
        int $pid,
        string $pesData,
        bool $isKeyFrame = false,
        bool $isVideo = false,
        ?int $pcrBase = null
    ) {
        $packetSize = 188;
        $syncByte = 0x47;

        if (!isset($this->continuityCounters[$pid])) {
            $this->continuityCounters[$pid] = 0;
        }
        $continuityCounter = &$this->continuityCounters[$pid];

        $offset = 0;
        $pesLength = strlen($pesData);
        $payloadUnitStartIndicator = 1;

        while ($offset < $pesLength) {
            $remaining = $pesLength - $offset;

            $adaptationFieldControl = 1; // '01' payload only
            $adaptationField = '';
            $headerLen = 4;
            $maxPayloadLen = $packetSize - $headerLen;

            if ($payloadUnitStartIndicator && $isVideo && $isKeyFrame && $pcrBase !== null) {
                $adaptationFieldControl = 3; // adaptation + payload
                $adaptLen = 8; // length(1) + flags(1) + PCR(6)
                $maxPayloadLen -= $adaptLen;

                $pcrBase33 = $pcrBase & 0x1FFFFFFFF;
                $pcrExt = 0;

                // 简单的填充逻辑，确保 PCR 放在第一个包
                $pcrBytes = pack('N', $pcrBase33 >> 1)
                    . pack('n', ($pcrBase33 & 0x1) << 15)
                    . pack('n', $pcrExt << 7);

                $stuffing = 0;
                if ($remaining < $maxPayloadLen) {
                    $stuffing = $packetSize - $headerLen - $remaining - 7; // 7 = 1(len) + 1(flags) + 5(pcr)
                    if ($stuffing < 0) $stuffing = 0;
                    $adaptLen = 7 + $stuffing;
                    $adaptationField = chr($adaptLen) . chr(0x10) . $pcrBytes . str_repeat("\xFF", $stuffing);
                } else {
                    $adaptationField = chr(7) . chr(0x10) . $pcrBytes;
                }
            } else {
                if ($remaining < $maxPayloadLen) {
                    $adaptationFieldControl = 3;
                    $stuffing = $packetSize - $headerLen - $remaining - 1;
                    if ($stuffing < 0) {
                        $stuffing = 0;
                    }
                    $adaptationField = chr($stuffing) . str_repeat("\xFF", $stuffing);
                    $maxPayloadLen -= ($stuffing + 1);
                }
            }

            $payloadLen = min($remaining, $maxPayloadLen);
            $payload = substr($pesData, $offset, $payloadLen);

            $header = chr($syncByte);
            $header .= chr(($payloadUnitStartIndicator << 6) | (($pid >> 8) & 0x1F));
            $header .= chr($pid & 0xFF);
            $header .= chr(($adaptationFieldControl << 4) | ($continuityCounter & 0x0F));

            $continuityCounter = ($continuityCounter + 1) & 0x0F;
            $payloadUnitStartIndicator = 0;

            $tsPacket = $header;
            if ($adaptationFieldControl & 0x2) {
                $tsPacket .= $adaptationField;
            }
            $tsPacket .= $payload;

            $padLen = $packetSize - strlen($tsPacket);
            if ($padLen > 0) {
                $tsPacket .= str_repeat("\xFF", $padLen);
            }

            fwrite($this->tsFileHandle, $tsPacket);

            $offset += $payloadLen;
        }
    }

    /**
     * MPEG-TS CRC32
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
     * 获取 HLS 播放地址
     */
    public function getHlsUrl()
    {
        return "/hls/{$this->streamId}/index.m3u8";
    }

    /**
     * 关闭输出
     */
    public function close()
    {
        if ($this->tsFileHandle) {
            fclose($this->tsFileHandle);
            $this->tsFileHandle = null;

            $m3u8Path = "{$this->streamDir}index.m3u8";
            $m3u8Content = @file_get_contents($m3u8Path);
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