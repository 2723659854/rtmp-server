<?php

require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '2048M');

/** -----提示：仅支持baseline profile级别的h264 + aac 格式的flv/mp4/hls重编码------ */
$profiles = [
    '240p' => [
        'width' => 426,
        'height' => 240,
        'bitrate' => 300000,
        'fps' => 10,
        'audioBitrate' => 48000,
        'qp' => 10,
        'watermark'=>false,
        'watermark_file'=> __DIR__."/watermark_80x16.yuv",
        'motionWorkers' => 8,
    ],
];

/** flv转hls重编码 */
$generator = new \Xiaosongshu\Flv2mp4\Recode\PurePhpHlsGenerator(
    $profiles,
    __DIR__ . '/hls/output',
    true,//是否开启分布式多进程加速
);

$startTime = time();
$generator->processFlv(__DIR__ . '/test.flv');
$endTime = time();
$cost = $endTime - $startTime;
echo "HLS 生成完成！\n";
echo "索引地址: hls/output/master.m3u8\n";
echo "cost {$cost}s\n";

$config = $profiles['240p'];

/** 重编码flv文件 */
//$start1 = time();
//$recoder = new \Xiaosongshu\Flv2mp4\Recode\FlvRecoder($config, true);
//$recoder->processFlv(__DIR__ . '/test.flv', __DIR__.'/output.flv');
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