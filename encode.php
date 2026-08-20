<?php

require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '2048M');

// 定义规格，支持多码率，默认只设置一个码率
$profiles = [
    '240p' => [
        'width' => 426,      // 或 424，保持 16:9 比例即可
        'height' => 240,
        'bitrate' => 300000, // 300 Kbps（视频码率）
        'fps' => 24,
        'audioBitrate' => 48000, // 48 Kbps
        'qp' => 30,          // 保持 30 以确保稳定性
        'watermark'=>false,     // 是否添加水印
        'watermark_file'=> __DIR__."/watermark_80x16.yuv",// 水印文件
        'motionWorkers' => 6,// 分布式架构重编码子进程数
    ],
];

// 生成 HLS
$generator = new \Xiaosongshu\Flv2mp4\Recode\PurePhpHlsGenerator(
    $profiles,
    __DIR__ . '/hls/output',
    true,//开启分布式加速
);
// 可以设置最大编码视频帧数
//$generator->setMaxFrames(200);
$startTime = time();
// 测试baseline profile 转码
$generator->processFlv(__DIR__ . '/input.flv');
$endTime = time();
$cost = $endTime - $startTime;
echo "HLS 生成完成！\n";
echo "索引地址: hls/output/master.m3u8\n";
echo "cost {$cost}s\n";

# 根据需求开启下面的flv/mp4重编码功能
$config = [
    'width' => 640,
    'height' => 360,
    'bitrate' => 600000,
    'fps' => 24,
    'audioBitrate' => 64000,
    'qp' => 26,
    'motionWorkers' => 6,// 分布式架构重编码子进程数
];

/** 重编码flv文件 */
//$start1 = time();
//$recoder = new \Xiaosongshu\Flv2mp4\Recode\FlvRecoder($config, true);
//$recoder->processFlv(__DIR__ . '/demo.flv', __DIR__.'/output.flv');
//$end1 = time();
//$cost1 = $end1 - $start1;
//echo "flv重编码完成,耗时{$cost1}s\r\n";

/** 重编码mp4文件 */
//$start2 = time();
//$recoder = new \Xiaosongshu\Flv2mp4\Recode\Mp4Recoder($config, true);
//$recoder->processMp4(__DIR__ . '/demo.mp4', __DIR__ . '/output.mp4');
//$end2 = time();
//$cost2 = $end2 - $start2;
//echo "mp4重编码完成 {$cost2}s\r\n";