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

    public function __construct($streamId, $config = [])
    {
        $this->streamId = $streamId;
        $this->streamDir = dirname(__DIR__, 2) . "/hls/{$streamId}/";

        if (isset($config['segmentDuration'])) {
            $this->segmentDuration = (int)$config['segmentDuration'];
        }
        if (isset($config['maxSegments'])) {
            $this->maxSegments = (int)$config['maxSegments'];
        }

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

            /** 可能会收到音频序列帧 ，在这里先尝试解码 */
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

        if ($this->tsFileHandle && $this->audioSequenceHeader &&
            $aacData['accPacketType'] == Flv::ACC_PACKET_TYPE_RAW) {
            $this->writeAudioToTS($aacData['data'], $relativeTime);
        }
    }

    private function writeAudioToTS($aacData, $timestamp)
    {
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

    private function createADTSHeader($aacDataLength)
    {
        if (!$this->audioSequenceHeader || strlen($this->audioSequenceHeader) < 2) {
            //throw new \RuntimeException("无效的音频序列头");
            return "";
        }

        $asc1 = ord($this->audioSequenceHeader[0]);
        $asc2 = ord($this->audioSequenceHeader[1]);

        $audioObjectType = (($asc1 >> 3) & 0x1F);
        $samplingFreqIdx = (($asc1 & 0x07) << 1) | (($asc2 >> 7) & 0x01);
        $channelConfig = (($asc2 >> 3) & 0x0F);

        $adtsLength = 7 + $aacDataLength;

        $adts = chr(0xFF); // Syncword
        $adts .= chr(0xF1); // MPEG-4, Layer, protection_absent
        $adts .= chr(
            (($audioObjectType - 1) << 6) |
            ($samplingFreqIdx << 2) |
            ($channelConfig >> 2)
        );
        $adts .= chr(
            (($channelConfig & 3) << 6) |
            ($adtsLength >> 11)
        );
        $adts .= chr(($adtsLength >> 3) & 0xFF);
        $adts .= chr((($adtsLength & 7) << 5) | 0x1F);
        $adts .= chr(0xFC); // buffer fullness

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

    private function createPESPacket($streamId, $payload, $pts, $dts = null)
    {
        $pesHeader = "\x00\x00\x01" . chr($streamId);

        $flags = 0x80; // PTS标志
        $headerDataLength = 5;

        if ($dts !== null && $dts !== $pts) {
            $flags |= 0x40; // DTS标志
            $headerDataLength += 5;
        }

        $pesPacketLength = strlen($payload) + 3 + $headerDataLength;
        if ($pesPacketLength > 0xFFFF) {
            $pesPacketLength = 0;
        }

        $pesHeader .= pack('n', $pesPacketLength);
        $pesHeader .= chr(0x80); // 标记位
        $pesHeader .= chr($flags);
        $pesHeader .= chr($headerDataLength);
        $pesHeader .= $this->encodeTimestamp(0x02, $pts);

        if ($flags & 0x40) {
            $pesHeader .= $this->encodeTimestamp(0x01, $dts);
        }

        return $pesHeader . $payload;
    }

    private function encodeTimestamp($flag, $ts)
    {
        return pack('C', ($flag << 4) | (($ts >> 30 & 0x07) << 1) | 1)
            . pack('n', (($ts >> 15) & 0x7FFF) << 1 | 1)
            . pack('n', ($ts & 0x7FFF) << 1 | 1);
    }

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