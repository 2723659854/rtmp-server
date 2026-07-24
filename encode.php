<?php

require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '2048M');
// 使用纯 PHP 多码率 HLS 生成器

// 定义规格
$profiles = [
//    '1080p' => [
//        'width' => 1920,
//        'height' => 1080,
//        'bitrate' => 5000000,  // 5 Mbps
//        'fps' => 30,
//        'audioBitrate' => 192000,
//    ],
//    '720p' => [
//        'width' => 1280,
//        'height' => 720,
//        'bitrate' => 2500000,  // 2.5 Mbps
//        'fps' => 30,
//        'audioBitrate' => 128000,
//    ],
//    '480p' => [
//        'width' => 854,
//        'height' => 480,
//        'bitrate' => 1200000,  // 1.2 Mbps
//        'fps' => 25,
//        'audioBitrate' => 96000,
//    ],
    '360p' => [
        'width' => 640,
        'height' => 360,
        'bitrate' => 600000,   // 600 Kbps
        'fps' => 24,
        'audioBitrate' => 64000,
    ],
];

// 生成 HLS
$generator = new \Xiaosongshu\Flv2mp4\Manage\PurePhpHlsGenerator(
    $profiles,
    __DIR__ . '/hls/output'
);
$startTime = time();
$generator->processFlv(__DIR__ . '/test.flv');
$endTime = time();
$cost = $endTime - $startTime;
echo "HLS 生成完成！\n";
echo "索引地址: hls/output/master.m3u8\n";
echo "cost {$cost}s\n";