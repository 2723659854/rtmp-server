<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/SabreAMF/RtmpPushClient.php';
ini_set('memory_limit', '2048M');

// 解析命令行参数
$argv = $_SERVER['argv'] ?? [];
$argc = count($argv);

if ($argc < 2) {
    echo "Usage: php client.php <flv_file> [rtmp_url] [speed]\n";
    echo "\n";
    echo "Examples:\n";
    echo "  php client.php test.flv rtmp://127.0.0.1:1935/live/stream\n";
    echo "  php client.php test.flv rtmp://127.0.0.1:1935/live/stream 2.0\n";
    echo "\n";
    echo "Options:\n";
    echo "  flv_file    FLV文件路径\n";
    echo "  rtmp_url    RTMP推流地址 (默认: rtmp://127.0.0.1:1935/live/stream)\n";
    echo "  speed       推流速度倍数 (0.1-10.0, 默认: 1.0)\n";
    exit(1);
}

$flvFile = $argv[1];
$rtmpUrl = $argv[2] ?? 'rtmp://127.0.0.1:1935/live/stream';
$speed = floatval($argv[3] ?? 1.0);

// 解析RTMP URL
// 格式: rtmp://host:port/app/streamKey
if (!preg_match('#^rtmp://([^:/]+)(?::(\d+))?/([^/]+)/(.+)$#', $rtmpUrl, $matches)) {
    echo "Invalid RTMP URL format. Expected: rtmp://host[:port]/app/streamKey\n";
    exit(1);
}

$host = $matches[1];
$port = intval($matches[2] ?: 1935);
$app = $matches[3];
$streamKey = $matches[4];

echo "Connecting to RTMP server: $host:$port\n";
echo "Application: $app\n";
echo "Stream Key: $streamKey\n";
echo "FLV File: $flvFile\n";
echo "Speed: {$speed}x\n\n";

try {
    $client = new RtmpPushClient();

    // 连接服务器
    echo "Connecting...\n";
    $client->connect($host, $app, $port);

    // 发送FCPublish (可选，某些服务器需要)
    echo "Sending FCPublish...\n";
    $client->fcPublish($streamKey);

    // 发布流
    echo "Publishing stream...\n";
    $client->publish($streamKey, 'live');

    // 推送FLV文件
    echo "Pushing FLV file...\n";
    $client->pushFlv($flvFile, $speed);

    // 关闭连接
    $client->close();
    echo "Done!\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}