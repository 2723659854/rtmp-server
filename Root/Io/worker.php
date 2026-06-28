<?php

// worker.php - 子进程入口
if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    fwrite(STDERR, "错误：此脚本需要PHP 8.1或更高版本\n");
    exit(1);
}

require_once dirname(__DIR__,2) . '/vendor/autoload.php';
ini_set('memory_limit', '2048M');

// 解析命令行参数
$options = getopt('', [
    'worker-id:',
    'worker-count:',
    'copy-port:',
    'flv-port:'
]);

$workerId = (int)($options['worker-id'] ?? getenv('WORKER_ID') ?: 1);
$workerCount = (int)($options['worker-count'] ?? getenv('WORKER_COUNT') ?: 4);
$copyPort = (int)($options['copy-port'] ?? getenv('COPY_PORT') ?: 8502);
$flvPort = (int)($options['flv-port'] ?? getenv('FLV_PORT') ?: 8501);

define('WORKER_ID', $workerId);
define('WORKER_COUNT', $workerCount);
define('COPY_PORT', $copyPort);
define('FLV_PORT', $flvPort);
define('IS_WORKER', true);
define('ENABLE_MULTI_PROCESS', true);

/** 是否开启hls协议 */
define('FLV_TO_HLS', false);
/** 是否录屏mp4 */
define('FLV_TO_MP4', false);
/** 是否开启flv录屏 */
define('FLV_TO_RECORD', false);
/** 是否开启flv推流到远程服务器 */
define('FLV_TO_PUSH', true);

/** 获取服务实例 */
$server = \Root\Io\RtmpDemo::instance();
$server->rtmpPort = 1935;
$server->flvPort = FLV_PORT;
$server->webPort = 80;

// 设置复制流端口（需要在 RtmpDemo 中添加这些方法）
\Root\Io\RtmpDemo::setCopyPort(COPY_PORT);
\Root\Io\RtmpDemo::setWorkerId(WORKER_ID);
\Root\Io\RtmpDemo::setWorkerCount(WORKER_COUNT);
\Root\Io\RtmpDemo::setIsWorker(true);

echo sprintf(
    "[Worker %d] 启动成功，PID: %d，复制流端口: %d\n",
    WORKER_ID,
    getmypid(),
    COPY_PORT
);

// 启动服务
$server->start();