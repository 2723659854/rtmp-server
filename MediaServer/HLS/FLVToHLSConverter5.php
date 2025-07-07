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
 * 确保生成的TS切片在VLC中完美播放音视频
 * @note 当前版本可以使用vlc播放
 * 识别出来为mp3 mp2,但是可以播放。
 * @note 这是稳定可播放版本
 */
class FLVToHLSConverter5
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

    /**
     * 初始化
     * @param $streamId
     * @param $config
     */
    public function __construct($streamId, $config = [])
    {
        /** 保存数据流ID */
        $this->streamId = $streamId;
        /** 设置切片保存路径 */
        $this->streamDir = dirname(__DIR__, 2) . "/hls/{$streamId}/";
        /** 切片时长 */
        if (isset($config['segmentDuration'])) {
            $this->segmentDuration = (int)$config['segmentDuration'];
        }else{
            /** 默认为4秒1个切片 */
            $this->segmentDuration = 4;
        }
        /** 最大的保存切片个数 */
        if (isset($config['maxSegments'])) {
            $this->maxSegments = (int)$config['maxSegments'];
        }else{
            /** 默认保存所有切片，方便回放视频 */
            $this->maxSegments = 10000;
        }

        /** 创建切片保存目录 */
        if (!is_dir($this->streamDir)) {
            mkdir($this->streamDir, 0777, true);
        }
    }

    /**
     * 处理FLV帧数据（对外接口）
     * @param mixed  $frame 视频帧或者音频帧
     * @throws \RuntimeException 若帧处理失败
     */
    public function processFrame(mixed $frame)
    {
        // 仅处理视频帧和音频帧
        if (!$frame instanceof VideoFrame && !$frame instanceof AudioFrame) {
            return;
        }

        // 初始化首个时间戳 ，只获取第一个视频关键帧的时间戳
        if ($frame instanceof VideoFrame  && $this->firstTimestamp === null) {
            // 解析FLV视频帧头（依赖Flv类）
            $videoData = Flv::videoFrameDataRead((string)$frame);
            if (empty($videoData)) {
                return;
                //throw new \RuntimeException("无法解析视频帧数据");
            }
            /** 如果是关键帧，则保存着第一个关键帧的时间戳作为基准时间，后面所有的包都以此时间作为基准 */
            if (($videoData['frameType'] == Flv::VIDEO_FRAME_TYPE_KEY_FRAME)){
                $this->firstTimestamp = $frame->timestamp;
            }
        }

        /** 没有拿到第一个关键帧 的时间戳，则不做处理，但是要确要收到音视频的序列帧数据 */
        if ($this->firstTimestamp === null){
            /** 此时可能是音频序列帧 */
            if ($frame instanceof AudioFrame) {
                /** 可能会收到音频序列帧 */
                $audioData = Flv::audioFrameDataRead((string)$frame);
                if ($audioData['soundFormat'] != Flv::SOUND_FORMAT_ACC) {
                    return; // 仅处理AAC音频
                }
                /** 防止音频序列帧被错误丢弃，则在此处先保存 */
                $aacData = Flv::accPacketDataRead($audioData['data']);
                if ($aacData['accPacketType'] == Flv::ACC_PACKET_TYPE_SEQUENCE_HEADER) {
                    $this->audioSequenceHeader = $aacData['data']; // 保存序列头
                    return;
                }
            }
            /** 可能是视频序列帧，也需要保存 */
            if ($frame instanceof VideoFrame) {
                // 解析FLV视频帧头（依赖Flv类）
                $videoData = Flv::videoFrameDataRead((string)$frame);
                if (empty($videoData)) {
                    //throw new \RuntimeException("无法解析视频帧数据");
                    return;
                }

                // 验证编码格式（仅支持H.264）
                $this->videoCodecId = $videoData['codecId'];
                if ($this->videoCodecId != Flv::VIDEO_CODEC_ID_AVC) {
                    throw new \RuntimeException("仅支持H.264编码，当前编码ID: {$this->videoCodecId}");
                }

                // 解析AVC帧数据（包含NAL单元）
                $avcData = Flv::avcPacketRead($videoData['data']);
                if (empty($avcData)) {
                    //throw new \RuntimeException("无法解析AVC帧数据");
                    return;
                }

                // 处理序列头（SPS/PPS，必须优先获取）
                if ($avcData['avcPacketType'] == Flv::AVC_PACKET_TYPE_SEQUENCE_HEADER) {
                    $this->videoSequenceHeader = $avcData['data'];
                    return;
                }
            }
            return;
        }

        // 计算相对时间（毫秒） 就是当前帧的时间相对于第一帧的时间
        $relativeTime = $frame->timestamp - $this->firstTimestamp;
        if ($frame instanceof VideoFrame) {
            /** 处理视频帧 */
            $this->processVideoFrame($frame, $relativeTime);
        } elseif ($frame instanceof AudioFrame) {
            /** 处理音频帧 */
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

        if ($audioData['soundFormat'] != Flv::SOUND_FORMAT_ACC) {
            return;
        }

        $aacData = Flv::accPacketDataRead($audioData['data']);

        if ($aacData['accPacketType'] == Flv::ACC_PACKET_TYPE_SEQUENCE_HEADER) {
            $this->audioSequenceHeader = $aacData['data'];
            return;
        }

        if (
            $this->tsFileHandle // 必须有打开的ts文件，就是必须是先写入了关键帧
            && $this->audioSequenceHeader  // 必须有音频序列帧
            && $aacData['accPacketType'] == Flv::ACC_PACKET_TYPE_RAW // 必须是aac编码
        ) {
            $this->writeAudioToTS($aacData['data'], $relativeTime);
        }
    }

    /**
     * 将音频帧写入到ts文件
     * @param $aacData
     * @param $timestamp
     * @return void
     */
    private function writeAudioToTS($aacData, $timestamp)
    {
        /** 将时间戳转化为90Hz频率的始终 */
        $pts = (int)($timestamp / 1000 * 90000);

        // 生成符合规范的ADTS头
        $adtsHeader = $this->createADTSHeader(strlen($aacData));
        $frameWithAdts = $adtsHeader . $aacData;

        $pesData = $this->createPESPacket(
            0xC0,
            $frameWithAdts,
            $pts,
            $pts
        );

        $this->writeTSPacket($this->audioPid, $pesData);
    }

    /**
     * 创建AAC音频的ADTS头（Audio Data Transport Stream）
     * ADTS头是AAC音频流在MPEG-TS等容器中的封装格式头，包含音频参数信息
     *
     * @param int $aacDataLength 当前AAC帧的数据长度（不含ADTS头）
     * @return string 7字节的ADTS头
     * @throws \RuntimeException 如果音频序列头未设置或无效
     */
    private function createADTSHeader(int $aacDataLength)
    {
        // 检查是否已设置音频序列头（Audio Specific Config）
        if ($this->audioSequenceHeader === null) {
            // throw new \RuntimeException("缺少音频序列头");
            return "";
        }
        // 获取音频序列头二进制数据（通常为2字节）
        $asc = $this->audioSequenceHeader;
        if (strlen($asc) < 2) {
            //throw new \RuntimeException("无效的音频序列头");
            return "";
        }
        /**
         * 解析音频序列头第一字节（ASC1）
         * 结构：AAAAAAAA (A=音频对象类型+采样率高位)
         */
        $asc1 = ord($asc[0]);

        /**
         * 解析音频序列头第二字节（ASC2）
         * 结构：BBBBBBBB (B=采样率低位+声道配置)
         */
        $asc2 = ord($asc[1]);

        // 从ASC1中提取音频对象类型（AAC编码规格）
        // 高5位：audioObjectType = (AAC Profile) - 1
        // 解析AAC参数（同之前）
        $audioObjectType = (($asc1 >> 3) & 0x1F);
        // 从ASC1和ASC2中组合采样率索引
        // ASC1低3位 + ASC2最高位 = 4位采样率索引
        $samplingFreqIdx = (($asc1 & 0x07) << 1) | (($asc2 >> 7) & 0x01);
        // 从ASC2中提取声道配置（低4位的高3位）
        $channelConfig = (($asc2 >> 3) & 0x0F);
        // 标准采样率索引对应表（MPEG-4规范）
        $sampleRates = [96000, 88200, 64000, 48000, 44100, 32000, 24000, 22050, 16000, 12000, 11025, 8000];
        // 获取实际采样率（默认44100Hz）
        $sampleRate = $sampleRates[$samplingFreqIdx] ?? 44100;

        //echo "音频实际采样率". $sampleRate."\r\n";
        /**
         * 计算ADTS帧总长度（含7字节头 + AAC数据）
         * 注意：ADTS长度字段只有13位，最大值8191（0x1FFF）
         */
        // 关键修正：ADTS总长度 = ADTS头（7字节） + AAC数据长度
        $adtsTotalLength = 7 + $aacDataLength;
        if ($adtsTotalLength > 0x1FFF) {
            throw new \RuntimeException("AAC帧过长，超过ADTS支持的最大长度");
        }

        // 开始构建ADTS头（共7字节）
        // 构建ADTS头（字段含义同之前，确保长度计算正确）
        $adts = chr(0xFF);// 同步字高8位（固定0xFF）
        $adts .= chr(0xF1);// 同步字低4位 + 版本/层/保护位（0xF1=MPEG-4, 无CRC）
        /**
         * 第三字节：
         * - 音频对象类型高2位（profile-1）
         * - 采样率索引4位
         * - 声道配置最高1位
         */
        $adts .= chr(
            (($audioObjectType - 1) << 6) | // 音频对象类型左移6位
            ($samplingFreqIdx << 2) |// 采样率索引左移2位
            (($channelConfig >> 2) & 0x01)// 声道配置最高位
        );
        /**
         * 第四字节：
         * - 声道配置低2位
         * - 帧长度高2位
         */
        $adts .= chr(
            (($channelConfig & 0x03) << 6) |// 声道配置低2位左移6位
            (($adtsTotalLength >> 11) & 0x03)// 帧长度右移11位取高2位
        );
        // 第五字节：帧长度中间8位
        $adts .= chr(($adtsTotalLength >> 3) & 0xFF);
        /**
         * 第六字节：
         * - 帧长度低3位（左移5位）
         * - 缓冲 fullness 固定值0x1F（二进制11111）
         */
        $adts .= chr((($adtsTotalLength & 0x07) << 5) |// 帧长度低3位左移5位
            0x1F);// 固定填充位
        // 第七字节：缓冲 fullness 续 + 帧数（固定0xFC表示单帧）
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
        $this->writeTSPacket($this->videoPid, $pesData, $isKeyFrame, true, $currentPCR);
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

    /**
     * 将数据写入TS包（MPEG-TS的传输单元）
     * @param int $pid 数据包ID
     * @param string $payload 负载数据
     */
    private function writeTSPacket($pid, $payload, $isKeyFrame = false, $isVideo = false, $pcrBase = null)
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

    public function getHlsUrl()
    {
        return "/hls/{$this->streamId}/index.m3u8";
    }

    public function close()
    {
        if ($this->tsFileHandle) {
            fclose($this->tsFileHandle);
            $this->tsFileHandle = null;

            $m3u8Path = "{$this->streamDir}index.m3u8";
            if (file_exists($m3u8Path)) {
                file_put_contents($m3u8Path, "\n#EXT-X-ENDLIST\n", FILE_APPEND);
            }
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}