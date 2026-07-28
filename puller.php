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
if ($argc < 3) {
    echo "Usage: php " . basename($argv[0]) . " <pull_url> <output_flv> [duration] [--no-reconnect]\n";
    echo "\n";
    echo "Examples:\n";
    echo "  php puller.php http://127.0.0.1:8501/live/stream.flv output.flv\n";
    echo "  php puller.php ws://127.0.0.1:8501/live/stream.flv output.flv\n";
    echo "  php puller.php rtmp://127.0.0.1:1935/live/stream output.flv\n";
    echo "  php puller.php http://127.0.0.1:8501/live/stream.flv output.flv 60\n";
    echo "  php puller.php http://127.0.0.1:8501/live/stream.flv output.flv 0 --no-reconnect\n";
    echo "\n";
    echo "Options:\n";
    echo "  pull_url       拉流地址，支持 http-flv, ws-flv, rtmp\n";
    echo "  output_flv     输出FLV文件路径\n";
    echo "  duration       拉流时长（秒），0表示持续拉流直到手动停止 (default: 0)\n";
    echo "  --no-reconnect  禁用自动重连\n";
    exit(1);
}

$pullUrl = $argv[1];
$outputFlv = $argv[2];
$duration = $argv[3] ?? 0;
$autoReconnect = !in_array('--no-reconnect', $argv);
$puller = new \Xiaosongshu\Flv2mp4\Manage\PullerManage($pullUrl, $outputFlv, $duration, $autoReconnect);
$puller->start();