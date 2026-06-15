<?php
require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '512M');
$file = __DIR__."/demo.flv";

// ffmpeg -v error -i segment.ts -f null -
// ffmpeg -v error -i test.mp4 -f null -
// ffmpeg -v error -i output_from_hls.flv -f null -

echo "\n === 示例3: 转换flv为hls === \n";
$outputDir1 = __DIR__ . "/hls2";
try {
    $res = \Xiaosongshu\Flv2mp4\Client::runFlv2Hls($file, $outputDir1);
    echo "\n hls转换完成 index = {$res['index']} dir = {$res['outputDir']}\n\n";

    echo "\n === 示例4: 转换hls回flv === \n";
    $outputFlv = __DIR__ . "/output_from_hls.flv";
    try {
        $res2 = \Xiaosongshu\Flv2mp4\Client::runHls2Flv($res['index'], $outputFlv);
        echo "\n hls转flv完成: {$res2}\n\n";
    } catch (\Exception $e) {
        echo "错误: " . $e->getMessage() . "\n\n";
    }
} catch (\Exception $e) {
    echo "错误: " . $e->getMessage() . "\n\n";
}