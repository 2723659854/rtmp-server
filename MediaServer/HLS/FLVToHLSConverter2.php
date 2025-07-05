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
 * FLV到HLS转换器（支持音视频）
 * 功能：将FLV格式的H.264视频流和AAC音频流转换为HLS格式
 * 注意：依赖MediaServer\Flv\Flv类解析FLV帧数据
 * @command 检查切片是否正常的命令 ffprobe -v error -show_format -show_streams segment_1.ts
 * @command 切片检查命令2 ffmpeg -i segment_1.ts -c copy test.mp4
 */
class FLVToHLSConverter2
{
    // 配置参数
    private $segmentDuration = 4;       // 切片时长(秒)
    private $maxSegments = 10;          // 最大保留切片数
    private $streamId;                  // 流ID
    private $streamDir;                 // 流目录（存储TS切片和M3U8）

    // 状态变量
    private $sequenceNumber = 0;        // 切片序号
    private $currentSegmentFile;        // 当前切片文件路径
    private $segmentStartTime = 0;      // 当前切片开始时间（毫秒）
    private $firstTimestamp = null;     // 首个帧时间戳（用于计算相对时间）
    private $lastKeyframeTimestamp = 0; // 上一个关键帧时间戳（毫秒）

    // TS流参数（MPEG-TS规范）
    private $videoPid = 0x100;          // 视频PID（0x100-0x1FFF有效范围）
    private $pmtPid = 0x10;             // PMT表PID
    private $patPid = 0;                // PAT表PID（固定为0）

    private $audioPid = 0x101;          // 音频PID

    // 视频元数据
    private $videoCodecId = null;       // 视频编码ID（仅处理H.264）
    private $videoSequenceHeader = null;// 视频序列头（SPS/PPS，解码必需）

    // 音频元数据
    private $audioCodecId = null;       // 音频编码ID
    private $audioSequenceHeader = null;// 音频序列头（AAC解码必需）

    // 文件句柄
    private $tsFileHandle = null;       // 当前TS文件句柄

    // 新增状态标记
    private $isFirstSegment = true;     // 是否为第一个切片
    private $pendingAudioFrames = [];   // 暂存的音频帧（等待关键帧）


    /**
     * 构造函数
     * @param string $streamId 流唯一标识
     * @param array $config 配置参数（可选）
     *  - segmentDuration: 切片时长（秒）
     *  - maxSegments: 最大保留切片数
     */
    public function __construct($streamId, $config = [])
    {
        $this->streamId = $streamId;
        $this->streamDir = dirname(__DIR__, 2) . "/hls/{$streamId}/";

        // 应用配置（带参数校验）
        if (isset($config['segmentDuration']) && is_numeric($config['segmentDuration']) && $config['segmentDuration'] > 0) {
            $this->segmentDuration = (int)$config['segmentDuration'];
        }
        if (isset($config['maxSegments']) && is_numeric($config['maxSegments']) && $config['maxSegments'] > 0) {
            $this->maxSegments = (int)$config['maxSegments'];
        }

        // 初始化目录（确保目录可写）
        if (!is_dir($this->streamDir)) {
            if (!mkdir($this->streamDir, 0777, true)) {
                throw new \RuntimeException("无法创建流目录: {$this->streamDir}");
            }
        }
    }


    /**
     * 处理FLV帧数据（对外接口）
     * @param mixed  $frame 从FLV中解析的视频帧
     * @throws \RuntimeException 若帧处理失败
     */
    public function processFrame($frame)
    {
        // 仅处理视频帧和音频帧
        if (!$frame instanceof VideoFrame && !$frame instanceof AudioFrame) {
            return;
        }

        // 初始化首个时间戳
        if ($this->firstTimestamp === null) {
            $this->firstTimestamp = $frame->timestamp;
        }

        // 计算相对时间（毫秒）
        $relativeTime = $frame->timestamp - $this->firstTimestamp;
        if ($frame instanceof VideoFrame) {
            $this->processVideoFrame($frame, $relativeTime);
        } elseif ($frame instanceof AudioFrame) {
            // 关键修改：首个切片且未写入关键帧时，暂存音频帧
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

    /**
     * 处理音频数据
     * @param AudioFrame $frame
     * @param $relativeTime
     * @return void
     */
    private function processAudioFrame(AudioFrame $frame, $relativeTime)
    {
        $audioData = Flv::audioFrameDataRead((string)$frame);
        if ($audioData['soundFormat'] != Flv::SOUND_FORMAT_ACC) {
            return; // 仅处理AAC音频
        }

        $aacData = Flv::accPacketDataRead($audioData['data']);
        if ($aacData['accPacketType'] == Flv::ACC_PACKET_TYPE_SEQUENCE_HEADER) {
            $this->audioSequenceHeader = $aacData['data']; // 保存序列头
            return;
        }

        // 确保序列头已获取且当前是音频数据帧
        if ($this->audioSequenceHeader && $aacData['accPacketType'] == Flv::ACC_PACKET_TYPE_RAW) {
            // 生成ADTS头并封装音频数据
            $adtsHeader = $this->createADTSHeader(strlen($aacData['data']));
            $frameWithAdts = $adtsHeader . $aacData['data'];

            // 写入TS（音频PID=0x101，流ID=0xC0）
            $pts = (int)($relativeTime / 1000 * 90000); // 转换为90kHz
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

        // 解析AAC参数（同之前）
        $audioObjectType = (($asc1 >> 3) & 0x1F);
        $samplingFreqIdx = (($asc1 & 0x07) << 1) | (($asc2 >> 7) & 0x01);
        $channelConfig = (($asc2 >> 3) & 0x0F);

        $sampleRates = [96000, 88200, 64000, 48000, 44100, 32000, 24000, 22050, 16000, 12000, 11025, 8000];
        $sampleRate = $sampleRates[$samplingFreqIdx] ?? 44100;

        // 关键修正：ADTS总长度 = ADTS头（7字节） + AAC数据长度
        $adtsTotalLength = 7 + $aacDataLength;
        if ($adtsTotalLength > 0x1FFF) {
            throw new \RuntimeException("AAC帧过长，超过ADTS支持的最大长度");
        }

        // 构建ADTS头（字段含义同之前，确保长度计算正确）
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
     * AnnexB
     * @param $nalu
     * @return string
     */
    private function toAnnexB($nalu)
    {
        // 有些 FLV 帧里 NAL 是长度前缀而非 start code 前缀
        // 必须替换为 Annex B start code
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
     * 处理视频帧（内部方法）
     * @param VideoFrame $frame 视频帧
     * @param int $relativeTime 相对时间（毫秒，相对于首个帧）
     */
    private function processVideoFrame(VideoFrame $frame, $relativeTime)
    {
        // 解析FLV视频帧头（依赖Flv类）
        $videoData = Flv::videoFrameDataRead((string)$frame);
        if (empty($videoData)) {
            throw new \RuntimeException("无法解析视频帧数据");
        }

        // 验证编码格式（仅支持H.264）
        $this->videoCodecId = $videoData['codecId'];
        if ($this->videoCodecId != Flv::VIDEO_CODEC_ID_AVC) {
            throw new \RuntimeException("仅支持H.264编码，当前编码ID: {$this->videoCodecId}");
        }

        // 解析AVC帧数据（包含NAL单元）
        $avcData = Flv::avcPacketRead($videoData['data']);
        if (empty($avcData)) {
            throw new \RuntimeException("无法解析AVC帧数据");
        }

        // 处理序列头（SPS/PPS，必须优先获取）
        if ($avcData['avcPacketType'] == Flv::AVC_PACKET_TYPE_SEQUENCE_HEADER) {
            $this->videoSequenceHeader = $avcData['data'];
            return;
        }

        // 必须先获取序列头才能处理帧数据
        if ($this->videoSequenceHeader === null) {
            throw new \RuntimeException("未获取视频序列头（SPS/PPS），无法处理帧数据");
        }

        // 判断是否为关键帧（I帧）
        $isKeyFrame = ($videoData['frameType'] == Flv::VIDEO_FRAME_TYPE_KEY_FRAME);

        // 关键帧触发新切片（满足时长条件时）
        if ($isKeyFrame) {
            $timeDiff = $relativeTime - $this->lastKeyframeTimestamp;
            if ($timeDiff >= $this->segmentDuration * 1000) {
                $this->startNewSegment($relativeTime);
                // 首个切片写入关键帧后，释放暂存的音频帧
                if ($this->isFirstSegment) {
                    $this->isFirstSegment = false;
                    foreach ($this->pendingAudioFrames as $audioFrameData) {
                        $this->processAudioFrame($audioFrameData['frame'], $audioFrameData['relativeTime']);
                    }
                    $this->pendingAudioFrames = []; // 清空缓存
                }
                //$this->lastKeyframeTimestamp = $relativeTime;
            }
        }

        // 写入视频帧到TS切片（确保文件句柄已打开）
        if ($this->tsFileHandle) {
            // 关键帧必须携带序列头（否则播放器无法解码）
            $videoPayload = $isKeyFrame
                ? $this->toAnnexB($this->videoSequenceHeader) . $this->toAnnexB($avcData['data'])
                : $this->toAnnexB($avcData['data']);
            $this->writeVideoToTS($videoPayload, $relativeTime, $isKeyFrame);
        }
    }


    private $segmentDurations = [];


    /**
     * 开始新切片
     * @param $timestamp
     * @return void
     */
    private function startNewSegment($timestamp)
    {
        // 关闭当前句柄（若存在）
        if ($this->tsFileHandle) {
            fclose($this->tsFileHandle);
            $this->tsFileHandle = null;

            // 计算上一个切片时长
            $duration = ($this->lastKeyframeTimestamp - $this->segmentStartTime) / 1000;
            $this->segmentDurations[$this->sequenceNumber] = round($duration, 3);
            $this->updateM3U8Playlist();
        }

        // 创建新切片
        $this->sequenceNumber++;
        $this->currentSegmentFile = "{$this->streamDir}segment_{$this->sequenceNumber}.ts";
        $this->tsFileHandle = fopen($this->currentSegmentFile, 'wb');
        if ($this->tsFileHandle === false) {
            throw new \RuntimeException("无法创建TS文件: {$this->currentSegmentFile}");
        }

        $this->segmentStartTime = $timestamp;
        $this->lastKeyframeTimestamp = $timestamp;

        // 写入PAT/PMT表
        $this->writePAT();
        $this->writePMT();
    }

    private function updateM3U8Playlist()
    {
        $m3u8Path = "{$this->streamDir}index.m3u8";
        $segments = glob("{$this->streamDir}segment_*.ts");
        sort($segments, SORT_NATURAL);

        $m3u8Content = "#EXTM3U\n";
        $m3u8Content .= "#EXT-X-VERSION:3\n";
        $m3u8Content .= "#EXT-X-TARGETDURATION:{$this->segmentDuration}\n";
        $m3u8Content .= "#EXT-X-MEDIA-SEQUENCE:" . ($this->sequenceNumber - count($segments) + 1) . "\n";

        foreach ($segments as $segment) {
            $seq = intval(pathinfo($segment, PATHINFO_FILENAME));
            $duration = $this->segmentDurations[$seq] ?? $this->segmentDuration;

            $m3u8Content .= "#EXTINF:{$duration},\n";
            $m3u8Content .= basename($segment) . "\n";
        }

        file_put_contents($m3u8Path, $m3u8Content);
    }


    /**
     * 写入PAT表（节目关联表）
     * 作用：告知播放器PMT表的PID
     */
    private function writePAT()
    {
        $patData = $this->createPAT();
        $this->writeTSPacket($this->patPid, $patData);
    }

    /**
     * 写入PMT表（节目映射表）
     * 作用：告知播放器视频流和音频流的PID和编码格式
     */
    private function writePMT()
    {
        $pmtData = $this->createPMT();
        $this->writeTSPacket($this->pmtPid, $pmtData);
    }

    /**
     * 创建PAT表数据
     * @return string 符合MPEG-TS规范的PAT表二进制数据
     */
    private function createPAT()
    {
        // PAT表结构（参考ISO/IEC 13818-1）
        $pat = pack('C', 0x00);                  // 表ID（PAT固定为0x00）
        $pat .= pack('C', 0xB0);                 // 标志位（固定0xB0）
        $pat .= pack('C', 0x0D);                 // 段长度（低8位，总长度13字节）
        $pat .= pack('n', 0x0001);               // 节目号（0x0001表示第一个节目）
        $pat .= pack('C', 0xC1);                 // 版本号（0x01）+ 当前/下一个标志（0x80）
        $pat .= pack('C', 0x00);                 // 段号（0x00）+ 最后段号（0x00）
        $pat .= pack('n', 0xE000 | $this->pmtPid); // PMT的PID（高3位固定0xE）
        // $crc = $this->calculateCRC32(substr($pat, 0, 8)); // 计算前8字节的CRC32
        $crc = $this->crc32mpeg(substr($pat, 0, 8)); // 计算前8字节的CRC32
        $pat .= pack('N', $crc);                 // CRC32校验值

        return $pat;
    }

    private function createPMT()
    {
        $pmt = pack('C', 0x02);                  // 表ID（PMT固定为0x02）
        $pmt .= pack('C', 0xB0);                 // 标志位（固定0xB0）
        $pmt .= pack('C', 0x1C);                 // 修正段长度（包含完整音视频描述）
        $pmt .= pack('n', 0x0001);               // 节目号
        $pmt .= pack('C', 0xC1);                 // 版本号+当前标志
        $pmt .= pack('C', 0x00);                 // 段号
        $pmt .= pack('n', 0x1FFF & $this->videoPid); // PCR PID（视频PID）
        $pmt .= pack('n', 0x0000);               // 节目信息长度

        // 视频流描述（H.264）
        $pmt .= pack('C', 0x1B);                 // 流类型（0x1B=H.264）
        $pmt .= pack('n', 0xE000 | $this->videoPid); // 视频PID
        $pmt .= pack('n', 0x0000);               // 描述符长度

        // 修正：音频流描述（强制AAC类型）
        $pmt .= pack('C', 0x0F);                 // 流类型（0x0F=AAC，固定值）
        $pmt .= pack('n', 0xE000 | $this->audioPid); // 音频PID（修正为0x101）
        $pmt .= pack('n', 0x0000);               // 描述符长度

        $crc = $this->crc32mpeg(substr($pmt, 0, 24)); // 修正CRC计算长度
        $pmt .= pack('N', $crc);

        return $pmt;
    }

    /**
     * 将视频数据写入TS切片
     * @param string $videoData 视频帧数据（NAL单元，含序列头）
     * @param int $timestamp 时间戳（毫秒）
     * @param bool $isKeyFrame 是否为关键帧
     */
    private function writeVideoToTS($videoData, $timestamp, $isKeyFrame)
    {
        // 转换时间戳为90kHz时钟（HLS标准时间单位）
        $pts = (int)($timestamp / 1000 * 90000);
        $dts = $pts; // 简化处理（PTS=DTS）

        // 创建PES包（视频数据必须封装为PES包）
        $pesData = $this->createPESPacket(
            0xE0,           // 视频流ID（0xE0-0xEF为视频流）
            $videoData,
            $pts,
            $dts,
            $isKeyFrame
        );
        $currentPCR = intval($pts * 300); // PTS in 90kHz ➜ PCR in 27MHz: 90kHz × 300
        // 写入TS包（PES包需分割为188字节的TS包）
        $this->writeTSPacket($this->videoPid, $pesData,$isKeyFrame,true, $currentPCR);
    }

    /**
     * 创建PES包（MPEG-TS的基本数据单元）
     * @param int $streamId 流ID（视频0xE0，音频0xC0）
     * @param string $payload 负载数据（视频/音频帧）
     * @param int $pts 展示时间戳
     * @param int $dts 解码时间戳
     * @param bool $isKeyFrame 是否为关键帧
     * @return string PES包二进制数据
     */
    private function createPESPacket($streamId, $payload, $pts, $dts)
    {
        // PES起始码（固定为0x000001）+ 流ID（1字节）
        $pesHeaderStart = "\x00\x00\x01" . chr($streamId);

        // 计算PES头部数据（PTS/DTS部分）
        $ptsData = $this->encodeTimestamp(0x02, $pts); // 仅PTS
        $headerData = $ptsData;
        $headerDataLength = strlen($headerData); // 动态计算头部数据长度

        // 处理DTS（若与PTS不同）
        if ($dts !== null && $dts !== $pts) {
            $dtsData = $this->encodeTimestamp(0x01, $dts);
            $headerData = $ptsData . $dtsData;
            $headerDataLength = strlen($headerData);
        }

        // PES标志位（包含PTS/DTS存在标志）
        $flags = 0x80; // 包含PTS
        if ($dts !== null && $dts !== $pts) {
            $flags |= 0x40; // 包含DTS
        }

        // 计算PES包总长度（不含起始码的3字节）
        $pesHeaderLength = 1 + 2 + 1 + $headerDataLength; // 流ID(1) + 包长度(2) + 标志(2) + 头部数据长度(1) + 头部数据
        $totalLength = $pesHeaderLength + strlen($payload);

        // PES包长度字段：0表示长度不固定（适用于>0xFFFF的情况）
        $packetLength = ($totalLength <= 0xFFFF) ? $totalLength : 0;

        // 组装完整PES头
        $pesHeader = $pesHeaderStart
            . pack('n', $packetLength) // 包长度（2字节）
            . chr(0x80) // 标志位1（固定0x80）
            . chr($flags) // 标志位2（含PTS/DTS标志）
            . chr($headerDataLength) // 头部数据长度（1字节）
            . $headerData; // 头部数据（PTS/DTS）

        // 拼接PES头和负载
        return $pesHeader . $payload;
    }

    private function encodeTimestamp($flag, $ts)
    {
        return pack('C', ($flag << 4) | (($ts >> 30 & 0x07) << 1) | 1)
            . pack('n', (($ts >> 15) & 0x7FFF) << 1 | 1)
            . pack('n', ($ts & 0x7FFF) << 1 | 1);
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


    /**
     * 将数据写入TS包（MPEG-TS的传输单元）
     * @param int $pid 数据包ID
     * @param string $payload 负载数据
     */
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
            // 计算当前包可承载的最大负载（确保不超过TS包大小）
            $adaptationFieldLength = 0;
            if ($firstPacket && $isVideo) {
                $adaptationFieldLength = 8; // PCR适配域固定8字节（含长度字节）
            }
            $maxPayload = $tsPacketSize - 4 - $adaptationFieldLength; // 4字节TS头 + 适配域长度
            $currentPayloadLength = min($maxPayload, $payloadLength - $payloadOffset);
            $currentPayload = substr($payload, $payloadOffset, $currentPayloadLength);
            $payloadOffset += $currentPayloadLength;

            // 构建TS头
            $syncByte = 0x47;
            $payloadUnitStart = $firstPacket ? 0x40 : 0x00; // 首个包标记起始
            $pidHigh = (($pid >> 8) & 0x1F);
            $tsHeader1 = $payloadUnitStart | $pidHigh;
            $tsHeader2 = $pid & 0xFF;

            // 适配域控制（高2位）+ 连续性计数器（低4位）
            $adaptationFieldCtrl = ($adaptationFieldLength > 0) ? 0x30 : 0x10;
            $counter = $continuityCounters[$pid] % 16;
            $tsHeader3 = $adaptationFieldCtrl | $counter;

            // 构建适配域（含PCR）
            $adaptationField = '';
            if ($adaptationFieldLength > 0) {
                $pcr = $pcrBase ? ($pcrBase * 300) : 0;
                $adaptationField = chr(7); // 适配域长度（7字节数据）
                $adaptationField .= chr(0x10); // PCR标志
                $adaptationField .= pack('N', ($pcr >> 16) & 0xFFFFFFFF);
                $adaptationField .= pack('n', $pcr & 0xFFFF);
            }

            // 组装TS包
            $tsPacket = chr($syncByte) . chr($tsHeader1) . chr($tsHeader2) . chr($tsHeader3);
            $tsPacket .= $adaptationField . $currentPayload;

            // 填充至188字节（用0xFF填充剩余空间）
            $remaining = $tsPacketSize - strlen($tsPacket);
            if ($remaining > 0) {
                $tsPacket .= str_repeat("\xFF", $remaining);
            }

            // 写入TS包（确保句柄有效）
            if ($this->tsFileHandle) {
                fwrite($this->tsFileHandle, $tsPacket);
            }

            $continuityCounters[$pid]++;
            $firstPacket = false;
        }
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