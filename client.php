<?php

// 检查PHP版本是否小于8.1
if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    fwrite(STDERR, "错误：此脚本需要PHP 8.1或更高版本，当前版本为 " . PHP_VERSION . "\n");
    exit(1);
}

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/SabreAMF/RtmpPushClient.php';
ini_set('memory_limit', '2048M');

// ============ 命令行入口 ============

if (PHP_SAPI !== 'cli') {
    die("This script can only be run from command line.\n");
}

// 解析命令行参数
if ($argc < 2) {
    echo "Usage: php " . basename($argv[0]) . " <flv_file> [rtmp_url] [speed] [--no-reconnect]\n";
    echo "\n";
    echo "Examples:\n";
    echo "  php client.php test.flv\n";
    echo "  php client.php test.flv rtmp://127.0.0.1:1935/live/stream\n";
    echo "  php client.php test.flv rtmp://127.0.0.1:1935/live/stream 2.0\n";
    echo "  php client.php test.flv rtmp://127.0.0.1:1935/live/stream 1.0 --no-reconnect\n";
    echo "\n";
    echo "Options:\n";
    echo "  flv_file       FLV文件路径\n";
    echo "  rtmp_url       RTMP推流地址 (默认: rtmp://127.0.0.1:1935/live/stream)\n";
    echo "  speed          推流速度倍数 (0.1-10.0, 默认: 1.0)\n";
    echo "  --no-reconnect 禁用自动重连\n";
    exit(1);
}

$flvFile = $argv[1];
$rtmpUrl = $argv[2] ?? 'rtmp://127.0.0.1:1935/live/stream';
$speed = floatval($argv[3] ?? 1.0);
$autoReconnect = !in_array('--no-reconnect', $argv);

// 创建推流器
$pusher = new RtmpPushClient($flvFile, $rtmpUrl, $speed, $autoReconnect);

// 启动推流
$pusher->start();