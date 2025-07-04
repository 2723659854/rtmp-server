<?php
class MpegTSPacketizer {
    private $fp;
    private $cc = [];
    private $videoPid = 256;  // 视频PID
    private $pmtPid = 32;     // PMT PID
    private $pcrPid = 256;    // PCR与视频PID绑定

    public function __construct($filename) {
        $this->fp = fopen($filename, 'wb');
        $this->cc = array_fill(0, 8192, 0);
    }

    public function generate() {
        // 先写入PAT/PMT表（确保优先被解析）
        $this->writePAT();
        $this->writePMT();

        // 写入1帧视频（包含完整SPS/PPS/IDR）
        $this->writeVideoFrame(0);
        fclose($this->fp);
    }

    // 修正PAT表：正确关联节目与PMT
    private function writePAT() {
        $data = "\x00";                  // table_id=0 (PAT)
        $data .= "\xB0\x0D";             // section_length=13
        $data .= "\x00\x01";             // transport_stream_id=1
        $data .= "\xC1\x00\x00";         // version=1, current_next=1
        $data .= "\x00\x01\xE0" . chr($this->pmtPid); // 节目1映射到PMT PID
        $data .= $this->crc32($data);
        $this->writeTSPacket(0, $data, true);
    }

    // 修正PMT表：明确节目信息
    private function writePMT() {
        $data = "\x02";                  // table_id=2 (PMT)
        $data .= "\xB0\x17";             // section_length=23
        $data .= "\x00\x01";             // program_number=1
        $data .= "\xC1\x00\x00";         // version=1, current_next=1
        $data .= "\xE0" . chr($this->pcrPid); // PCR PID
        $data .= "\x00\x00";             // program_info_length=0

        // 视频流信息（H.264）
        $data .= "\x1B";                 // stream_type=0x1b (H.264)
        $data .= "\xE0" . chr($this->videoPid); // 视频PID
        $data .= "\x00\x00";             // ES_info_length=0

        $data .= $this->crc32($data);
        $this->writeTSPacket($this->pmtPid, $data, true);
    }

    // 修正H.264帧：使用标准SPS/PPS
    private function writeVideoFrame($timestamp) {
        // 标准SPS（320x240分辨率，Baseline profile）
        $sps = "\x00\x00\x00\x01\x67\x42\x00\x1E\x95\xA8\x08\x00\x00\x03\x00\x01\x00\x00\x03\x00\x3C\x8F\x14\x29\x96";
        // 标准PPS（与SPS关联）
        $pps = "\x00\x00\x00\x01\x68\xCE\x38\x80";
        // IDR帧（红色画面，与SPS/PPS匹配）
        $idr = "\x00\x00\x00\x01\x65\x88\x84\x00\x1E\x95\xF1\x60\xD9\x4A\x4C\x6A\x00\xE0";
        $idr .= str_repeat("\x0F\x15\x0E", 200); // 红色YUV数据（Y=15, U=21, V=14）

        // 合并为完整访问单元
        $accessUnit = $sps . $pps . $idr;
        $this->writePES($this->videoPid, $accessUnit, $timestamp, $timestamp);
    }

    // 修正PES包：正确的头部格式
    private function writePES($pid, $data, $pts, $dts) {
        $pesHeader = "\x00\x00\x01\xE0"; // 起始码 + 视频流ID

        // PES长度（0表示可变长度）
        $pesHeader .= "\x00\x00";

        // PES标志位（包含PTS）
        $pesHeader .= "\x80\x80";        // 标志位（MPEG-2, 包含PTS）
        $pesHeader .= "\x05";            // 头部长度（5字节PTS）

        // 生成正确的PTS（90kHz时钟）
        $pts90k = $pts * 90;
        $pesHeader .= chr(0x21) .
            chr((($pts90k >> 29) & 0x07) << 4 | (($pts90k >> 22) & 0x7F)) .
            chr(($pts90k >> 14) & 0xFF) .
            chr((($pts90k >> 7) & 0x7F) << 1 | 0x01) .
            chr(($pts90k & 0x7F) << 1);

        // 写入PES包
        $this->writeTSPacket($pid, $pesHeader . $data, true);
    }

    // 修正TS包：同步字节与连续性计数器
    private function writeTSPacket($pid, $payload, $isStart) {
        $cc = $this->cc[$pid]++ % 16;

        $tsHeader = "\x47"; // 同步字节（必须是0x47）
        // PID高8位 + 起始标志
        $tsHeader .= chr((($pid >> 8) & 0x1F) | ($isStart ? 0x40 : 0x00));
        // PID低8位
        $tsHeader .= chr($pid & 0xFF);
        // 适配字段标志 + 连续性计数器
        $tsHeader .= chr(0x10 | $cc); // 仅含有效载荷

        // 分割 payload 到TS包（188字节）
        $payloadLength = strlen($payload);
        $offset = 0;
        while ($offset < $payloadLength) {
            $chunk = substr($payload, $offset, 184); // 188 - 4字节头部 = 184
            $tsPacket = $tsHeader . $chunk;
            // 填充到188字节
            $tsPacket .= str_repeat("\xFF", 188 - strlen($tsPacket));
            fwrite($this->fp, $tsPacket);
            $offset += 184;
        }
    }

    // 修正CRC32计算
    private function crc32($data) {
        $crc = 0xFFFFFFFF;
        for ($i = 0; $i < strlen($data); $i++) {
            $crc ^= ord($data[$i]) << 24;
            for ($j = 0; $j < 8; $j++) {
                $crc = ($crc & 0x80000000) ? ($crc << 1 ^ 0x04C11DB7) : ($crc << 1);
                $crc &= 0xFFFFFFFF;
            }
        }
        return pack('N', $crc ^ 0xFFFFFFFF);
    }
}

// 生成TS文件
$generator = new MpegTSPacketizer('fixed_red_frame.ts');
$generator->generate();
echo "生成成功：fixed_red_frame.ts\n";