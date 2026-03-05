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
 * FLV -> HLS (MPEG-TS) 转换器 (终极修复版)
 * 核心修复：
 * 1. 关键帧判断逻辑错误
 * 2. 单帧TS写入逻辑（强制写入PAT/PMT/视频帧）
 * 3. 跳过stripLeadingSPSPps，保证IDR帧不丢失
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

    // 调试标记
    private $debugSingleFrameGenerated = false;

    // 第一个序列头缓存
    public $firstAudioSequenceHeader = null;
    public $firstVideoSequenceHeader = null;

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
        // 调试：清空旧的调试文件
        $debugFiles = glob($this->streamDir . 'debug_*');
        foreach ($debugFiles as $file) {
            unlink($file);
        }
    }

    public function log(string $message)
    {
        $logFile = $this->streamDir . date('Y_m_d') . ".log";
        file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] " . $message . "\r\n", FILE_APPEND);
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
            // 修复：正确的关键帧判断
            if ($videoData['frameType'] == Flv::VIDEO_FRAME_TYPE_KEY_FRAME) {
                $this->firstTimestamp = $frame->timestamp;
                $this->log("第一个关键帧时间戳：{$this->firstTimestamp}");
            }
        }

        // 起始时间还没确定时，只缓存序列头
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
                    $this->log("缓存音频序列头");
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
                    $this->log("缓存视频序列头");
                    // 调试：保存序列头
                    file_put_contents($this->streamDir . 'debug_seq_header.hex', bin2hex($this->videoSequenceHeader));
                    return;
                }
            }
            return;
        }

        $relativeTime = $frame->timestamp - $this->firstTimestamp;
        $this->log("处理帧：类型=" . ($frame instanceof VideoFrame ? "视频" : "音频") . " 相对时间={$relativeTime}ms");

        if ($frame instanceof VideoFrame) {
            $this->processVideoFrame($frame, $relativeTime);
        } elseif ($frame instanceof AudioFrame) {
            $this->processAudioFrame($frame, $relativeTime);
        }
    }

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
     * 处理视频帧 (终极修复版)
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
            $this->log("视频序列头为空，跳过");
            return;
        }

        // 修复：正确的关键帧判断
        $isKeyFrame = ($videoData['frameType'] == Flv::VIDEO_FRAME_TYPE_KEY_FRAME) && ($avcData['avcPacketType'] == Flv::AVC_PACKET_TYPE_NALU);
        if ($isKeyFrame) {
            $this->log("检测到关键帧，相对时间：{$relativeTime}ms");

            // 切片逻辑
            $timeDiff = $relativeTime - $this->lastKeyframeTimestamp;
            if ($timeDiff >= $this->segmentDuration * 1000 || $this->tsFileHandle === null) {
                $this->startNewSegment($relativeTime);
                $this->lastKeyframeTimestamp = $relativeTime;
            }
        }

        if ($this->tsFileHandle) {
            $videoPayload = '';

            // 将 FLV 格式的 NALU 转换为 Annex-B 格式
            $currentFrameAnnexB = $this->toAnnexB($avcData['data']);
            // 调试：保存原始转换后的帧数据
            static $dumpRawFrame = false;
            if ($isKeyFrame && !$dumpRawFrame) {
                $dumpRawFrame = true;
                file_put_contents($this->streamDir . 'debug_raw_annexb.hex', bin2hex($currentFrameAnnexB));
            }

            if ($isKeyFrame) {
                // 终极修复：跳过stripLeadingSPSPps，直接拼接SPS/PPS+原始帧
                $spsPpsAnnexB = $this->avcSequenceHeaderToAnnexB($this->videoSequenceHeader);
                $videoPayload = $spsPpsAnnexB . $currentFrameAnnexB;

                // 调试：保存最终的videoPayload
                file_put_contents($this->streamDir . 'debug_final_payload.hex', bin2hex($videoPayload));
                $this->log("关键帧Payload长度：" . strlen($videoPayload) . "字节");

                // 生成单帧调试文件
                $this->generateDebugSingleFrame($videoPayload, $relativeTime);
            } else {
                $videoPayload = $currentFrameAnnexB;
            }

            // 日志
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
     * 移除 Annex-B 数据流开头的 SPS/PPS（暂时不用，先保证IDR不丢）
     */
    private function stripLeadingSPSPps(string $annexBData): string
    {
        return $annexBData; // 直接返回，跳过清理
    }

    /**
     * AVCDecoderConfigurationRecord -> Annex‑B SPS/PPS
     */
    private function avcSequenceHeaderToAnnexB(string $seq): string
    {
        $len = strlen($seq);
        $result = '';

        if ($len < 7) {
            $this->log("序列头长度不足，无法解析：长度=" . $len);
            return $result;
        }

        // 解析AVCDecoderConfigurationRecord核心字段
        $configurationVersion = ord($seq[0]);
        $avcProfileIndication = ord($seq[1]);
        $profileCompatibility = ord($seq[2]);
        $avcLevelIndication = ord($seq[3]);
        $lengthSizeMinusOne = ord($seq[4]) & 0x03; // 关键：NALU长度字段的字节数-1（必须是3，对应4字节）
        $this->log("序列头解析：profile={$avcProfileIndication} level={$avcLevelIndication} lengthSizeMinusOne={$lengthSizeMinusOne}");

        // 强制设置为4字节长度（修复nal_length_size=0问题）
        if ($lengthSizeMinusOne != 3) {
            $this->log("警告：NALU长度字段异常，强制设置为4字节");
            $lengthSizeMinusOne = 3;
        }

        $offset = 5;
        $numOfSPS = ord($seq[$offset]) & 0x1F;
        $offset++;
        $this->log("SPS数量：{$numOfSPS}");

        for ($i = 0; $i < $numOfSPS; $i++) {
            if ($offset + 2 > $len) {
                $this->log("SPS{$i}解析越界，offset={$offset} len={$len}");
                break;
            }
            $spsLength = (ord($seq[$offset]) << 8) | ord($seq[$offset + 1]);
            $offset += 2;
            if ($spsLength <= 0 || $offset + $spsLength > $len) {
                $this->log("SPS{$i}长度无效：{$spsLength}");
                break;
            }
            $sps = substr($seq, $offset, $spsLength);
            $offset += $spsLength;

            // 核心修复：强制添加4字节起始码 + 记录SPS内容
            $result .= "\x00\x00\x00\x01" . $sps;
            $this->log("解析SPS{$i}：长度=" . strlen($sps) . " 内容=" . bin2hex(substr($sps, 0, 20)));
        }

        if ($offset + 1 <= $len) {
            $numOfPPS = ord($seq[$offset]);
            $offset++;
            $this->log("PPS数量：{$numOfPPS}");

            for ($i = 0; $i < $numOfPPS; $i++) {
                if ($offset + 2 > $len) {
                    $this->log("PPS{$i}解析越界，offset={$offset} len={$len}");
                    break;
                }
                $ppsLength = (ord($seq[$offset]) << 8) | ord($seq[$offset + 1]);
                $offset += 2;
                if ($ppsLength <= 0 || $offset + $ppsLength > $len) {
                    $this->log("PPS{$i}长度无效：{$ppsLength}");
                    break;
                }
                $pps = substr($seq, $offset, $ppsLength);
                $offset += $ppsLength;

                // 核心修复：强制添加4字节起始码
                $result .= "\x00\x00\x00\x01" . $pps;
                $this->log("解析PPS{$i}：长度=" . strlen($pps) . " 内容=" . bin2hex(substr($pps, 0, 20)));
            }
        }

        // 调试：保存解析后的SPS/PPS
        file_put_contents($this->streamDir . 'debug_sps_pps.hex', bin2hex($result));

        if (strlen($result) == 0) {
            $this->log("SPS/PPS解析失败，原始序列头：" . bin2hex($seq));
        } else {
            $this->log("SPS/PPS解析成功，总长度：" . strlen($result) . "字节");
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
        $lengthSize = 4; // 强制4字节长度（对应lengthSizeMinusOne=3）

        $this->log("转换NALU到Annex-B：总长度={$len} 长度字段={$lengthSize}字节");

        while ($offset + $lengthSize <= $len) {
            // 读取NALU长度（4字节）
            if ($lengthSize == 4) {
                $naluLen = unpack('N', substr($nalu, $offset, 4))[1];
            } else {
                $naluLen = unpack('n', substr($nalu, $offset, 2))[1];
            }
            $offset += $lengthSize;

            if ($naluLen <= 0 || $offset + $naluLen > $len) {
                $this->log("NALU长度无效：{$naluLen} offset={$offset}");
                break;
            }

            $naluData = substr($nalu, $offset, $naluLen);
            $nalType = ord($naluData[0]) & 0x1F;
            $nalTypeName = $this->getNalTypeName($nalType);

            // 强制添加4字节起始码
            $result .= "\x00\x00\x00\x01" . $naluData;
            $this->log("解析NALU：类型={$nalType}({$nalTypeName}) 长度={$naluLen}");

            $offset += $naluLen;
        }

        // 调试：保存转换后的NALU
        file_put_contents($this->streamDir . 'debug_annexb_all.hex', bin2hex($result));

        return $result;
    }

// 新增：NALU类型名称映射（方便调试）
    private function getNalTypeName(int $type): string
    {
        $map = [
            0 => 'UNSPECIFIED',
            1 => 'SLICE',
            5 => 'IDR',
            6 => 'SEI',
            7 => 'SPS',
            8 => 'PPS',
            9 => 'AUD',
            10 => 'EOSEQ',
            11 => 'EOSTREAM',
            12 => 'FILLER',
        ];
        return $map[$type] ?? "UNKNOWN({$type})";
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
        if (!$this->tsFileHandle) {
            $this->log("创建切片失败：{$this->currentSegmentFile}");
            return;
        }
        $this->segmentStartTime = $timestamp;

        $this->writePAT();
        $this->writePMT();

        $this->log("开启新的切片{$this->sequenceNumber}：{$this->currentSegmentFile}");
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
     * 写 PAT
     */
    private function writePAT()
    {
        $table_id = 0x00;
        $section_syntax_indicator = 1;
        $section_length = 13;
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
            (0x03 << 4) |
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

        $patPayload = chr(0x00) . $section_data;

        $this->writeTSPackets($this->patPid, $patPayload);
    }

    /**
     * 写 PMT
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

        $video_stream_type = 0x1B;
        $video_PID = 0xE000 | $this->videoPid;
        $video_ES_info_length = 0;

        $audio_stream_type = 0x0F;
        $audio_PID = 0xE000 | $this->audioPid;
        $audio_ES_info_length = 0;

        $section_length = 9 + 5 + 5 + 4;

        $section_header = chr($table_id);
        $section_header .= chr(
            (($section_syntax_indicator & 0x01) << 7) |
            (0 << 6) |
            (0x03 << 4) |
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
        $section_data .= chr($video_stream_type);
        $section_data .= pack('n', $video_PID);
        $section_data .= pack('n', 0xF000 | ($video_ES_info_length & 0x0FFF));
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
        // 正确的PTS/DTS计算（90kHz时钟）
        $pts = (int)round(($timestamp / 1000) * 90000);
        $dts = $pts;

        // 核心修复：正确的PCR计算（MPEG-TS标准：PCR = PTS * 300 = 90kHz * 300 = 27MHz）
        $pcrBase = $pts * 300;
        // 确保PCR是33位（MPEG-TS标准）
        $pcrBase = $pcrBase & 0x1FFFFFFFF;

        $pesData = $this->createPESPacket(
            0xE0,
            $videoData,
            $pts,
            $dts
        );

        $this->log("写入视频帧：PTS={$pts} DTS={$dts} PCR={$pcrBase} 关键帧={$isKeyFrame} 长度=" . strlen($videoData));
        $this->writeTSPackets($this->videoPid, $pesData, $isKeyFrame, true, $pcrBase);
    }

    /**
     * 创建 PES 包
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

        // 核心修复：手动计算PES长度（不再用0）
        $pesHeaderLength = 3 + 2 + 3 + $headerDataLength; // 起始码(3) + 长度(2) + 标志位(3) + 时间戳数据
        $packetLength = $pesHeaderLength + strlen($payload);
        if ($packetLength > 0xFFFF) {
            $packetLength = 0; // 超过65535时用0
        }

        $pesHeader = $pesHeaderStart
            . pack('n', $packetLength) // 修复：设置实际长度
            . chr(0x80) // PES_scrambling_control=0, PES_priority=0, data_alignment_indicator=1
            . chr($flags) // PTS/DTS标志
            . chr($headerDataLength) // 头部数据长度
            . $headerData;

        return $pesHeader . $payload;
    }

    /**
     * PTS/DTS 编码
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
     * 拆 PES 为 TS 包
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

        if ($pesLength == 0) {
            $this->log("PES数据为空，PID：0x" . dechex($pid));
            return;
        }

        while ($offset < $pesLength) {
            $remaining = $pesLength - $offset;

            $adaptationFieldControl = 1;
            $adaptationField = '';
            $headerLen = 4;
            $maxPayloadLen = $packetSize - $headerLen;

            if ($payloadUnitStartIndicator && $isVideo && $isKeyFrame && $pcrBase !== null) {
                $adaptationFieldControl = 3;
                $adaptLen = 8;
                $maxPayloadLen -= $adaptLen;

                $pcrBase33 = $pcrBase & 0x1FFFFFFFF;
                $pcrExt = 0;
                $pcrBytes = pack('N', $pcrBase33 >> 1)
                    . pack('n', ($pcrBase33 & 0x1) << 15)
                    . pack('n', $pcrExt << 7);

                $stuffing = 0;
                if ($remaining < $maxPayloadLen) {
                    $stuffing = $packetSize - $headerLen - $remaining - 7;
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

            $writeLen = fwrite($this->tsFileHandle, $tsPacket);
            if ($writeLen !== 188) {
                $this->log("TS包写入失败：PID=0x" . dechex($pid) . " 预期188字节，实际写入{$writeLen}字节");
            }

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
     * 生成单帧调试TS文件（终极修复版）
     */
    private function generateDebugSingleFrame(string $videoPayload, int $relativeTime)
    {
        if ($this->debugSingleFrameGenerated) {
            return;
        }
        $this->debugSingleFrameGenerated = true;

        $singleFrameTsFile = $this->streamDir . 'debug_single_keyframe.ts';
        $tsHandle = fopen($singleFrameTsFile, 'wb');
        if (!$tsHandle) {
            $this->log("生成单帧TS失败：无法创建文件 {$singleFrameTsFile}");
            return;
        }
        $this->log("开始生成单帧调试TS：{$singleFrameTsFile}");

        // ========== 只写入标准PAT/PMT/视频帧（删除手动构造的包，避免冲突） ==========
        // 重置连续计数器，保证PAT/PMT包的连续性
        $this->continuityCounters = [];

        // 写入标准PAT（复用现有writePAT逻辑，保证格式统一）
        $patPayload = $this->buildPATPayload();
        $this->writeTSPacketsToHandle($tsHandle, $this->patPid, $patPayload);

        // 写入标准PMT（复用现有writePMT逻辑）
        $pmtPayload = $this->buildPMTPayload();
        $this->writeTSPacketsToHandle($tsHandle, $this->pmtPid, $pmtPayload);

        // 写入视频帧（带PCR，保证可播放）
        $pts = (int)round($relativeTime / 1000 * 90000);
        $dts = $pts;
        $pesData = $this->createPESPacket(0xE0, $videoPayload, $pts, $dts);
        $currentPCR = $pts * 300;
        $this->writeTSPacketsToHandle($tsHandle, $this->videoPid, $pesData, true, true, $currentPCR);

        // ========== 修复空音频帧逻辑（关键：用最小有效AAC帧，而非空帧） ==========
        $this->log("写入兼容音频帧适配VLC播放");
        // 构造最小有效AAC帧（ADTS头 + 空数据，长度设为1而非0）
        $emptyAdts = $this->createADTSHeader(1); // 修复：长度设为1，避免ADTS头异常
        $emptyAacData = "\x00"; // 最小空AAC数据
        $emptyAacFrame = $emptyAdts . $emptyAacData;
        $emptyAacPes = $this->createPESPacket(0xC0, $emptyAacFrame, $pts, $pts);
        $this->writeTSPacketsToHandle($tsHandle, $this->audioPid, $emptyAacPes);

        fclose($tsHandle);
        $fileSize = filesize($singleFrameTsFile);
        $this->log("单帧TS生成完成，文件大小：{$fileSize}字节");

        // 生成16进制文件
        // 1. 关键帧原始数据
        $rawHex = implode(' ', str_split(bin2hex($videoPayload), 2));
        $idrPos = strpos($rawHex, '00 00 00 01 65');
        $nalType = $idrPos ? '65' : (strpos($rawHex, '00 00 00 01 68') ? '68' : '无');
        $rawHexComment = "\n=== 实时转码第一个关键帧解析 ===\n";
        $rawHexComment .= "SPS/PPS起始码：" . substr($rawHex, 0, 11) . " → " . (substr($rawHex, 0, 11) === '00 00 00 01' ? '正确' : '错误') . "\n";
        $rawHexComment .= "关键帧NALU类型：0x{$nalType} → " . ($nalType === '65' ? 'IDR帧（正确）' : ($nalType === '68' ? 'PPS（错误）' : '无（错误）')) . "\n";
        $rawHexComment .= "IDR帧位置：" . ($idrPos ? "第{$idrPos}个字符" : "未找到") . "\n";
        file_put_contents($this->streamDir . 'debug_keyframe_raw.hex', $rawHex . $rawHexComment);

        // 2. TS文件16进制 + 精准搜索逻辑
        $tsContent = file_get_contents($singleFrameTsFile);
        $tsHex = bin2hex($tsContent);
        $tsHexSplit = implode(' ', str_split($tsHex, 2));

        // 精准搜索：遍历所有188字节的TS包头部
        $patPos = -1;
        $pmtPos = -1;
        $videoPos = -1;
        $packetCount = strlen($tsContent) / 188;

        for ($i = 0; $i < $packetCount; $i++) {
            $packetOffset = $i * 188;
            $packetHeader = substr($tsContent, $packetOffset, 4);
            $headerHex = bin2hex($packetHeader);

            // 匹配PAT包（PID=0x0000）
            if (substr($headerHex, 0, 2) === '47' && substr($headerHex, 2, 4) === '4000') {
                $patPos = $packetOffset;
            }
            // 匹配PMT包（PID=0x0010）
            if (substr($headerHex, 0, 2) === '47' && substr($headerHex, 2, 4) === '4010') {
                $pmtPos = $packetOffset;
            }
            // 匹配视频包（PID=0x100）
            if (substr($headerHex, 0, 2) === '47' && substr($headerHex, 2, 4) === '4100') {
                $videoPos = $packetOffset;
            }
        }

        $tsHexComment = "\n=== 单帧TS文件解析 ===\n";
        $tsHexComment .= "TS包魔数（第1字节）：" . substr($tsHex, 0, 2) . " → " . (substr($tsHex, 0, 2) === '47' ? '正确' : '错误（应为47）') . "\n";
        $tsHexComment .= "PAT表PID：0x0000 → " . ($patPos >= 0 ? '存在' : '缺失') . " (特征：474000)\n";
        $tsHexComment .= "PMT表PID：0x0010 → " . ($pmtPos >= 0 ? '存在' : '缺失') . " (特征：474010)\n";
        $tsHexComment .= "视频PID（0x100）：0x100 → " . ($videoPos >= 0 ? '存在' : '缺失') . " (特征：474100)\n";
        $tsHexComment .= "TS文件总字节数：" . strlen($tsContent) . "\n";
        $tsHexComment .= "PAT特征位置：" . ($patPos >= 0 ? $patPos : '未找到') . "\n";
        $tsHexComment .= "PMT特征位置：" . ($pmtPos >= 0 ? $pmtPos : '未找到') . "\n";
        $tsHexComment .= "视频PID特征位置：" . ($videoPos >= 0 ? $videoPos : '未找到') . "\n";

        file_put_contents($this->streamDir . 'debug_single_keyframe_ts.hex', $tsHexSplit . $tsHexComment);

        $this->log("单帧调试文件生成完成：");
        $this->log("- 单帧TS：{$singleFrameTsFile}");
        $this->log("- PAT位置：" . ($patPos >= 0 ? $patPos : '未找到'));
        $this->log("- PMT位置：" . ($pmtPos >= 0 ? $pmtPos : '未找到'));
        $this->log("- 视频PID位置：" . ($videoPos >= 0 ? $videoPos : '未找到'));

        // ========== 新增：导出纯H.264文件用于验证 ==========
        file_put_contents($this->streamDir . 'debug_keyframe.h264', $videoPayload);
        $this->log("导出纯H.264文件：{$this->streamDir}debug_keyframe.h264");
    }

    /**
     * 构建标准PAT载荷（复用writePAT逻辑）
     */
    private function buildPATPayload()
    {
        $table_id = 0x00;
        $section_syntax_indicator = 1;
        $section_length = 13;
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
            (0x03 << 4) |
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

        return chr(0x00) . $section_data;
    }

    /**
     * 构建标准PMT载荷（复用writePMT逻辑）
     */
    private function buildPMTPayload()
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

        $video_stream_type = 0x1B;
        $video_PID = 0xE000 | $this->videoPid;
        $video_ES_info_length = 0;

        $audio_stream_type = 0x0F;
        $audio_PID = 0xE000 | $this->audioPid;
        $audio_ES_info_length = 0;

        $section_length = 9 + 5 + 5 + 4;

        $section_header = chr($table_id);
        $section_header .= chr(
            (($section_syntax_indicator & 0x01) << 7) |
            (0 << 6) |
            (0x03 << 4) |
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
        $section_data .= chr($video_stream_type);
        $section_data .= pack('n', $video_PID);
        $section_data .= pack('n', 0xF000 | ($video_ES_info_length & 0x0FFF));
        $section_data .= chr($audio_stream_type);
        $section_data .= pack('n', $audio_PID);
        $section_data .= pack('n', 0xF000 | ($audio_ES_info_length & 0x0FFF));

        $crc = $this->crc32mpeg($section_data);
        $section_data .= pack('N', $crc);

        return chr(0x00) . $section_data;
    }

    /**
     * 写入TS包到指定句柄（复用writeTSPackets逻辑）
     */
    private function writeTSPacketsToHandle(
        $handle,
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

        if ($pesLength == 0) {
            $this->log("PES数据为空，PID：0x" . dechex($pid));
            return;
        }

        while ($offset < $pesLength) {
            $remaining = $pesLength - $offset;

            $adaptationFieldControl = 1;
            $adaptationField = '';
            $headerLen = 4;
            $maxPayloadLen = $packetSize - $headerLen;

            if ($payloadUnitStartIndicator && $isVideo && $isKeyFrame && $pcrBase !== null) {
                $adaptationFieldControl = 3;
                $adaptLen = 7; // 修复：PCR适配字段固定7字节（标准长度）
                $maxPayloadLen -= $adaptLen;

                // ========== 核心修复：正确的PCR编码 ==========
                $pcrBase33 = $pcrBase & 0x1FFFFFFFF; // 33位PCR Base
                $pcrExt = 0; // PCR Ext（9位，暂设为0）

                // MPEG-TS标准PCR编码格式
                $pcrBytes = pack('N', $pcrBase33 >> 1)          // PCR Base [32..1]
                    . chr((($pcrBase33 & 0x1) << 7) | ($pcrExt >> 9)) // PCR Base [0] + PCR Ext [8]
                    . pack('n', ($pcrExt & 0x1FF) << 7);        // PCR Ext [7..0] + 保留位

                // 适配字段：长度(1) + 标志(1) + PCR(6)
                $adaptationField = chr($adaptLen)          // 适配字段长度（7）
                    . chr(0x10)                            // 仅PCR标志位
                    . $pcrBytes;                           // PCR数据（6字节）

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

            fwrite($handle, $tsPacket);
            $offset += $payloadLen;
        }
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