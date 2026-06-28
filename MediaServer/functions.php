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

if (!function_exists('safe_trace_log')) {
    /**
     * 安全写入堆栈调用日志
     * @param mixed $data 要记录的数据（debug_backtrace()）
     * @param string $logFile 日志路径
     * @return void
     */
    function safe_trace_log($data = [], $logFile = '')
    {
        if (empty($data)){
            $data = debug_backtrace();
        }
        if (!is_dir(app_path('/log/'))) {
            mkdir(app_path('/log/'));
        }
        if (empty($logFile)) {
            $logFile = app_path('/log/' . time() . "_debug_trace.log") ;
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

if (!function_exists('startWithProcOpen')){
    /**
     * Windows 多进程启动（使用 proc_open）
     */
    function startWithProcOpen($server)
    {
        $workerCount = WORKER_COUNT;
        $copyPortStart = COPY_PORT_START;
        $baseFlvPort = BASE_FLV_PORT;
        $cwd = dirname(__DIR__);
        echo sprintf(
            "[INFO] 启动多进程模式 (Windows)，进程数: %d，对外端口: %d，复制流端口: %d-%d\n",
            $workerCount,
            $baseFlvPort,
            $copyPortStart,
            $copyPortStart + $workerCount - 1
        );

        $processes = [];
        $scriptPath = realpath(dirname(__DIR__,1) . '/Root/Io/worker.php');

        if (!file_exists($scriptPath)) {
            fwrite(STDERR, "错误：找不到 worker.php 文件\n");
            exit(1);
        }

        $phpPath = PHP_BINARY;
        echo "[INFO] PHP 路径: {$phpPath}\n";

        for ($i = 0; $i < $workerCount; $i++) {
            $workerId = $i + 1;
            $copyPort = $copyPortStart + $i;

            $cmd = sprintf(
                '"%s" "%s" --worker-id=%d --worker-count=%d --copy-port=%d --flv-port=%d',
                $phpPath,
                $scriptPath,
                $workerId,
                $workerCount,
                $copyPort,
                $baseFlvPort
            );

            echo sprintf(
                "[INFO] 启动 Worker %d，复制流端口: %d\n",
                $workerId,
                $copyPort
            );

            // 关键修复：不阻塞地启动进程
            // 使用 create_process 方式，不等待子进程输出
            $descriptors = [
                0 => ['pipe', 'r'],  // stdin - 不写入
                1 => ['file', 'NUL', 'w'],  // stdout - 丢弃
                2 => ['file', 'NUL', 'w'],  // stderr - 丢弃
            ];

            // Windows 上如果 NUL 不可用，使用临时文件
            if (DIRECTORY_SEPARATOR === '\\') {
                $logFile = $cwd  . "/logs/worker_{$workerId}.log";
                if (!is_dir($cwd  . '/logs')) {
                    mkdir($cwd  . '/logs', 0777, true);
                }
                $descriptors[1] = ['file', $logFile, 'a'];
                $descriptors[2] = ['file', $logFile, 'a'];
            }



            // 关键：使用 proc_open 但不读取管道
            $process = proc_open($cmd, $descriptors, $pipes, $cwd);

            if (is_resource($process)) {
                // 关闭管道（不写入）
                if (isset($pipes[0]) && is_resource($pipes[0])) {
                    fclose($pipes[0]);
                }
                if (isset($pipes[1]) && is_resource($pipes[1])) {
                    fclose($pipes[1]);
                }
                if (isset($pipes[2]) && is_resource($pipes[2])) {
                    fclose($pipes[2]);
                }

                $processes[$workerId] = [
                    'process' => $process,
                    'pid' => null,
                    'start_time' => time(),
                    'restart_count' => 0,
                    'log_file' => $logFile ?? null
                ];

                $status = proc_get_status($process);
                if ($status && isset($status['pid'])) {
                    $processes[$workerId]['pid'] = $status['pid'];
                    echo sprintf("[INFO] Worker %d PID: %d\n", $workerId, $status['pid']);
                }
            } else {
                fwrite(STDERR, sprintf("错误：启动 Worker %d 失败\n", $workerId));
            }
        }

        echo "[INFO] 所有 Worker 已启动\n";
        echo "[INFO] 按 Ctrl+C 停止所有进程\n";
        echo "[INFO] 查看日志: logs/worker_*.log\n";

        // 监控进程
        while (true) {
            foreach ($processes as $workerId => &$info) {
                // 检查进程是否存在
                if (!is_resource($info['process'])) {
                    // 进程已退出，尝试重启
                    $info['restart_count']++;
                    echo sprintf(
                        "[WARN] Worker %d 已退出 (重启次数: %d)，正在重启...\n",
                        $workerId,
                        $info['restart_count']
                    );

                    if ($info['restart_count'] > 10) {
                        echo sprintf("[ERROR] Worker %d 重启次数过多，停止重启\n", $workerId);
                        unset($processes[$workerId]);
                        continue;
                    }

                    // 重启该 Worker
                    $copyPort = COPY_PORT_START + ($workerId - 1);
                    $cmd = sprintf(
                        '"%s" "%s" --worker-id=%d --worker-count=%d --copy-port=%d --flv-port=%d',
                        $phpPath,
                        $scriptPath,
                        $workerId,
                        WORKER_COUNT,
                        $copyPort,
                        BASE_FLV_PORT
                    );

                    $logFile = $cwd  . "/logs/worker_{$workerId}.log";
                    $descriptors = [
                        0 => ['pipe', 'r'],
                        1 => ['file', $logFile, 'a'],
                        2 => ['file', $logFile, 'a'],
                    ];

                    $process = proc_open($cmd, $descriptors, $pipes, $cwd);
                    if (is_resource($process)) {
                        if (isset($pipes[0]) && is_resource($pipes[0])) {
                            fclose($pipes[0]);
                        }
                        if (isset($pipes[1]) && is_resource($pipes[1])) {
                            fclose($pipes[1]);
                        }
                        if (isset($pipes[2]) && is_resource($pipes[2])) {
                            fclose($pipes[2]);
                        }

                        $info['process'] = $process;
                        $info['start_time'] = time();
                        $info['log_file'] = $logFile;
                        echo sprintf("[INFO] Worker %d 重启成功，PID: %d\n", $workerId, proc_get_status($process)['pid']);
                    } else {
                        echo sprintf("[ERROR] Worker %d 重启失败\n", $workerId);
                    }
                    continue;
                }

                // 检查进程状态（非阻塞）
                $status = proc_get_status($info['process']);
                if (!$status['running']) {
                    proc_close($info['process']);
                    $info['process'] = null;
                    echo sprintf("[WARN] Worker %d 进程已停止\n", $workerId);
                }
            }

            // 移除已停止的进程
            $processes = array_filter($processes, function ($info) {
                return is_resource($info['process']) || $info['restart_count'] < 10;
            });

            if (empty($processes)) {
                echo "[ERROR] 所有 Worker 已停止，退出\n";
                break;
            }

            sleep(2);
        }
    }
}


if (!function_exists('startWithPcntl')) {
    /**
     * Linux 多进程启动（使用 pcntl_fork）
     */
    function startWithPcntl($server)
    {
        $workerCount = WORKER_COUNT;
        $pids = [];
        $copyPortStart = COPY_PORT_START;
        $baseFlvPort = BASE_FLV_PORT;

        echo sprintf(
            "[INFO] 启动多进程模式，进程数: %d，对外端口: %d，复制流端口: %d-%d\n",
            $workerCount,
            $baseFlvPort,
            $copyPortStart,
            $copyPortStart + $workerCount - 1
        );

        // 获取当前进程ID作为进程组ID
        $masterPid = posix_getpid();

        // ============ 父进程信号处理 ============
        pcntl_signal(SIGTERM, function () use ($masterPid) {
            echo "[INFO] 收到终止信号，正在停止所有进程...\n";
            // 向整个进程组发送 SIGTERM
            posix_kill(0, SIGTERM);
            // 等待2秒让子进程有机会退出
            sleep(2);
            // 强制杀死所有子进程
            posix_kill(0, SIGKILL);
            exit(0);
        });

        pcntl_signal(SIGINT, function () use ($masterPid) {
            echo "\n[INFO] 收到中断信号 (Ctrl+C)，正在停止所有进程...\n";
            // 向整个进程组发送 SIGTERM
            posix_kill(0, SIGTERM);
            // 等待2秒让子进程有机会退出
            sleep(2);
            // 强制杀死所有子进程
            posix_kill(0, SIGKILL);
            exit(0);
        });

        // ============ 创建子进程 ============
        for ($i = 0; $i < $workerCount; $i++) {
            $pid = pcntl_fork();

            if ($pid == -1) {
                fwrite(STDERR, "错误：创建子进程失败\n");
                exit(1);
            }

            if ($pid == 0) {
                // ============ 子进程 ============
                $workerId = $i + 1;
                $copyPort = $copyPortStart + $i;

                // 设置进程组为父进程的进程组
                posix_setpgid(0, $masterPid);

                // 设置环境变量
                putenv("WORKER_ID={$workerId}");
                putenv("WORKER_COUNT={$workerCount}");
                putenv("COPY_PORT={$copyPort}");
                putenv("COPY_PORT_START={$copyPortStart}");
                putenv("IS_WORKER=true");
                putenv("ENABLE_MULTI_PROCESS=true");

                // 定义常量
                define('WORKER_ID', $workerId);
                define('COPY_PORT', $copyPort);
                define('IS_WORKER', true);

                // 重新创建服务实例
                $workerServer = \Root\Io\RtmpDemo::instance();
                $workerServer->rtmpPort = 1935;
                $workerServer->flvPort = $baseFlvPort;
                $workerServer->webPort = 80;

                \Root\Io\RtmpDemo::setCopyPort($copyPort);
                \Root\Io\RtmpDemo::setWorkerId($workerId);
                \Root\Io\RtmpDemo::setWorkerCount($workerCount);
                \Root\Io\RtmpDemo::setIsWorker(true);

                echo sprintf(
                    "[INFO] Worker %d 启动，PID: %d，复制流端口: %d\n",
                    $workerId,
                    getmypid(),
                    $copyPort
                );

                // 启动服务（完全阻塞）
                $workerServer->start();

                exit(0);
            }

            // 父进程记录子进程 PID
            $pids[] = $pid;
        }

        // ============ 父进程：监控子进程状态 ============
        echo "[INFO] 所有子进程已启动，父进程 PID: " . getmypid() . "\n";
        echo "[INFO] 按 Ctrl+C 停止所有进程\n";

        while (true) {
            // 处理信号
            pcntl_signal_dispatch();

            $status = 0;
            $deadPid = pcntl_wait($status, WNOHANG);

            if ($deadPid > 0) {
                // 子进程退出，查找是哪个 Worker
                $index = array_search($deadPid, $pids);
                if ($index !== false) {
                    $workerId = $index + 1;
                    echo sprintf(
                        "[WARN] Worker %d (PID: %d) 已退出，正在重启...\n",
                        $workerId,
                        $deadPid
                    );

                    // 移除退出的 PID
                    unset($pids[$index]);
                    $pids = array_values($pids);

                    // 重新启动该 Worker
                    $newPid = pcntl_fork();
                    if ($newPid == -1) {
                        fwrite(STDERR, "错误：重启 Worker 失败\n");
                        continue;
                    }

                    if ($newPid == 0) {
                        // ============ 新的子进程（重启） ============
                        $workerId = $index + 1;
                        $copyPort = COPY_PORT_START + $index;
                        $copyPortStart = COPY_PORT_START;

                        // 设置进程组为父进程的进程组
                        posix_setpgid(0, $masterPid);

                        putenv("WORKER_ID={$workerId}");
                        putenv("WORKER_COUNT={$workerCount}");
                        putenv("COPY_PORT={$copyPort}");
                        putenv("COPY_PORT_START={$copyPortStart}");
                        putenv("IS_WORKER=true");
                        putenv("ENABLE_MULTI_PROCESS=true");

                        define('WORKER_ID', $workerId);
                        define('COPY_PORT', $copyPort);
                        define('IS_WORKER', true);

                        $workerServer = \Root\Io\RtmpDemo::instance();
                        $workerServer->rtmpPort = 1935;
                        $workerServer->flvPort = BASE_FLV_PORT;
                        $workerServer->webPort = 80;

                        \Root\Io\RtmpDemo::setCopyPort($copyPort);
                        \Root\Io\RtmpDemo::setWorkerId($workerId);
                        \Root\Io\RtmpDemo::setWorkerCount($workerCount);
                        \Root\Io\RtmpDemo::setIsWorker(true);

                        echo sprintf(
                            "[INFO] Worker %d 重启成功，PID: %d，复制流端口: %d\n",
                            $workerId,
                            getmypid(),
                            $copyPort
                        );

                        $workerServer->start();
                        exit(0);
                    }

                    // 记录新的 PID
                    $pids[$index] = $newPid;
                    echo sprintf(
                        "[INFO] Worker %d 已重启，新 PID: %d\n",
                        $workerId,
                        $newPid
                    );
                }
            }

            usleep(100000); // 100ms
        }
    }
}


