<?php
// 检查PHP版本是否小于8.1
if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    // 输出错误信息到标准错误（STDERR）
    fwrite(STDERR, "错误：此脚本需要PHP 8.1或更高版本，当前版本为 " . PHP_VERSION . "\n");
    // 退出脚本并返回错误码1（表示一般错误）
    exit(1);
}
require_once __DIR__ . '/vendor/autoload.php';
// ========== 启动静态文件服务器 ==========

$host = $argv[1] ?? '0.0.0.0';
$port = isset($argv[2]) ? (int)$argv[2] : 8100;
$documentRoot = $argv[3] ?? __DIR__;
$enableDirListing = isset($argv[4]) && $argv[4] === '--dir';

echo "========================================\n";
echo "  高性能静态文件服务器网关\n";
echo "========================================\n";
echo "用法: php fileGateway.php [host] [port] [document_root] [--dir]\n\n";

try {
    $server = new \Root\Io\FileGateway($host, $port, $documentRoot, $enableDirListing);
    $server->debug = true;
    $server->start();
} catch (\Exception $e) {
    echo "错误: " . $e->getMessage() . "\n";
    exit(1);
}