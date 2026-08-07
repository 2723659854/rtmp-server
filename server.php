<?php

// 检查PHP版本是否小于8.1
if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    fwrite(STDERR, "错误：此脚本需要PHP 8.1或更高版本，当前版本为 " . PHP_VERSION . "\n");
    exit(1);
}

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__."/config/app.php";
ini_set('memory_limit', '2048M');



/** 获取服务实例 */
$server = \Root\Io\RtmpDemo::instance();
$server->rtmpPort = BASE_RTMP_PORT;
$server->flvPort = BASE_FLV_PORT;
$server->webPort = BASE_WEB_PORT;

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