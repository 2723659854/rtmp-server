<?php

// 检查PHP版本是否小于8.1
if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    // 输出错误信息到标准错误（STDERR）
    fwrite(STDERR, "错误：此脚本需要PHP 8.1或更高版本，当前版本为 " . PHP_VERSION . "\n");
    // 退出脚本并返回错误码1（表示一般错误）
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
    echo "Usage: php " . basename($argv[0]) . " <flv_file> [push_url] [speed] [--no-reconnect]\n";
    echo "\n";
    echo "Examples:\n";
    echo "  php pusher.php test.flv\n";
    echo "  php pusher.php test.mp4\n";
    echo "  php pusher.php test.flv http://127.0.0.1:8501/live/stream\n";
    echo "  php pusher.php test.flv http://127.0.0.1:8501/live/stream 2.0\n";
    echo "  php pusher.php test.flv http://127.0.0.1:8501/live/stream 1.0 --no-reconnect\n";
    echo "\n";
    echo "Options:\n";
    echo "  speed        推流速度倍数 (0.1-10.0, default: 1.0)\n";
    echo "  --no-reconnect  禁用自动重连\n";
    exit(1);
}

$flvFile = $argv[1];
$pushUrl = $argv[2] ?? 'http://127.0.0.1:8501/live/stream';
$speed = $argv[3] ?? 1.0;
$autoReconnect = !in_array('--no-reconnect', $argv);

// 创建推流器
$pusher = new \Root\Io\PusherManage($flvFile, $pushUrl, $speed, $autoReconnect);

// 启动推流
$pusher->start();