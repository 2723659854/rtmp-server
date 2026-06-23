<?php

/**
 * RTMP Server 鉴权凭证生成工具
 * 
 * 使用方式:
 * 1. 命令行模式:
 *    php auth_generate.php --help                    显示帮助
 *    php auth_generate.php --key                    生成随机Stream Key
 *    php auth_generate.php --key=N                  生成N位随机Stream Key
 * 
 * 2. HTTP接口模式 (通过服务器访问):
 *    GET /auth_generate.php?action=key              生成Stream Key
 */

function generateRandomKey($length = 16)
{
    $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $key = '';
    for ($i = 0; $i < $length; $i++) {
        $key .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $key;
}

function printHelp()
{
    $help = <<<'EOF'
RTMP Server 鉴权凭证生成工具

Usage: php auth_generate.php [options]

Options:
  --help          显示帮助信息
  --key           生成随机Stream Key (默认16位)
  --key=N         生成N位随机Stream Key

Examples:
  php auth_generate.php --key
  php auth_generate.php --key=32

HTTP接口:
  GET /auth_generate.php?action=key
  GET /auth_generate.php?action=key&length=32

EOF;
    echo $help;
}

function handleCommandLine()
{
    global $argv;
    
    if (count($argv) < 2 || in_array('--help', $argv)) {
        printHelp();
        exit(0);
    }
    
    $options = [];
    foreach ($argv as $arg) {
        if (strpos($arg, '=') !== false) {
            list($key, $value) = explode('=', $arg, 2);
            $options[ltrim($key, '-')] = $value;
        } else {
            $options[ltrim($arg, '-')] = true;
        }
    }
    
    if (isset($options['key'])) {
        $length = is_numeric($options['key']) ? (int)$options['key'] : 16;
        $key = generateRandomKey($length);
        echo "Stream Key: {$key}\n";
        echo "\n请将此密钥添加到 auth_config.php 中的 stream_keys 数组\n";
        exit(0);
    }
    
    echo "未知选项，请使用 --help 查看帮助\n";
    exit(1);
}

function handleHttp()
{
    $action = $_GET['action'] ?? '';
    
    header('Content-Type: application/json; charset=utf-8');
    
    switch ($action) {
        case 'key':
            $length = (int)($_GET['length'] ?? 16);
            $key = generateRandomKey($length);
            echo json_encode(['success' => true, 'key' => $key]);
            break;
            
        default:
            echo json_encode([
                'success' => false,
                'error' => '未知action',
                'available_actions' => ['key']
            ]);
    }
}

if (php_sapi_name() === 'cli') {
    handleCommandLine();
} else {
    handleHttp();
}