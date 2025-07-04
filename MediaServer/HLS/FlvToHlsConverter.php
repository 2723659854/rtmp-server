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
 * FLV到HLS转换器（仅视频版本）
 * 功能：将FLV格式的H.264视频流转换为HLS格式（仅包含视频轨道）
 * 注意：依赖MediaServer\Flv\Flv类解析FLV帧数据
 * @command 检查切片是否正常的命令 ffprobe -v error -show_format -show_streams segment_1.ts
 */
class FLVToHLSConverter
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

    private $audioPid = 0x101;

    // 视频元数据
    private $videoCodecId = null;       // 视频编码ID（仅处理H.264）
    private $videoSequenceHeader = null;// 视频序列头（SPS/PPS，解码必需）

    // 文件句柄
    private $tsFileHandle = null;       // 当前TS文件句柄


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
        // 仅处理视频帧
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

    /**
     * 写入音频ts包
     * @param $audioData
     * @param $timestamp
     * @return void
     */
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
                $this->lastKeyframeTimestamp = $relativeTime;
            }
        }

        // 写入视频帧到TS切片（确保文件句柄已打开）
        if ($this->tsFileHandle) {
            // 关键帧必须携带序列头（否则播放器无法解码）
            //$videoPayload = $isKeyFrame ? $this->videoSequenceHeader . $avcData['data'] : $avcData['data'];
            $videoPayload = $isKeyFrame
                ? $this->toAnnexB($this->videoSequenceHeader) . $this->toAnnexB($avcData['data'])
                : $this->toAnnexB($avcData['data']);
            $this->writeVideoToTS($videoPayload, $relativeTime, $isKeyFrame);
        }
    }

    /**
     * 开始新的TS切片
     * @param int $timestamp 切片开始时间（毫秒）
     */
    private function startNewSegment($timestamp)
    {
        // 关闭当前切片（若存在）
        if ($this->tsFileHandle) {
            fclose($this->tsFileHandle);
            $this->tsFileHandle = null;
            $this->updateM3U8Playlist(); // 关闭后立即更新播放列表
        }

        // 创建新切片文件
        $this->sequenceNumber++;
        $this->currentSegmentFile = "{$this->streamDir}segment_{$this->sequenceNumber}.ts";
        $this->tsFileHandle = fopen($this->currentSegmentFile, 'wb');
        if (!$this->tsFileHandle) {
            throw new \RuntimeException("无法创建TS切片文件: {$this->currentSegmentFile}");
        }

        // 初始化切片信息
        $this->segmentStartTime = $timestamp;

        // 写入TS流必需的PAT和PMT表（每个切片开头必须包含）
        $this->writePAT();
        $this->writePMT();
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
     * 作用：告知播放器视频流的PID和编码格式
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
        $crc = $this->calculateCRC32(substr($pat, 0, 8)); // 计算前8字节的CRC32
        $pat .= pack('N', $crc);                 // CRC32校验值

        return $pat;
    }

    /**
     * 创建PMT表数据
     * @return string 符合MPEG-TS规范的PMT表二进制数据
     */
    private function createPMT()
    {
        // PMT表结构（参考ISO/IEC 13818-1）
        $pmt = pack('C', 0x02);                  // 表ID（PMT固定为0x02）
        $pmt .= pack('C', 0xB0);                 // 标志位（固定0xB0）
        $pmt .= pack('C', 0x15);                 // 段长度（低8位，总长度21字节）
        $pmt .= pack('n', 0x0001);               // 节目号（与PAT对应）
        $pmt .= pack('C', 0xC1);                 // 版本号（0x01）+ 当前/下一个标志（0x80）
        $pmt .= pack('C', 0x00);                 // 段号（0x00）+ 最后段号（0x00）
        $pmt .= pack('n', 0x1FFF & $this->videoPid); // PCR PID（使用视频PID作为时钟参考）
        $pmt .= pack('n', 0x0000);               // 节目信息长度（无扩展信息）

        // 视频流描述符（H.264固定类型0x1B）
        $pmt .= pack('C', 0x1B);                 // 流类型（0x1B表示H.264）
        $pmt .= pack('n', 0xE000 | $this->videoPid); // 视频流PID
        $pmt .= pack('n', 0x0000);               // 描述符长度（无扩展）

        $crc = $this->calculateCRC32(substr($pmt, 0, 17)); // 计算前17字节的CRC32
        $pmt .= pack('N', $crc);                 // CRC32校验值

        return $pmt;
    }

    /**
     * 计算CRC32校验值（MPEG-TS专用）
     * @param string $data 待校验的数据
     * @return int 32位CRC值（大端序）
     */
    private function calculateCRC32($data)
    {
        $crc = 0xFFFFFFFF;
        $length = strlen($data);
        for ($i = 0; $i < $length; $i++) {
            $crc ^= (ord($data[$i]) << 24);
            for ($j = 0; $j < 8; $j++) {
                if (($crc & 0x80000000) != 0) {
                    $crc = (($crc << 1) & 0xFFFFFFFF) ^ 0x04C11DB7;
                } else {
                    $crc = ($crc << 1) & 0xFFFFFFFF;
                }
            }
        }
        return $crc ^ 0xFFFFFFFF;
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

        // 写入TS包（PES包需分割为188字节的TS包）
        $this->writeTSPacket($this->videoPid, $pesData);
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
    private function createPESPacket($streamId, $payload, $pts, $dts, $isKeyFrame = false)
    {
        $payloadLen = strlen($payload);

        // PES包头（参考ISO/IEC 13818-1）
        $pes = pack('CCC', 0x00, 0x00, 0x01);    // 起始码（0x000001）
        $pes .= pack('C', $streamId);            // 流ID
        $pes .= pack('n', $payloadLen + 13);     // PES包长度（包头+负载）
        $pes .= pack('C', 0x80);                 // PES标志1（固定0x80）
        $pes .= pack('C', 0x80);                 // PES标志2（含PTS）
        $pes .= pack('C', 0x05);                 // PES头长度（5字节）

        // 编码PTS（展示时间戳）
        $pes .= $this->encodeTimestamp(0x2, $pts);

        // 关键帧添加扩展标志（帮助播放器快速同步）
        if ($isKeyFrame) {
            $pes .= pack('C', 0x01); // 扩展标志
            $pes .= pack('C', 0x01); // 扩展长度
            $pes .= pack('C', 0x80); // 随机访问指示
        }

        return $pes . $payload;
    }

    /**
     * 编码时间戳（PTS/DTS的特殊编码格式）
     * @param int $prefix 前缀（PTS=0x2，DTS=0x3）
     * @param int $timestamp 时间戳（90kHz时钟）
     * @return string 编码后的时间戳二进制数据
     */
    private function encodeTimestamp($prefix, $timestamp)
    {
        // 时间戳编码规则（参考ISO/IEC 13818-1）
        return pack('C', ($prefix << 4) | ((($timestamp >> 30) & 0x07) << 1) | 1) .
            pack('C', (($timestamp >> 22) & 0xFF)) .
            pack('C', ((($timestamp >> 15) & 0x7F) << 1) | 1) .
            pack('C', (($timestamp >> 7) & 0xFF)) .
            pack('C', (($timestamp & 0x7F) << 1) | 1);
    }

    /**
     * 将数据写入TS包（MPEG-TS的传输单元）
     * @param int $pid 数据包ID
     * @param string $payload 负载数据
     */
    private function writeTSPacket($pid, $payload)
    {
        $tsPacketSize = 188;
        $payloadLength = strlen($payload);
        $maxPayloadPerPacket = 184; // 每个TS包的最大负载（188-4字节包头）

        // 初始化连续性计数器（每个PID独立计数）
        static $continuityCounters = [
            0x00 => 0,  // PAT表
            0x10 => 0,  // PMT表
            0x100 => 0  // 视频流
        ];

        // 确保PID在有效范围内
        if ($pid < 0 || $pid > 0x1FFF) {
            throw new \InvalidArgumentException("无效的PID值: {$pid}");
        }

        // 计算需要的TS包数量
        $numPackets = ceil($payloadLength / $maxPayloadPerPacket);

        for ($i = 0; $i < $numPackets; $i++) {
            // 提取当前TS包的负载
            $packetPayload = substr($payload, $i * $maxPayloadPerPacket, $maxPayloadPerPacket);
            $payloadLen = strlen($packetPayload);

            // 创建TS包头
            $tsHeader = pack('C', 0x47);  // 同步字节（固定0x47）

            // 传输错误指示符（0）、有效载荷单元起始指示符、传输优先级（0）
            $flags = 0;
            if ($i == 0) {
                $flags |= 0x40;  // 第一个包设置有效载荷单元起始指示符
            }
            $tsHeader .= pack('C', $flags);

            // PID（高2位固定0x11，低13位为PID值）
            $tsHeader .= pack('n', 0x4000 | ($pid & 0x1FFF));

            // 传输控制（含适配字段和连续性计数器）
            $adaptationFieldLength = 0;
            $continuityCounter = $continuityCounters[$pid]++ % 16;

            // 如果负载不足184字节，需要添加适配字段
            if ($payloadLen < $maxPayloadPerPacket) {
                $adaptationFieldLength = $maxPayloadPerPacket - $payloadLen;

                // 适配字段存在标志
                $transportScrambling = 0;  // 未加扰
                $adaptationFieldExist = 0x02;  // 有适配字段
                $payloadExist = $payloadLen > 0 ? 0x01 : 0;  // 有负载

                $tsHeader .= pack('C', ($transportScrambling << 6) |
                    ($adaptationFieldExist | $payloadExist) << 4 |
                    $continuityCounter);

                // 添加适配字段
                $tsHeader .= pack('C', $adaptationFieldLength);

                // 适配字段标志（至少包含一个空字节）
                if ($adaptationFieldLength > 0) {
                    $adaptationFlags = 0x00;
                    if ($adaptationFieldLength > 1) {
                        $adaptationFlags |= 0x01;  // 不连续标志（可选）
                    }
                    $tsHeader .= pack('C', $adaptationFlags);

                    // 添加空字节填充
                    if ($adaptationFieldLength > 1) {
                        $tsHeader .= str_repeat("\x00", $adaptationFieldLength - 1);
                    }
                }
            } else {
                // 只有负载，无适配字段
                $tsHeader .= pack('C', (0 << 6) | (0x01 << 4) | $continuityCounter);
            }

            // 写入TS包
            $tsPacket = $tsHeader . $packetPayload;

            // 确保TS包长度为188字节
            if (strlen($tsPacket) < $tsPacketSize) {
                $tsPacket .= str_repeat("\xFF", $tsPacketSize - strlen($tsPacket));
            }

            fwrite($this->tsFileHandle, $tsPacket);
        }
    }

    /**
     * 更新M3U8播放列表文件
     */
    private function updateM3U8Playlist()
    {
        $m3u8Path = "{$this->streamDir}index.m3u8";
        $segments = glob("{$this->streamDir}segment_*.ts");

        // 按文件名排序（确保按时间顺序）
        sort($segments, SORT_NATURAL);

        // 生成M3U8内容
        $m3u8Content = "#EXTM3U\n";
        $m3u8Content .= "#EXT-X-VERSION:3\n";  // HLS版本3
        $m3u8Content .= "#EXT-X-TARGETDURATION:{$this->segmentDuration}\n";  // 目标切片时长
        $m3u8Content .= "#EXT-X-MEDIA-SEQUENCE:{$this->sequenceNumber}\n";  // 起始序列号

        // 添加所有切片信息
        foreach ($segments as $segment) {
            $duration = $this->segmentDuration;  // 简化处理（实际应计算每个切片的真实时长）
            $m3u8Content .= "#EXTINF:{$duration},\n";
            $m3u8Content .= basename($segment) . "\n";
        }

        // 流未结束，不添加#EXT-X-ENDLIST

        // 写入M3U8文件
        if (file_put_contents($m3u8Path, $m3u8Content) === false) {
            throw new \RuntimeException("无法写入M3U8文件: {$m3u8Path}");
        }

        // 清理旧切片（保留最新的$maxSegments个）
        $this->cleanupOldSegments();
    }

    /**
     * 清理旧的TS切片文件
     */
    private function cleanupOldSegments()
    {
        $segments = glob("{$this->streamDir}segment_*.ts");

        // 按文件名排序（确保最旧的文件在前）
        sort($segments, SORT_NATURAL);

        // 删除超出保留数量的旧文件
        if (count($segments) > $this->maxSegments) {
            $oldSegments = array_slice($segments, 0, count($segments) - $this->maxSegments);
            foreach ($oldSegments as $segment) {
                unlink($segment);
            }
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