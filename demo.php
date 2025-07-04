<?php
class MpegTSPacketizer {
    private $fp;
    private $cc = [];
    private $videoPid = 256; // Standard video PID
    private $pmtPid = 32;    // Standard PMT PID
    private $pcrPid = 256;   // PCR same as video PID

    public function __construct($filename) {
        $this->fp = fopen($filename, 'wb');
        $this->cc = array_fill(0, 8192, 0);
    }

    public function generate() {
        // Write PAT/PMT every 40ms (typical for TS)
        $frameCount = 100;
        for ($i = 0; $i < $frameCount; $i++) {
            if ($i % 10 == 0) { // Every 10 frames
                $this->writePAT();
                $this->writePMT();
            }
            $this->writeVideoFrame($i * 40); // 40ms per frame (~25fps)
        }
        fclose($this->fp);
    }

    private function writePAT() {
        $data = "\x00"; // PAT
        $data .= "\xB0\x0D"; // Length
        $data .= "\x00\x01"; // TS ID
        $data .= "\xC1\x00\x00"; // Version/control
        $data .= "\x00\x00\xE0\x10"; // Network PID (unused)
        $data .= "\x00\x01\xE0" . chr($this->pmtPid); // Program -> PMT PID
        $data .= $this->crc32($data);
        $this->writeTSPacket(0, $data, true);
    }

    private function writePMT() {
        $data = "\x02"; // PMT
        $data .= "\xB0\x17"; // Length
        $data .= "\x00\x01"; // Program number
        $data .= "\xC1\x00\x00"; // Version/control
        $data .= "\xE0" . chr($this->pcrPid); // PCR PID
        $data .= "\xF0\x00"; // Program info length

        // H.264 video stream
        $data .= "\x1B"; // Stream type (H.264)
        $data .= "\xE0" . chr($this->videoPid); // Elementary PID
        $data .= "\xF0\x00"; // ES info length

        $data .= $this->crc32($data);
        $this->writeTSPacket($this->pmtPid, $data, true);
    }

    private function writeVideoFrame($timestamp) {
        // Generate proper H.264 access unit with SPS/PPS/IDR
        $sps = "\x00\x00\x00\x01\x67\x42\x00\x1E\x95\xA8\x08\x00\x00\x03\x00\x01\x00\x00\x03\x00\x3C\x8F\x14\x29\x96";
        $pps = "\x00\x00\x00\x01\x68\xCE\x38\x80";
        $idr = "\x00\x00\x00\x01\x65\x88\x84\x00\x1E\x95\xF1\x60\xD9\x4A\x4C\x6A\x00\xE0";
        $idr .= str_repeat("\x0F\x15\x0E", 200); // Red frame data

        // Write as single access unit with proper timing
        $accessUnit = $sps . $pps . $idr;
        $this->writePES($this->videoPid, $accessUnit, $timestamp, $timestamp);

        // Add PCR (Program Clock Reference) periodically
        if ($timestamp % 200 == 0) { // Every 200ms
            $this->writePCR($timestamp);
        }
    }

    private function writePCR($timestamp) {
        $pcr = $timestamp * 90; // Convert to 90kHz
        $pcrBase = $pcr;
        $pcrExt = 0;

        $adaptation = "\x07"; // Adaptation field length
        $adaptation .= "\x10"; // PCR flag
        $adaptation .= pack('N', ($pcrBase >> 1));
        $adaptation .= chr((($pcrBase & 1) << 7) | (($pcrExt & 0x1FF) >> 8));
        $adaptation .= chr($pcrExt & 0xFF);

        $tsHeader = "\x47";
        $tsHeader .= chr(0x40 | (($this->videoPid >> 8) & 0x1F));
        $tsHeader .= chr($this->videoPid & 0xFF);
        $tsHeader .= chr(0x20 | ($this->cc[$this->videoPid]++ % 16));

        $tsPacket = $tsHeader . $adaptation . str_repeat("\xFF", 188 - strlen($tsHeader) - strlen($adaptation));
        fwrite($this->fp, $tsPacket);
    }

    private function writePES($pid, $data, $pts, $dts) {
        $pesHeader = "\x00\x00\x01\xE0"; // PES start code + stream ID

        // PES packet length (0 for unbounded)
        $pesHeader .= "\x00\x00"; // Will be filled later

        // PES header flags
        $pesHeader .= "\x84"; // PES scrambling control, priority, alignment
        $flags = 0x80; // PTS flag
        if ($pts != $dts) {
            $flags |= 0x40; // DTS flag
        }
        $pesHeader .= chr($flags);

        // PES header length
        $headerLength = 5; // PTS only
        if ($pts != $dts) {
            $headerLength += 5; // Plus DTS
        }
        $pesHeader .= chr($headerLength);

        // Add timing info
        $pesHeader .= $this->createPTS($pts);
        if ($pts != $dts) {
            $pesHeader .= $this->createDTS($dts);
        }

        // Update PES packet length (data + header - start code)
        $pesLength = strlen($data) + strlen($pesHeader) - 4;
        if ($pesLength > 65535) $pesLength = 0;
        $pesHeader[4] = chr(($pesLength >> 8) & 0xFF);
        $pesHeader[5] = chr($pesLength & 0xFF);

        $this->writeTSPacket($pid, $pesHeader . $data, true);
    }

    private function createPTS($pts) {
        $pts *= 90; // Convert to 90kHz
        return chr(0x21 | (($pts >> 29) & 0x0E)) .
            chr(($pts >> 22) & 0xFF) .
            chr(0x01 | (($pts >> 14) & 0xFE)) .
            chr(($pts >> 7) & 0xFF) .
            chr(0x01 | (($pts << 1) & 0xFE));
    }

    private function createDTS($dts) {
        return $this->createPTS($dts); // Same format as PTS
    }

    private function writeTSPacket($pid, $payload, $isStart) {
        $cc = $this->cc[$pid]++ % 16;

        $tsHeader = "\x47"; // Sync byte
        $tsHeader .= chr(($pid >> 8) & 0x1F) | ($isStart ? 0x40 : 0x00);
        $tsHeader .= chr($pid & 0xFF);

        $adaptation = '';
        $payloadOffset = 0;
        $payloadSpace = 184;

        if ($isStart && strlen($payload) < $payloadSpace) {
            $adaptationLength = $payloadSpace - strlen($payload) - 1;
            $adaptation = chr($adaptationLength) . "\x00"; // Flags (none set)
            $tsHeader .= chr(0x30 | $cc); // Adaptation + payload
            $payloadSpace -= ($adaptationLength + 1);
        } else {
            $tsHeader .= chr(0x10 | $cc); // Payload only
        }

        $tsPacket = $tsHeader . $adaptation;
        $payloadChunk = substr($payload, $payloadOffset, $payloadSpace);
        $tsPacket .= $payloadChunk;
        $payloadOffset += strlen($payloadChunk);

        // Pad with 0xFF if needed
        $tsPacket .= str_repeat("\xFF", 188 - strlen($tsPacket));
        fwrite($this->fp, $tsPacket);

        // Handle remaining payload
        $remaining = substr($payload, $payloadOffset);
        if ($remaining !== '') {
            $this->writeTSPacket($pid, $remaining, false);
        }
    }

    private function crc32($data) {
        $crc = 0xFFFFFFFF;
        for ($i = 0; $i < strlen($data); $i++) {
            $crc ^= (ord($data[$i]) << 24);
            for ($j = 0; $j < 8; $j++) {
                $crc = ($crc & 0x80000000) ?
                    (($crc << 1) ^ 0x04C11DB7) : ($crc << 1);
                $crc &= 0xFFFFFFFF;
            }
        }
        return pack('N', $crc);
    }
}

$generator = new MpegTSPacketizer('correct_red_frame.ts');
$generator->generate();
echo "TS file generated successfully\n";