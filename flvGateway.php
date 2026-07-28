<?php

// 检查PHP版本是否小于8.1
if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    // 输出错误信息到标准错误（STDERR）
    fwrite(STDERR, "错误：此脚本需要PHP 8.1或更高版本，当前版本为 " . PHP_VERSION . "\n");
    // 退出脚本并返回错误码1（表示一般错误）
    exit(1);
}
require_once __DIR__ . '/vendor/autoload.php';
// ====== 启动 ======
error_reporting(E_ALL);
set_time_limit(0);
ini_set('memory_limit', '2048M');
/** 本网关对下游提供服务的端口 */
$port = isset($argv[1]) ? (int)$argv[1] : 8080;
/** 上游flv播放地址 */
$upstream = isset($argv[2]) ? $argv[2] : 'http://127.0.0.1:8501';

/**
 * # 一级网关
 * php flvGateway.php 8080 http://127.0.0.1:8501
 *
 * # 二级网关
 * php flvGateway.php 8081 http://127.0.0.1:8080
 *
 * # 三级网关
 * php flvGateway.php 8082 http://127.0.0.1:8081
 */
//$gateway = new \Root\Io\FlvGateway($port, $upstream);
$gateway = new \Xiaosongshu\Flv2mp4\manage\FlvGateway($port, $upstream);
/** 是否开启调试模式，调试模式打印日志 */
$gateway->debug = true;
/** 启动网关 */
$gateway->start();