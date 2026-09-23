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

if ($argc !== 4) {
    echo "用法：php liveCompact.php  <直播拉流地址> <目标分辨率宽度> <目标分辨率高度>\n";
    echo "示例：php liveCompact.php ws://192.168.110.72:8501/a/b.flv 640 360\n";
    exit(1);
}

// ======================== 直播拉流配置 支持（http/https/ws/wss） ========================
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

// ======================== 转码压缩配置 ========================
$config = [
    // —— 目标规格（width/height 必须同时给，0=保持源尺寸）——
    'width'        => $targetWidth,
    'height'       => $targetHeight,
    'bitrate'      => 800000,  // 目标视频码率 bps
    'fps'          => 0,       // 目标帧率（串行管道仅传编码器，不抽帧；0=保持）
    'qp'           => 30,      // 量化参数 0-51
    'audioBitrate' => 64000,   // 音频码率 bps

    // —— 并行/输出 ——
    'motionWorkers'   => 12,   // 运动估计子进程数
    'decodeWorkers'   => 2,    // 解码+缩放worker数（缩放在此并行完成，勿置0走串行）
    'segmentDuration' => 3,    // HLS切片时长（秒）
    'watermark'       => false, // 是否加水印
    'watermark_file'  => __DIR__ . '/watermark_80x16.yuv', //水印文件

    // —— 输出目录与流名 ——
    'outputDir'  => __DIR__ . '/hls/' . $streamPath . '/' . $targetWidth . 'x' . $targetHeight . '/',

    // ======================== 拉流客户端配置 ========================
    'maxRetries'    => 5,      // 断线重连次数
    'retryDelay'    => 3,      // 重连间隔（秒）
    'connectTimeout'=> 10,     // 连接/握手超时（秒）
    'idleTimeout'   => 30,     // 连续无数据判定断流（秒）
    'queueMaxBytes' => 8388608,  // 转码落后容忍8MB；超限拉流进程跳IDR追直播
    'duration'   => 0,      // 限定运行秒数，0=不限
    'tlsVerify'  => false,  // https/wss 自签证书时关闭校验
];
// 启动压缩转码服务
(new \Xiaosongshu\Flv2mp4\Manage\Flv2HlsCompact($pullUrl, $config))->run();
