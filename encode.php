<?php

require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '512M');
$file = __DIR__."/test.flv";
//本次修复后，检查生成的flv没有看到错误，使用ffmpeg转码mp4可以播放
//D:\php\rtmp_server\flv\a\b>ffmpeg -v error -i a.flv -f null -
//
//D:\php\rtmp_server\flv\a\b>ffprobe -v error -show_error a.flv

echo "=== 示例1: flv静态文件切片fMP4并合并为mp4文件 ===\n";
$outputDir1 = __DIR__."/encode";
try{
    $res = \Xiaosongshu\Flv2mp4\Client::runFlv2mp4($file, $outputDir1);
    echo "\n转换完成: " . $res . "\n\n";
}catch (\Exception $e){
    echo "错误: " . $e->getMessage() . "\n\n";
}