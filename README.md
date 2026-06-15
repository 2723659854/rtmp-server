# RTMP Server

<p align="center">
  <a href="./README.cn.md"><strong>🇨🇳 中文</strong></a> •
  <a href="./README.md"><strong>🇬🇧 English</strong></a>
</p>

> A lightweight RTMP live streaming service written purely in PHP, **no third-party streaming media dependencies**, enabling rapid deployment of private live streaming platforms.
> **Linux environment automatically enables epoll event-driven architecture, a single process easily handles 20,000+ concurrent connections; Windows falls back to select mode for compatibility.**

## 🏗️ System Architecture

```
                                                    【Pusher】OBS/FFmpeg
                                                         │
                                       RTMP Push(1935)  /  HTTP-FLV/WS-FLV Push(8501)
                                                         │
                                                         ▼
╔══════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════╗
║                                                      RTMP Origin Server (Core)                                                 ║
║                                                                                                                              ║
║  📥 Ingest        RTMP / HTTP-FLV / WebSocket-FLV three-protocol ingestion, connection authentication                         ║
║  🔄 Transmuxing   RTMP / HTTP-FLV / WS-FLV → HTTP-FLV / WebSocket-FLV / HLS / fMP4 / MP4                                      ║
║  💾 Recording     ┌──────────────┬──────────────┬──────────────┐                                                               ║
║                   │  FLV Record  │  fMP4 Segment│  HLS Segment │  Three independent parallel tasks                           ║
║                   │  (raw stream)│  (live chunk)│  (live chunk)│                                                               ║
║                   └──────────────┴──────────────┴──────────────┘                                                               ║
║  📤 Output        HTTP-FLV(8501) / WebSocket-FLV / HLS live stream / fMP4 live stream                                         ║
║  📦 VOD Output    fMP4 segments generated live → automatically merged into complete MP4 after stream ends                     ║
║  📁 Static Serve  Origin built-in HTTP server (port 80), can directly serve static files (for low concurrency scenarios)      ║
╚══════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════╝
│
┌───────────────────────┼───────────────────────┐
│                       │                       │
▼                       ▼                       ▼
HTTP-FLV(8501)           HLS(TS/m3u8)           fMP4(segments)
live stream              static files           static files
│                       │                       │
│                       │                       │
▼                       ▼                       ▼
┌─────────────────┐     ┌─────────────────────────────────────────────────┐
│  FLV Gateway    │     │           Static File Gateway Cluster (fileGateway)        │
│  Cluster        │     │         🎯 Hosts: HLS / fMP4 / MP4 / FLV / Web pages        │
│                 │     │                                                 │
│  ┌───────────┐  │     │  ┌───────────┐  ┌───────────┐  ┌───────────┐   │
│  │ Level-1   │  │     │  │ Gateway 1 │  │ Gateway 2 │  │ Gateway 3 │   │
│  │ Gateway   │  │     │  │ (8100)    │  │ (8101)    │  │ (8102)    │   │
│  │ (8080)    │  │     │  └─────┬─────┘  └─────┬─────┘  └─────┬─────┘   │
│  └─────┬─────┘  │     │        │              │              │         │
│        │        │     │        ▼              ▼              ▼         │
│  ┌─────┴─────┐  │     │   ┌──────────────────────────────────────┐    │
│  ▼     ▼     ▼  │     │   │           Client                      │    │
│ ┌───┐ ┌───┐ ┌───┐│     │   │   HLS player / MSE player / VOD request / ffplay │
│ │Sub│ │Sub│ │Sub││     │   └──────────────────────────────────────┘    │
│ │GW1│ │GW2│ │GW3││     │                                                 │
│ └─┬─┘ └─┬─┘ └─┬─┘│     └─────────────────────────────────────────────────┘
│   │     │     │  │
│   ▼     ▼     ▼  │
│ ┌──────────────┐ │
│ │   Client     │ │
│ │ FLV Player / ffplay │
│ └──────────────┘ │
└─────────────────┘
```

### Architecture Explanation

- **Origin Server**: The only stream production node. Supports **RTMP, HTTP-FLV, WebSocket-FLV three-protocol ingestion**, responsible for ingest, pull, and multi-protocol remuxing. **FLV recording, fMP4 segmentation, and HLS segmentation are three completely independent parallel tasks**, non-blocking.

- **Origin Static Capability**: The origin includes a built-in HTTP server (default port 80) that can directly serve static files. **For low concurrency scenarios, no extra gateway is needed**; works out-of-the-box.

- **Real-time Recording Mechanism**:
    - **FLV Recording**: Saves the raw stream in real time, producing a complete FLV file after the stream ends.
    - **fMP4 Segmentation**: Generates audio/video fMP4 segments in real time (supports both mixed and separate formats), automatically merges into a complete MP4 after the stream ends.
    - **HLS Segmentation**: Generates TS segments + m3u8 index in real time (mobile compatible).
    - **Independent Switches**: Users can enable/disable each recording task separately in `server.php`.

- **FLV Live Gateway Cluster**: Pure traffic forwarding service. Pulls HTTP-FLV streams upstream, caches GOP keyframes for instant playback, and distributes to end clients or downstream gateways.
    - **Supports unlimited cascading levels**: Level-1 gateway → Level-2 gateway → Level-3 gateway → ... → clients.
    - **Supports horizontal scaling**: Deploy multiple gateway instances at the same level, distribute traffic via load balancing.
    - **Linux epoll high performance**: Single process can handle 20,000+ concurrent connections; Windows fallback to select model.

- **Static File Gateway Cluster (Recommended)**: Lightweight HTTP static file server, unified hosting for all static assets.
    - **Supported protocols**: HLS (.m3u8/.ts), fMP4 (.m4s/.mp4), MP4 VOD files, FLV recorded files, web player pages.
    - **Supports horizontal scaling**: Deploy multiple instances at the same level, linearly increase concurrency.
    - **Supports vertical scaling**: Can be layered with reverse proxies like Nginx for multi-level traffic distribution.
    - **Linux epoll high performance**: Single process can handle 20,000+ concurrent connections; Windows fallback to select.
    - **Best practice**: Point HLS/fMP4/MP4 playback paths to this gateway cluster to achieve read-write separation for static assets.

- **Deployment Recommendations**:
    - **Low concurrency** (< 500 concurrent): Use origin built-in HTTP server directly, no extra gateways needed.
    - **Medium concurrency** (500 – 5,000 concurrent):
        - Origin + single-layer gateway cluster (FLV gateway or static file gateway)
        - A single gateway process is usually sufficient; no need for multiple instances.
    - **High concurrency** (> 5,000 concurrent):
        - Origin focuses on `ingest, remuxing, real-time recording`.
        - **Multi-level FLV gateway cluster**: Level-1 → Level-2 → clients.
        - **Multi-level static file gateway cluster**: Level-1 → Level-2 → clients.
        - Each layer can be horizontally scaled to linearly increase concurrency.

## ✨ Features

- 🎥 **Three-Protocol Ingest**: Full implementation of standard RTMP push/pull + HTTP-FLV push/pull + WebSocket-FLV push, compatible with mainstream push tools.
- 📡 **HTTP-FLV / WebSocket-FLV**: Low-latency browser live streaming solution, supports ffplay and other players for direct pull.
- 🔌 **Built-in Web Push Test Page**: Test WebSocket push directly in the browser without installing any push tool.
- 🧩 **Automatic HLS Segmentation**: Real-time generation of m3u8 + TS, fully compatible with mobile platforms.
- 📦 **fMP4 Real-time Segmentation + Auto Merge**: Generates fMP4 segments live, automatically merges into a complete MP4 after stream ends.
- 🎬 **Dual fMP4 Format Support**: Supports both mixed (audio+video) and separate audio/video segment formats.
- 💾 **Independent FLV Recording**: Saves raw FLV stream in real time, decoupled from fMP4/MP4.
- 🎛️ **Independent Task Switches**: FLV recording, fMP4 segmentation, and HLS segmentation can be independently enabled/disabled.
- 🖥️ **Built-in Multiple Web Players**: Ready to use, supports FLV/HLS/MP4/mixed fMP4/separate fMP4 playback.
- 🚀 **Cascadable FLV Streaming Gateway**: Unlimited layered distribution, GOP cache for instant playback, automatic reconnection on stream break, supporting high-concurrency live scenarios.
- 📁 **Static File Gateway**: Unified hosting for HLS/fMP4/MP4 recorded assets and web pages, supporting high-concurrency VOD scenarios.
- 🎞️ **Cross-Platform Playback Compatibility**: Supports ffplay, VLC, browsers, mobile players, etc.
- 🐳 **Docker One-Click Deployment**: Quickly spin up a test environment.
- ⚡ **Native Pure PHP Implementation**: No dependency on any third-party streaming software.

## 📋 Requirements

- PHP >= 8.1 (CLI mode only)
- Required extension: `sockets`
- Recommended extension: `event` (greatly improves concurrency performance on Linux, automatically enables epoll)

## 🚀 Quick Start

### 1. Install the project
```bash
composer create-project xiaosongshu/rtmp_server
```

### 2. Configure recording switches (`server.php`)
```php
// Three independent recording task switches, can be enabled/disabled as needed
define('FLV_TO_RECORD', true);   // Whether to record raw FLV file in real time
define('FLV_TO_MP4', true);      // Whether to generate fMP4 segments in real time and merge to MP4
define('FLV_TO_HLS', true);      // Whether to generate HLS (TS) segments in real time
```

### 3. Start the origin server
```bash
php server.php
```

### 4. Access playback (directly from origin in low concurrency)
```bash
# Playback page access (origin built-in HTTP service)
http://127.0.0.1/index.html      # FLV live page
http://127.0.0.1/play.html       # HLS live page
http://127.0.0.1/mp4.html        # MP4 VOD page
http://127.0.0.1/play_merge.html # fMP4 segment VOD page (supports both mixed/separate formats)
```

### 5. Medium/High concurrency: Deploy static file gateway cluster (recommended)

> **Use case**: High-concurrency access to HLS (.ts/.m3u8), fMP4 (.m4s/.mp4), MP4 VOD files, and web pages.
> On Linux, a single gateway process can handle 20,000+ connections; for higher loads, scale horizontally with multiple instances.

```bash
# Start a single instance (under epoll, directly handles high concurrency)
php fileGateway.php 0.0.0.0 8100

# 【Horizontal scaling】Multiple instances for ultra-high concurrency or multi-server
php fileGateway.php 0.0.0.0 8100
php fileGateway.php 0.0.0.0 8101
php fileGateway.php 0.0.0.0 8102

# Linux/macOS run in background
php fileGateway.php 0.0.0.0 8100 > /dev/null 2>&1 &

# Vertical scaling: multi-level distribution via Nginx reverse proxy
# Level-1 Nginx -> Level-2 fileGateway (8100/8101/8102) -> Level-3 fileGateway ...
```

Access examples (via static file gateway):
```
http://127.0.0.1:8100/play.html       # HLS player page through gateway
http://127.0.0.1:8100/hls/live/stream/index.m3u8  # HLS stream through gateway
```

### 6. Medium/High concurrency: Deploy FLV live gateway cluster

> On Linux, a single FLV gateway process can stably handle nearly 20,000 concurrent playback sessions.

```bash
# Level-1 gateway: pull stream from origin
php flvGateway.php 8080 http://origin_IP:8501

# Horizontal scaling: multiple instances at same level
php flvGateway.php 8081 http://origin_IP:8501
php flvGateway.php 8082 http://origin_IP:8501

# Vertical scaling: multi-level cascading
php flvGateway.php 8080 http://origin_IP:8501      # Level-1 gateway
php flvGateway.php 8081 http://127.0.0.1:8080   # Level-2 gateway (pulls from Level-1)
php flvGateway.php 8082 http://127.0.0.1:8081   # Level-3 gateway (pulls from Level-2)
```

### 7. Stop services
| OS         | Stop command     |
| ---------- | ---------------- |
| Windows    | `Ctrl + C`       |
| Linux/macOS| `kill -9 PID`    |

## 🔧 Port Configuration (modify in `server.php`)

| Port  | Protocol       | Purpose                                                       |
| ----- | -------------- | ------------------------------------------------------------- |
| 1935  | RTMP           | RTMP push, RTMP pull playback                                 |
| 8501  | HTTP/WebSocket | HTTP-FLV push/pull / WS-FLV push/pull / WS-FLV live playback |
| 80    | HTTP           | Static file service + web player pages + web push test page   |

## 🌐 Web Push Test Page

The project includes a built-in WebSocket push test page, allowing you to test push functionality directly in your browser without installing any push software.

### Access URL
```
http://127.0.0.1/push.html
```

### Instructions
1. **Start origin server**: Ensure `php server.php` is running.
2. **Open push page**: Visit `http://127.0.0.1/push.html` in your browser.
3. **Configure push parameters**:
    - Push URL: `ws://127.0.0.1:8501/live/stream` (pre-filled by default)
    - App name: `live`
    - Stream name: `stream`
4. **Choose push method**:
    - **Camera push**: Click `Get Camera`, authorize, and start pushing.
    - **Screen share push**: Click `Share Screen`, select a window or tab to start.
    - **Local file push**: Select a local video file (MP4/FLV supported), click push.
5. **Start pushing**: Click `Start Push`, the page will send audio/video data to the server in real time.
6. **Verify live stream**:
    - Open player page `http://127.0.0.1/index.html` to watch FLV live.
    - Or use ffplay: `ffplay ws://127.0.0.1:8501/live/stream.flv`

### Push URL Format
```
# WebSocket-FLV push address
ws://127.0.0.1:8501/{app_name}/{stream_name}

# Example
ws://127.0.0.1:8501/live/stream
ws://127.0.0.1:8501/live/test
```

> **Note**: Web push is based on WebSocket protocol and only supports H.264 video encoding and AAC/MP3 audio encoding. Before pushing, ensure your browser supports the required codecs.

## 🚀 FLV Streaming Gateway (High-Concurrency Live Distribution)

### Gateway Introduction

A lightweight traffic distribution component supporting unlimited cascading levels. Pulls HTTP-FLV from upstream origin/gateway, caches stream headers and GOP keyframes for instant playback on new client connections, and replicates stream data to downstream clients or sub-gateways. **Designed for medium to high-concurrency live scenarios**, supports both horizontal and vertical scaling.

### Gateway Core Capabilities

- 📡 Single instance forwards multiple concurrent streams, handling different channels simultaneously.
- 🔄 Unlimited cascading: Level-1 → Level-2 → Level-3 chain expansion.
- ⚡ GOP pre-caching: new connections don't wait for keyframes, enabling instant playback.
- 🔁 Automatic reconnection on upstream stream disconnection, transparent to end users.
- 📊 Built-in runtime statistics: outputs online count, upload/download traffic every 10 seconds.
- 🚀 **Horizontal scaling**: add more gateway processes/instances at same level, linearly increase concurrency.
- 🚀 **Vertical scaling**: multi-level cascading, distribute pressure across layers.
- 🧠 **Adaptive I/O**: Linux automatically uses epoll, single process 20,000+ concurrent; Windows falls back to select for compatibility.

### FLV Gateway Start Commands

```bash
# 【Horizontal scaling】Multiple instances at same level
php flvGateway.php 8080 http://origin_IP:8501
php flvGateway.php 8081 http://origin_IP:8501
php flvGateway.php 8082 http://origin_IP:8501

# 【Vertical scaling】Multi-level cascading
php flvGateway.php 8080 http://origin_IP:8501      # Level-1 gateway
php flvGateway.php 8081 http://127.0.0.1:8080   # Level-2 gateway
php flvGateway.php 8082 http://127.0.0.1:8081   # Level-3 gateway

# 【Combined scaling】Multi-level + multiple instances per level
# Level-1 gateway cluster
php flvGateway.php 8080 http://origin_IP:8501
php flvGateway.php 8081 http://origin_IP:8501
# Level-2 gateway cluster (pulling from Level-1)
php flvGateway.php 8180 http://127.0.0.1:8080
php flvGateway.php 8181 http://127.0.0.1:8081
```

### Gateway Playback URL Specification

```
http://gateway_IP:port/{app_name}/{stream_name}.flv
```

Examples:
```
# Level-1 gateway
http://127.0.0.1:8080/live/stream.flv
# Level-2 gateway
http://127.0.0.1:8081/live/stream.flv
```

### Debug Logging

Add `$gateway->debug = true;` in the gateway startup script to enable full detailed runtime logs.

## 📁 Static File Gateway `fileGateway.php` (High-Concurrency VOD Asset Hosting)

### Gateway Introduction

A lightweight HTTP static file server, unified hosting for all static assets. **For file-based protocols like HLS, fMP4, MP4, this is the recommended playback method**. Supports horizontal and vertical scaling, can handle massive VOD concurrency.

### Core Capabilities

- 📁 Unified hosting for all static assets (recorded files + player pages).
- 🔗 **Horizontal scaling**: multiple instances, load balancing.
- 🔗 **Vertical scaling**: multi-level cascading (e.g., Nginx + fileGateway + backend storage).
- 📊 Built-in access logs for statistics.
- 🚀 Pure PHP implementation, lightweight with no dependencies.
- 🧠 **Adaptive I/O**: Linux epoll single process 20,000+ concurrent, Windows select compatible.
- **💡 Best practice**: Point HLS/fMP4/MP4 playback paths to this gateway cluster; origin only writes files, achieving read-write separation.

### Start Commands (multiple processes / instances for distribution)

```bash
# Basic start (serves current directory, port 8100)
php fileGateway.php 0.0.0.0 8100

# 【Horizontal scaling】Multiple instances
php fileGateway.php 0.0.0.0 8100
php fileGateway.php 0.0.0.0 8101
php fileGateway.php 0.0.0.0 8102

# Linux/macOS run multiple instances in background
php fileGateway.php 0.0.0.0 8100 > /dev/null 2>&1 &
php fileGateway.php 0.0.0.0 8101 > /dev/null 2>&1 &
php fileGateway.php 0.0.0.0 8102 > /dev/null 2>&1 &
```

### Nginx Reverse Proxy Configuration Example

```nginx
upstream filegateway_cluster {
    # Horizontal scaling: multiple fileGateway instances
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

### Access URL Specification

```
http://gateway_IP:port/{relative_file_path}
```

Examples:
```
# Web player pages (via static gateway)
http://127.0.0.1:8100/index.html      # FLV live page
http://127.0.0.1:8100/play.html       # HLS live page
http://127.0.0.1:8100/mp4.html        # MP4 VOD page
http://127.0.0.1:8100/video.html      # FLV VOD page
http://127.0.0.1:8100/play_merge.html # fMP4 segment VOD page

# Recorded asset access
http://127.0.0.1:8100/hls/live/stream/index.m3u8
http://127.0.0.1:8100/mp4/live/stream/output_merge/init.mp4
http://127.0.0.1:8100/mp4/live/stream/output_merge/stream_full.mp4
http://127.0.0.1:8100/flv/live/stream/index.flv
```

## 📡 Ingest Tutorial

The project supports **RTMP, HTTP-FLV, WebSocket-FLV** three mainstream ingestion protocols, compatible with OBS, FFmpeg, and browser-based Web push.

### 1. RTMP Push
#### URL Format
```
rtmp://127.0.0.1:1935/{app_name}/{stream_name}
```
- `app_name`: e.g., `live`
- `stream_name`: e.g., `stream`
- Only alphanumeric characters allowed.

#### Push Examples
1. **OBS Studio Push**
    1. Download and install [OBS Studio](https://obsproject.com/)
    2. Settings → Stream → Server: `rtmp://127.0.0.1:1935/live`
    3. Stream Key: `stream`
    4. Start streaming

2. **FFmpeg Loop Push**
```bash
ffmpeg -re -stream_loop -1 -i "video.mp4"  -vcodec h264 -acodec aac -f flv  rtmp://127.0.0.1:1935/live/stream
```

### 2. HTTP-FLV Push
#### URL Format
```
http://127.0.0.1:8501/{app_name}/{stream_name}
```
- Same naming rules as RTMP, alphanumeric only.

#### Push Example (FFmpeg)
```bash
# Push local FLV file
ffmpeg -re -i test.flv -c:v libx264 -c:a aac -f flv http://127.0.0.1:8501/live/stream

# Loop push local MP4 file
ffmpeg -re -stream_loop -1 -i video.mp4 -c:v libx264 -c:a aac -f flv http://127.0.0.1:8501/live/stream
```

### 3. WebSocket-FLV Push (New)
#### URL Format
```
ws://127.0.0.1:8501/{app_name}/{stream_name}
```
- Same naming rules, alphanumeric only.

#### Push Methods

**1. Built-in Web Push Test Page (Recommended)**
```
http://127.0.0.1/push.html
```
Configure push URL `ws://127.0.0.1:8501/live/stream` on the page, choose camera, screen share, or local file to start pushing.

**2. FFmpeg Push**
```bash
# Push local file via WebSocket (requires conversion tool)
ffmpeg -re -i video.mp4 -c:v libx264 -c:a aac -f flv - | websocat -b ws://127.0.0.1:8501/live/stream
```

**3. Custom WebSocket Client**
```javascript
// JavaScript WebSocket push example
const ws = new WebSocket('ws://127.0.0.1:8501/live/stream');
const mediaStream = await navigator.mediaDevices.getUserMedia({ video: true, audio: true });
const recorder = new MediaRecorder(mediaStream, { mimeType: 'video/webm' });

recorder.ondataavailable = (event) => {
    if (event.data.size > 0 && ws.readyState === WebSocket.OPEN) {
        ws.send(event.data);
    }
};
recorder.start(100); // send data every 100ms
```

### 4. Client Push Tool
The project includes a client push tool supporting FLV/MP4 static file push:
```bash
# HTTP-FLV push
php pusher.php test.flv http://127.0.0.1:8501/live/stream 1.0 --no-reconnect
php pusher.php test.mp4 http://127.0.0.1:8501/live/stream 1.0 --no-reconnect

# WebSocket-FLV push
php pusher.php test.flv ws://127.0.0.1:8501/live/stream 1.0 --no-reconnect
php pusher.php test.mp4 ws://127.0.0.1:8501/live/stream 1.0 --no-reconnect
```

## 📺 Playback URL Summary & Tools

### Live Stream URLs

| Protocol       | Access URL                                                       | Description                                    | Distribution Suggestion       |
| -------------- | ---------------------------------------------------------------- | ---------------------------------------------- | ----------------------------- |
| RTMP           | `rtmp://127.0.0.1:1935/live/stream`                              | Native RTMP player, ffplay available           | Origin direct serve           |
| HTTP-FLV       | `http://127.0.0.1:8501/live/stream.flv`                          | Low-latency browser playback, ffplay available | **Via FLV gateway cluster**   |
| WebSocket-FLV  | `ws://127.0.0.1:8501/live/stream.flv`                            | WebSocket streaming (native browser support)   | **Via FLV gateway cluster**   |
| HLS            | `http://{fileGateway_IP}:8100/hls/live/stream/index.m3u8` | Android/iOS mobile preferred                   | **Must use fileGateway**      |

### VOD Playback URLs (after recording completes)

| File Type                         | Access URL (must use fileGateway)                                                                           | Description                         |
| --------------------------------- | ----------------------------------------------------------------------------------------------------------- | ----------------------------------- |
| Merged MP4 VOD                    | `http://{fileGateway_IP}:8100/mp4/live/stream/output_merge/stream_full.mp4`                                 |                                     |
| Mixed fMP4 segments (MSE)         | `http://{fileGateway_IP}:8100/mp4/live/stream/output_merge/init.mp4`                                        |                                     |
| Separate audio/video fMP4 VOD     | `http://{fileGateway_IP}:8100/mp4/live/stream/output_separate/audio_init.mp4` (and video counterpart) |                                     |
| Raw FLV VOD                       | `http://{fileGateway_IP}:8100/flv/live/stream/index.flv`                                                    |                                     |

> **Under high concurrency**: must use the static file gateway cluster (e.g., `127.0.0.1:8100/8101/8102`) with load balancing to achieve read-write separation for static assets.

### Web Player Pages

| Page Purpose                    | Access URL (recommended via fileGateway)                | Description                                         |
| ------------------------------- | ------------------------------------------------------- | --------------------------------------------------- |
| FLV live playback               | `http://{fileGateway_IP}:8100/index.html`               | HTTP-FLV low-latency live                          |
| HLS live playback               | `http://{fileGateway_IP}:8100/play.html`                | HLS mobile-compatible live                         |
| Merged MP4 VOD                  | `http://{fileGateway_IP}:8100/mp4.html`                 | Complete MP4 file VOD                              |
| Raw FLV VOD                     | `http://{fileGateway_IP}:8100/video.html`               | FLV native file VOD                                |
| **fMP4 segment VOD**            | `http://{fileGateway_IP}:8100/play_merge.html`          | **Supports both mixed and separate fMP4 playback** |

### Command-line Playback Tools (ffplay)
The project is fully compatible with **ffplay**; you can directly pull any stream URL for playback:
```bash
# Play RTMP stream
ffplay rtmp://127.0.0.1:1935/live/stream

# Play origin HTTP-FLV stream
ffplay http://127.0.0.1:8501/live/stream.flv

# Play origin WebSocket-FLV stream
ffplay ws://127.0.0.1:8501/live/stream.flv

# Play FLV gateway forwarded stream
ffplay http://127.0.0.1:8080/live/stream.flv

# Play HLS stream
ffplay http://127.0.0.1:8100/hls/live/stream/index.m3u8

# Play VOD FLV/MP4 file
ffplay http://127.0.0.1:8100/flv/live/stream/index.flv
ffplay http://127.0.0.1:8100/mp4/live/stream/output_merge/stream_full.mp4
```

## 💾 Real-time Recording Explanation

### Recording Mechanism (Three Independent Parallel Tasks)

Once ingestion starts, the origin simultaneously launches three **independent parallel** recording tasks, non-blocking:

```
                    ┌─────────────────────────────────────────────────┐
                    │    RTMP / HTTP-FLV / WS-FLV three-protocol ingest│
                    └─────────────────────┬───────────────────────────┘
                                          │
                    ┌─────────────────────┼───────────────────────────┐
                    │                     │                           │
                    ▼                     ▼                           ▼
            ┌───────────────┐     ┌───────────────┐           ┌───────────────┐
            │   FLV Record  │     │   fMP4 Segment│           │   HLS Segment │
            │  (raw stream) │     │  (live chunk) │           │  (live chunk) │
            └───────┬───────┘     └───────┬───────┘           └───────┬───────┘
                    │                     │                           │
                    ▼                     ▼                           ▼
            ┌───────────────┐     ┌───────────────┐           ┌───────────────┐
            │  Complete FLV │     │  fMP4 segments│           │  TS segments  │
            │  (after stream│     │  (during live)│           │  + m3u8 index │
            │      ends)    │     │               │           │               │
            └───────────────┘     └───────┬───────┘           └───────────────┘
                                          │
                                          │ automatically merged after stream ends
                                          ▼
                                    ┌───────────────┐
                                    │  Complete MP4 │
                                    │  (VOD replay) │
                                    └───────────────┘
```

### Task Independence Explanation

| Recording Task   | Real-time | Output                            | Use Case                                      | Independent Switch |
| ---------------- | --------- | --------------------------------- | --------------------------------------------- | ------------------ |
| **FLV Record**   | Yes       | Complete FLV file                 | Raw format backup, VLC/ffplay playback       | `FLV_TO_RECORD`    |
| **fMP4 Segment** | Yes       | fMP4 segments → merged to MP4     | Browser MSE playback, VOD replay             | `FLV_TO_MP4`       |
| **HLS Segment**  | Yes       | TS segments + m3u8                | Mobile compatibility, HLS live               | `FLV_TO_HLS`       |

## 📁 Project Directory Structure

```
rtmp_server/
├── flv/                              # Raw FLV recorded files (FLV_TO_RECORD)
├── mp4/                              # MP4/fMP4 transcoding output (FLV_TO_MP4)
├── hls/                              # HLS TS segments + m3u8 index (FLV_TO_HLS)
├── MediaServer/                      # RTMP core protocol, push/pull session logic
├── Root/                             # Low-level async I/O, socket event driving (incl. epoll adaptive)
├── SabreAMF/                         # AMF0/AMF3 encoding/decoding
├── server.php                        # Origin server entry point
├── fileGateway.php                   # Static file gateway (supports epoll, 20k+ concurrency)
├── flvGateway.php                    # FLV live gateway (supports epoll, 20k+ concurrency)
├── pusher.php                        # FLV/MP4 static file push client
├── push.html                         # WebSocket-FLV push test page (browser push)
├── *.html                            # Web player pages
└── README.md
```

## 📈 Concurrency Performance Test

The following tests were performed under the same environment: **Docker container, ulimit -n 65535**, using the same stress test script with 20,000 concurrent clients, each pulling stream for 5 seconds.

### Origin Server (RTMP origin)
```
Current container pids.max: unknown
Start batch: 1000 clients (20 batches total)
All clients started, waiting for completion...

===== Results =====
Success: 17,330
Failure: 2,670
```

### FLV Live Gateway
```
Current container pids.max: unknown
Start batch: 1000 clients (20 batches total)
All clients started, waiting for completion...

===== Results =====
Success: 19,923
Failure: 77
```

### Static File Gateway
```
Concurrency: 20,000
Duration per client: 5s
Batch size: 1000

===== Results =====
Success: 20,000
Failure: 0
```

> **Explanation**:
> - The origin server, due to carrying RTMP/HTTP-FLV/WS-FLV ingestion, multi-protocol remuxing, and other business logic, still stably supports **17,330** successful concurrent connections; the few failures are due to port collisions during test.
> - FLV gateway focuses purely on traffic forwarding, achieving **99.6% success rate** (19,923/20,000), approaching the single-machine TCP port pool limit.
> - Static file gateway is extremely lightweight: **20,000 concurrent all successful**, zero failures.
> - All components adapt to the OS: **Linux automatically enables epoll**, breaking the traditional select 1024 limit.

## ❓ FAQ

### 1. How can a single process support 20,000+ concurrent connections?

- **Linux**: If the `event` extension is detected, the server **automatically enables epoll event-driven model**, no longer limited by the traditional `select` 1024 file descriptor limit; a single process easily handles 20,000+ connections.
- **Windows**: Since the `event` extension is missing, it falls back to the `select` model, limited to ~256 connections per process; it's recommended to deploy multiple instances.
- **Performance tests**: In a Docker container (ulimit -n 65535), the static file gateway achieved **20,000 concurrent zero failures**, and the FLV gateway achieved 99.6% success.

### 2. How to support even higher concurrency with gateways?

| Scaling Method   | Explanation                                                      | Example                                                       |
| ---------------- | ---------------------------------------------------------------- | ------------------------------------------------------------- |
| **Single-process** | Linux epoll mode: a single process can handle 20k+              | One fileGateway process handles 20,000 static requests       |
| **Horizontal**   | Multiple instances at the same level, load balancing            | 3 fileGateway instances → 60,000+ concurrency                |
| **Vertical**     | Multi-level cascading                                            | Level-1 → Level-2 → Level-3 gateways...                      |
| **Combined**     | Horizontal + vertical together                                   | 3 instances per level × 3 levels = theoretical 180,000+ concurrency |

### 3. When is a gateway needed?

| Concurrency Scenario               | Deployment Plan                                                         |
| ---------------------------------- | ----------------------------------------------------------------------- |
| **Low** (< 500)                   | Origin only; origin built-in HTTP server serves directly                |
| **Medium** (500 – 5,000)          | Origin + single-layer gateway (1–2 instances sufficient)               |
| **High** (> 5,000)                | Origin + multi-level gateway cluster (each layer can be horizontally scaled) |

### 4. What is the difference between FLV gateway and static file gateway?

| Gateway Type          | Use Case                     | Resource Types Handled                         | Scaling Methods            |
| --------------------- | ---------------------------- | ---------------------------------------------- | -------------------------- |
| **FLV live gateway**  | Live stream distribution     | HTTP-FLV real-time streams                     | Horizontal + vertical, cascading |
| **Static file gateway**| Unified static asset hosting | HLS/fMP4/MP4/FLV static files + web player pages | Horizontal + vertical, can combine with Nginx |

### 5. What are the advantages of WebSocket-FLV push?

- **Native browser support**: No plugins or push software needed; push from a web page.
- **Lower barrier**: Non-technical users can quickly test live streaming via built-in test page.
- **Mobile friendly**: Supports camera push from mobile browsers (HTTPS environment required).
- **Coexists with HTTP-FLV**: Both protocols can be used simultaneously without interference.

### 6. How to verify gateway concurrency capability?

```bash
# Use built-in stress test script (20000 concurrency)
sh play.sh

# Or use ab (Apache Bench) to test static file gateway
ab -n 10000 -c 500 http://127.0.0.1:8100/index.html

# Use wrk to test FLV gateway
wrk -t4 -c1000 -d30s http://127.0.0.1:8080/live/stream.flv
```

## 📦 Toolkit

The protocol conversion part of this project has been extracted into an independent toolkit `xiaosongshu/flv2mp4`. Repository: `https://github.com/2723659854/flv2mp4`. It supports flv, mp4, hls transcoding, flv and file gateways, and an flv static file push client.

## 📄 License

This project is for learning and technical research only; commercial use risks are borne by the user.

## ⚠️ Disclaimer

1. Some open source code is taken from the open source community; if copyright is involved, the author can be contacted for removal.
2. The project is completely open source and free, only for technical exchange.
3. The author assumes no responsibility for any legal consequences arising from commercial or illegal use by users.

## 📧 Contact
- 📬 Email: 2723659854@qq.com
- 🐙 GitHub: [2723659854](https://github.com/2723659854)
