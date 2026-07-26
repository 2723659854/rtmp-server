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

//    '240p' => [
//        'width' => 426,      // 或 424，保持 16:9 比例即可
//        'height' => 240,
//        'bitrate' => 300000, // 300 Kbps（视频码率）
//        'fps' => 24,
//        'audioBitrate' => 48000, // 48 Kbps
//        'qp' => 30,          // 保持 30 以确保稳定性
//    ],

//    '180p' => [
//        'width' => 320,
//        'height' => 180,
//        'bitrate' => 150000, // 150 Kbps
//        'fps' => 15,         // 帧率可以降一半，肉眼在极低分辨率下察觉不到
//        'audioBitrate' => 32000,
//        'qp' => 30,
//    ],
];

// 生成 HLS
$generator = new \Xiaosongshu\Flv2mp4\Manage\PurePhpHlsGenerator(
    $profiles,
    __DIR__ . '/hls/output'
);
$startTime = time();
$generator->processFlv(__DIR__ . '/index.flv');
$endTime = time();
$cost = $endTime - $startTime;
echo "HLS 生成完成！\n";
echo "索引地址: hls/output/master.m3u8\n";
echo "cost {$cost}s\n";