@echo off
setlocal enabledelayedexpansion
chcp 65001 >nul

set "url=http://127.0.0.1:8501/a/b.flv"
set "concurrent=50"
set "duration=30"
set "logdir=%cd%\logs"
if not exist "%logdir%" mkdir "%logdir%"

echo 正在启动 %concurrent% 个 ffmpeg 拉流实例（每个测试 %duration% 秒）...
for /l %%i in (1,1,%concurrent%) do (
    start "ffmpeg_%%i" /min cmd /c "ffmpeg -i "%url%" -t %duration% -f null - -vstats 2>"%logdir%\ffmpeg_%%i.log""
    echo 实例 %%i 已启动
)

echo 等待 %duration%+10 秒后检查结果...
timeout /t %duration% >nul
timeout /t 10 >nul

echo.
echo ========== 检查视频帧解码情况 ==========
for /l %%i in (1,1,%concurrent%) do (
    set "logfile=%logdir%\ffmpeg_%%i.log"
    set "frames=0"
    if exist "!logfile!" (
        for /f "tokens=2 delims= " %%a in ('findstr /r /c:"frame= *[0-9]" "!logfile!" ^| findstr /v /c:"Output"') do (
            set "frames=%%a"
        )
        rem 去掉可能存在的首尾空格
        for /f "tokens=* delims=0 " %%a in ("!frames!") do set "frames=%%a"
        if !frames! gtr 0 (
            echo 实例 %%i: 成功 —— 已解码 !frames! 帧
        ) else (
            echo 实例 %%i: 失败 —— 帧数仍为 0
        )
    ) else (
        echo 实例 %%i: 日志文件不存在（启动失败或权限不足）
    )
)

echo.
echo 测试完成！按任意键退出...
pause >nul