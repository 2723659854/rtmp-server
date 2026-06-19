<?php
require_once __DIR__ . '/RtmpClient.php';
require_once __DIR__ . '/InputStream.php';
require_once __DIR__ . '/OutputStream.php';
require_once __DIR__ . '/AMF0/Deserializer.php';
require_once __DIR__ . '/AMF0/Serializer.php';
/**
 * RTMP推流客户端
 * 用于读取FLV文件并通过RTMP协议推流到服务器
 */
class RtmpPushClient extends RTMPClient
{
    const RTMP_SIG_SIZE = 1536;

    /** @var int 流ID */
    private $streamId = 0;

    /** @var bool 是否已发布 */
    private $published = false;

    /** @var int 时间戳基准 */
    private $baseTimestamp = 0;

    /** @var int 上一次发送的视频时间戳 */
    private $lastVideoTimestamp = -1;

    /** @var int 上一次发送的音频时间戳 */
    private $lastAudioTimestamp = -1;

    /** @var int 发送块大小 */
    private $sendChunkSize = 4096;

    /** @var int 音频块流ID */
    private $audioChunkStreamId = 4;

    /** @var int 视频块流ID */
    private $videoChunkStreamId = 5;

    /** @var int 元数据块流ID */
    private $metaChunkStreamId = 3;

    /**
     * 发布流
     * @param string $streamKey 流名称
     * @param string $type 发布类型 (live/record/append)
     * @return bool
     */
    public function publish($streamKey, $type = 'live')
    {
        // 创建流
        $this->createStream();

        // 发布流
        $this->sendPublish($streamKey, $type);

        // 设置块大小
        $this->sendSetChunkSize($this->sendChunkSize);

        $this->published = true;
        return true;
    }

    /**
     * 创建流
     */
    private function createStream()
    {
        echo "Creating stream...\n";
        $result = $this->call('createStream');
        echo "createStream result: " . json_encode($result) . "\n";
        if ($result && isset($result[0])) {
            $this->streamId = (int)$result[0];
            echo "streamId set to: {$this->streamId}\n";
        } else {
            echo "WARNING: streamId not set from createStream result!\n";
        }
    }

    /**
     * 发送发布命令
     * @param string $streamKey
     * @param string $type
     */
    private function sendPublish($streamKey, $type = 'live')
    {
        $message = new RtmpMessage('publish', null, [$streamKey, $type]);
        $packet = $this->encodeAMF0Message($message);
        $packet->streamId = $this->streamId;

        $this->sendPacket($packet);
        // publish 命令不需要等待响应，可以直接开始发送数据
    }

    /**
     * 使用AMF0编码消息
     * @param RtmpMessage $message
     * @return RtmpPacket
     */
    private function encodeAMF0Message(RtmpMessage $message)
    {
        $p = new RtmpPacket();
        $p->chunkStreamId = 3;
        $p->streamId = 0;
        $p->chunkType = RtmpPacket::CHUNK_TYPE_0;
        $p->type = RtmpPacket::TYPE_INVOKE_AMF0;

        $stream = new SabreAMF_OutputStream();
        $serializer = new SabreAMF_AMF0_Serializer($stream);

        $serializer->writeAMFData($message->commandName);
        $serializer->writeAMFData(0);
        $serializer->writeAMFData(null);

        if ($message->arguments != null) {
            foreach ($message->arguments as $arg) {
                $serializer->writeAMFData($arg);
            }
        }

        $p->payload = $stream->getRawData();
        $p->length = strlen($p->payload);

        $message->setPacket($p);
        return $p;
    }

    /**
     * 发送设置块大小命令
     * @param int $size
     */
    private function sendSetChunkSize($size)
    {
        $p = new RtmpPacket();
        $p->chunkStreamId = 2;
        $p->type = RtmpPacket::TYPE_CHUNK_SIZE;
        $p->streamId = 0;
        $p->chunkType = RtmpPacket::CHUNK_TYPE_0;

        $stream = new RtmpStream();
        $stream->writeInt32($size);

        $p->payload = $stream->flush();
        $p->length = strlen($p->payload);

        $this->sendPacket($p);
    }

    /**
     * 监听结果
     */
    private function listenForResult()
    {
        $timeout = 5;
        $start = time();

        while (time() - $start < $timeout) {
            if ($p = $this->readPacket()) {
                switch ($p->type) {
                    case RtmpPacket::TYPE_INVOKE_AMF0:
                    case RtmpPacket::TYPE_INVOKE_AMF3:
                        $this->handle_invoke($p);
                        return;
                }
            }
            usleep(10000);
        }
    }

    /**
     * 推送FLV文件
     * @param string $flvFile FLV文件路径
     * @param float $speed 推流速度倍数
     * @return bool
     */
    public function pushFlv($flvFile, $speed = 1.0)
    {
        if (!file_exists($flvFile)) {
            throw new Exception("FLV file not found: $flvFile");
        }

        if (!$this->published) {
            throw new Exception("Stream not published, call publish() first");
        }

        // 读取整个FLV文件
        $flvData = file_get_contents($flvFile);
        if ($flvData === false || strlen($flvData) < 13) {
            throw new Exception("Cannot read FLV file: $flvFile");
        }

        // 验证FLV签名
        if (substr($flvData, 0, 3) !== 'FLV') {
            throw new Exception("Invalid FLV file: wrong signature");
        }

        $version = ord($flvData[3]);
        $flags = ord($flvData[4]);
        $hasAudio = ($flags & 4) !== 0;
        $hasVideo = ($flags & 1) !== 0;

        echo "FLV Info: Version=$version, Audio=" . ($hasAudio ? 'Yes' : 'No') . ", Video=" . ($hasVideo ? 'Yes' : 'No') . "\n";

        // 参考FlvParse: read(9) + read(4)
        $offset = 9; // 跳过FLV header
        $offset += 4; // 跳过PreviousTagSize

        $totalLen = strlen($flvData);
        $tagCount = 0;
        $this->baseTimestamp = 0;
        $firstTag = true;
        $startTime = microtime(true);

        // 解析并发送标签
        while ($offset < $totalLen) {
            // 确保有足够的字节读取标签头(11字节) + PreviousTagSize(4字节)
            if ($offset + 15 > $totalLen) {
                break;
            }

            // 读取标签头 (11字节)
            $tagType = ord($flvData[$offset]);
            $offset++;

            // DataSize: 3 bytes, big-endian
            $dataSize = (ord($flvData[$offset]) << 16) | (ord($flvData[$offset + 1]) << 8) | ord($flvData[$offset + 2]);
            $offset += 3;

            // Timestamp: 3 bytes, big-endian + 1 byte extended
            $timestamp = (ord($flvData[$offset]) << 16) | (ord($flvData[$offset + 1]) << 8) | ord($flvData[$offset + 2]);
            $offset += 3;
            $timestamp |= (ord($flvData[$offset]) << 24);
            $offset++;

            // StreamID: 3 bytes, big-endian (通常为0)
            $offset += 3;

            // 确保有足够的数据读取 body + PreviousTagSize
            if ($offset + $dataSize + 4 > $totalLen) {
                break;
            }

            // 读取标签数据
            $tagData = substr($flvData, $offset, $dataSize);
            $offset += $dataSize;

            // 读取PreviousTagSize (4字节)
            $prevTagSize = (ord($flvData[$offset]) << 24) | (ord($flvData[$offset + 1]) << 16) 
                         | (ord($flvData[$offset + 2]) << 8) | ord($flvData[$offset + 3]);
            $offset += 4;

            $tagCount++;

            // 处理时间戳
            if ($firstTag && $dataSize > 0) {
                $this->baseTimestamp = $timestamp;
                $firstTag = false;
            }

            $adjustedTimestamp = $timestamp - $this->baseTimestamp;

            // 跳过无效或空的标签
            if ($dataSize <= 0) {
                continue;
            }

            // 调试输出
            $typeName = $tagType == 8 ? 'Audio' : ($tagType == 9 ? 'Video' : ($tagType == 18 ? 'Meta' : "Type$tagType"));
            echo "Tag #$tagCount: $typeName, size=$dataSize, ts=$adjustedTimestamp\n";

            // 根据标签类型发送
            switch ($tagType) {
                case 8: // 音频
                    $this->sendAudioData($tagData, $adjustedTimestamp);
                    break;
                case 9: // 视频
                    $this->sendVideoData($tagData, $adjustedTimestamp);
                    break;
                case 18: // 脚本数据
                    $this->sendMetaData($tagData, $adjustedTimestamp);
                    break;
            }

            // 控制推流速度
            if ($speed > 0 && $adjustedTimestamp > 0) {
                $elapsed = (microtime(true) - $startTime) * 1000;
                $expectedTime = ($adjustedTimestamp / $speed);
                $delay = (int)(($expectedTime - $elapsed) * 1000);
                if ($delay > 0) {
                    usleep($delay);
                }
            }
        }

        echo "FLV push completed. Total tags: $tagCount\n";
        return true;
    }

    /**
     * 发送音频数据
     * @param string $data 音频数据
     * @param int $timestamp 时间戳
     */
    private function sendAudioData($data, $timestamp)
    {
        $p = new RtmpPacket();
        $p->chunkStreamId = $this->audioChunkStreamId;
        $p->type = RtmpPacket::TYPE_AUDIO;
        $p->streamId = $this->streamId;
        $p->payload = $data;
        $p->length = strlen($data);

        // 计算时间戳增量
        $delta = $timestamp - $this->lastAudioTimestamp;
        
        // 如果增量有效且上一个包存在，使用CHUNK_TYPE_1发送增量
        // 否则使用CHUNK_TYPE_0发送绝对时间戳
        if ($this->lastAudioTimestamp >= 0 && $delta >= 0) {
            $p->chunkType = RtmpPacket::CHUNK_TYPE_1;
            $p->timestamp = $delta;  // CHUNK_TYPE_1发送的是增量
        } else {
            $p->chunkType = RtmpPacket::CHUNK_TYPE_0;
            $p->timestamp = $timestamp;  // CHUNK_TYPE_0发送的是绝对时间戳
        }

        $this->lastAudioTimestamp = $timestamp;
        $this->sendMediaPacket($p);
    }

    /**
     * 发送视频数据
     * @param string $data 视频数据
     * @param int $timestamp 时间戳
     */
    private function sendVideoData($data, $timestamp)
    {
        $p = new RtmpPacket();
        $p->chunkStreamId = $this->videoChunkStreamId;
        $p->type = RtmpPacket::TYPE_VIDEO;
        $p->streamId = $this->streamId;
        $p->payload = $data;
        $p->length = strlen($data);

        // 计算时间戳增量
        $delta = $timestamp - $this->lastVideoTimestamp;
        
        // 如果增量有效且上一个包存在，使用CHUNK_TYPE_1发送增量
        // 否则使用CHUNK_TYPE_0发送绝对时间戳
        if ($this->lastVideoTimestamp >= 0 && $delta >= 0) {
            $p->chunkType = RtmpPacket::CHUNK_TYPE_1;
            $p->timestamp = $delta;  // CHUNK_TYPE_1发送的是增量
        } else {
            $p->chunkType = RtmpPacket::CHUNK_TYPE_0;
            $p->timestamp = $timestamp;  // CHUNK_TYPE_0发送的是绝对时间戳
        }

        $this->lastVideoTimestamp = $timestamp;
        $this->sendMediaPacket($p);
    }

    /**
     * 发送元数据
     * @param string $data 元数据
     * @param int $timestamp 时间戳
     */
    private function sendMetaData($data, $timestamp)
    {
        $stream = new SabreAMF_InputStream($data);
        $deserializer = new SabreAMF_AMF0_Deserializer($stream);
        
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
        $p->timestamp = $timestamp;
        $p->payload = $outputStream->getRawData();
        $p->length = strlen($p->payload);
        $p->chunkType = RtmpPacket::CHUNK_TYPE_0;

        $this->sendMediaPacket($p);
    }

    /**
     * 发送媒体数据包
     * @param RtmpPacket $packet
     */
    private function sendMediaPacket(RtmpPacket $packet)
    {
        if (!$packet->length) {
            $packet->length = strlen($packet->payload);
        }

        // 构建块头
        $header = new RtmpStream();

        // 写入基本头
        $header->writeByte($packet->chunkType << 6 | $packet->chunkStreamId);

        // 根据块类型写入额外头信息
        if ($packet->chunkType <= RtmpPacket::CHUNK_TYPE_1) {
            // 时间戳 (3字节)
            if ($packet->timestamp >= 0xFFFFFF) {
                $header->writeInt24(0xFFFFFF);
            } else {
                $header->writeInt24($packet->timestamp);
            }
        }

        if ($packet->chunkType <= RtmpPacket::CHUNK_TYPE_1) {
            // 长度 (3字节)
            $header->writeInt24($packet->length);
            // 类型 (1字节)
            $header->writeByte($packet->type);
        }

        if ($packet->chunkType == RtmpPacket::CHUNK_TYPE_0) {
            // 流ID (4字节, 小端)
            $header->writeInt32LE($packet->streamId);
        }

        // 发送头
        $this->socketWrite($header);

        // 发送扩展时间戳
        if ($packet->timestamp >= 0xFFFFFF && $packet->chunkType <= RtmpPacket::CHUNK_TYPE_1) {
            $extTimestamp = new RtmpStream();
            $extTimestamp->writeInt32($packet->timestamp);
            $this->socketWrite($extTimestamp);
        }

        // 分块发送数据
        $offset = 0;
        $firstChunk = true;

        while ($offset < $packet->length) {
            if (!$firstChunk) {
                // 发送类型3头 (继续块)
                $this->socketWrite(new RtmpStream(chr(0xC0 | $packet->chunkStreamId)));
            }
            $firstChunk = false;

            $chunkSize = min($this->sendChunkSize, $packet->length - $offset);
            $chunkData = new RtmpStream(substr($packet->payload, $offset, $chunkSize));
            $this->socketWrite($chunkData, $chunkSize);
            $offset += $chunkSize;
        }
    }

    /**
     * 发送FCPublish命令
     * @param string $streamKey
     */
    public function fcPublish($streamKey)
    {
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

    /**
     * 关闭连接
     */
    public function close()
    {
        $this->published = false;
        parent::close();
    }
}