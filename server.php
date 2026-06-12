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

/** 是否开启hls协议 false表示关闭，true表示开启 */
define('FLV_TO_HLS',true);
/** 是否录屏mp4 ， false表示关闭，true表示开启 */
define('FLV_TO_MP4',false);
/** 是否开启flv录屏 ， false表示关闭，true表示开启 */
define('FLV_TO_RECORD',true);

/** 获取服务实例 */
$server = \Root\Io\RtmpDemo::instance();
/** 设置rtmp通信端口 可以自行修改 默认1935 */
$server->rtmpPort = 1935;
/** 设置flv通信端口 可以自行修改 默认8501 */
$server->flvPort = 8501;
/** hls通信端口 可以自行修改 默认80  */
$server->webPort = 80;
/** 启动服务 */
$server->start();

