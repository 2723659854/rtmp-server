<?php

// 检查PHP版本是否小于8.1
if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    fwrite(STDERR, "错误：此脚本需要PHP 8.1或更高版本，当前版本为 " . PHP_VERSION . "\n");
    exit(1);
}

require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '2048M');

// ============ 命令行入口 ============

if (PHP_SAPI !== 'cli') {
    die("This script can only be run from command line.\n");
}

// 解析命令行参数
if ($argc < 2) {
    echo "Usage: php " . basename($argv[0]) . " <file> [rtmp_url] [speed] [--no-reconnect]\n";
    echo "\n";
    echo "Examples:\n";
    echo "  php client.php test.flv\n";
    echo "  php client.php test.mp4\n";
    echo "  php client.php test.flv rtmp://127.0.0.1:1935/live/stream\n";
    echo "  php client.php test.mp4 rtmp://127.0.0.1:1935/live/stream 2.0\n";
    echo "  php client.php test.mp4 rtmp://127.0.0.1:1935/live/stream 1.0 --no-reconnect\n";
    echo "\n";
    echo "Options:\n";
    echo "  file          FLV或MP4文件路径\n";
    echo "  rtmp_url      RTMP推流地址 (默认: rtmp://127.0.0.1:1935/live/stream)\n";
    echo "  speed         推流速度倍数 (0.1-10.0, 默认: 1.0)\n";
    echo "  --no-reconnect 禁用自动重连\n";
    exit(1);
}

$filePath = $argv[1];
$rtmpUrl = $argv[2] ?? 'rtmp://127.0.0.1:1935/a/b';
$speed = floatval($argv[3] ?? 1.0);
$autoReconnect = !in_array('--no-reconnect', $argv);

// 检查文件是否存在
if (!file_exists($filePath)) {
    fwrite(STDERR, "错误：文件不存在: {$filePath}\n");
    exit(1);
}

// 获取文件扩展名
$extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

// 根据文件类型选择推流器
if ($extension === 'mp4') {
    // MP4文件使用Mp4ToRtmp推流
    require_once __DIR__ . '/SabreAMF/RtmpPushMp4Client.php';
    $pusher = new RtmpPushMp4Client($filePath, $rtmpUrl, $speed, $autoReconnect);
} else {
    // FLV或其他文件使用RtmpPushClient推流
    require_once __DIR__ . '/SabreAMF/RtmpPushFlvClient.php';
    $pusher = new RtmpPushFlvClient($filePath, $rtmpUrl, $speed, $autoReconnect);
}

// 启动推流
$pusher->start();