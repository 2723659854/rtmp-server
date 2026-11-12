<?php
/**
 * @purpose WebRTC直播服务启动文件
 * @author yanglong
 * @command php start.php
 */

use Root\WebrtcGateway;
use Xiaosongshu\Webrtc\WebRTCServer;

require_once __DIR__."/vendor/autoload.php";
require_once __DIR__."/config/app.php";
ini_set('memory_limit', '3072M');

$server = new WebRTCServer(WS_PORT, UDP_PORT, STUN_PORT, __DIR__ . "/webrtc_debug.log",__DIR__.'/webrtc');
// 设置公网ip，当对外提供服务的时候务必设置
$server->publicIp = PUBLIC_IP;
// 生产环境建议关闭
$server->isDev = false;
// 要求推流端每隔2秒一个关键帧
$server->periodicKeyFrameIntervalSec = 2;
// 关闭"把一端的 ICE candidate 转发给同房间对端"的 P2P 兼容逻辑
$server->relayPeerCandidates = false;
// 启动代理注册服务，并将webrtc桥接到rtmp
$gateway = new WebrtcGateway($server,['wsFlvPushUrl'=>WS_FLV_PUSH_URL,'webrtc2rtmp'=>WEBRTC_TO_RTMP,'opusWorkerPort'=>OPUS_2_AAC_PORT]);
$gateway->registerShutdownHandlers();
// 启动webrtc服务
$server->start();
