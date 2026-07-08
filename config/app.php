<?php
//===================基础服务配置=============================
/** 基础 FLV 端口 */
define('BASE_FLV_PORT', 8501);
/** RTMP端口 */
define('BASE_RTMP_PORT', 1935);
/** WEB端口 */
define('BASE_WEB_PORT', 80);

//===================流媒体转码配置===========================
/** 是否开启hls协议 false表示关闭，true表示开启 */
define('FLV_TO_HLS', true);
/** 是否录屏mp4 ， false表示关闭，true表示开启 */
define('FLV_TO_MP4', false);
/** 是否开启flv录屏 ， false表示关闭，true表示开启 */
define('FLV_TO_RECORD', false);

// ==================== 多进程配置 ============================
/** 是否启用多进程模式 */
define('ENABLE_MULTI_PROCESS', true);
/** 进程数量（建议不超过 CPU 核心数） */
define('WORKER_COUNT',3);
/** 内部复制流端口起始（从 8502 开始） */
define('COPY_PORT_START', 8502);
/** 是否复制流到其他进程 ， 开启多进程则自动开启进程复制，关闭则进程完全独立 */
define('FLV_TO_PUSH', ENABLE_MULTI_PROCESS);
// ==========================================================