<?php

namespace MediaServer\HLS;

use MediaServer\Flv\Flv;
use MediaServer\MediaReader\AudioFrame;
use MediaServer\MediaReader\VideoFrame;

/**
 * rtmp转码hls
 * 修复版：
 * - 正确PTS/DTS
 * - 正确PCR
 * - 使用真实IDR切片
 * - 修复缩略图
 * - 修复illegal short term buffer
 * - 修复DTS discontinuity
 */
class FLVToHLSConverter15
{
    private int $segmentDuration = 4;

    private string $streamId;
    private string $streamDir;

    private int $videoPid = 0x100;
    private int $audioPid = 0x101;
    private int $pmtPid   = 0x1000;

    private int $sequenceNumber = 0;

    private $tsHandle = null;

    private ?int $firstTimestamp = null;
    private int $segmentStartTs = 0;

    private array $continuityCounters = [];

    private ?string $audioSpecificConfig = null;

    private int $lastVideoDts = -1;
    private int $lastAudioPts = -1;

    public function __construct(string $streamId, array $config = [])
    {
        $streamId = trim($streamId, '/');

        $this->streamId = $streamId;

        $this->streamDir =
            dirname(__DIR__, 2)
            . "/hls/{$streamId}/";

        if (!is_dir($this->streamDir)) {
            mkdir($this->streamDir, 0777, true);
        }

        if (isset($config['segmentDuration'])) {
            $this->segmentDuration =
                (int)$config['segmentDuration'];
        }
    }

    public function processFrame($frame): void
    {
        if (
            !$frame instanceof VideoFrame
            &&
            !$frame instanceof AudioFrame
        ) {
            return;
        }

        if ($frame instanceof AudioFrame) {
            $this->handleAudioFrame($frame);
            return;
        }

        $this->handleVideoFrame($frame);
    }

    private function handleVideoFrame(
        VideoFrame $frame
    ): void {

        $videoData =
            Flv::videoFrameDataRead((string)$frame);

        if (!$videoData) {
            return;
        }

        $avc =
            Flv::avcPacketRead($videoData['data']);

        if (!$avc) {
            return;
        }

        // AVC sequence header
        if (
            $avc['avcPacketType']
            ==
            Flv::AVC_PACKET_TYPE_SEQUENCE_HEADER
        ) {
            return;
        }

        if (
            $avc['avcPacketType']
            !=
            Flv::AVC_PACKET_TYPE_NALU
        ) {
            return;
        }

        $annexb =
            $this->avccToAnnexB($avc['data']);

        if ($annexb === '') {
            return;
        }

        $isRealIDR =
            $this->isRealIDR($annexb);

        // 第一帧必须是真IDR
        if ($this->firstTimestamp === null) {

            if (!$isRealIDR) {
                return;
            }

            $this->firstTimestamp =
                $frame->timestamp;

            $this->startSegment(0);
        }

        $relativeTs =
            $frame->timestamp
            - $this->firstTimestamp;

        // 使用真实IDR切片
        if (
            $isRealIDR
            &&
            ($relativeTs - $this->segmentStartTs)
            >= ($this->segmentDuration * 1000)
        ) {

            $this->closeSegment();

            $this->startSegment($relativeTs);
        }

        $cts =
            $avc['compositionTime'] ?? 0;

        // signed 24bit
        if ($cts & 0x800000) {
            $cts -= 0x1000000;
        }

        // 正确DTS
        $dts = (int)($relativeTs * 90);

        if ($dts <= $this->lastVideoDts) {
            $dts = $this->lastVideoDts + 1;
        }

        $this->lastVideoDts = $dts;

        // 正确PTS
        $pts =
            $dts
            + (int)($cts * 90);

        if ($pts < $dts) {
            $pts = $dts;
        }

        $pes = $this->createPES(
            0xE0,
            $annexb,
            $pts,
            $dts
        );

        $this->writeTSPackets(
            $this->videoPid,
            $pes,
            true,
            $dts
        );
    }

    /**
     * 检查是否是真IDR
     */
    private function isRealIDR(
        string $annexb
    ): bool {

        $len = strlen($annexb);

        $i = 0;

        while ($i + 4 < $len) {

            if (
                $annexb[$i] === "\x00"
                &&
                $annexb[$i + 1] === "\x00"
                &&
                $annexb[$i + 2] === "\x00"
                &&
                $annexb[$i + 3] === "\x01"
            ) {

                $i += 4;

                if ($i >= $len) {
                    break;
                }

                $nalType =
                    ord($annexb[$i]) & 0x1F;

                // IDR
                if ($nalType === 5) {
                    return true;
                }
            }

            $i++;
        }

        return false;
    }

    private function handleAudioFrame(
        AudioFrame $frame
    ): void {

        $raw = (string)$frame;

        if (strlen($raw) < 2) {
            return;
        }

        $soundFormat =
            (ord($raw[0]) >> 4) & 0x0F;

        // AAC only
        if ($soundFormat != 10) {
            return;
        }

        $aacPacketType =
            ord($raw[1]);

        // AAC sequence header
        if ($aacPacketType == 0) {

            $asc = substr($raw, 2);

            if (strlen($asc) >= 2) {
                $this->audioSpecificConfig =
                    substr($asc, 0, 2);
            }

            return;
        }

        if ($aacPacketType != 1) {
            return;
        }

        if ($this->firstTimestamp === null) {
            return;
        }

        if (!$this->audioSpecificConfig) {
            return;
        }

        $relativeTs =
            $frame->timestamp
            - $this->firstTimestamp;

        $aacRaw = substr($raw, 2);

        if ($aacRaw === '') {
            return;
        }

        $adts =
            $this->createADTSHeader(
                strlen($aacRaw)
            );

        $payload =
            $adts . $aacRaw;

        $pts = (int)($relativeTs * 90);

        if ($pts <= $this->lastAudioPts) {
            $pts = $this->lastAudioPts + 1;
        }

        $this->lastAudioPts = $pts;

        $pes = $this->createPES(
            0xC0,
            $payload,
            $pts,
            null
        );

        $this->writeTSPackets(
            $this->audioPid,
            $pes
        );
    }

    private function avccToAnnexB(
        string $data
    ): string {

        $offset = 0;

        $result = '';

        $len = strlen($data);

        while ($offset + 4 <= $len) {

            $nalSize = unpack(
                'N',
                substr($data, $offset, 4)
            )[1];

            $offset += 4;

            if (
                $nalSize <= 0
                ||
                $offset + $nalSize > $len
            ) {
                break;
            }

            $result .= "\x00\x00\x00\x01";

            $result .= substr(
                $data,
                $offset,
                $nalSize
            );

            $offset += $nalSize;
        }

        return $result;
    }

    private function createADTSHeader(
        int $aacLength
    ): string {

        $asc = $this->audioSpecificConfig;

        $b1 = ord($asc[0]);
        $b2 = ord($asc[1]);

        $audioObjectType =
            ($b1 >> 3) & 0x1F;

        $freqIndex =
            (($b1 & 0x07) << 1)
            |
            (($b2 >> 7) & 0x01);

        $channelConfig =
            ($b2 >> 3) & 0x0F;

        $profile =
            max(0, $audioObjectType - 1);

        $frameLength =
            $aacLength + 7;

        return pack(
            'CCCCCCC',

            0xFF,
            0xF1,

            (($profile & 0x03) << 6)
            |
            (($freqIndex & 0x0F) << 2)
            |
            (($channelConfig >> 2) & 0x01),

            (($channelConfig & 0x03) << 6)
            |
            (($frameLength >> 11) & 0x03),

            ($frameLength >> 3) & 0xFF,

            (($frameLength & 0x07) << 5)
            | 0x1F,

            0xFC
        );
    }

    private function createPES(
        int $streamId,
        string $payload,
        int $pts,
        ?int $dts
    ): string {

        $pesHeaderData = '';

        if (
            $dts !== null
            &&
            $dts != $pts
        ) {

            $flags = 0xC0;

            $pesHeaderData .=
                $this->encodeTimestamp(
                    0x03,
                    $pts
                );

            $pesHeaderData .=
                $this->encodeTimestamp(
                    0x01,
                    $dts
                );

        } else {

            $flags = 0x80;

            $pesHeaderData .=
                $this->encodeTimestamp(
                    0x02,
                    $pts
                );
        }

        $pesHeaderLength =
            strlen($pesHeaderData);

        $packetLength =
            strlen($payload)
            + 3
            + $pesHeaderLength;

        // 视频允许为0
        if ($streamId == 0xE0) {
            $packetLength = 0;
        }

        return
            "\x00\x00\x01"
            . chr($streamId)
            . pack('n', $packetLength)
            . "\x80"
            . chr($flags)
            . chr($pesHeaderLength)
            . $pesHeaderData
            . $payload;
    }

    private function encodeTimestamp(
        int $type,
        int $ts
    ): string {

        $ts &= 0x1FFFFFFFF;

        return pack(
            'CCCCC',

            (($type << 4) & 0xF0)
            |
            ((($ts >> 30) & 0x07) << 1)
            | 1,

            ($ts >> 22) & 0xFF,

            ((($ts >> 15) & 0x7F) << 1)
            | 1,

            ($ts >> 7) & 0xFF,

            (($ts & 0x7F) << 1)
            | 1
        );
    }

    private function encodePCR(
        int $pcr
    ): string {

        return pack(
            'CCCCCC',

            ($pcr >> 25) & 0xFF,
            ($pcr >> 17) & 0xFF,
            ($pcr >> 9) & 0xFF,
            ($pcr >> 1) & 0xFF,

            (($pcr & 1) << 7)
            | 0x7E,

            0x00
        );
    }

    private function writeTSPackets(
        int $pid,
        string $payload,
        bool $writePCR = false,
        int $pcr = 0
    ): void {

        $cc =
            &$this->continuityCounters[$pid];

        if (!isset($cc)) {
            $cc = 0;
        }

        $offset = 0;

        $payloadLen = strlen($payload);

        $first = true;

        while ($offset < $payloadLen) {

            $remaining =
                $payloadLen - $offset;

            $packet = '';

            // sync byte
            $packet .= "\x47";

            $packet .= chr(
                (($first ? 1 : 0) << 6)
                |
                (($pid >> 8) & 0x1F)
            );

            $packet .= chr($pid & 0xFF);

            $adaptationField = '';

            $adaptationControl = 1;

            // PCR
            if ($writePCR && $first) {

                $adaptationControl = 3;

                $adaptationField =
                    chr(7)
                    . chr(0x10)
                    . $this->encodePCR($pcr);
            }

            $payloadSpace =
                188
                - 4
                - strlen($adaptationField);

            // stuffing
            if ($remaining < $payloadSpace) {

                $adaptationControl = 3;

                $stuffing =
                    $payloadSpace
                    - $remaining;

                if ($adaptationField === '') {

                    $adaptationField =
                        chr($stuffing - 1)
                        . chr(0x00);

                    if ($stuffing > 2) {

                        $adaptationField .=
                            str_repeat(
                                "\xFF",
                                $stuffing - 2
                            );
                    }

                } else {

                    $currentLen =
                        ord($adaptationField[0]);

                    $adaptationField[0] =
                        chr(
                            $currentLen
                            + $stuffing
                        );

                    $adaptationField .=
                        str_repeat(
                            "\xFF",
                            $stuffing
                        );
                }

                $payloadSpace =
                    188
                    - 4
                    - strlen($adaptationField);
            }

            $packet .= chr(
                ($adaptationControl << 4)
                |
                ($cc & 0x0F)
            );

            $cc = ($cc + 1) & 0x0F;

            $packet .= $adaptationField;

            $packet .= substr(
                $payload,
                $offset,
                $payloadSpace
            );

            $packet = str_pad(
                $packet,
                188,
                "\xFF"
            );

            fwrite(
                $this->tsHandle,
                $packet
            );

            $offset += $payloadSpace;

            $first = false;
        }
    }

    private function startSegment(
        int $timestamp
    ): void {

        $this->sequenceNumber++;

        $this->segmentStartTs =
            $timestamp;

        $file =
            $this->streamDir
            . "segment_{$this->sequenceNumber}.ts";

        $this->tsHandle =
            fopen($file, 'wb');

        $this->writePAT();

        $this->writePMT();
    }

    private function closeSegment(): void
    {
        if ($this->tsHandle) {

            fclose($this->tsHandle);

            $this->tsHandle = null;

            $this->updatePlaylist();
        }
    }

    private function writePAT(): void
    {
        $section =
            "\x00"
            . "\xB0\x0D"
            . "\x00\x01"
            . "\xC1"
            . "\x00"
            . "\x00"
            . "\x00\x01"
            . pack(
                'n',
                0xE000 | $this->pmtPid
            );

        $section .= pack(
            'N',
            $this->crc32mpeg($section)
        );

        $payload =
            "\x00"
            . $section;

        $this->writeTSPackets(
            0x0000,
            $payload
        );
    }

    private function writePMT(): void
    {
        $section =
            "\x02"
            . "\xB0\x17"
            . "\x00\x01"
            . "\xC1"
            . "\x00"
            . "\x00"
            . pack(
                'n',
                0xE000 | $this->videoPid
            )
            . "\xF0\x00"

            // H264
            . "\x1B"
            . pack(
                'n',
                0xE000 | $this->videoPid
            )
            . "\xF0\x00"

            // AAC
            . "\x0F"
            . pack(
                'n',
                0xE000 | $this->audioPid
            )
            . "\xF0\x00";

        $section .= pack(
            'N',
            $this->crc32mpeg($section)
        );

        $payload =
            "\x00"
            . $section;

        $this->writeTSPackets(
            $this->pmtPid,
            $payload
        );
    }

    private function crc32mpeg(
        string $data
    ): int {

        $crc = 0xFFFFFFFF;

        $len = strlen($data);

        for ($i = 0; $i < $len; $i++) {

            $crc ^= ord($data[$i]) << 24;

            for ($j = 0; $j < 8; $j++) {

                if ($crc & 0x80000000) {

                    $crc =
                        (($crc << 1)
                            ^ 0x04C11DB7)
                        & 0xFFFFFFFF;

                } else {

                    $crc =
                        ($crc << 1)
                        & 0xFFFFFFFF;
                }
            }
        }

        return $crc;
    }

    private function updatePlaylist(): void
    {
        $m3u8 =
            "#EXTM3U\n"
            . "#EXT-X-VERSION:3\n"
            . "#EXT-X-TARGETDURATION:{$this->segmentDuration}\n"
            . "#EXT-X-MEDIA-SEQUENCE:1\n";

        for (
            $i = 1;
            $i <= $this->sequenceNumber;
            $i++
        ) {

            $m3u8 .=
                "#EXTINF:{$this->segmentDuration},\n"
                . "segment_{$i}.ts\n";
        }

        file_put_contents(
            $this->streamDir . "index.m3u8",
            $m3u8
        );
    }

    public function close(): void
    {
        $this->closeSegment();
    }

    public function getHlsUrl(): string
    {
        return "/hls/{$this->streamId}/index.m3u8";
    }
}