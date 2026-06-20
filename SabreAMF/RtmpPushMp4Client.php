<?php
require_once __DIR__ . '/RtmpClient.php';

/**
 * MP4边转码边RTMP推流器
 * 直接将MP4解析并通过RTMP协议推流，无需先转换为FLV文件
 * 
 * @version 1.0.0
 */
class RtmpPushMp4Client extends RTMPClient
{
    private $inputFile;
    private $mp4Data;
    private $boxTree;
    private $videoTrack = null;
    private $audioTrack = null;

    private $hasWrittenVideoHeader = false;
    private $hasWrittenAudioHeader = false;

    private $sps = '';
    private $pps = '';
    private $audioSpecificConfig = '';
    private $audioSampleRate = 44100;
    private $audioChannels = 2;
    private $audioObjectType = 2;
    private $isHeAac = false;

    private $duration = 0;
    private $maxVideoDtsMs = 0;
    private $maxAudioDtsMs = 0;
    private $videoWidth = 0;
    private $videoHeight = 0;
    private $videoFrameRate = 30;

    // RTMP推流属性
    private $pushUrl = '';
    private $streamId = 0;
    private $published = false;
    private $sendChunkSize = 4096;
    private $audioChunkStreamId = 4;
    private $videoChunkStreamId = 5;
    private $metaChunkStreamId = 3;
    private $lastVideoTimestamp = -1;
    private $lastAudioTimestamp = -1;

    // 推流控制
    private $speed = 1.0;
    private $autoReconnect = true;
    private $maxRetries = 5;
    private $retryDelay = 3;
    private $isRunning = true;

    // 统计信息
    private $stats = [
        'tags_sent' => 0,
        'bytes_sent' => 0,
        'start_time' => 0,
        'video_tags' => 0,
        'audio_tags' => 0,
        'meta_tags' => 0,
        'reconnect_count' => 0,
    ];

    private $lastProgressTime = 0;
    private $host = '';
    private $port = 1935;
    private $app = '';
    private $streamKey = '';

    public function __construct($inputFile = '', $pushUrl = '', $speed = 1.0, $autoReconnect = true)
    {
        $this->inputFile = $inputFile;
        $this->pushUrl = $pushUrl;
        $this->speed = max(0.1, min(10.0, (float)$speed));
        $this->autoReconnect = $autoReconnect;

        // 解析RTMP URL
        if ($pushUrl && preg_match('#^rtmp://([^:/]+)(?::(\d+))?/([^/]+)/(.+)$#', $pushUrl, $matches)) {
            $this->host = $matches[1];
            $this->port = intval($matches[2] ?: 1935);
            $this->app = $matches[3];
            $this->streamKey = $matches[4];
        }

        if (!file_exists($inputFile)) {
            throw new RuntimeException("MP4文件不存在: {$inputFile}");
        }

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, [$this, 'handleSignal']);
            pcntl_signal(SIGTERM, [$this, 'handleSignal']);
        }
    }

    public function handleSignal($signal)
    {
        $this->log("[信号] 收到退出信号，正在优雅关闭...", 'warning');
        $this->isRunning = false;
        $this->close();
        $this->printFinalStats();
        exit(0);
    }

    /**
     * 启动推流
     */
    public function start(): bool
    {
        $this->log("========================================", 'info');
        $this->log("MP4 Direct Pusher v1.0.0", 'info');
        $this->log("========================================", 'info');
        $this->log("文件: {$this->inputFile}", 'info');
        $this->log("推流地址: {$this->pushUrl}", 'info');
        $this->log("协议: RTMP", 'info');
        $this->log("推流速度: {$this->speed}x", 'info');
        $this->log("自动重连: " . ($this->autoReconnect ? '是' : '否'), 'info');
        $this->log("========================================", 'info');

        $this->mp4Data = file_get_contents($this->inputFile);
        if (empty($this->mp4Data)) {
            $this->log("无法读取MP4文件", 'error');
            return false;
        }

        $fileSize = strlen($this->mp4Data);
        $this->log("文件大小: " . $this->formatBytes($fileSize), 'info');

        $this->log("开始解析MP4...", 'info');
        $this->parseMp4Boxes();
        $this->parseTracks();
        $this->log("MP4解析完成", 'success');
        $this->log("视频: {$this->videoWidth}x{$this->videoHeight}", 'info');
        $this->log("音频: {$this->audioSampleRate}Hz, {$this->audioChannels}通道", 'info');
        $this->log("时长: {$this->duration}秒", 'info');

        $result = $this->doPush();
        $this->printFinalStats();

        return $result;
    }

    private function doPush(): bool
    {
        $this->stats['start_time'] = microtime(true);
        $this->stats['tags_sent'] = 0;
        $this->stats['bytes_sent'] = 0;
        $retryCount = 0;

        while ($this->isRunning && $retryCount <= $this->maxRetries) {
            try {
                $this->log("连接RTMP服务器: {$this->host}:{$this->port}", 'info');
                $this->connect($this->host, $this->app, $this->port);

                $this->fcPublish($this->streamKey);
                $this->publish($this->streamKey, 'live');

                $this->log("RTMP连接成功", 'success');

                $this->hasWrittenVideoHeader = false;
                $this->hasWrittenAudioHeader = false;
                $this->lastVideoTimestamp = -1;
                $this->lastAudioTimestamp = -1;

                $this->pushStream();
                $this->log("推流完成！", 'success');
                $this->close();
                return true;

            } catch (Exception $e) {
                $this->log("推流错误: " . $e->getMessage(), 'error');
                $this->close();

                if ($this->autoReconnect && $retryCount < $this->maxRetries) {
                    $retryCount++;
                    $this->stats['reconnect_count']++;
                    $this->log("等待{$this->retryDelay}秒后进行第{$retryCount}次重连...", 'warning');
                    sleep($this->retryDelay);
                    continue;
                } else {
                    $this->log("达到最大重连次数", 'error');
                    return false;
                }
            }
        }
        return false;
    }

    private function publish($streamKey, $type = 'live')
    {
        $this->createStream();
        $this->sendPublish($streamKey, $type);
        $this->sendSetChunkSize($this->sendChunkSize);
        $this->published = true;
    }

    private function createStream()
    {
        $result = $this->call('createStream');
        if ($result && isset($result[0])) {
            $this->streamId = (int)$result[0];
        }
    }

    private function sendPublish($streamKey, $type = 'live')
    {
        require_once __DIR__ . '/OutputStream.php';
        require_once __DIR__ . '/AMF0/Serializer.php';
        
        $p = new RtmpPacket();
        $p->chunkStreamId = 3;
        $p->streamId = 0;
        $p->chunkType = RtmpPacket::CHUNK_TYPE_0;
        $p->type = RtmpPacket::TYPE_INVOKE_AMF0;

        $stream = new SabreAMF_OutputStream();
        $serializer = new SabreAMF_AMF0_Serializer($stream);
        $serializer->writeAMFData('publish');
        $serializer->writeAMFData(0);
        $serializer->writeAMFData(null);
        $serializer->writeAMFData($streamKey);
        $serializer->writeAMFData($type);

        $p->payload = $stream->getRawData();
        $p->length = strlen($p->payload);
        $this->sendPacket($p);
    }

    private function sendSetChunkSize($size)
    {
        $p = new RtmpPacket();
        $p->chunkStreamId = 2;
        $p->type = RtmpPacket::TYPE_CHUNK_SIZE;
        $p->streamId = 0;
        $p->chunkType = RtmpPacket::CHUNK_TYPE_0;

        require_once __DIR__ . '/RtmpStream.php';
        $stream = new RtmpStream();
        $stream->writeInt32($size);
        $p->payload = $stream->flush();
        $p->length = strlen($p->payload);
        $this->sendPacket($p);
    }

    public function fcPublish($streamKey)
    {
        require_once __DIR__ . '/OutputStream.php';
        require_once __DIR__ . '/AMF0/Serializer.php';
        
        $p = new RtmpPacket();
        $p->chunkStreamId = 3;
        $p->type = RtmpPacket::TYPE_INVOKE_AMF0;
        $p->streamId = 0;
        $p->chunkType = RtmpPacket::CHUNK_TYPE_0;

        $stream = new SabreAMF_OutputStream();
        $serializer = new SabreAMF_AMF0_Serializer($stream);
        $serializer->writeAMFData('FCPublish');
        $serializer->writeAMFData(0);
        $serializer->writeAMFData(null);
        $serializer->writeAMFData($streamKey);

        $p->payload = $stream->getRawData();
        $p->length = strlen($p->payload);
        $this->sendPacket($p);
    }

    private function pushStream()
    {
        $this->writeMetaData();
        $this->extractAndPushMediaData();
    }

    private function writeMetaData()
    {
        $duration = max($this->maxVideoDtsMs, $this->maxAudioDtsMs) / 1000;
        $metaData = [
            'duration' => $duration,
            'width' => (float)($this->videoWidth ?: 720),
            'height' => (float)($this->videoHeight ?: 480),
            'videocodecid' => 'avc1',
            'audiocodecid' => 'mp4a',
            'audiosamplerate' => (float)($this->audioSampleRate ?: 44100),
            'audiochannels' => (float)($this->audioChannels ?: 2),
            'framerate' => (float)($this->videoFrameRate ?: 30.0),
        ];

        $data = $this->serializeAmf0($metaData);
        $cmdData = $this->serializeAmf0('onMetaData') . $data;

        require_once __DIR__ . '/OutputStream.php';
        require_once __DIR__ . '/InputStream.php';
        require_once __DIR__ . '/AMF0/Deserializer.php';
        require_once __DIR__ . '/AMF0/Serializer.php';

        // 解码再重新封装为 @setDataFrame 格式
        $inputStream = new SabreAMF_InputStream($cmdData);
        $deserializer = new SabreAMF_AMF0_Deserializer($inputStream);
        $cmd = $deserializer->readAMFData();
        $dataObj = $deserializer->readAMFData();

        $outputStream = new SabreAMF_OutputStream();
        $serializer = new SabreAMF_AMF0_Serializer($outputStream);
        $serializer->writeAMFData('@setDataFrame');
        $serializer->writeAMFData($cmd);
        $serializer->writeAMFData($dataObj);

        $p = new RtmpPacket();
        $p->chunkStreamId = $this->metaChunkStreamId;
        $p->type = RtmpPacket::TYPE_METADATA;
        $p->streamId = $this->streamId;
        $p->timestamp = 0;
        $p->payload = $outputStream->getRawData();
        $p->length = strlen($p->payload);
        $p->chunkType = RtmpPacket::CHUNK_TYPE_0;

        $this->sendMediaPacket($p);
        $this->stats['meta_tags']++;
        $this->stats['tags_sent']++;
    }

    private function serializeAmf0($value): string
    {
        if (is_string($value)) {
            return "\x02" . pack('n', strlen($value)) . $value;
        } elseif (is_float($value) || is_numeric($value)) {
            return "\x00" . $this->packDoubleBE((float)$value);
        } elseif (is_bool($value)) {
            return $value ? "\x01\x01" : "\x01\x00";
        } elseif (is_array($value)) {
            $result = "\x03";
            foreach ($value as $key => $val) {
                if (!is_string($key)) continue;
                $result .= pack('n', strlen($key)) . $key;
                $result .= $this->serializeAmf0($val);
            }
            $result .= "\x00\x00\x09";
            return $result;
        }
        return '';
    }

    private function packDoubleBE(float $value): string
    {
        return strrev(pack('d', $value));
    }

    private function extractAndPushMediaData()
    {
        $mdat = $this->findBox($this->boxTree, 'mdat');
        if (!$mdat) throw new RuntimeException("未找到mdat盒子");

        $moov = $this->findBox($this->boxTree, 'moov');
        if (!$moov) return;

        $allSamples = [];
        $traks = $this->findAllBoxes([$moov], 'trak');

        foreach ($traks as $trak) {
            $mdia = $this->findBox([$trak], 'mdia');
            if (!$mdia) continue;

            $hdlr = $this->findBox([$mdia], 'hdlr');
            if (!$hdlr) continue;
            $handlerType = substr($hdlr['data'], 8, 4);

            $stbl = $this->findBox([$mdia], 'stbl');
            if (!$stbl) continue;

            $samples = $this->extractSamplesFromStbl($stbl, $handlerType);
            foreach ($samples as &$s) {
                $s['type'] = ($handlerType === 'vide') ? 'video' : 'audio';
            }
            unset($s);
            $allSamples = array_merge($allSamples, $samples);
        }

        usort($allSamples, function($a, $b) {
            return $a['dtsMs'] - $b['dtsMs'];
        });

        // 调整时间戳基准，使第一个样本的 dtsMs 为 0
        $baseTimestamp = $allSamples[0]['dtsMs'] ?? 0;
        foreach ($allSamples as &$sample) {
            $sample['dtsMs'] -= $baseTimestamp;
            $sample['ctsMs'] = ($sample['ctsMs'] ?? 0) - $baseTimestamp;
        }
        unset($sample);

        $startTime = microtime(true);
        $firstTimestamp = -1;
        $tagCount = 0;

        foreach ($allSamples as $sample) {
            if (!$this->isRunning) break;

            $dtsMs = $sample['dtsMs'];

            if ($firstTimestamp < 0) $firstTimestamp = $dtsMs;

            if ($sample['type'] === 'video') {
                $this->writeVideoSample($sample['data'], $dtsMs, $sample['ctsMs'] ?? 0, $sample['keyframe']);
                $this->stats['video_tags']++;
            } else {
                $this->writeAudioSample($sample['data'], $dtsMs);
                $this->stats['audio_tags']++;
            }

            $this->stats['tags_sent']++;

            // 控制推流速度（使用调整后的时间戳）
            if ($this->speed > 0 && $dtsMs > 0) {
                $adjustedTimestamp = $dtsMs - $firstTimestamp;
                $elapsed = (microtime(true) - $startTime) * 1000;
                $targetTime = $adjustedTimestamp / $this->speed;
                if ($targetTime > $elapsed) {
                    $delay = (int)(($targetTime - $elapsed) * 1000);
                    if ($delay > 0 && $delay < 5000) {
                        usleep($delay);
                    }
                }
            }

            // 定期输出进度
            $tagCount++;
            $currentTime = microtime(true);
            if ($tagCount % 100 == 0 && ($currentTime - $this->lastProgressTime) >= 1) {
                $this->printProgress($dtsMs);
                $this->lastProgressTime = $currentTime;
            }
        }

        $this->log("共处理 {$tagCount} 个Tag", 'info');
    }

    private function writeVideoSample(string $data, int $dtsMs, int $ctsMs, bool $isKeyFrame)
    {
        if ($dtsMs > $this->maxVideoDtsMs) $this->maxVideoDtsMs = $dtsMs;
        if (!$this->hasWrittenVideoHeader && !empty($this->sps)) {
            $this->writeAVCSequenceHeader();
        }

        $codecId = 7;
        $frameType = $isKeyFrame ? 1 : 2;
        $videoData = chr(($frameType << 4) | $codecId) . "\x01" .
            chr(($ctsMs >> 16) & 0xFF) . chr(($ctsMs >> 8) & 0xFF) . chr($ctsMs & 0xFF) . $data;

        $this->writeFLVTag(9, $videoData, $dtsMs);
    }

    private function writeAudioSample(string $data, int $dtsMs)
    {
        if ($dtsMs > $this->maxAudioDtsMs) $this->maxAudioDtsMs = $dtsMs;
        if (!$this->hasWrittenAudioHeader && !empty($this->audioSpecificConfig)) {
            $this->writeAACSequenceHeader();
        }

        $soundFormat = 10;
        $soundRate = $this->getSoundRate();
        $soundSize = 1;
        $soundType = ($this->audioChannels == 2) ? 1 : 0;
        $audioHeader = ($soundFormat << 4) | ($soundRate << 2) | ($soundSize << 1) | $soundType;
        $audioData = chr($audioHeader) . "\x01" . $data;

        $this->writeFLVTag(8, $audioData, $dtsMs);
    }

    private function writeAVCSequenceHeader()
    {
        $record = "\x01" .
            ($this->sps[1] ?? "\x42") .
            ($this->sps[2] ?? "\x00") .
            ($this->sps[3] ?? "\x1F") .
            "\xFF" .
            "\xE1" . pack('n', strlen($this->sps)) . $this->sps .
            "\x01" . pack('n', strlen($this->pps)) . $this->pps;

        $videoData = "\x17\x00\x00\x00\x00" . $record;
        $this->writeFLVTag(9, $videoData, 0);
        $this->hasWrittenVideoHeader = true;
    }

    private function writeAACSequenceHeader()
    {
        $soundFormat = 10;
        $soundRate = $this->getSoundRate();
        $soundSize = 1;
        $soundType = ($this->audioChannels == 2) ? 1 : 0;
        $audioHeader = ($soundFormat << 4) | ($soundRate << 2) | ($soundSize << 1) | $soundType;
        $audioData = chr($audioHeader) . "\x00" . $this->audioSpecificConfig;
        $this->writeFLVTag(8, $audioData, 0);
        $this->hasWrittenAudioHeader = true;
    }

    private function getSoundRate(): int
    {
        switch ($this->audioSampleRate) {
            case 5512:  return 0;
            case 11025: return 1;
            case 22050: return 2;
            case 44100: return 3;
            case 48000: return 4;
            default:    return 3;
        }
    }

    private function writeFLVTag(int $tagType, string $data, int $timestamp)
    {
        $dataSize = strlen($data);
        $timestamp &= 0xFFFFFFFF;

        $p = new RtmpPacket();
        $p->type = $tagType == 9 ? RtmpPacket::TYPE_VIDEO : RtmpPacket::TYPE_AUDIO;
        $p->streamId = $this->streamId;
        $p->payload = $data;
        $p->length = $dataSize;

        if ($tagType == 9) {
            $p->chunkStreamId = $this->videoChunkStreamId;
            $delta = $timestamp - $this->lastVideoTimestamp;
            if ($this->lastVideoTimestamp >= 0 && $delta >= 0) {
                $p->chunkType = RtmpPacket::CHUNK_TYPE_1;
                $p->timestamp = $delta;
            } else {
                $p->chunkType = RtmpPacket::CHUNK_TYPE_0;
                $p->timestamp = $timestamp;
            }
            $this->lastVideoTimestamp = $timestamp;
        } else {
            $p->chunkStreamId = $this->audioChunkStreamId;
            $delta = $timestamp - $this->lastAudioTimestamp;
            if ($this->lastAudioTimestamp >= 0 && $delta >= 0) {
                $p->chunkType = RtmpPacket::CHUNK_TYPE_1;
                $p->timestamp = $delta;
            } else {
                $p->chunkType = RtmpPacket::CHUNK_TYPE_0;
                $p->timestamp = $timestamp;
            }
            $this->lastAudioTimestamp = $timestamp;
        }

        $this->sendMediaPacket($p);
        $this->stats['bytes_sent'] += $dataSize + 11 + 4;
    }

    private function sendMediaPacket(RtmpPacket $packet)
    {
        require_once __DIR__ . '/RtmpStream.php';

        if (!$packet->length) {
            $packet->length = strlen($packet->payload);
        }

        $header = new RtmpStream();
        $header->writeByte($packet->chunkType << 6 | $packet->chunkStreamId);

        if ($packet->chunkType <= RtmpPacket::CHUNK_TYPE_1) {
            if ($packet->timestamp >= 0xFFFFFF) {
                $header->writeInt24(0xFFFFFF);
            } else {
                $header->writeInt24($packet->timestamp);
            }
        }

        if ($packet->chunkType <= RtmpPacket::CHUNK_TYPE_1) {
            $header->writeInt24($packet->length);
            $header->writeByte($packet->type);
        }

        if ($packet->chunkType == RtmpPacket::CHUNK_TYPE_0) {
            $header->writeInt32LE($packet->streamId);
        }

        $this->socketWrite($header);

        if ($packet->timestamp >= 0xFFFFFF && $packet->chunkType <= RtmpPacket::CHUNK_TYPE_1) {
            $extTimestamp = new RtmpStream();
            $extTimestamp->writeInt32($packet->timestamp);
            $this->socketWrite($extTimestamp);
        }

        $offset = 0;
        $firstChunk = true;

        while ($offset < $packet->length) {
            if (!$firstChunk) {
                $this->socketWrite(new RtmpStream(chr(0xC0 | $packet->chunkStreamId)));
            }
            $firstChunk = false;

            $chunkSize = min($this->sendChunkSize, $packet->length - $offset);
            $chunkData = new RtmpStream(substr($packet->payload, $offset, $chunkSize));
            $this->socketWrite($chunkData, $chunkSize);
            $offset += $chunkSize;
        }
    }

    // ==================== MP4解析 ====================

    private function parseMp4Boxes()
    {
        $this->boxTree = $this->parseBox($this->mp4Data, 0, strlen($this->mp4Data));
    }

    private function parseBox(string $data, int $offset, int $end): array
    {
        $boxes = [];
        while ($offset + 8 <= $end) {
            $size = unpack('N', substr($data, $offset, 4))[1];
            $type = substr($data, $offset + 4, 4);
            if ($size == 1) {
                if ($offset + 16 <= $end) {
                    $size = unpack('J', substr($data, $offset + 8, 8))[1];
                    $headerSize = 16;
                } else break;
            } elseif ($size == 0) {
                $size = $end - $offset;
                $headerSize = 8;
            } else {
                $headerSize = 8;
            }
            $boxEnd = $offset + $size;
            if ($boxEnd > $end) break;
            $boxData = substr($data, $offset + $headerSize, $size - $headerSize);
            $box = ['type' => $type, 'size' => $size, 'offset' => $offset, 'data' => $boxData, 'children' => []];
            if ($size > $headerSize) {
                $box['children'] = $this->parseBox($data, $offset + $headerSize, $boxEnd);
            }
            $boxes[] = $box;
            $offset = $boxEnd;
        }
        return $boxes;
    }

    private function findBox(array $boxes, string $type): ?array
    {
        foreach ($boxes as $box) {
            if ($box['type'] === $type) return $box;
            if (!empty($box['children'])) {
                $found = $this->findBox($box['children'], $type);
                if ($found !== null) return $found;
            }
        }
        return null;
    }

    private function findAllBoxes(array $boxes, string $type): array
    {
        $result = [];
        foreach ($boxes as $box) {
            if ($box['type'] === $type) $result[] = $box;
            if (!empty($box['children'])) {
                $result = array_merge($result, $this->findAllBoxes($box['children'], $type));
            }
        }
        return $result;
    }

    private function parseTracks()
    {
        $moov = $this->findBox($this->boxTree, 'moov');
        if (!$moov) throw new RuntimeException("未找到moov盒子");

        $mvhd = $this->findBox([$moov], 'mvhd');
        if ($mvhd) {
            $mvhdData = $mvhd['data'];
            $version = ord($mvhdData[0]);
            if ($version == 0) {
                $timescale = unpack('N', substr($mvhdData, 12, 4))[1];
                $duration = unpack('N', substr($mvhdData, 16, 4))[1];
            } else {
                $timescale = unpack('N', substr($mvhdData, 20, 4))[1];
                $duration = unpack('J', substr($mvhdData, 24, 8))[1];
            }
            $this->duration = round($duration * 1000 / $timescale) / 1000;
        }

        $traks = $this->findAllBoxes([$moov], 'trak');
        foreach ($traks as $trak) {
            $this->parseTrack($trak);
        }
    }

    private function parseTrack(array $trak)
    {
        $tkhd = $this->findBox([$trak], 'tkhd');
        if (!$tkhd) return;

        $tkhdData = $tkhd['data'];
        $trackId = unpack('N', substr($tkhdData, 12, 4))[1];

        $version = ord($tkhdData[0]);
        $widthOffset = ($version == 0) ? 76 : 88;
        $heightOffset = ($version == 0) ? 80 : 92;

        if (strlen($tkhdData) >= $heightOffset + 4) {
            $tkhdWidth = unpack('N', substr($tkhdData, $widthOffset, 4))[1] / 65536;
            $tkhdHeight = unpack('N', substr($tkhdData, $heightOffset, 4))[1] / 65536;
            if ($tkhdWidth > 0 && $tkhdHeight > 0) {
                $this->videoWidth = (int)round($tkhdWidth);
                $this->videoHeight = (int)round($tkhdHeight);
            }
        }

        $mdia = $this->findBox([$trak], 'mdia');
        if (!$mdia) return;

        $hdlr = $this->findBox([$mdia], 'hdlr');
        if (!$hdlr) return;
        $handlerType = substr($hdlr['data'], 8, 4);

        $minf = $this->findBox([$mdia], 'minf');
        if (!$minf) return;

        $stbl = $this->findBox([$minf], 'stbl');
        if (!$stbl) return;

        $stsd = $this->findBox([$stbl], 'stsd');
        if (!$stsd) return;

        $mdhd = $this->findBox([$mdia], 'mdhd');
        $timescale = 90000;
        if ($mdhd) {
            $timescale = unpack('N', substr($mdhd['data'], 12, 4))[1];
        }

        $stsdData = $stsd['data'];
        $pos = 8;
        while ($pos + 8 <= strlen($stsdData)) {
            $entrySize = unpack('N', substr($stsdData, $pos, 4))[1];
            $entryType = substr($stsdData, $pos + 4, 4);

            if ($handlerType === 'vide' && $entryType === 'avc1') {
                $this->videoTrack = ['id' => $trackId, 'type' => 'video', 'codec' => 'avc1', 'timescale' => $timescale];
                $this->parseAvcCFromBox(substr($stsdData, $pos, $entrySize));
                break;
            } elseif ($handlerType === 'soun' && $entryType === 'mp4a') {
                $this->audioTrack = ['id' => $trackId, 'type' => 'audio', 'codec' => 'mp4a', 'timescale' => $timescale];
                $this->parseEsdsFromBox(substr($stsdData, $pos, $entrySize));
                break;
            }
            $pos += $entrySize;
        }
    }

    private function parseAvcCFromBox(string $data)
    {
        $pos = strpos($data, 'avcC');
        if ($pos === false || $pos < 4) return;
        $boxSize = unpack('N', substr($data, $pos - 4, 4))[1];
        $avcCData = substr($data, $pos + 4, $boxSize - 8);
        $this->parseAvcC($avcCData);
    }

    private function parseAvcC(string $data)
    {
        if (strlen($data) < 8) return;
        $numSps = ord($data[5]) & 0x1F;
        $offset = 6;
        for ($i = 0; $i < $numSps; $i++) {
            if ($offset + 2 > strlen($data)) break;
            $spsLength = unpack('n', substr($data, $offset, 2))[1];
            $offset += 2;
            if ($offset + $spsLength > strlen($data)) break;
            $this->sps = substr($data, $offset, $spsLength);
            $offset += $spsLength;
            $this->parseSpsForDimensions($this->sps);
            break;
        }
        $numPps = ord($data[$offset]); $offset++;
        for ($i = 0; $i < $numPps; $i++) {
            if ($offset + 2 > strlen($data)) break;
            $ppsLength = unpack('n', substr($data, $offset, 2))[1];
            $offset += 2;
            if ($offset + $ppsLength > strlen($data)) break;
            $this->pps = substr($data, $offset, $ppsLength);
            break;
        }
    }

    private function parseSpsForDimensions(string $sps)
    {
        if (strlen($sps) < 10) return;
        $pos = 0;
        if (ord($sps[0]) & 0x80) $pos++;
        $pos += 3;
        $pos++;
        $pos = $this->skipUEG($sps, $pos);
        $picOrderCntType = $this->readUEG($sps, $pos);
        $pos = $this->skipUEG($sps, $pos);
        if ($picOrderCntType == 0) {
            $pos = $this->skipUEG($sps, $pos);
            $pos = $this->skipUEG($sps, $pos);
        } elseif ($picOrderCntType == 1) {
            $pos = $this->skipUEG($sps, $pos);
            $pos = $this->skipUEG($sps, $pos);
            $pos = $this->skipUEG($sps, $pos);
            $pos = $this->skipUEG($sps, $pos);
            $numRefFramesInPicOrderCntCycle = $this->readUEG($sps, $pos);
            $pos = $this->skipUEG($sps, $pos);
            for ($i = 0; $i < $numRefFramesInPicOrderCntCycle; $i++) {
                $pos = $this->skipSEG($sps, $pos);
            }
        }
        $pos = $this->skipUEG($sps, $pos);
        $pos++;
    }

    private function readUEG(string $data, int &$pos): int
    {
        $leadingZeroBits = 0;
        while ($pos < strlen($data) && (ord($data[$pos]) & 0x80) == 0) { $leadingZeroBits++; $pos++; }
        if ($pos >= strlen($data)) return 0;
        $result = ord($data[$pos]) & 0x7F; $pos++;
        for ($i = 0; $i < $leadingZeroBits; $i++) {
            if ($pos >= strlen($data)) break;
            $result = ($result << 7) | (ord($data[$pos]) & 0x7F); $pos++;
        }
        return $result - 1;
    }

    private function skipUEG(string $data, int $pos): int
    {
        while ($pos < strlen($data) && (ord($data[$pos]) & 0x80) == 0) $pos++;
        if ($pos >= strlen($data)) return $pos;
        $pos++;
        return $pos;
    }

    private function skipSEG(string $data, int $pos): int
    {
        return $this->skipUEG($data, $pos);
    }

    private function parseEsdsFromBox(string $data)
    {
        $pos = strpos($data, 'esds');
        if ($pos === false || $pos < 4) return;
        $boxSize = unpack('N', substr($data, $pos - 4, 4))[1];
        $esdsData = substr($data, $pos + 4, $boxSize - 8);
        $this->parseEsds($esdsData);
    }

    private function parseEsds(string $data, bool $hasFullBoxHeader = true)
    {
        $len = strlen($data);
        if ($len < 2) return;

        $pos = $hasFullBoxHeader ? 4 : 0;

        while ($pos + 2 <= $len) {
            $tag = ord($data[$pos]);
            $pos++;

            if ($pos >= $len) break;

            $length = 0;
            while ($pos < $len) {
                $byte = ord($data[$pos]);
                $pos++;
                $length = ($length << 7) | ($byte & 0x7F);
                if (($byte & 0x80) == 0) break;
            }

            if ($pos + $length > $len) break;

            if ($tag == 0x05) {
                $this->audioSpecificConfig = substr($data, $pos, $length);
                $this->parseAudioSpecificConfig($this->audioSpecificConfig);
                return;
            }

            if ($tag == 0x03) {
                $skipBytes = 3;
                if ($length > $skipBytes) {
                    $this->parseEsds(substr($data, $pos + $skipBytes, $length - $skipBytes), false);
                }
            } elseif ($tag == 0x04) {
                $skipBytes = 13;
                if ($length > $skipBytes) {
                    $this->parseEsds(substr($data, $pos + $skipBytes, $length - $skipBytes), false);
                }
            }

            $pos += $length;
        }
    }

    private function parseAudioSpecificConfig(string $config)
    {
        $len = strlen($config);
        if ($len < 2) return;

        $bytes = unpack('n', substr($config, 0, 2))[1];
        $this->audioObjectType = ($bytes >> 11) & 0x1F;
        $freqIndex = ($bytes >> 7) & 0x0F;
        $channelConfig = ($bytes >> 3) & 0x0F;

        $rates = [96000, 88200, 64000, 48000, 44100, 32000, 24000, 22050, 16000, 12000, 11025, 8000, 7350];
        $baseRate = $rates[$freqIndex] ?? 44100;

        switch ($this->audioObjectType) {
            case 2:
                $this->audioSampleRate = $baseRate;
                $this->audioChannels = $channelConfig;
                break;
            case 5:
                $this->audioSampleRate = $baseRate * 2;
                $this->audioChannels = $channelConfig;
                break;
            case 29:
                $this->audioSampleRate = $baseRate * 2;
                $this->audioChannels = 2;
                break;
            default:
                $this->audioSampleRate = $baseRate;
                $this->audioChannels = $channelConfig;
                break;
        }
    }

    private function extractSamplesFromStbl(array $stbl, string $handlerType): array
    {
        $stsz = $this->findBox([$stbl], 'stsz');
        $stco = $this->findBox([$stbl], 'stco');
        $stsc = $this->findBox([$stbl], 'stsc');
        $stts = $this->findBox([$stbl], 'stts');

        if (!$stsz || !$stco || !$stsc || !$stts) return [];

        $timescale = ($handlerType === 'vide')
            ? ($this->videoTrack['timescale'] ?? 90000)
            : ($this->audioTrack['timescale'] ?? 90000);

        $stszData = $stsz['data'];
        $sampleSize = unpack('N', substr($stszData, 4, 4))[1];
        $sampleCount = unpack('N', substr($stszData, 8, 4))[1];
        $sampleSizes = [];
        if ($sampleSize == 0) {
            for ($i = 0; $i < $sampleCount; $i++) {
                $sampleSizes[] = unpack('N', substr($stszData, 12 + $i * 4, 4))[1];
            }
        } else {
            $sampleSizes = array_fill(0, $sampleCount, $sampleSize);
        }

        $stscData = $stsc['data'];
        $stscEntries = unpack('N', substr($stscData, 4, 4))[1];
        $chunkMap = [];
        for ($i = 0; $i < $stscEntries; $i++) {
            $firstChunk = unpack('N', substr($stscData, 8 + $i * 12, 4))[1];
            $samplesPerChunk = unpack('N', substr($stscData, 12 + $i * 12, 4))[1];
            $chunkMap[$firstChunk] = $samplesPerChunk;
        }

        $stcoData = $stco['data'];
        $chunkCount = unpack('N', substr($stcoData, 4, 4))[1];
        $chunkOffsets = [];
        for ($i = 0; $i < $chunkCount; $i++) {
            $chunkOffsets[] = unpack('N', substr($stcoData, 8 + $i * 4, 4))[1];
        }

        $chunkSamples = [];
        for ($chunk = 0; $chunk < $chunkCount; $chunk++) {
            $chunkNum = $chunk + 1;
            $samples = 1;
            foreach ($chunkMap as $firstChunk => $spc) {
                if ($chunkNum >= $firstChunk) $samples = $spc;
            }
            $chunkSamples[] = $samples;
        }

        $sampleOffsets = [];
        $sampleIndex = 0;
        foreach ($chunkOffsets as $chunkIdx => $chunkOffset) {
            $count = $chunkSamples[$chunkIdx];
            $runningOffset = $chunkOffset;
            for ($j = 0; $j < $count && $sampleIndex < count($sampleSizes); $j++) {
                $sampleOffsets[$sampleIndex] = $runningOffset;
                $runningOffset += $sampleSizes[$sampleIndex];
                $sampleIndex++;
            }
        }

        $sttsData = $stts['data'];
        $sttsEntries = unpack('N', substr($sttsData, 4, 4))[1];
        $timeDeltas = [];
        $pos = 8;
        for ($i = 0; $i < $sttsEntries; $i++) {
            $count = unpack('N', substr($sttsData, $pos, 4))[1];
            $delta = unpack('N', substr($sttsData, $pos + 4, 4))[1];
            $pos += 8;
            for ($j = 0; $j < $count; $j++) $timeDeltas[] = $delta;
        }

        $ctOffsets = [];
        $ctts = $this->findBox([$stbl], 'ctts');
        if ($ctts && $handlerType === 'vide') {
            $cttsData = $ctts['data'];
            $cttsEntries = unpack('N', substr($cttsData, 4, 4))[1];
            $pos = 8;
            for ($i = 0; $i < $cttsEntries; $i++) {
                $count = unpack('N', substr($cttsData, $pos, 4))[1];
                $offset = unpack('N', substr($cttsData, $pos + 4, 4))[1];
                $pos += 8;
                for ($j = 0; $j < $count; $j++) $ctOffsets[] = $offset;
            }
        }

        $keyframeSet = [];
        $stss = $this->findBox([$stbl], 'stss');
        if ($stss && $handlerType === 'vide') {
            $stssData = $stss['data'];
            $entries = unpack('N', substr($stssData, 4, 4))[1];
            for ($i = 0; $i < $entries; $i++) {
                $keyframeSet[unpack('N', substr($stssData, 8 + $i * 4, 4))[1] - 1] = true;
            }
        }

        $samples = [];
        $dtsTicks = 0;
        for ($i = 0; $i < count($sampleSizes) && $i < count($sampleOffsets); $i++) {
            $offset = $sampleOffsets[$i];
            if ($offset < 0 || $offset + $sampleSizes[$i] > strlen($this->mp4Data)) continue;
            $rawData = substr($this->mp4Data, $offset, $sampleSizes[$i]);

            // CTS = DTS + composition_time_offset
            $compositionOffset = $ctOffsets[$i] ?? 0;
            $dtsMs = (int)round($dtsTicks * 1000 / $timescale);
            $ctsMs = (int)round(($dtsTicks + $compositionOffset) * 1000 / $timescale);
            $isKeyframe = isset($keyframeSet[$i]);

            $samples[] = ['data' => $rawData, 'dtsMs' => $dtsMs, 'ctsMs' => $ctsMs, 'keyframe' => $isKeyframe];
            $dtsTicks += $timeDeltas[$i] ?? 0;
        }
        return $samples;
    }

    // ==================== 辅助方法 ====================

    private function log(string $message, string $level = 'info')
    {
        $timestamp = date('Y-m-d H:i:s');
        $levelUpper = strtoupper($level);
        $prefix = '[' . $timestamp . '] [' . $levelUpper . '] ';
        echo $prefix . $message . "\n";
    }

    private function printProgress($currentTimestamp)
    {
        $elapsed = microtime(true) - $this->stats['start_time'];
        $speed = $elapsed > 0 ? $this->stats['tags_sent'] / $elapsed : 0;
        $bitrate = $elapsed > 0 ? ($this->stats['bytes_sent'] * 8 / $elapsed) / 1000 : 0;

        $tsSeconds = floor($currentTimestamp / 1000);
        $tsFormatted = sprintf("%02d:%02d", floor($tsSeconds / 60), $tsSeconds % 60);

        $this->log(sprintf(
            "[进度] 已发送: %d tags | 时间戳: %s | 速率: %.1f tags/s | 码率: %.1f kbps",
            $this->stats['tags_sent'],
            $tsFormatted,
            $speed,
            $bitrate
        ), 'debug');
    }

    private function printFinalStats()
    {
        $elapsed = microtime(true) - $this->stats['start_time'];
        $elapsedFormatted = sprintf("%02d:%02d", floor($elapsed / 60), (int)$elapsed % 60);
        $avgSpeed = $elapsed > 0 ? $this->stats['tags_sent'] / $elapsed : 0;
        $totalBitrate = $elapsed > 0 ? ($this->stats['bytes_sent'] * 8 / $elapsed) / 1000 : 0;

        $this->log("========================================", 'info');
        $this->log("推流统计", 'info');
        $this->log("========================================", 'info');
        $this->log("总耗时: {$elapsedFormatted}", 'info');
        $this->log("发送 Tag 数: {$this->stats['tags_sent']}", 'info');
        $this->log("视频 Tag 数: {$this->stats['video_tags']}", 'info');
        $this->log("音频 Tag 数: {$this->stats['audio_tags']}", 'info');
        $this->log("元数据 Tag 数: {$this->stats['meta_tags']}", 'info');
        $this->log("发送字节数: " . $this->formatBytes($this->stats['bytes_sent']), 'info');
        $this->log("平均速率: " . sprintf("%.1f", $avgSpeed) . " tags/s", 'info');
        $this->log("平均码率: " . sprintf("%.1f", $totalBitrate) . " kbps", 'info');
        $this->log("重连次数: {$this->stats['reconnect_count']}", 'info');
        $this->log("========================================", 'info');
    }

    private function formatBytes($bytes)
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return sprintf("%.2f GB", $bytes / (1024 * 1024 * 1024));
        } elseif ($bytes >= 1024 * 1024) {
            return sprintf("%.2f MB", $bytes / (1024 * 1024));
        } elseif ($bytes >= 1024) {
            return sprintf("%.2f KB", $bytes / 1024);
        } else {
            return $bytes . ' B';
        }
    }

    public function close()
    {
        $this->published = false;
        parent::close();
    }
}