# RTMP Server

<p align="center">
  <a href="./README.cn.md"><strong>🇨🇳 中文</strong></a> •
  <a href="./README.md"><strong>🇬🇧 English</strong></a>
</p>

> 纯 PHP 编写的轻量级 RTMP 直播服务，**无第三方流媒体服务依赖**，开箱即可快速搭建私有化直播平台。

> Linux 环境下自动启用 epoll 事件驱动；Windows 环境回退 select 模式，保证兼容性。

> 本项目是一个基础设施层，一套生产级的 RTMP 流媒体协议栈和异步网络通信引擎，需要用户自己搭建上层应用。
---

## 目录

- [环境依赖](#环境依赖)
- [快速开始](#快速开始)
- [推流地址](#推流地址)
- [播放地址](#播放地址)
- [Web 播放页面](#web-播放页面)
- [目录结构](#目录结构)
- [系统架构](#系统架构)
- [端口配置](#端口配置)
- [录制开关配置](#录制开关配置)
- [推流鉴权](#推流鉴权)
- [FLV 流媒体网关](#flv-流媒体网关)
- [静态文件网关](#静态文件网关)
- [推流接入教程](#推流接入教程)
- [常见问题 FAQ](#常见问题-faq)
- [开源协议](#开源协议)
- [联系方式](#联系方式)

---

## 环境依赖

| 依赖项 | 说明 |
|--------|------|
| PHP | >= 8.1（仅 CLI 命令行模式运行） |
| `sockets` 扩展 | **必需**，提供底层 Socket 通信能力 |
| `event` 扩展 | **强烈推荐**，Linux 下大幅提升并发性能，自动启用 epoll 模式 |

> 💡 本项目提供 Docker 快速搭建环境，执行 `docker-compose up -d` 即可一键启动。

---

## 快速开始

### 安装

```bash
composer create-project xiaosongshu/rtmp_server
cd rtmp_server
```

### 启动源站服务

```bash
php server.php
```

输出示例：

```
[INFO] RTMP Server started on 0.0.0.0:1935
[INFO] HTTP-FLV/WS-FLV Server started on 0.0.0.0:8501
[INFO] HTTP Static Server started on 0.0.0.0:80
```

### 推流

#### 方式一：浏览器推流（无需安装软件）

- 打开 `http://127.0.0.1/push.html`，点击「开始推流」即可。
- 或打开 `http://127.0.0.1/flv_push.html`，选择 MP4/FLV 静态文件，点击「开始推流」即可。

#### 方式二：FFmpeg 推流

```bash
ffmpeg -re -stream_loop -1 -i video.mp4 -c:v libx264 -c:a aac -f flv rtmp://127.0.0.1:1935/live/stream
```

#### 方式三：OBS 推流

- 服务器地址：`rtmp://127.0.0.1:1935/live/`
- 串流密钥：`stream`

#### 方式四：PHP 推流

```bash
php pusher.php test.mp4 http://127.0.0.1:8501/live/stream
```

### 观看直播

打开 `http://127.0.0.1/index.html` 即可观看。

---

## 推流地址

| 协议 | 地址格式 | 示例 |
|------|---------|------|
| RTMP | `rtmp://host:1935/{app}/{stream}` | `rtmp://127.0.0.1:1935/live/stream` |
| HTTP-FLV | `http://host:8501/{app}/{stream}` | `http://127.0.0.1:8501/live/stream` |
| WebSocket-FLV | `ws://host:8501/{app}/{stream}` | `ws://127.0.0.1:8501/live/stream` |

> **说明**：`{app}` 为应用名，`{stream}` 为频道名，仅支持英文和数字。

---

## 播放地址

### 实时直播

| 协议 | 播放地址 | 说明 |
|------|---------|------|
| RTMP | `rtmp://127.0.0.1:1935/live/stream` | 原生播放器 / ffplay |
| HTTP-FLV | `http://127.0.0.1:8501/live/stream.flv` | 浏览器低延迟播放 |
| WebSocket-FLV | `ws://127.0.0.1:8501/live/stream.flv` | 浏览器原生 WebSocket 支持 |
| HLS | `http://127.0.0.1:80/hls/live/stream/index.m3u8` | 移动端首选 |

### 点播回放

录制文件位于项目根目录下：

| 文件类型 | 文件路径 |
|---------|---------|
| 合并 MP4 | `mp4/live/stream/output_merge/stream_full.mp4` |
| FLV 录制 | `flv/live/stream/index.flv` |
| HLS 切片 | `hls/live/stream/` |

访问示例：`http://127.0.0.1:80/mp4/live/stream/output_merge/stream_full.mp4`

---

## Web 播放页面

### 播放页面

| 页面 | 用途 | 访问地址 |
|------|------|---------|
| `index.html` | FLV 低延迟直播 | `http://127.0.0.1/index.html` |
| `play.html` | HLS 移动端直播 | `http://127.0.0.1/play.html` |
| `mp4.html` | MP4 点播 | `http://127.0.0.1/mp4.html` |
| `video.html` | FLV 点播 | `http://127.0.0.1/video.html` |
| `play_merge.html` | fMP4 分片点播 | `http://127.0.0.1/play_merge.html` |

### 推流页面

| 页面                    | 用途                        | 访问地址 |
|-----------------------|---------------------------|---------|
| `push.html`           | 屏幕共享推流                    | `http://127.0.0.1/push.html` |
| `flv_push.html`       | 本地 FLV/MP4 推流             | `http://127.0.0.1/flv_push.html` |
| `push_merge.html`     | 多路直播合并推流                  | `http://127.0.0.1/push_merge.html` |
| `push_transcode.html` | 将直播转码为其他码率并推流，适配客户端不同网络环境 | `http://127.0.0.1/push_transcode.html` |

### PHP 客户端

| 脚本 | 用途 | 命令示例 |
|------|------|---------|
| `pusher.php` | 推流客户端 | `php pusher.php video.mp4 http://127.0.0.1:8501/live/stream` |
| `puller.php` | 拉流客户端 | `php puller.php http://127.0.0.1:8501/live/stream.flv output.flv` |

---

## 目录结构

```
rtmp_server/
├── config/                     # 项目配置文件，包含权限配置和应用配置
├── flv/                        # FLV 原始录制文件
├── mp4/                        # MP4 / fMP4 转码产物
├── hls/                        # HLS TS 分片 + m3u8 索引
├── MediaServer/                # RTMP 核心协议、推拉流会话逻辑
├── record/                     # 拉流客户端静态文件存放目录
├── Root/                       # 底层异步 IO、Socket 事件驱动
├── server.php                  # 源站启动入口
├── fileGateway.php             # 静态文件网关
├── flvGateway.php              # FLV直播网关（支持ws-flv/http-flv）
├── puller.php                  # 拉流客户端
├── pusher.php                  # 推流客户端
├── push.html                   # Web 推流（屏幕共享）
├── push_merge.html             # Web 多路直播合并推流
├── push_transcode.html         # Web 直播转码推流（多种码率，自由选择）
├── flv_push.html               # Web 推流（文件）
├── auth_config.php             # 推流鉴权配置
└── *.html                      # Web 播放页面
```

---

## 系统架构

```
                                                    【推流端】OBS / FFmpeg
                                                         │
                                       RTMP 推流(1935)  /  HTTP-FLV / WS-FLV 推流(8501)
                                                         │
                                                         ▼
╔══════════════════════════════════════════════════════════════════════════════════════╗
║                              RTMP 源站主服务器 (核心)                                  ║
║                                                                                      ║
║  📥 推流接入    RTMP / HTTP-FLV / WebSocket-FLV 三协议推流、链接认证                    ║
║  🔄 协议转换    RTMP / HTTP-FLV / WS-FLV → HTTP-FLV / WebSocket-FLV / HLS / fMP4 / MP4 ║
║  💾 实时录制    ┌──────────┬──────────┬──────────┐                                    ║
║                │ FLV 录制  │ fMP4 切片 │ HLS 切片  │  三个独立并行任务                   ║
║                │ (实时裸流) │ (实时分片) │ (实时分片) │                                  ║
║                └──────────┴──────────┴──────────┘                                    ║
║  📤 直播输出    HTTP-FLV(8501) / WebSocket-FLV / HLS 实时流 / fMP4 实时流              ║
║  📦 点播产出    fMP4 切片实时生成 → 直播结束后自动合并为完整 MP4                         ║
║  📁 静态服务    源站内置 HTTP 服务(80 端口)，可直接提供静态文件访问                       ║
╚══════════════════════════════════════════════════════════════════════════════════════╝
│
┌───────────────────┼───────────────────┐
│                   │                   │
▼                   ▼                   ▼
HTTP-FLV(8501)     HLS(TS/m3u8)       fMP4(切片)
实时流输出          静态文件             静态文件
│                   │                   │
│                   │                   │
▼                   ▼                   ▼
┌─────────────┐    ┌──────────────────────────────────────────┐
│ FLV 网关集群 │    │          静态文件网关集群 (fileGateway)     │
│             │    │    🎯 托管：HLS / fMP4 / MP4 / FLV / Web 页面 │
│ ┌─────────┐ │    │                                          │
│ │ 一级网关 │ │    │ ┌───────┐ ┌───────┐ ┌───────┐           │
│ │ (8080)  │ │    │ │网关 1 │ │网关 2 │ │网关 3 │           │
│ └───┬─────┘ │    │ │(8100) │ │(8101) │ │(8102) │           │
│     │       │    │ └──┬────┘ └──┬────┘ └──┬────┘           │
│ ┌───┴───┐   │    │    │        │        │                 │
│ ▼   ▼   ▼   │    │    ▼        ▼        ▼                 │
│ ┌─┐ ┌─┐ ┌─┐ │    │ ┌──────────────────────────────────┐   │
│ │子│ │子│ │子│ │    │ │         客户端 (Client)           │   │
│ │网│ │网│ │网│ │    │ │ HLS 播放器 / MSE / 点播 / ffplay │   │
│ │关│ │关│ │关│ │    │ └──────────────────────────────────┘   │
│ └┬─┘ └┬─┘ └┬─┘ │    │                                          │
│  │    │    │   │    └──────────────────────────────────────────┘
│  ▼    ▼    ▼   │
│ ┌────────────┐ │
│ │   客户端    │ │
│ │ FLV / ffplay│ │
│ └────────────┘ │
└─────────────────┘
```

### 架构说明

- **源站服务**：唯一流生产节点，支持 **RTMP、HTTP-FLV、WebSocket-FLV 三协议推流**，负责推拉流接入及多协议转封装。**FLV 录制、fMP4 切片、HLS 切片三个任务完全独立并行运行**，互不阻塞。

- **源站静态能力**：源站内置 HTTP 服务（默认 80 端口），可直接提供静态文件访问。**低并发场景下无需额外部署网关**，开箱即用。

- **实时录制机制**：
  - **FLV 录制**：实时保存原始裸流，直播结束后得到完整 FLV 文件。
  - **fMP4 切片**：实时生成音视频 fMP4 分片，直播结束后自动合并为完整 MP4。
  - **HLS 切片**：实时生成 TS 分片 + m3u8 索引，兼容移动端播放。
  - **独立开关**：用户可在 `server.php` 中分别配置是否开启各录制任务。

- **FLV 直播网关集群**：纯流量转发服务，向上拉取 HTTP-FLV/WS-FLV 流，缓存 GOP 关键帧实现播放秒开。
  - 支持多层级级联：一级网关 → 二级网关 → 三级网关 → ... → 客户端(建议一级网关，最多两级网关，层级越多延迟越高越卡顿)。
  - 支持横向扩容：同层级部署多个网关实例，通过负载均衡分发流量（建议横向扩容）。
  - Linux epoll 高性能：单进程可承载 20,000+ 并发连接；Windows 兼容 select 模型（此处仅为实验室场景测试数据，实际情况根据具体服务器配置而定）。
  - **作者建议**：生产环境高并发场景，所有的拉流建议都使用网关，降低主服务压力。

- **静态文件网关集群**：轻量级 HTTP 静态文件服务器，统一托管所有静态资源。
  - **适用协议**：HLS（.m3u8/.ts）、fMP4（.m4s/.mp4）、MP4 点播文件、FLV 录制文件、Web 播放页面。
  - 支持横向与纵向扩容，可支撑大规模点播并发。
  - **最佳实践**：将 HLS/fMP4/MP4 播放路径指向此网关集群，实现静态资源读写分离。
  - **作者建议**：生产环境高并发场景，静态文件访问统一使用静态文件网关，降低主服务压力。

### 部署建议

| 并发场景               | 部署方案 |
|--------------------|---------|
| 低并发（< 1000）        | 直接使用源站内置 HTTP 服务，无需额外网关 |
| 中等并发（1000 – 5,000） | 源站 + 单层网关集群 |
| 高并发（> 5,000）       | 源站 + FLV 网关多级集群 + 静态文件网关多级集群 |

---

## 端口配置

编辑 `config/app.php` 可修改端口：

| 端口 | 协议 | 用途 |
|------|------|------|
| 1935 | RTMP | RTMP 推拉流 |
| 8501 | HTTP / WebSocket | HTTP-FLV / WS-FLV 推拉流 |
| 80 | HTTP | 静态文件服务 + Web 页面 |
```php
/** 基础 FLV 端口 */
define('BASE_FLV_PORT', 8501);
/** RTMP端口 */
define('BASE_RTMP_PORT', 1935);
/** WEB端口 */
define('BASE_WEB_PORT', 80);
```
---

## 录制开关配置

编辑 `config/app.php`，可独立控制三个录制任务的开关：

```php
define('FLV_TO_RECORD', true);   // 是否实时录制 FLV 原始文件
define('FLV_TO_MP4', true);      // 是否实时生成 fMP4 切片并合并为 MP4
define('FLV_TO_HLS', true);      // 是否实时生成 HLS (TS) 切片
```

> 三个任务独立并行运行，互不阻塞。

## 开启多进程服务
为了提升并发能力，本项目支持多进程运行，因为PHP存在进程隔离，所以使用php自研客户端同步直播流到不同进程，需要配置进程间通信端口，`config/app.php`详细配置如下：
```php
/** 是否启用多进程模式 */
define('ENABLE_MULTI_PROCESS', true);
/** 进程数量（建议不超过 CPU 核心数） */
define('WORKER_COUNT',3);
/** 进程内部通信端口起始（从 8502 开始） */
define('COPY_PORT_START', 8502);
```

因为`Windows`环境严格意义上来说不支持端口复用，导致`8501`端口被第一个进程独占，那么多进程就无法自动实现负载均衡，本项目保留了进程通信端口对外服务能力，
所以可以使用`Nginx`负载均衡来分配进程提高并发能力。但是即便如此，Windows系统受到文件句柄限制，单进程最大也只能处理大约256个并发，所以作者建议
Windows环境作为测试即可，正式环境还请使用Linux系统。

---

## 推流鉴权

### 概述

为防止未经授权的推流覆盖您的直播，服务器使用 **Stream Key** 鉴权。只有携带有效 Stream Key 的推流请求才会被允许。

### 配置

编辑 `config/auth.php` 配置鉴权：

```php
<?php
return [
    'enabled' => true,
    
    'publish' => [
        'require_auth' => true,
        'stream_keys' => [
            'live_123456',
            'stream_key_abc',
        ],
    ],
    
    'global' => [
        'allowed_apps' => ['live'],
        'deny_apps' => [],
    ],
];
```

### 使用鉴权推流

在推流 URL 中使用 `key` 参数：

**RTMP 推流：**

```bash
ffmpeg -re -stream_loop -1 -i video.mp4 -c:v libx264 -c:a aac -f flv \
  rtmp://127.0.0.1:1935/live/stream?key=live_123456
```

**OBS：**

- 服务器地址：`rtmp://127.0.0.1:1935/live/`
- 串流密钥：`stream?key=live_123456`

**HTTP-FLV 推流：**

```bash
ffmpeg -re -stream_loop -1 -i video.mp4 -c:v libx264 -c:a aac -f flv  http://127.0.0.1:8501/live/stream?key=live_123456
```

**WebSocket-FLV 推流：**

```bash
php pusher.php test.flv "ws://127.0.0.1:8501/live/stream?key=live_123456"
```

> **注意**：拉流/播放不需要鉴权。

### 安全最佳实践

1. **修改默认密钥**：务必将默认的 `stream_keys` 替换为强随机字符串
2. **使用 HTTPS**：公网环境使用 HTTPS 传输，防止凭证被截获
3. **定期轮换密钥**：定期更新 `stream_keys`

---

## FLV 流媒体网关

### 简介

轻量化流量分发组件，支持无限层级级联部署。从上游源站/上级网关拉取 HTTP-FLV/WS-FLV，缓存流头与 GOP 关键帧，对外提供HTTP-FLV/WS-FLV服务，新用户接入秒开，并复制流数据下发客户端或子网关。**专为中高并发直播场景设计**，支持横向与纵向扩容。

### 启动命令

```bash
# 基本启动
php flvGateway.php 8080 http://源站IP:8501
php flvGateway.php 8080 ws://源站IP:8501

# 横向扩容：同层多实例
php flvGateway.php 8080 http://源站IP:8501
php flvGateway.php 8081 http://源站IP:8501
php flvGateway.php 8082 ws://源站IP:8501

# 纵向扩容：多级级联
php flvGateway.php 8080 http://源站IP:8501        # 一级网关
php flvGateway.php 8081 http://127.0.0.1:8080     # 二级网关
php flvGateway.php 8082 ws://127.0.0.1:8081     # 三级网关

# Linux/macOS 后台运行
php flvGateway.php 8080 http://源站IP:8501 > /dev/null 2>&1 &
```
理论上网关可以无限嵌套，但是作者不建议这么操作，因为层级越深，延迟越高，越卡顿，理论上一层网关就够了。
### 播放地址

```
http://网关IP:端口/{应用名}/{频道名}.flv
ws://网关IP:端口/{应用名}/{频道名}.flv
```

示例：`http://127.0.0.1:8080/live/stream.flv` 和 `ws://127.0.0.1:8080/live/stream.flv`

---

## 静态文件网关

### 简介

轻量级 HTTP 静态文件服务器，统一托管所有静态资源。**对于 HLS、fMP4、MP4 等基于文件的协议，这是推荐的播放方式**。支持横向与纵向扩容，可支撑大规模点播并发。

### 启动命令

```bash
# 基本启动
php fileGateway.php 0.0.0.0 8100

# 横向扩容：多实例部署
php fileGateway.php 0.0.0.0 8100
php fileGateway.php 0.0.0.0 8101
php fileGateway.php 0.0.0.0 8102

# Linux/macOS 后台运行
php fileGateway.php 0.0.0.0 8100 > /dev/null 2>&1 &
```

### Nginx 反向代理配置

```nginx
upstream filegateway_cluster {
    server 127.0.0.1:8100;
    server 127.0.0.1:8101;
    server 127.0.0.1:8102;
}

server {
    listen 80;
    server_name media.example.com;

    location ~* \.(m3u8|ts|mp4|m4s|flv|html|css|js)$ {
        proxy_pass http://filegateway_cluster;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
    }
}
```

### 访问地址

```
http://网关IP:端口/{文件相对路径}
```

示例：

```
http://127.0.0.1:8100/index.html
http://127.0.0.1:8100/hls/live/stream/index.m3u8
http://127.0.0.1:8100/mp4/live/stream/output_merge/stream_full.mp4
```

---

## 推流接入教程

### RTMP 推流

**地址格式**：`rtmp://127.0.0.1:1935/{应用名}/{频道名}`

**OBS Studio**：
- 服务器：`rtmp://127.0.0.1:1935/live`
- 串流密钥：`stream`

**FFmpeg**：

```bash
ffmpeg -re -stream_loop -1 -i video.mp4 -c:v libx264 -c:a aac -f flv rtmp://127.0.0.1:1935/live/stream
```

**PHP Client**：

```bash
php pusher.php test.flv rtmp://127.0.0.1:1935/live/stream
php pusher.php video.mp4 rtmp://127.0.0.1:1935/live/stream
```

### HTTP-FLV 推流

**地址格式**：`http://127.0.0.1:8501/{应用名}/{频道名}`

**PHP 客户端**：

```bash
php pusher.php test.flv http://127.0.0.1:8501/live/stream
php pusher.php video.mp4 http://127.0.0.1:8501/live/stream
php pusher.php test.flv http://127.0.0.1:8501/live/stream 2.0
php pusher.php test.flv http://127.0.0.1:8501/live/stream 1.0 --no-reconnect
```

**FFmpeg**：

```bash
ffmpeg -re -stream_loop -1 -i video.mp4 -c:v libx264 -c:a aac -f flv http://127.0.0.1:8501/live/stream
```

### WebSocket-FLV 推流

**地址格式**：`ws://127.0.0.1:8501/{应用名}/{频道名}`

**浏览器推流**：打开 `http://127.0.0.1/push.html` 或 `http://127.0.0.1/flv_push.html`

**PHP 客户端**：

```bash
php pusher.php test.flv ws://127.0.0.1:8501/live/stream
```
### 浏览器播放
本项目提供web浏览器直接观看直播，无需下载第三方播放器软件，你可以参考页面
`http://127.0.0.1/index.html`

### 浏览器推流
本项目提供web浏览器直接推流，脱离专业推流软件，无需下载各种第三方推流软件，你可以参考页面
`http://127.0.0.1/push.html`
ps：浏览器使用ws-flv完成推流和拉流，直播延迟可以在50ms以下。
### 合并直播流
本项目使用web前端合并直播流，降低专用硬件芯片和软件的依赖，你可以参考页面
`http://127.0.0.1/push_merge.html`

### 直播转码

本项目使用web前端实现低成本直播转码，页面提供多种组合多种码率，降低专用硬件芯片和软件的依赖，你可以参考
`http://127.0.0.1/push_transcode.html`

---

### PHP 拉流
本项目提供PHP客户端拉流，参考命令如下，
```bash
php puller.php http://127.0.0.1:8501/live/stream.flv output.flv
php puller.php ws://127.0.0.1:8501/live/stream.flv output.flv
```
ps:本项目的php的推流客户端`pusher.php`和拉流客户端`puller.php`配合使用，有助于后端实现自动化工程。本项目可以脱离其他第三方软件，实现直播一体化工程。


## 常见问题 FAQ

### Q1: Windows 下启动失败，提示缺少 `event` 扩展？

Windows 环境不支持 `event` 扩展，服务器会自动回退到 `sockets` 扩展 + select 模型。确保已安装 `sockets` 扩展即可正常运行。

### Q2: 如何查看服务器运行状态？

服务器启动后会输出以下日志：

```
[INFO] RTMP Server started on 0.0.0.0:1935
[INFO] HTTP-FLV/WS-FLV Server started on 0.0.0.0:8501
[INFO] HTTP Static Server started on 0.0.0.0:80
```

### Q3: 推流成功但播放卡顿？

1. 检查网络延迟
2. 降低推流码率或帧率
3. 使用 FLV 网关集群缓存 GOP

### Q4: 如何停止服务器？

直接关闭运行 `php server.php` 的终端窗口，或使用 `Ctrl+C`。

### Q5: 支持哪些推流工具？

支持所有标准 RTMP 推流工具：OBS、FFmpeg、xSplit、移动端推流 SDK 等。

---

## 开源协议

本项目采用 **Apache License** 开源。

本项目的代码按"现状"（AS IS）提供，不提供任何明示或暗示的担保，包括但不限于适销性、特定用途适用性和非侵权性的担保。在任何情况下，作者均不对因使用本软件而产生的任何直接、间接、偶然、特殊、惩罚性或后果性损害承担责任。

详细免责条款请参阅 [LICENSE](LICENSE) 文件。

---

## 工具包
当前项目已将大部分功能分离出单独的工具包`xiaosongshu/flv2mp4`(https://github.com/2723659854/flv2mp4)，支持flv,mp4,fmp4,hls格式转换，提供flv,file网关，提供php推拉流客户端(支持rtmp,http-flv,ws-flv协议)。

## 联系方式

- 📬 Email：`2723659854@qq.com`
- 🐙 GitHub：[2723659854](https://github.com/2723659854)
