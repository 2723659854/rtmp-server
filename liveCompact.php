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

if ($argc !== 4) {
    echo "用法：php liveCompact.php  <直播拉流地址> <目标分辨率宽度> <目标分辨率高度>\n";
    echo "示例：php liveCompact.php ws://192.168.110.72:8501/a/b.flv 640 360\n";
    exit(1);
}

$pullUrl = $argv[1];
$targetWidth = filter_var($argv[2], FILTER_VALIDATE_INT);
$targetHeight = filter_var($argv[3], FILTER_VALIDATE_INT);

$urlInfo = parse_url($pullUrl);
if (!in_array($urlInfo['scheme'] ?? '', ['http', 'https', 'ws', 'wss'], true)) {
    fwrite(STDERR, "错误：直播拉流地址必须使用 http、https、ws 或 wss 协议。\n");
    exit(1);
}
if ($targetWidth === false || $targetWidth <= 0 || $targetHeight === false || $targetHeight <= 0) {
    fwrite(STDERR, "错误：目标分辨率宽度和高度必须是正整数。\n");
    exit(1);
}

$streamPath = trim(parse_url($pullUrl, PHP_URL_PATH) ?: '', '/');
$streamPath = preg_replace('/\.[^\/\.]+$/', '', $streamPath);
if ($streamPath === '') {
    $streamPath = 'live';
}

$config = [
    'width'        => $targetWidth,
    'height'       => $targetHeight,
    'bitrate'      => 0,
    'fps'          => 0,
    'qp'           => 30,
    'audioBitrate' => 64000,
    'watermark'       => false,
    'watermark_file'  => __DIR__ . '/watermark_80x16.yuv',

    'motionWorkers'   => 1,
    'decodeWorkers'   => 6,
    'segmentDuration' => 3,
    'fastMotion' => true,
    'outputDir'  => __DIR__ . '/hls/' . $streamPath . '/' . $targetWidth . 'x' . $targetHeight . '/',

    'maxRetries'    => 5,
    'retryDelay'    => 3,
    'connectTimeout'=> 10,
    'idleTimeout'   => 30,
    'queueMaxBytes' => 8388608,
    'duration'   => 0,
    'tlsVerify'  => false,
];

(new \Xiaosongshu\Flv2mp4\Manage\Flv2HlsCompact($pullUrl, $config))->run();
