<?php

if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    fwrite(STDERR, "错误：此脚本需要PHP 8.1或更高版本，当前版本为 " . PHP_VERSION . "\n");
    exit(1);
}
require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '2048M');

if (PHP_SAPI !== 'cli') {
    die("This script can only be run from command line.\n");
}

if ($argc < 3) {
    echo "Usage: php " . basename($argv[0]) . " <pull_url> <push_urls> [duration] [--no-reconnect]\n";
    echo "\n";
    echo "Examples:\n";
    echo "  php forward.php ws://127.0.0.1:8501/a/b.flv rtmp://127.0.0.1:1935/c/d\n";
    echo "  php forward.php http://127.0.0.1:8501/a/b.flv \"rtmp://127.0.0.1:1935/c/d,ws://127.0.0.1:8501/c/e\"\n";
    echo "  php forward.php rtmp://127.0.0.1:1935/a/b \"rtmp://127.0.0.1:1935/c/d,http://127.0.0.1:8501/c/f\"\n";
    echo "  php forward.php http://127.0.0.1:8501/a/b.flv rtmp://127.0.0.1:1935/c/d 60\n";
    echo "  php forward.php ws://127.0.0.1:8501/a/b.flv rtmp://127.0.0.1:1935/c/d 0 --no-reconnect\n";
    echo "\n";
    echo "Options:\n";
    echo "  pull_url       拉流地址，支持 http-flv, ws-flv, rtmp\n";
    echo "  push_urls      推流地址，多个地址用逗号分隔，支持 rtmp, ws-flv, http-flv\n";
    echo "  duration       推流时长（秒），0表示持续推流直到手动停止 (default: 0)\n";
    echo "  --no-reconnect  禁用自动重连\n";
    exit(1);
}

$pullUrl = $argv[1];
$pushUrlsStr = $argv[2];
$pushUrls = array_map('trim', explode(',', $pushUrlsStr));
$duration = $argv[3] ?? 0;
$autoReconnect = !in_array('--no-reconnect', $argv);

$forwarder = new \Xiaosongshu\Flv2mp4\Flv\FlvForwardClient($pullUrl, $pushUrls, $duration, $autoReconnect);
$forwarder->start();