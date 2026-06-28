<?php

// 检查PHP版本是否小于8.1
if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    fwrite(STDERR, "错误：此脚本需要PHP 8.1或更高版本，当前版本为 " . PHP_VERSION . "\n");
    exit(1);
}

require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '2048M');

/** 是否开启hls协议 false表示关闭，true表示开启 */
define('FLV_TO_HLS', true);
/** 是否录屏mp4 ， false表示关闭，true表示开启 */
define('FLV_TO_MP4', false);
/** 是否开启flv录屏 ， false表示关闭，true表示开启 */
define('FLV_TO_RECORD', false);

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

    // 信号处理：优雅退出
    pcntl_signal(SIGTERM, function () use (&$pids) {
        echo "[INFO] 收到终止信号，正在停止所有子进程...\n";
        foreach ($pids as $pid) {
            posix_kill($pid, SIGTERM);
        }
        exit(0);
    });

    pcntl_signal(SIGINT, function () use (&$pids) {
        echo "\n[INFO] 收到中断信号，正在停止所有子进程...\n";
        foreach ($pids as $pid) {
            posix_kill($pid, SIGTERM);
        }
        exit(0);
    });

    for ($i = 0; $i < $workerCount; $i++) {
        $pid = pcntl_fork();

        if ($pid == -1) {
            fwrite(STDERR, "错误：创建子进程失败\n");
            exit(1);
        }

        if ($pid == 0) {
            // 子进程
            $workerId = $i + 1;
            $copyPort = $copyPortStart + $i;

            // 设置环境变量，供子进程识别
            putenv("WORKER_ID={$workerId}");
            putenv("WORKER_COUNT={$workerCount}");
            putenv("COPY_PORT={$copyPort}");
            putenv("IS_WORKER=true");

            // 重新创建服务实例（子进程独立）
            $workerServer = \Root\Io\RtmpDemo::instance();
            $workerServer->rtmpPort = 1935;
            $workerServer->flvPort = $baseFlvPort;
            $workerServer->webPort = 80;

            // 设置复制流端口（通过静态属性或方法）
            // 需要在 RtmpDemo 中添加静态方法设置复制端口
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

            // 启动服务
            $workerServer->start();

            exit(0);
        }

        // 父进程记录子进程 PID
        $pids[] = $pid;
    }

    // 父进程：监控子进程状态
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
                    // 新的子进程
                    $workerId = $index + 1;
                    $copyPort = COPY_PORT_START + $index;

                    putenv("WORKER_ID={$workerId}");
                    putenv("WORKER_COUNT={$workerCount}");
                    putenv("COPY_PORT={$copyPort}");
                    putenv("IS_WORKER=true");

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
            }
        }

        usleep(100000); // 100ms
    }
}

/**
 * Windows 多进程启动（使用 proc_open）
 */
/**
 * Windows 多进程启动（使用 proc_open）
 */
function startWithProcOpen($server)
{
    $workerCount = WORKER_COUNT;
    $copyPortStart = COPY_PORT_START;
    $baseFlvPort = BASE_FLV_PORT;

    echo sprintf(
        "[INFO] 启动多进程模式 (Windows)，进程数: %d，对外端口: %d，复制流端口: %d-%d\n",
        $workerCount,
        $baseFlvPort,
        $copyPortStart,
        $copyPortStart + $workerCount - 1
    );

    $processes = [];
    $scriptPath = realpath(__DIR__ . '/worker.php');

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
            $logFile = __DIR__ . "/logs/worker_{$workerId}.log";
            if (!is_dir(__DIR__ . '/logs')) {
                mkdir(__DIR__ . '/logs', 0777, true);
            }
            $descriptors[1] = ['file', $logFile, 'a'];
            $descriptors[2] = ['file', $logFile, 'a'];
        }

        $cwd = __DIR__;

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

                $logFile = __DIR__ . "/logs/worker_{$workerId}.log";
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