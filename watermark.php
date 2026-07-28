<?php
require_once __DIR__ . '/vendor/autoload.php';

use Xiaosongshu\Flv2mp4\Codec\WatermarkUtil;

echo "=== 测试 WatermarkUtil ===\n\n";

echo "使用文字生成水印 (宽x高： 80x16)...\n";
$outputFile1 = __DIR__ . '/watermark_text_80x16.yuv';
$result = WatermarkUtil::generateFromText(
    'xiaosongshu',
    $outputFile1,
    80,
    16,

    [
        'fontSize' => 5, // 内置字体大小 1-5
        'fontColor' => [255, 255, 255],
        'bgColor' => [0, 0, 0],
    ]
);
if ($result && file_exists($outputFile1)) {
    echo "   文字水印文件生成成功!\n";
} else {
    echo "   ❌ 文字水印文件生成失败\n";
}

//echo "使用图片生成水印 (宽x高： 80x16)...\n";
//$outputFile2 = __DIR__ . '/watermark_image_80x16.yuv';
//$result = WatermarkUtil::generateFromImage(
//    /** 你的水印图片，支持png和jpg ,请确保你的水印图片存在 */
//    __DIR__."/watermark_80x16.png",
//    $outputFile1,
//    80,
//    16,
//);
//if ($result && file_exists($outputFile1)) {
//    echo "   图片水印文件成功!\n";
//} else {
//    echo "   ❌ 图片水印文件生成失败\n";
//}