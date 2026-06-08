<?php


use Apix\Log\Logger\Stream;

if (!function_exists('logger')) {
    /**
     * 打印日志
     * @return Stream
     */
    function logger()
    {
        static $logger;
        if (is_null($logger)) $logger = new Apix\Log\Logger\Stream();
        return $logger;
    }
}

if (!function_exists('echo_now_init')) {
    /**
     * 打印当前毫秒时间戳
     * @return mixed
     */
    function echo_now_init()
    {
        global $beginTime;
        $beginTime = timestamp();
    }
}

if (!function_exists('echo_now')) {
    /**
     * 打印当前时间
     * @return mixed
     */
    function echo_now()
    {
        global $beginTime;
        logger()->info("[echo now] " . (timestamp() - $beginTime));
    }
}

if (!function_exists('make_random_str')) {
    /**
     * 生成随机字符串
     * @param $length
     * @return false|string
     */
    function make_random_str($length = 32)
    {
        static $char = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        if (!is_int($length) || $length < 0) {
            return false;
        }
        $string = pack("@$length");
        for ($i = 0, $clen = strlen($char); $i < $length; $i++) {
            $string[$i] = $char[mt_rand(0, $clen - 1)];
        }
        return $string;
    }
}


if (!function_exists('generateNewSessionID')) {
    /**
     * 生成sessionid
     * @param $length
     * @return false|string
     */
    function generateNewSessionID($length = 8)
    {
        static $char = 'ABCDEFGHIJKLMNOPQRSTUVWKYZ0123456789';
        if (!is_int($length) || $length < 0) {
            return false;
        }
        $string = pack("@$length");
        for ($i = 0, $clen = strlen($char); $i < $length; $i++) {
            $string[$i] = $char[mt_rand(0, $clen - 1)];
        }
        return $string;
    }
}


if (!function_exists('timestamp')) {
    /**
     * 毫秒时间戳
     * @return false|float
     */
    function timestamp()
    {
        return floor(microtime(true) * 1000);
    }
}

if (!function_exists('is_assoc')) {
    function is_assoc($arr)
    {
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}

if (!function_exists('safe_log_to_file')) {
    /**
     * 安全写入日志：过滤二进制数据，保留结构，写入文件
     * @param mixed $data 要记录的数据（debug_backtrace()）
     * @param string $logFile 日志路径
     * @return void
     */
    function safe_log_to_file($data, $logFile = '')
    {
        if (!is_dir(app_path('/log/'))) {
            mkdir(app_path('/log/'));
        }
        if (empty($logFile)) {
            $logFile = app_path('/log/' . time() . "_debug_flv.log") ;
        }

        $clean = [];
        foreach ($data as $trace) {
            $item = [
                'file' => isset($trace['file']) ? $trace['file'] : '',
                'line' => isset($trace['line']) ? $trace['line'] : '',
                'function' => isset($trace['function']) ? $trace['function'] : '',
                'class' => isset($trace['class']) ? $trace['class'] : '',
            ];
            $clean[] = $item;
        }

        $log = print_r($clean, true);
        file_put_contents($logFile, $log, FILE_APPEND | LOCK_EX);
    }
}

if (!function_exists('app_path')) {

    /**
     * 项目根目录
     * @param string $path
     * @return string
     */
    function app_path(string $path = ''){
        return dirname(__DIR__) . $path;
    }
}


