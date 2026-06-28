<?php

// 检查PHP版本是否小于8.1
if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    fwrite(STDERR, "错误：此脚本需要PHP 8.1或更高版本，当前版本为 " . PHP_VERSION . "\n");
    exit(1);
}

require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '2048M');

/** 是否开启hls协议 false表示关闭，true表示开启 */
define('FLV_TO_HLS', false);
/** 是否录屏mp4 ， false表示关闭，true表示开启 */
define('FLV_TO_MP4', false);
/** 是否开启flv录屏 ， false表示关闭，true表示开启 */
define('FLV_TO_RECORD', false);
/** 是否开启flv推流到远程服务器 ， false表示关闭，true表示开启 */
define('FLV_TO_PUSH', true);

// ==================== 多进程配置 ====================
/** 是否启用多进程模式 */
define('ENABLE_MULTI_PROCESS', true);

/** 进程数量（建议不超过 CPU 核心数） */
define('WORKER_COUNT', 2);

/** 基础 FLV 端口（对外服务端口） */
define('BASE_FLV_PORT', 8501);

/** 内部复制流端口起始（从 8502 开始） */
define('COPY_PORT_START', 8502);

/** 是否启用复制流端口（多进程模式下自动启用） */
define('ENABLE_COPY_PORT', ENABLE_MULTI_PROCESS);

// ==================================================

/** 获取服务实例 */
$server = \Root\Io\RtmpDemo::instance();
$server->rtmpPort = 1935;
$server->flvPort = BASE_FLV_PORT;
$server->webPort = 80;

// 检测运行环境
$isLinux = DIRECTORY_SEPARATOR === '/';
$isWindows = !$isLinux;

// 多进程启动
if (ENABLE_MULTI_PROCESS) {
    if ($isLinux && extension_loaded('pcntl')) {
        startWithPcntl($server);
    } elseif ($isWindows) {
        startWithProcOpen($server);
    } else {
        fwrite(STDERR, "警告：当前环境不支持多进程，将以单进程模式启动\n");
        $server->start();
    }
} else {
    // 单进程模式
    $server->start();
}