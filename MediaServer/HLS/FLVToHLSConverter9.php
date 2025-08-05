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
 * 修复版FLV转HLS转换器
 * 解决切片时长异常和后续切片无画面问题
 * @note 此版本不知道，反正不正确
 */
class FLVToHLSConverter9
{
    // 配置参数
    private $segmentDuration = 4;      // 目标切片时长(秒)
    private $maxSegments = 10;         // 最大保留切片数
    private $streamId;                 // 流ID
    private $streamDir;                // 切片保存目录

    // 状态变量
    private $sequenceNumber = 0;       // 切片序号
    private $currentSegmentFile;       // 当前切片文件路径
    private $segmentStartTime = 0;     // 当前切片起始相对时间(毫秒)
    private $firstTimestamp = null;    // 首个关键帧时间戳(基准)
    private $lastKeyframeTimestamp = 0;// 上一个关键帧相对时间(毫秒)
    private $currentSegmentStartRelativeTime = 0; // 当前切片起始相对时间

    // TS流参数
    private $videoPid = 0x100;         // 视频PID
    private $pmtPid = 0x10;           // PMT PID
    private $patPid = 0;              // PAT PID
    private $audioPid = 0x101;         // 音频PID

    // 编解码器数据
    private $videoSequenceHeader = null;  // 视频序列头(SPS/PPS)
    private $audioSequenceHeader = null;  // 音频序列头
    private $lastKeyframeData = null;     // 最近关键帧数据缓存

    // 视频元数据
    private $videoCodecId = null;      // 视频编码ID（仅处理H.264）

    // 文件句柄
    private $tsFileHandle = null;      // TS文件句柄
    private $segmentDurations = [];    // 切片时长记录

    /**
     * 初始化
     * @param $streamId
     * @param $config
     */
    public function __construct($streamId, $config = [])
    {
        $this->streamId = $streamId;
        $this->streamDir = dirname(__DIR__, 2) . "/hls/{$streamId}/";

        // 配置覆盖
        if (isset($config['segmentDuration'])) {
            $this->segmentDuration = (int)$config['segmentDuration'];
        }
        if (isset($config['maxSegments'])) {
            $this->maxSegments = (int)$config['maxSegments'];
        }

        // 创建目录
        if (!is_dir($this->streamDir)) {
            mkdir($this->streamDir, 0777, true);
        }
    }

    /**
     * 处理FLV帧数据
     * @param mixed $frame 视频帧或者音频帧
     * @throws \RuntimeException 若帧处理失败
     */
    public function processFrame(mixed $frame)
    {
        // 仅处理视频帧和音频帧
        if (!$frame instanceof VideoFrame && !$frame instanceof AudioFrame) {
            return;
        }

        // 初始化首个时间戳（仅关键帧）
        if ($frame instanceof VideoFrame && $this->firstTimestamp === null) {
            $videoData = Flv::videoFrameDataRead((string)$frame);
            if (empty($videoData)) {
                return;
            }
            // 以第一个关键帧时间戳为基准
            if ($videoData['frameType'] == Flv::VIDEO_FRAME_TYPE_KEY_FRAME) {
                $this->firstTimestamp = $frame->timestamp;
                // 初始化首个关键帧时间
                $this->lastKeyframeTimestamp = 0;
            }
        }

        // 未获取基准时间前仅处理序列头
        if ($this->firstTimestamp === null) {
            $this->processSequenceHeadersBeforeFirstKeyframe($frame);
            return;
        }

        // 计算相对时间（毫秒）
        $relativeTime = $frame->timestamp - $this->firstTimestamp;
        if ($relativeTime < 0) {
            return; // 忽略早于基准时间的帧
        }

        // 处理音视频帧
        if ($frame instanceof VideoFrame) {
            $this->processVideoFrame($frame, $relativeTime);
        } elseif ($frame instanceof AudioFrame) {
            $this->processAudioFrame($frame, $relativeTime);
        }
    }

    /**
     * 处理首个关键帧前的序列头
     */
    private function processSequenceHeadersBeforeFirstKeyframe($frame)
    {
        // 处理音频序列头
        if ($frame instanceof AudioFrame) {
            $audioData = Flv::audioFrameDataRead((string)$frame);
            if ($audioData['soundFormat'] != Flv::SOUND_FORMAT_ACC) {
                return;
            }
            $aacData = Flv::accPacketDataRead($audioData['data']);
            if ($aacData['accPacketType'] == Flv::ACC_PACKET_TYPE_SEQUENCE_HEADER) {
                $this->audioSequenceHeader = $aacData['data'];
            }
        }

        // 处理视频序列头
        if ($frame instanceof VideoFrame) {
            $videoData = Flv::videoFrameDataRead((string)$frame);
            if (empty($videoData) || $videoData['codecId'] != Flv::VIDEO_CODEC_ID_AVC) {
                return;
            }
            $avcData = Flv::avcPacketRead($videoData['data']);
            if ($avcData['avcPacketType'] == Flv::AVC_PACKET_TYPE_SEQUENCE_HEADER) {
                $this->videoSequenceHeader = $avcData['data'];
                $this->videoCodecId = $videoData['codecId'];
            }
        }
    }

    /**
     * 处理音频帧
     * @param AudioFrame $frame
     * @param int $relativeTime 相对时间(毫秒)
     */
    private function processAudioFrame(AudioFrame $frame, int $relativeTime)
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

        // 仅处理有效音频帧
        if ($this->tsFileHandle && $this->audioSequenceHeader
            && $aacData['accPacketType'] == Flv::ACC_PACKET_TYPE_RAW) {
            $this->writeAudioToTS($aacData['data'], $relativeTime);
        }
    }

    /**
     * 写入音频到TS
     */
    private function writeAudioToTS($aacData, $timestamp)
    {
        // 计算切片内相对时间(毫秒) -> 转换为90kHz时钟
        $segmentRelativeTime = $timestamp - $this->currentSegmentStartRelativeTime;
        $pts = (int)($segmentRelativeTime / 1000 * 90000);

        // 生成ADTS头
        $adtsHeader = $this->createADTSHeader(strlen($aacData));
        $frameWithAdts = $adtsHeader . $aacData;

        // 创建PES包
        $pesData = $this->createPESPacket(
            0xC0,  // 音频流ID
            $frameWithAdts,
            $pts,
            $pts
        );

        $this->writeTSPacket($this->audioPid, $pesData);
    }

    /**
     * 创建AAC的ADTS头
     */
    private function createADTSHeader(int $aacDataLength)
    {
        if ($this->audioSequenceHeader === null || strlen($this->audioSequenceHeader) < 2) {
            return "";
        }

        $asc = $this->audioSequenceHeader;
        $asc1 = ord($asc[0]);
        $asc2 = ord($asc[1]);

        // 解析音频参数
        $audioObjectType = (($asc1 >> 3) & 0x1F);
        $samplingFreqIdx = (($asc1 & 0x07) << 1) | (($asc2 >> 7) & 0x01);
        $channelConfig = (($asc2 >> 3) & 0x0F);

        $sampleRates = [96000, 88200, 64000, 48000, 44100, 32000, 24000, 22050, 16000, 12000, 11025, 8000];
        $sampleRate = $sampleRates[$samplingFreqIdx] ?? 44100;

        // 计算ADTS总长度
        $adtsTotalLength = 7 + $aacDataLength;
        if ($adtsTotalLength > 0x1FFF) {
            return "";
        }

        // 构建ADTS头
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
     */
    private function processVideoFrame(VideoFrame $frame, int $relativeTime)
    {
        $videoData = Flv::videoFrameDataRead((string)$frame);
        if (empty($videoData) || $videoData['codecId'] != Flv::VIDEO_CODEC_ID_AVC) {
            return;
        }

        $avcData = Flv::avcPacketRead($videoData['data']);
        if (empty($avcData)) {
            return;
        }

        // 处理视频序列头
        if ($avcData['avcPacketType'] == Flv::AVC_PACKET_TYPE_SEQUENCE_HEADER) {
            $this->videoSequenceHeader = $avcData['data'];
            return;
        }

        if ($this->videoSequenceHeader === null) {
            return;
        }

        $isKeyFrame = ($videoData['frameType'] == Flv::VIDEO_FRAME_TYPE_KEY_FRAME);

        // 关键帧处理：切片分割
        if ($isKeyFrame) {
            $this->handleKeyframeForSegmentation($relativeTime, $avcData);
        }

        // 写入视频数据
        if ($this->tsFileHandle) {
            $videoPayload = $isKeyFrame
                ? $this->toAnnexB($this->videoSequenceHeader) . $this->toAnnexB($avcData['data'])
                : $this->toAnnexB($avcData['data']);

            $this->writeVideoToTS($videoPayload, $relativeTime, $isKeyFrame);
        }
    }

    /**
     * 关键帧切片分割逻辑
     */
    private function handleKeyframeForSegmentation($relativeTime, $avcData)
    {
        // 缓存关键帧数据（用于新切片初始化）
        $this->lastKeyframeData = $this->toAnnexB($this->videoSequenceHeader) . $this->toAnnexB($avcData['data']);

        // 计算时间差
        $timeDiff = $relativeTime - $this->lastKeyframeTimestamp;

        // 首次创建切片或满足时长条件时分割
        if ($this->lastKeyframeTimestamp === 0 || $timeDiff >= $this->segmentDuration * 1000) {
            $this->startNewSegment($relativeTime);
            $this->lastKeyframeTimestamp = $relativeTime;
        }
    }

    /**
     * AVCC转Annex B格式
     */
    private function toAnnexB($nalu)
    {
        $offset = 0;
        $result = '';

        while ($offset + 4 <= strlen($nalu)) {
            $naluLen = unpack('N', substr($nalu, $offset, 4))[1];
            $offset += 4;

            if ($offset + $naluLen > strlen($nalu)) {
                break;
            }

            $result .= "\x00\x00\x00\x01" . substr($nalu, $offset, $naluLen);
            $offset += $naluLen;
        }

        return $result;
    }

    /**
     * 创建新切片
     */
    private function startNewSegment($timestamp)
    {
        // 关闭上一切片
        if ($this->tsFileHandle) {
            fclose($this->tsFileHandle);

            // 计算上一切片实际时长
            $duration = ($timestamp - $this->segmentStartTime) / 1000;
            $duration = max(0.1, min($this->segmentDuration + 2, $duration)); // 限制时长范围
            $this->segmentDurations[$this->sequenceNumber] = round($duration, 3);

            $this->updateM3U8Playlist();
        }

        // 初始化新切片
        $this->sequenceNumber++;
        $this->currentSegmentFile = "{$this->streamDir}segment_{$this->sequenceNumber}.ts";
        $this->tsFileHandle = fopen($this->currentSegmentFile, 'wb');
        $this->segmentStartTime = $timestamp;
        $this->currentSegmentStartRelativeTime = $timestamp; // 记录当前切片起始相对时间

        // 写入PAT/PMT表
        $this->writePAT();
        $this->writePMT();

        // 新切片开头写入关键帧数据（确保可独立解码）
        if ($this->lastKeyframeData) {
            $this->writeVideoToTS($this->lastKeyframeData, $timestamp, true);
        }
    }

    /**
     * 更新M3U8播放列表
     */
    private function updateM3U8Playlist()
    {
        $m3u8Path = "{$this->streamDir}index.m3u8";
        $segments = glob("{$this->streamDir}segment_*.ts");
        sort($segments, SORT_NATURAL);

        // 清理过期切片
        if (count($segments) > $this->maxSegments) {
            $toDelete = array_slice($segments, 0, count($segments) - $this->maxSegments);
            foreach ($toDelete as $file) {
                if (file_exists($file)) unlink($file);
            }
            $segments = array_slice($segments, -$this->maxSegments);
        }

        // 生成M3U8内容
        $m3u8Content = "#EXTM3U\n";
        $m3u8Content .= "#EXT-X-VERSION:3\n";
        $m3u8Content .= "#EXT-X-TARGETDURATION:" . ceil($this->segmentDuration) . "\n";
        $m3u8Content .= "#EXT-X-MEDIA-SEQUENCE:" . max(1, $this->sequenceNumber - count($segments) + 1) . "\n";

        foreach ($segments as $segment) {
            // 提取切片序号
            if (preg_match('/segment_(\d+)\.ts/', basename($segment), $matches)) {
                $seq = intval($matches[1]);
                $duration = $this->segmentDurations[$seq] ?? $this->segmentDuration;
                $m3u8Content .= "#EXTINF:{$duration},\n";
                $m3u8Content .= basename($segment) . "\n";
            }
        }

        file_put_contents($m3u8Path, $m3u8Content);
    }

    /**
     * 写入PAT表
     */
    private function writePAT()
    {
        $pat = pack('C', 0x00);         // 表ID
        $pat .= pack('C', 0xB0);        // 标志位
        $pat .= pack('C', 0x0D);        // 段长度
        $pat .= pack('n', 0x0001);      // 节目号
        $pat .= pack('C', 0xC1);        // 版本号+标志
        $pat .= pack('C', 0x00);        // 段号
        $pat .= pack('n', 0xE000 | $this->pmtPid); // PMT PID
        $crc = $this->crc32mpeg(substr($pat, 0, 8));
        $pat .= pack('N', $crc);

        $this->writeTSPacket($this->patPid, $pat);
    }

    /**
     * 写入PMT表
     */
    private function writePMT()
    {
        $pmt = pack('C', 0x02);         // 表ID
        $pmt .= pack('C', 0xB0);        // 标志位
        $pmt .= pack('C', 0x18);        // 段长度
        $pmt .= pack('n', 0x0001);      // 节目号
        $pmt .= pack('C', 0xC1);        // 版本号+标志
        $pmt .= pack('C', 0x00);        // 段号
        $pmt .= pack('n', 0x1FFF & $this->videoPid); // PCR PID
        $pmt .= pack('n', 0x0000);      // 节目信息长度

        // 视频流描述 (H.264)
        $pmt .= pack('C', 0x1B);        // 流类型
        $pmt .= pack('n', 0xE000 | $this->videoPid);
        $pmt .= pack('n', 0x0000);

        // 音频流描述 (AAC)
        $pmt .= pack('C', 0x0F);        // 流类型
        $pmt .= pack('n', 0xE000 | $this->audioPid);
        $pmt .= pack('n', 0x0000);

        $crc = $this->crc32mpeg(substr($pmt, 0, 20));
        $pmt .= pack('N', $crc);

        $this->writeTSPacket($this->pmtPid, $pmt);
    }

    /**
     * 写入视频到TS
     */
    private function writeVideoToTS($videoData, $timestamp, $isKeyFrame)
    {
        // 计算切片内相对时间 -> 转换为90kHz时钟
        $segmentRelativeTime = $timestamp - $this->currentSegmentStartRelativeTime;
        $pts = (int)($segmentRelativeTime / 1000 * 90000);
        $dts = $pts;

        // 创建PES包
        $pesData = $this->createPESPacket(
            0xE0,  // 视频流ID
            $videoData,
            $pts,
            $dts
        );

        // 计算PCR值（27MHz时钟）
        $currentPCR = $pts * 300;
        $this->writeTSPacket($this->videoPid, $pesData, $isKeyFrame, true, $currentPCR);
    }

    /**
     * 创建PES包
     */
    private function createPESPacket($streamId, $payload, $pts, $dts)
    {
        $pesHeaderStart = "\x00\x00\x01" . chr($streamId);

        // 编码时间戳
        $ptsData = $this->encodeTimestamp(0x02, $pts);
        $headerData = $ptsData;

        // 处理DTS
        if ($dts !== null && $dts !== $pts) {
            $dtsData = $this->encodeTimestamp(0x01, $dts);
            $headerData = $ptsData . $dtsData;
        }

        $headerDataLength = strlen($headerData);
        $flags = 0x80; // 包含PTS
        if ($dts !== null && $dts !== $pts) {
            $flags |= 0x40; // 包含DTS
        }

        // 计算PES长度
        $totalLength = 1 + 2 + 1 + $headerDataLength + strlen($payload);
        $packetLength = ($totalLength <= 0xFFFF) ? $totalLength : 0;

        // 组装PES头
        $pesHeader = $pesHeaderStart
            . pack('n', $packetLength)
            . chr(0x80)
            . chr($flags)
            . chr($headerDataLength)
            . $headerData;

        return $pesHeader . $payload;
    }

    /**
     * 编码时间戳
     */
    private function encodeTimestamp($flag, $ts)
    {
        return pack('C', ($flag << 4) | (($ts >> 30 & 0x07) << 1) | 1)
            . pack('n', (($ts >> 15) & 0x7FFF) << 1 | 1)
            . pack('n', ($ts & 0x7FFF) << 1 | 1);
    }

    /**
     * 写入TS包
     */
    private function writeTSPacket($pid, $payload, $isKeyFrame = false, $isVideo = false, $pcrBase = null)
    {
        $tsPacketSize = 188;
        $syncByte = 0x47;

        // 构建TS头
        $header = chr($syncByte);
        $header .= chr((($isKeyFrame ? 0x40 : 0x00) | (($pid >> 8) & 0x1F)));
        $header .= chr($pid & 0xFF);

        $adaptationFieldControl = 0x10;
        $adaptationField = '';

        // 关键帧添加PCR
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

        // 组装TS包
        $packet = $header . $adaptationField . $payload;

        // 填充至188字节
        if (strlen($packet) < $tsPacketSize) {
            $packet .= str_repeat("\xFF", $tsPacketSize - strlen($packet));
        }

        fwrite($this->tsFileHandle, $packet);
    }

    /**
     * MPEG CRC32计算
     */
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
     * 获取HLS播放地址
     */
    public function getHlsUrl()
    {
        return "/hls/{$this->streamId}/index.m3u8";
    }

    /**
     * 关闭资源
     */
    public function close()
    {
        if ($this->tsFileHandle) {
            fclose($this->tsFileHandle);
            $this->tsFileHandle = null;

            // 补充M3U8结束标记
            $m3u8Path = "{$this->streamDir}index.m3u8";
            if (file_exists($m3u8Path)) {
                file_put_contents($m3u8Path, "\n#EXT-X-ENDLIST\n", FILE_APPEND);
            }
        }
    }

    /**
     * 析构函数
     */
    public function __destruct()
    {
        $this->close();
    }
}
