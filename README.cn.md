# RTMP Server
<p align="center">
  <a href="./README.cn.md"><strong>🇨🇳 中文</strong></a> •
  <a href="./README.md"><strong>🇬🇧 English</strong></a>
</p>
纯 PHP 编写的轻量级 RTMP 直播服务，**无第三方流媒体服务依赖**，开箱即可快速搭建私有化直播平台。

> Linux 环境下自动启用 epoll 事件驱动，单进程轻松承载 **20,000+** 并发连接；Windows 环境回退 select 模式，保证兼容性。

---

## 目录

- [环境依赖](#环境依赖)
- [快速开始（5 分钟跑通直播）](#快速开始5-分钟跑通直播)
- [推流地址](#推流地址)
- [播放地址](#播放地址)
- [Web 播放页面](#web-播放页面)
- [录制开关配置](#录制开关配置)
- [系统架构](#系统架构)
- [FLV 流媒体网关](#flv-流媒体网关高并发直播分发)
- [静态文件网关](#静态文件网关-filegatewayphp高并发点播资源托管)
- [推流接入教程（详细）](#推流接入教程详细)
- [命令行播放工具（ffplay）](#命令行播放工具ffplay)
- [端口配置](#端口配置)
- [目录结构](#目录结构)
- [并发性能实测](#并发性能实测)
- [相关工具包](#相关工具包)
- [常见问题 FAQ](#常见问题-faq)
- [开源协议 & 免责声明](#开源协议--免责声明)
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

## 快速开始（5 分钟跑通直播）

### 1. 安装

```bash
composer create-project xiaosongshu/rtmp_server
cd rtmp_server
```

### 2. 启动源站服务

```bash
php server.php
```

看到以下输出即表示启动成功：

```
[INFO] RTMP Server started on 0.0.0.0:1935
[INFO] HTTP-FLV/WS-FLV Server started on 0.0.0.0:8501
[INFO] HTTP Static Server started on 0.0.0.0:80
```

### 3. 推流（四选一）

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
- 保存后点击「开始推流」即可。

#### 方式四：PHP 推流

```bash
# 使用静态文件（支持 FLV/MP4）推流
php pusher.php test.mp4 http://127.0.0.1:8501/live/stream
```

### 4. 观看直播

打开 `http://127.0.0.1/index.html` 即可观看。

> 🎉 到此，你已完成一个完整的直播流闭环！

---

## 推流地址

| 协议 | 地址格式 | 示例 |
|------|---------|------|
| RTMP | `rtmp://127.0.0.1:1935/{app}/{stream}` | `rtmp://127.0.0.1:1935/live/stream` |
| HTTP-FLV | `http://127.0.0.1:8501/{app}/{stream}` | `http://127.0.0.1:8501/live/stream` |
| WebSocket-FLV | `ws://127.0.0.1:8501/{app}/{stream}` | `ws://127.0.0.1:8501/live/stream` |

> **说明**：`{app}` 为应用名（如 `live`），`{stream}` 为频道名（如 `stream`），仅支持英文和数字。

---

## 播放地址

### 实时直播

| 协议 | 播放地址 | 说明 |
|------|---------|------|
| RTMP | `rtmp://127.0.0.1:1935/live/stream` | 原生播放器 / ffplay |
| HTTP-FLV | `http://127.0.0.1:8501/live/stream.flv` | 浏览器低延迟播放 |
| WebSocket-FLV | `ws://127.0.0.1:8501/live/stream.flv` | 浏览器原生 WebSocket 支持 |
| HLS | `http://127.0.0.1:80/hls/live/stream/index.m3u8` | 移动端首选 |

### 点播回放（录制完成后）

录制文件位于项目根目录下：

| 文件类型 | 文件路径 |
|---------|---------|
| 合并 MP4 | `mp4/live/stream/output_merge/stream_full.mp4` |
| FLV 录制 | `flv/live/stream/index.flv` |
| HLS 切片 | `hls/live/stream/` |

访问示例：`http://127.0.0.1:80/mp4/live/stream/output_merge/stream_full.mp4`

---

## Web 播放页面

| 页面 | 用途 | 访问地址 |
|------|------|---------|
| `index.html` | FLV 低延迟直播 | `http://127.0.0.1/index.html` |
| `play.html` | HLS 移动端直播 | `http://127.0.0.1/play.html` |
| `mp4.html` | MP4 点播 | `http://127.0.0.1/mp4.html` |
| `video.html` | FLV 点播 | `http://127.0.0.1/video.html` |
| `play_merge.html` | fMP4 分片点播 | `http://127.0.0.1/play_merge.html` |

### Web 推流页面

| 页面 | 用途 | 访问地址 |
|------|------|---------|
| `push.html` | 屏幕共享推流 | `http://127.0.0.1/push.html` |
| `flv_push.html` | 本地 FLV/MP4 伪直播推流 | `http://127.0.0.1/flv_push.html` |

### PHP 推流客户端

| 脚本 | 用途 | 命令示例 |
|------|------|---------|
| `pusher.php` | 本地 FLV/MP4 伪直播推流 | `php pusher.php video.mp4 http://127.0.0.1:8501/live/stream` |

---

## 录制开关配置

编辑 `server.php`，可独立控制三个录制任务的开关：

```php
define('FLV_TO_RECORD', true);   // 是否实时录制 FLV 原始文件
define('FLV_TO_MP4', true);      // 是否实时生成 fMP4 切片并合并为 MP4
define('FLV_TO_HLS', true);      // 是否实时生成 HLS (TS) 切片
```

> 三个任务独立并行运行，互不阻塞。

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
║  📁 静态服务    源站内置 HTTP 服务(80 端口)，可直接提供静态文件访问（适用于低并发场景）    ║
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
    - **fMP4 切片**：实时生成音视频 fMP4 分片（支持混合切片和分离切片两种格式），直播结束后自动合并为完整 MP4。
    - **HLS 切片**：实时生成 TS 分片 + m3u8 索引，兼容移动端播放。
    - **独立开关**：用户可在 `server.php` 中分别配置是否开启各录制任务。

- **FLV 直播网关集群**：纯流量转发服务，向上拉取 HTTP-FLV 流，缓存 GOP 关键帧实现播放秒开，向下分发至终端客户端或下级网关。
    - 支持无限层级级联：一级网关 → 二级网关 → 三级网关 → ... → 客户端。
    - 支持横向扩容：同层级部署多个网关实例，通过负载均衡分发流量。
    - Linux epoll 高性能：单进程可承载 20,000+ 并发连接；Windows 兼容 select 模型。

- **静态文件网关集群（推荐）**：轻量级 HTTP 静态文件服务器，统一托管所有静态资源。
    - **适用协议**：HLS（.m3u8/.ts）、fMP4（.m4s/.mp4）、MP4 点播文件、FLV 录制文件、Web 播放页面。
    - 支持横向扩容：同层级部署多个网关实例，线性提升并发能力。
    - 支持纵向扩容：可通过 Nginx 等反向代理对静态文件网关进行多层级流量分发。
    - Linux epoll 高性能：单进程可承载 20,000+ 并发连接；Windows 兼容 select 模型。
    - **最佳实践**：将 HLS/fMP4/MP4 播放路径指向此网关集群，实现静态资源读写分离。

- **部署建议**：

| 并发场景 | 部署方案 |
|---------|---------|
| 低并发（< 500） | 直接使用源站内置 HTTP 服务，无需额外网关 |
| 中等并发（500 – 5,000） | 源站 + 单层网关集群（FLV 网关 或 静态文件网关） |
| 高并发（> 5,000） | 源站 + FLV 网关多级集群 + 静态文件网关多级集群 |

---

## FLV 流媒体网关（高并发直播分发）

### 网关简介

轻量化流量分发组件，支持无限层级级联部署。从上游源站/上级网关拉取 HTTP-FLV，缓存流头与 GOP 关键帧，新用户接入秒开，并复制流数据下发客户端或子网关。**专为中高并发直播场景设计**，支持横向与纵向扩容。

### 启动命令

```bash
# 基本启动（拉取源站流）
php flvGateway.php 8080 http://源站IP:8501

# 【横向扩容】同层多实例
php flvGateway.php 8080 http://源站IP:8501
php flvGateway.php 8081 http://源站IP:8501
php flvGateway.php 8082 http://源站IP:8501

# 【纵向扩容】多级级联
php flvGateway.php 8080 http://源站IP:8501        # 一级网关
php flvGateway.php 8081 http://127.0.0.1:8080     # 二级网关（拉取一级）
php flvGateway.php 8082 http://127.0.0.1:8081     # 三级网关（拉取二级）

# Linux/macOS 后台运行
php flvGateway.php 8080 http://源站IP:8501 > /dev/null 2>&1 &
```

### 播放地址

```
http://网关IP:端口/{应用名}/{频道名}.flv
```

示例：`http://127.0.0.1:8080/live/stream.flv`

---

## 静态文件网关 `fileGateway.php`（高并发点播资源托管）

### 网关简介

轻量级 HTTP 静态文件服务器，统一托管所有静态资源。**对于 HLS、fMP4、MP4 等基于文件的协议，这是推荐的播放方式**。支持横向与纵向扩容，可支撑大规模点播并发。

### 启动命令

```bash
# 基本启动（托管当前目录，端口 8100）
php fileGateway.php 0.0.0.0 8100

# 【横向扩容】多实例部署
php fileGateway.php 0.0.0.0 8100
php fileGateway.php 0.0.0.0 8101
php fileGateway.php 0.0.0.0 8102

# Linux/macOS 后台运行
php fileGateway.php 0.0.0.0 8100 > /dev/null 2>&1 &
```

### Nginx 反向代理配置示例

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

## 推流接入教程（详细）

### 一、RTMP 推流

#### 地址格式

```
rtmp://127.0.0.1:1935/{应用名}/{频道名}
```

#### 推流示例

**OBS Studio：**

- 服务器：`rtmp://127.0.0.1:1935/live`
- 串流密钥：`stream`

**FFmpeg：**

```bash
ffmpeg -re -stream_loop -1 -i video.mp4 -c:v libx264 -c:a aac -f flv rtmp://127.0.0.1:1935/live/stream
```

**php client**
```bash
# 推流 FLV 文件（rtmp协议）
php pusher.php test.flv rtmp://127.0.0.1:1935/live/stream

# 推流 MP4 文件自动转 FLV 格式推送 (rtmp协议)
php pusher.php video.mp4 rtmp://127.0.0.1:1935/live/stream
```

### 二、HTTP-FLV 推流

#### 地址格式

```
http://127.0.0.1:8501/{应用名}/{频道名}
```

#### PHP 客户端推流

```bash
# 循环推流 FLV 文件（默认）
php pusher.php test.flv http://127.0.0.1:8501/live/stream

# 循环推流 MP4 文件（自动转 FLV 格式推送）
php pusher.php video.mp4 http://127.0.0.1:8501/live/stream

# 2 倍速推流
php pusher.php test.flv http://127.0.0.1:8501/live/stream 2.0

# 推流一次后不重连
php pusher.php test.flv http://127.0.0.1:8501/live/stream 1.0 --no-reconnect
```

#### FFmpeg 推流

```bash
ffmpeg -re -stream_loop -1 -i video.mp4 -c:v libx264 -c:a aac -f flv http://127.0.0.1:8501/live/stream
```

### 三、WebSocket-FLV 推流

#### 地址格式

```
ws://127.0.0.1:8501/{应用名}/{频道名}
```

#### 浏览器推流（推荐）

打开 `http://127.0.0.1/push.html` 或 `http://127.0.0.1/flv_push.html` 即可。

#### PHP 客户端推流

```bash
# 推流 FLV 文件（默认）
php pusher.php test.flv ws://127.0.0.1:8501/live/stream

# 推流 MP4 文件（自动转 FLV 格式推送）
php pusher.php video.mp4 ws://127.0.0.1:8501/live/stream

# 2 倍速推流
php pusher.php test.flv ws://127.0.0.1:8501/live/stream 2.0

# 推流一次后不重连
php pusher.php test.flv ws://127.0.0.1:8501/live/stream 1.0 --no-reconnect

# 推流 FLV 文件（rtmp协议）
php pusher.php test.flv rtmp://127.0.0.1:1935/live/stream

# 推流 MP4 文件自动转 FLV 格式推送 (rtmp协议)
php pusher.php video.mp4 rtmp://127.0.0.1:1935/live/stream
```

#### FFmpeg 推流

```bash
ffmpeg -re -i video.mp4 -c:v libx264 -c:a aac -f flv - | websocat -b ws://127.0.0.1:8501/live/stream
```

---

## 命令行播放工具（ffplay）

```bash
# RTMP 流
ffplay rtmp://127.0.0.1:1935/live/stream

# HTTP-FLV 流
ffplay http://127.0.0.1:8501/live/stream.flv

# WebSocket-FLV 流
ffplay ws://127.0.0.1:8501/live/stream.flv

# FLV 网关转发流
ffplay http://127.0.0.1:8080/live/stream.flv

# HLS 流
ffplay http://127.0.0.1:8100/hls/live/stream/index.m3u8

# 点播文件
ffplay http://127.0.0.1:8100/flv/live/stream/index.flv
ffplay http://127.0.0.1:8100/mp4/live/stream/output_merge/stream_full.mp4
```

> 💡 **推荐播放器**：使用 [VLC](https://www.videolan.org/) 进行测试播放，它是一款专业级播放软件，支持各种格式媒体。

---

## 端口配置

编辑 `server.php` 可修改端口：

| 端口 | 协议 | 用途 |
|------|------|------|
| 1935 | RTMP | RTMP 推拉流 |
| 8501 | HTTP / WebSocket | HTTP-FLV / WS-FLV 推拉流 |
| 80 | HTTP | 静态文件服务 + Web 页面 |

---

## 目录结构

```
rtmp_server/
├── flv/                        # FLV 原始录制文件
├── mp4/                        # MP4 / fMP4 转码产物
├── hls/                        # HLS TS 分片 + m3u8 索引
├── MediaServer/                # RTMP 核心协议、推拉流会话逻辑
├── Root/                       # 底层异步 IO、Socket 事件驱动
├── server.php                  # 源站启动入口
├── fileGateway.php             # 静态文件网关
├── flvGateway.php              # FLV 直播网关
├── pusher.php                  # FLV/MP4 推流客户端
├── push.html                   # Web 推流（屏幕共享）
├── flv_push.html               # Web 推流（FLV/MP4 推流页面）
├── *.html                      # Web 播放页面
└── README.md
```

---

## 并发性能实测

> 以下测试均在 **Docker 容器内、`ulimit -n 65535`** 环境下完成，并发 20,000 个客户端，每个客户端持续拉流 5 秒。

| 组件 | 成功连接数 | 失败连接数 | 成功率 |
|------|-----------|-----------|--------|
| 主服务器（源站） | 17,330 | 2,670 | 86.7% |
| FLV 直播网关 | 19,923 | 77 | 99.6% |
| 静态文件网关 | 20,000 | 0 | 100% |

> **说明**：
> - 主服务器因承载三协议推流、多协议转封装等业务，单进程稳定支撑 17,330 并发。
> - FLV 网关专注于纯流转发，成功率 99.6%。
> - 静态文件网关极致轻量，20,000 并发全部成功。
> - **Linux 下自动启用 epoll**，突破 select 的 1024 限制。

---

## 相关工具包

协议转换独立工具包：[xiaosongshu/flv2mp4](https://github.com/2723659854/flv2mp4)

支持 FLV、MP4、HLS 转码，FLV 网关、静态文件网关，以及 FLV/MP4 静态文件推流客户端（支持ws-flv,http-flv,rtmp协议）。

---

## 常见问题 FAQ

### 1. 单进程为什么能支持 20,000+ 并发？

- **Linux**：检测到 `event` 扩展后**自动启用 epoll**，不再受 select 的 1024 限制。
- **Windows**：回退为 select 模型，建议部署多实例。
- 实测静态文件网关 20,000 并发零失败。

### 2. 什么时候需要部署网关？

| 并发场景 | 部署方案 |
|---------|---------|
| 低并发（< 500） | 仅源站即可 |
| 中等并发（500 – 5,000） | 源站 + 单层网关（1–2 个实例） |
| 高并发（> 5,000） | 源站 + 多级网关集群 |

### 3. FLV 网关和静态文件网关的区别？

| 网关 | 用途 | 处理的资源 |
|------|------|-----------|
| FLV 直播网关 | 直播流分发 | HTTP-FLV 实时流 |
| 静态文件网关 | 静态资源托管 | HLS / fMP4 / MP4 / FLV + Web 页面 |

### 4. WebSocket-FLV 推流有什么优势？

- 浏览器原生支持，无需安装软件。
- 可通过内置测试页面快速测试。
- 支持手机浏览器摄像头推流（需 HTTPS）。

---

## 开源协议 & 免责声明

- 本项目仅限学习、技术研究使用；商用落地风险由使用者自行承担。
- 部分开源代码取自开源社区，如涉及版权问题，可联系作者删除。
- 项目完全开源免费，仅用于技术交流。
- 用户因任何商用或违法使用造成的法律后果，作者不承担连带责任。

---

## 联系方式

- 📬 Email：`2723659854@qq.com`
- 🐙 GitHub：[2723659854](https://github.com/2723659854)

