<?php
namespace MediaServer\HLS;

use MediaServer\Flv\Flv;
use MediaServer\MediaReader\AudioFrame;
use MediaServer\MediaReader\VideoFrame;
use function count;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function fopen;
use function fwrite;
use function glob;
use function implode;
use function is_dir;
use function mkdir;
use function ord;
use function pack;
use function preg_match;
use function sprintf;
use function strlen;
use function substr;
use function time;

/**
 * FLV到HLS转换器
 */
class FLVToHLSConverter
{
    // 配置参数
    private $segmentDuration = 4;       // 切片时长(秒)
    private $maxSegments = 10;          // 最大保留切片数
    private $streamId;                  // 流ID
    private $streamDir;                 // 流目录

    // 状态变量
    private $sequenceNumber = 0;        // 切片序号
    private $currentSegmentFile;        // 当前切片文件
    private $segmentStartTime = 0;      // 当前切片开始时间
    private $firstTimestamp = null;     // 首个时间戳
    private $lastKeyframeTimestamp = 0; // 上一个关键帧时间戳

    // 编码器参数
    private $videoPid = 0x100;          // 视频PID
    private $audioPid = 0x101;          // 音频PID
    private $pmtPid = 0x10;             // PMT表PID
    private $patPid = 0;                // PAT表PID

    // 元数据
    private $videoCodecId = null;       // 视频编码ID
    private $audioCodecId = null;       // 音频编码ID
    private $videoSequenceHeader = null;// 视频序列头
    private $audioSequenceHeader = null;// 音频序列头

    // 文件句柄
    private $tsFileHandle;              // 当前TS文件句柄

    public function __construct($streamId, $config = [])
    {
        $this->streamId = $streamId;
        $this->streamDir = dirname(__DIR__,2) . "/hls/{$streamId}/";
        var_dump($this->streamDir);

        // 应用配置
        if (isset($config['segmentDuration'])) {
            $this->segmentDuration = $config['segmentDuration'];
        }
        if (isset($config['maxSegments'])) {
            $this->maxSegments = $config['maxSegments'];
        }

        // 初始化目录
        if (!is_dir($this->streamDir)) {
            mkdir($this->streamDir, 0777, true);
        }
    }

    /**
     * 处理FLV数据包
     * @param mixed $frame 视频/音频帧
     */
    public function processFrame($frame)
    {
        // 记录首个时间戳
        if ($this->firstTimestamp === null) {
            $this->firstTimestamp = $frame->timestamp;
        }

        // 计算相对时间(毫秒)
        $relativeTime = $frame->timestamp - $this->firstTimestamp;

        // 处理不同类型的帧
        if ($frame instanceof VideoFrame) {
            $this->processVideoFrame($frame, $relativeTime);
        } elseif ($frame instanceof AudioFrame) {
            $this->processAudioFrame($frame, $relativeTime);
        }
    }

    /**
     * 处理视频帧
     * @param VideoFrame $frame 视频帧
     * @param int $relativeTime 相对时间(毫秒)
     */
    private function processVideoFrame(VideoFrame $frame, $relativeTime)
    {
        // 解析视频帧数据
        $videoData = Flv::videoFrameDataRead((string)$frame);
        $string = "";
        switch ($videoData['frameType']) {
            case 1:
                $string = "关键帧";
                break;
            case 2:
                $string = "中间帧b/p帧";
                break;
            case 3:
                $string = "可丢弃的中间帧";
                break;
            case 4:
                $string = "生成的关键帧";
                break;
            case 5:
                $string = " 视频信息 / 命令帧";
                break;

        }
        echo $string . "\r\n";
        // 保存视频编码ID
        if ($this->videoCodecId === null) {
            $this->videoCodecId = $videoData['codecId'];
        }

        // 处理AVC/H.264数据
        if ($videoData['codecId'] == Flv::VIDEO_CODEC_ID_AVC) {
            $avcData = Flv::avcPacketRead($videoData['data']);

            // 保存视频序列头
            if ($avcData['avcPacketType'] == Flv::AVC_PACKET_TYPE_SEQUENCE_HEADER) {
                $this->videoSequenceHeader = $avcData['data'];
                return;
            }

            // 检查是否为关键帧
            $isKeyFrame = ($videoData['frameType'] == Flv::VIDEO_FRAME_TYPE_KEY_FRAME);

            // 关键帧触发新切片
            if ($isKeyFrame && ($relativeTime - $this->lastKeyframeTimestamp) >= ($this->segmentDuration * 1000)) {
                $this->startNewSegment($relativeTime);
                $this->lastKeyframeTimestamp = $relativeTime;
            }

            // 写入视频数据
            if ($this->tsFileHandle) {
                $this->writeVideoToTS(
                    $avcData['data'],
                    $relativeTime,
                    $isKeyFrame
                );
            }
        }
    }

    /**
     * 处理音频帧
     * @param AudioFrame $frame 音频帧
     * @param int $relativeTime 相对时间(毫秒)
     */
    private function processAudioFrame(AudioFrame $frame, $relativeTime)
    {
        // 解析音频帧数据
        $audioData = Flv::audioFrameDataRead((string)$frame);

        // 保存音频编码ID
        if ($this->audioCodecId === null) {
            $this->audioCodecId = $audioData['soundFormat'];
        }

        // 处理AAC音频
        if ($audioData['soundFormat'] == Flv::SOUND_FORMAT_ACC) {
            $aacData = Flv::accPacketDataRead($audioData['data']);

            // 保存音频序列头
            if ($aacData['accPacketType'] == Flv::ACC_PACKET_TYPE_SEQUENCE_HEADER) {
                $this->audioSequenceHeader = $aacData['data'];
                return;
            }

            // 写入音频数据
            if ($this->tsFileHandle) {
                $this->writeAudioToTS(
                    $aacData['data'],
                    $relativeTime
                );
            }
        }
    }

    /**
     * 开始新切片
     * @param int $timestamp 时间戳(毫秒)
     */
    private function startNewSegment($timestamp)
    {
        // 关闭当前TS文件
        if ($this->tsFileHandle) {
            fclose($this->tsFileHandle);
            $this->updateM3U8Playlist();
        }

        // 创建新TS文件
        $this->sequenceNumber++;
        $this->currentSegmentFile = "{$this->streamDir}segment_{$this->sequenceNumber}.ts";
        $this->tsFileHandle = fopen($this->currentSegmentFile, 'wb');
        $this->segmentStartTime = $timestamp;

        // 写入PAT和PMT表
        $this->writePAT();
        $this->writePMT();
    }

    /**
     * 写入PAT表
     */
    private function writePAT()
    {
        $patData = $this->createPAT();
        $this->writeTSPacket($this->patPid, $patData);
    }

    /**
     * 写入PMT表
     */
    private function writePMT()
    {
        $pmtData = $this->createPMT();
        $this->writeTSPacket($this->pmtPid, $pmtData);
    }

    /**
     * 创建PAT表
     * @return string
     */
    private function createPAT()
    {
        $pat = pack('CC', 0x00, 0xB0); // 表ID, 段长度(高字节)
        $pat .= pack('C', 13);         // 段长度(低字节)
        $pat .= pack('n', 0x0001);     // 节目号
        $pat .= pack('CC', 0xC1, 0x00); // 版本号, 当前/下一个标志, 段号, 最后段号
        $pat .= pack('n', 0xE000 | $this->pmtPid); // PMT PID
        $pat .= pack('N', 0);          // CRC32

        return $pat;
    }

    /**
     * 创建PMT表
     * @return string
     */
    private function createPMT()
    {
        $pmt = pack('CC', 0x02, 0xB0); // 表ID, 段长度(高字节)

        // 计算描述符和节目信息长度
        $programInfoLength = 0;
        $descriptorLength = 5; // 视频 + 音频描述符长度

        $sectionLength = 13 + $programInfoLength + $descriptorLength;
        $pmt .= pack('C', $sectionLength & 0xFF);

        $pmt .= pack('n', 0x0001);     // 节目号
        $pmt .= pack('CC', 0xC1, 0x00); // 版本号, 当前/下一个标志, 段号, 最后段号
        $pmt .= pack('n', 0x1FFF & $this->videoPid); // PCR PID

        $pmt .= pack('n', $programInfoLength); // 节目信息长度

        // 视频流描述符
        $pmt .= pack('C', 0x1B);      // 视频流类型(H.264)
        $pmt .= pack('n', 0xE000 | $this->videoPid); // 元素PID
        $pmt .= pack('n', 0);        // 描述符长度

        // 音频流描述符
        $pmt .= pack('C', 0x0F);      // 音频流类型(AAC)
        $pmt .= pack('n', 0xC000 | $this->audioPid); // 元素PID
        $pmt .= pack('n', 0);        // 描述符长度

        $pmt .= pack('N', 0);          // CRC32

        return $pmt;
    }

    /**
     * 写入视频数据到TS
     * @param string $videoData 视频数据
     * @param int $timestamp 时间戳(毫秒)
     * @param bool $isKeyFrame 是否为关键帧
     */
    private function writeVideoToTS($videoData, $timestamp, $isKeyFrame)
    {
        // 转换时间戳为90kHz时钟
        $pts = ($timestamp / 1000) * 90000;
        $dts = $pts; // 简化处理，PTS和DTS相同

        // 创建PES包
        $pesData = $this->createPESPacket(
            0xE0, // 视频流ID
            $videoData,
            $pts,
            $dts,
            $isKeyFrame
        );

        // 写入TS包
        $this->writeTSPacket($this->videoPid, $pesData);
    }

    /**
     * 写入音频数据到TS
     * @param string $audioData 音频数据
     * @param int $timestamp 时间戳(毫秒)
     */
    private function writeAudioToTS($audioData, $timestamp)
    {
        // 转换时间戳为90kHz时钟
        $pts = ($timestamp / 1000) * 90000;
        $dts = $pts; // 简化处理，PTS和DTS相同

        // 创建PES包
        $pesData = $this->createPESPacket(
            0xC0, // 音频流ID
            $audioData,
            $pts,
            $dts
        );

        // 写入TS包
        $this->writeTSPacket($this->audioPid, $pesData);
    }

    /**
     * 创建PES包
     * @param int $streamId 流ID
     * @param string $payload 负载数据
     * @param int $pts 展示时间戳
     * @param int $dts 解码时间戳
     * @param bool $isKeyFrame 是否为关键帧
     * @return string
     */
    private function createPESPacket($streamId, $payload, $pts, $dts, $isKeyFrame = false)
    {
        $payloadLength = strlen($payload);

        // PES包头
        $pesHeader = pack('CCC', 0x00, 0x00, 0x01); // PES起始码
        $pesHeader .= pack('C', $streamId);         // 流ID

        // PES包长度(如果>65535则设为0)
        $pesLength = ($payloadLength + 13) > 65535 ? 0 : ($payloadLength + 13);
        $pesHeader .= pack('n', $pesLength);

        // PES标志
        $flags1 = 0x80; // 固定值
        $flags2 = 0x80; // 有PTS

        // 如果提供了DTS且与PTS不同，则同时设置PTS和DTS
        if ($dts !== null && $dts != $pts) {
            $flags2 |= 0x40; // 有DTS
        }

        $pesHeader .= pack('CC', $flags1, $flags2);

        // PES头长度
        $headerLength = $flags2 == 0x80 ? 5 : 10;
        $pesHeader .= pack('C', $headerLength);

        // 添加PTS
        $pesHeader .= $this->encodeTimestamp(0x2, $pts);

        // 如果有DTS，添加DTS
        if ($flags2 & 0x40) {
            $pesHeader .= $this->encodeTimestamp(0x3, $dts);
        }

        // 如果是关键帧，添加额外信息
        if ($isKeyFrame) {
            // 关键帧标志
            $pesHeader .= pack('C', 0x01); // PES扩展标志
            $pesHeader .= pack('C', 0x01); // PES扩展长度
            $pesHeader .= pack('C', 0x80); // 扩展标志2 (有P-STD缓冲区)
        }

        return $pesHeader . $payload;
    }

    /**
     * 编码时间戳
     * @param int $prefix 前缀
     * @param int $timestamp 时间戳
     * @return string
     */
    private function encodeTimestamp($prefix, $timestamp)
    {
        $timestampBytes = [
            ($prefix << 4) | ((($timestamp >> 30) & 0x07) << 1) | 1,
            (($timestamp >> 22) & 0xFF),
            ((($timestamp >> 15) & 0x7F) << 1) | 1,
            (($timestamp >> 7) & 0xFF),
            (($timestamp & 0x7F) << 1) | 1
        ];

        return pack('CCCCC', ...$timestampBytes);
    }

    /**
     * 写入TS包
     * @param int $pid PID
     * @param string $payload 负载数据
     */
    private function writeTSPacket($pid, $payload)
    {
        $tsPacketSize = 188;
        $payloadLength = strlen($payload);
        $maxPayloadPerPacket = 184; // 减去4字节TS头

        // 初始化连续性计数器
        static $continuityCounters = [
            0 => 0,   // PAT
            0x10 => 0, // PMT
            0x100 => 0, // 视频
            0x101 => 0  // 音频
        ];

        // 计算需要的TS包数量
        $numPackets = ceil($payloadLength / $maxPayloadPerPacket);

        for ($i = 0; $i < $numPackets; $i++) {
            // 提取当前包的负载
            $packetPayload = substr($payload, $i * $maxPayloadPerPacket, $maxPayloadPerPacket);
            $payloadLen = strlen($packetPayload);

            // 创建TS包头
            $tsHeader = pack('C', 0x47); // 同步字节

            // 传输错误指示符(0)、有效载荷单元起始指示符、传输优先级(0)
            $flags = 0;
            if ($i == 0) {
                $flags |= 0x40; // 第一个包设置有效载荷单元起始指示符
            }

            $tsHeader .= pack('C', $flags);

            // PID
            $pidValue = $pid & 0x1FFF;
            $tsHeader .= pack('n', $pidValue | 0x4000); // 设置PID高位

            // 传输控制
            $transportControl = 0x01; // 只有有效载荷

            // 计算适配字段长度
            $adaptationFieldLength = 0;
            if ($payloadLen < $maxPayloadPerPacket) {
                $adaptationFieldLength = $maxPayloadPerPacket - $payloadLen;

                // 如果需要适配字段，更新传输控制
                if ($adaptationFieldLength > 0) {
                    $transportControl |= 0x02; // 有适配字段

                    // 如果没有有效载荷，设置适配字段标志
                    if ($payloadLen == 0) {
                        $transportControl &= ~0x01; // 没有有效载荷
                    }
                }
            }

            $tsHeader .= pack('C', ($transportControl << 4) | ($continuityCounters[$pid]++ % 16));

            // 添加适配字段(如果有)
            if ($transportControl & 0x02) {
                $adaptationField = pack('C', $adaptationFieldLength);

                // 适配字段标志
                $adaptationFlags = 0x00;

                // 如果是最后一个包且没有足够的数据，设置填充标志
                if ($payloadLen == 0 && $adaptationFieldLength > 0) {
                    $adaptationFlags |= 0x01; // 不连续指示器
                }

                $adaptationField .= pack('C', $adaptationFlags);

                // 添加空字节作为填充
                if ($adaptationFieldLength > 1) {
                    $adaptationField .= str_repeat("\x00", $adaptationFieldLength - 1);
                }

                $tsHeader .= $adaptationField;
            }

            // 写入TS包
            $tsPacket = $tsHeader . $packetPayload;

            // 如果需要，填充到188字节
            if (strlen($tsPacket) < $tsPacketSize) {
                $tsPacket .= str_repeat("\xFF", $tsPacketSize - strlen($tsPacket));
            }

            fwrite($this->tsFileHandle, $tsPacket);
        }
    }

    /**
     * 更新M3U8播放列表
     */
    private function updateM3U8Playlist()
    {
        $m3u8Path = "{$this->streamDir}index.m3u8";
        $segments = glob("{$this->streamDir}segment_*.ts");
        sort($segments);

        $m3u8Content = "#EXTM3U\n";
        $m3u8Content .= "#EXT-X-VERSION:3\n";
        $m3u8Content .= "#EXT-X-TARGETDURATION:{$this->segmentDuration}\n";
        $m3u8Content .= "#EXT-X-MEDIA-SEQUENCE:1\n";

        foreach ($segments as $segment) {
            $duration = $this->segmentDuration; // 简化处理，实际应使用每个片段的真实时长
            $m3u8Content .= "#EXTINF:{$duration},\n";
            $m3u8Content .= basename($segment) . "\n";
        }

        // 如果流未结束，不要添加ENDLIST
        $m3u8Content = rtrim($m3u8Content, "\n") . "\n";

        file_put_contents($m3u8Path, $m3u8Content);

        // 清理旧文件
        $this->cleanupOldSegments();
    }

    /**
     * 清理旧切片文件
     */
    private function cleanupOldSegments()
    {
        $segments = glob("{$this->streamDir}segment_*.ts");
        sort($segments);

        if (count($segments) > $this->maxSegments) {
            $oldSegments = array_slice($segments, 0, count($segments) - $this->maxSegments);
            foreach ($oldSegments as $segment) {
                unlink($segment);
            }
        }
    }

    /**
     * 获取HLS播放地址
     * @return string
     */
    public function getHlsUrl()
    {
        return "/hls/{$this->streamId}/index.m3u8";
    }
}