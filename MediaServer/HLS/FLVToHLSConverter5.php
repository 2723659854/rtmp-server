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

class FLVToHLSConverter5
{
    private $segmentDuration = 4;
    private $maxSegments = 10;
    private $streamId;
    private $streamDir;
    private $sequenceNumber = 0;
    private $currentSegmentFile;
    private $segmentStartTime = 0;
    private $firstTimestamp = null;
    private $lastKeyframeTimestamp = 0;
    private $videoPid = 0x100;
    private $pmtPid = 0x10;
    private $patPid = 0;
    private $audioPid = 0x101;
    private $videoSequenceHeader = null;
    private $audioSequenceHeader = null;
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

    public function processFrame($frame)
    {
        if (!$frame instanceof VideoFrame && !$frame instanceof AudioFrame) {
            return;
        }

        if ($this->firstTimestamp === null) {
            $this->firstTimestamp = $frame->timestamp;
        }

        $relativeTime = $frame->timestamp - $this->firstTimestamp;

        if ($frame instanceof VideoFrame) {
            $this->processVideoFrame($frame, $relativeTime);
        } else {
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
            throw new \RuntimeException("Invalid audio sequence header");
        }

        $asc1 = ord($this->audioSequenceHeader[0]);
        $asc2 = ord($this->audioSequenceHeader[1]);

        // 强制使用AAC-LC (Audio Object Type = 2)
        $audioObjectType = 2;
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
            $videoPayload = $this->prepareVideoPayload($avcData['data'], $isKeyFrame);
            $this->writeVideoToTS($videoPayload, $relativeTime, $isKeyFrame);
        }
    }

    private function prepareVideoPayload($videoData, $isKeyFrame)
    {
        if (!$isKeyFrame) {
            return $this->toAnnexB($videoData);
        }

        $sps_pps = $this->extractSpsPps($this->videoSequenceHeader);
        return $sps_pps . $this->toAnnexB($videoData);
    }

    private function extractSpsPps($avcSeqHeader)
    {
        $result = '';
        $offset = 0;

        if (strlen($avcSeqHeader) > 4) {
            $offset += 4; // Skip version info

            // SPS
            if (strlen($avcSeqHeader) > $offset + 2) {
                $spsLen = unpack('n', substr($avcSeqHeader, $offset, 2))[1];
                $offset += 2;
                if (strlen($avcSeqHeader) >= $offset + $spsLen) {
                    $result .= "\x00\x00\x00\x01" . substr($avcSeqHeader, $offset, $spsLen);
                    $offset += $spsLen;
                }
            }

            // PPS
            if (strlen($avcSeqHeader) > $offset + 1) {
                $ppsCount = ord($avcSeqHeader[$offset++]);
                if ($ppsCount > 0 && strlen($avcSeqHeader) > $offset + 2) {
                    $ppsLen = unpack('n', substr($avcSeqHeader, $offset, 2))[1];
                    $offset += 2;
                    if (strlen($avcSeqHeader) >= $offset + $ppsLen) {
                        $result .= "\x00\x00\x00\x01" . substr($avcSeqHeader, $offset, $ppsLen);
                    }
                }
            }
        }

        return $result;
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
        $pat = pack('C', 0x00);         // Table ID
        $pat .= pack('C', 0xB0);        // Flags
        $pat .= pack('C', 0x0D);        // Section length
        $pat .= pack('n', 0x0001);      // Program number
        $pat .= pack('C', 0xC1);        // Version/current indicator
        $pat .= pack('C', 0x00);        // Section number
        $pat .= pack('n', 0xE000 | $this->pmtPid); // PMT PID
        $crc = $this->crc32mpeg(substr($pat, 0, 8));
        $pat .= pack('N', $crc);

        $this->writeTSPacket($this->patPid, $pat);
    }

    private function writePMT()
    {
        $pmt = pack('C', 0x02);         // Table ID
        $pmt .= pack('C', 0xB0);        // Flags

        // Section length (12 + video(5) + audio(6) + CRC(4) = 27 → 0x1B)
        $pmt .= pack('C', 0x1B);
        $pmt .= pack('n', 0x0001);      // Program number
        $pmt .= pack('C', 0xC1);        // Version/current indicator
        $pmt .= pack('C', 0x00);        // Section number
        $pmt .= pack('n', 0x1FFF & $this->videoPid); // PCR PID
        $pmt .= pack('n', 0x0000);      // Program info length

        // Video stream (H.264)
        $pmt .= pack('C', 0x1B);        // Stream type (H.264)
        $pmt .= pack('n', 0xE000 | $this->videoPid);
        $pmt .= pack('n', 0x0000);      // ES info length

        // Audio stream (AAC with descriptor)
        $pmt .= pack('C', 0x0F);        // Stream type (AAC)
        $pmt .= pack('n', 0xE000 | $this->audioPid);
        $pmt .= pack('n', 0x0001);      // ES info length
        $pmt .= chr(0x11);              // MPEG-4 audio descriptor

        $crc = $this->crc32mpeg(substr($pmt, 0, 19));
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

        $flags = 0x80; // PTS flag
        $headerDataLength = 5;

        if ($dts !== null && $dts !== $pts) {
            $flags |= 0x40; // DTS flag
            $headerDataLength += 5;
        }

        $pesPacketLength = strlen($payload) + 3 + $headerDataLength;
        if ($pesPacketLength > 0xFFFF) {
            $pesPacketLength = 0;
        }

        $pesHeader .= pack('n', $pesPacketLength);
        $pesHeader .= chr(0x80); // Marker bits
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

        $adaptationFieldControl = 0x10; // Payload only
        $adaptationField = '';

        if ($isVideo && $isKeyFrame && $pcrBase !== null) {
            $adaptationFieldControl = 0x30; // Adaptation + payload

            $pcrBase33 = $pcrBase & 0x1FFFFFFFF;
            $pcrExt = 0;

            $adaptationField .= chr(7); // Adaptation field length
            $adaptationField .= chr(0x10); // PCR flag
            $adaptationField .= pack('N', ($pcrBase33 << 1)) . chr(0); // PCR base (33 bits)
            $adaptationField .= pack('n', $pcrExt << 7); // PCR ext (9 bits)
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