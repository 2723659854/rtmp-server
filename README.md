# RTMP Server

<p align="center">
  <a href="./README.cn.md"><strong>🇨🇳 中文</strong></a> •
  <a href="./README.md"><strong>🇬🇧 English</strong></a>
</p>

> A lightweight RTMP live streaming service written in pure PHP, **no third-party streaming media dependencies**, quickly build a private live streaming platform out of the box.
> **Linux environment automatically enables epoll event-driven, single process easily handles 20,000+ concurrent connections, Windows falls back to select mode for compatibility.**

## 🏗️ System Architecture

```
                                                    【Push Client】OBS/FFmpeg
                                                         │
                                       RTMP Push(1935)  /  HTTP-FLV/WS-FLV Push(8501)
                                                         │
                                                         ▼
╔══════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════╗
║                                                      RTMP Origin Server (Core)                                                 ║
║                                                                                                                              ║
║  📥 Push Ingestion   RTMP / HTTP-FLV / WebSocket-FLV Triple Protocol Push, Connection Authentication                         ║
║  🔄 Protocol Conversion   RTMP / HTTP-FLV / WS-FLV → HTTP-FLV / WebSocket-FLV / HLS / fMP4 / MP4                              ║
║  💾 Real-time Recording   ┌──────────────┬──────────────┬──────────────┐                                                     ║
║                           │  FLV Record  │  fMP4 Slice  │   HLS Slice  │  Three Independent Parallel Tasks                   ║
║                           │ (Raw Stream) │(Real-time)   │ (Real-time)  │                                                     ║
║                           └──────────────┴──────────────┴──────────────┘                                                     ║
║  📤 Live Output   HTTP-FLV(8501) / WebSocket-FLV / HLS Live Stream / fMP4 Live Stream                                        ║
║  📦 VOD Output    fMP4 Slices Generated in Real-time → Automatically Merged to Complete MP4 After Streaming                 ║
║  📁 Static Service   Built-in HTTP Service (Port 80), Direct Static File Access (For Low Concurrency Scenarios)              ║
╚══════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════╝
│
┌───────────────────────┼───────────────────────┐
│                       │                       │
▼                       ▼                       ▼
HTTP-FLV(8501)           HLS(TS/m3u8)           fMP4(Slices)
Live Stream Output       Static Files           Static Files
│                       │                       │
│                       │                       │
▼                       ▼                       ▼
┌─────────────────┐     ┌─────────────────────────────────────────────────┐
│  FLV Gateway    │     │           Static File Gateway Cluster (fileGateway)     │
│    Cluster      │     │         🎯 Hosts: HLS / fMP4 / MP4 / FLV / Web Pages    │
│                 │     │                                                 │
│  ┌───────────┐  │     │  ┌───────────┐  ┌───────────┐  ┌───────────┐   │
│  │ Level 1   │  │     │  │ Gateway 1 │  │ Gateway 2 │  │ Gateway 3 │   │
│  │ Gateway   │  │     │  │ (8100)    │  │ (8101)    │  │ (8102)    │   │
│  │ (8080)    │  │     │  └─────┬─────┘  └─────┬─────┘  └─────┬─────┘   │
│  └─────┬─────┘  │     │        │              │              │         │
│        │        │     │        ▼              ▼              ▼         │
│  ┌─────┴─────┐  │     │   ┌──────────────────────────────────────┐    │
│  ▼     ▼     ▼  │     │   │           Client                      │    │
│ ┌───┐ ┌───┐ ┌───┐│     │   │   HLS Player / MSE Player / VOD Request / ffplay│
│ │Sub│ │Sub│ │Sub││     │   └──────────────────────────────────────┘    │
│ │GW │ │GW │ │GW ││     │                                                 │
│ └─┬─┘ └─┬─┘ └─┬─┘│     └─────────────────────────────────────────────────┘
│   │     │     │  │
│   ▼     ▼     ▼  │
│ ┌──────────────┐ │
│ │   Client     │ │
│ │ FLV Player / ffplay │
│ └──────────────┘ │
└─────────────────┘
```

### Architecture Description

- **Origin Server**: The only stream production node, supports **RTMP, HTTP-FLV, WebSocket-FLV triple protocol push**, responsible for push/pull ingestion and multi-protocol remuxing. **FLV recording, fMP4 slicing, HLS slicing run completely independently and in parallel**, non-blocking.

- **Origin Static Capability**: Built-in HTTP service (default port 80) provides direct static file access. **No additional gateway needed for low concurrency scenarios**, ready to use out of the box.

- **Real-time Recording Mechanism**:
  - **FLV Recording**: Real-time saving of original raw stream, complete FLV file available after streaming ends
  - **fMP4 Slicing**: Real-time generation of audio/video fMP4 slices (supports both multiplexed and demultiplexed formats), automatically merged to complete MP4 after streaming
  - **HLS Slicing**: Real-time generation of TS slices + m3u8 index (mobile compatible)
  - **Independent Switches**: Users can configure each recording task independently in `server.php`

- **FLV Live Gateway Cluster**: Pure traffic forwarding service, pulls HTTP-FLV streams upstream, caches GOP keyframes for instant playback, distributes to end clients or downstream gateways.
  - **Supports Unlimited Hierarchical Cascading**: Level 1 Gateway → Level 2 Gateway → Level 3 Gateway → ... → Client
  - **Supports Horizontal Scaling**: Multiple gateway instances at same level, traffic distribution via load balancing
  - **Linux epoll High Performance**: Single process handles 20,000+ concurrent connections, Windows select compatible

- **Static File Gateway Cluster (Recommended)**: Lightweight HTTP static file server, unified hosting for all static resources.
  - **Supported Protocols**: HLS (.m3u8/.ts), fMP4 (.m4s/.mp4), MP4 VOD files, FLV recording files, Web player pages
  - **Supports Horizontal Scaling**: Multiple gateway instances at same level, linearly increasing concurrent capacity
  - **Supports Vertical Scaling**: Multi-level traffic distribution via reverse proxies like Nginx
  - **Linux epoll High Performance**: Single process handles 20,000+ concurrent connections, Windows select compatible
  - **Best Practice**: Point HLS/fMP4/MP4 playback paths to this gateway cluster for read/write separation

- **Deployment Recommendations**:
  - **Low Concurrency** (< 500 connections): Use origin built-in HTTP service directly, no additional gateway needed
  - **Medium Concurrency** (500 – 5,000 connections):
    - Origin + Single-layer gateway cluster (FLV Gateway or Static File Gateway)
    - Single gateway process usually sufficient, no multiple instances needed
  - **High Concurrency** (> 5,000 connections):
    - Origin focuses on "push, protocol conversion, real-time recording"
    - **FLV Gateway Multi-level Cluster**: Level 1 Gateway → Level 2 Gateway → Client
    - **Static File Gateway Multi-level Cluster**: Level 1 Gateway → Level 2 Gateway → Client
    - Each gateway layer can scale horizontally, linearly increasing concurrent capacity

## ✨ Features

- 🎥 **Triple Protocol Push**: Full implementation of standard RTMP push/pull + HTTP-FLV push/pull + WebSocket-FLV push, compatible with mainstream push tools
- 📡 **HTTP-FLV / WebSocket-FLV**: Browser low-latency live streaming solution, supports ffplay direct playback
- 🔌 **Built-in Web Push Test Page**: Browser-based WebSocket push testing, no need to install push tools
- 🧩 **HLS Auto Slicing**: Real-time generation of m3u8 + TS, fully compatible with all mobile platforms
- 📦 **fMP4 Real-time Slicing + Auto Merge**: Real-time fMP4 slice generation during live streaming, auto-merged to complete MP4 after streaming ends
- 🎬 **Dual fMP4 Format Support**: Supports both multiplexed (audio+video) and demultiplexed (separate) slice formats
- 💾 **FLV Independent Recording**: Real-time saving of original FLV raw stream, decoupled from fMP4/MP4
- 🎛️ **Independent Task Switches**: FLV recording, fMP4 slicing, HLS slicing can be independently enabled/disabled
- 🖥️ **Built-in Multiple Web Players**: Ready to use, supports FLV/HLS/MP4/Multiplexed fMP4/Demultiplexed fMP4 playback
- 🚀 **Cascadable FLV Streaming Gateway**: Unlimited hierarchical distribution, GOP cache for instant playback, automatic reconnection on stream disconnect, supports high concurrency live streaming scenarios
- 📁 **Static File Gateway**: Unified hosting for HLS/fMP4/MP4 recorded resources and player pages, supports high concurrency VOD scenarios
- 🎞️ **Cross-platform Playback Compatibility**: Supports ffplay, VLC, browsers, mobile players and other mainstream playback terminals
- 🐳 **Docker One-click Deployment**: Quickly set up test environment
- ⚡ **Native Pure PHP Implementation**: No dependency on any third-party streaming media programs

## 📋 Requirements

- PHP >= 8.1 (CLI command line mode only)
- Required extension: `sockets`
- Recommended extension: `event` (Greatly improves concurrent performance on Linux, automatically enables epoll)

## 🚀 Quick Start

### 1. Install Project
```bash
composer create-project xiaosongshu/rtmp_server
```

### 2. Configure Recording Switches (`server.php`)
```php
// Three independent recording task switches, can be enabled/disabled as needed
define('FLV_TO_RECORD', true);   // Whether to record FLV raw files in real-time
define('FLV_TO_MP4', true);      // Whether to generate fMP4 slices in real-time and merge to MP4
define('FLV_TO_HLS', true);      // Whether to generate HLS (TS) slices in real-time
```

### 3. Start Origin Server
```bash
php server.php
```

### 4. Access Playback (Direct Origin for Low Concurrency)
```bash
# Playback page access (origin built-in HTTP service)
http://127.0.0.1/index.html      # FLV live page
http://127.0.0.1/play.html       # HLS live page
http://127.0.0.1/mp4.html        # MP4 VOD page
http://127.0.0.1/play_merge.html # fMP4 slice VOD page (supports both multiplexed/demultiplexed)
```

### 5. Medium/High Concurrency: Deploy Static File Gateway Cluster (Recommended)

> **Use Case**: High concurrency access to HLS (.ts/.m3u8), fMP4 (.m4s/.mp4), MP4 VOD files, and Web pages.
> A single gateway process on Linux handles 20,000+ connections. For higher loads, scale horizontally with multiple instances.

```bash
# Start single instance (directly handles high concurrency with epoll)
php fileGateway.php 0.0.0.0 8100

# 【Horizontal Scaling】Multiple instance deployment (for super concurrency or multi-machine load balancing)
php fileGateway.php 0.0.0.0 8100
php fileGateway.php 0.0.0.0 8101
php fileGateway.php 0.0.0.0 8102

# Linux/macOS background execution
php fileGateway.php 0.0.0.0 8100 > /dev/null 2>&1 &

# Vertical scaling: Multi-level distribution via Nginx reverse proxy
# Level 1 Nginx -> Level 2 fileGateway (8100/8101/8102) -> Level 3 fileGateway ...
```

Access examples (via static file gateway):
```
http://127.0.0.1:8100/play.html       # Access HLS player page via gateway
http://127.0.0.1:8100/hls/live/stream/index.m3u8  # Access HLS stream via gateway
```

### 6. Medium/High Concurrency: Deploy FLV Live Gateway Cluster

> A single FLV gateway process on Linux stably supports nearly 20,000 concurrent playback connections.

```bash
# Level 1 Gateway: Pull stream from origin
php flvGateway.php 8080 http://origin_IP:8501

# Horizontal scaling: Multiple gateway instances at same level
php flvGateway.php 8081 http://origin_IP:8501
php flvGateway.php 8082 http://origin_IP:8501

# Vertical scaling: Multi-level cascading
php flvGateway.php 8080 http://origin_IP:8501      # Level 1 gateway
php flvGateway.php 8081 http://127.0.0.1:8080      # Level 2 gateway (pulls from level 1)
php flvGateway.php 8082 http://127.0.0.1:8081      # Level 3 gateway (pulls from level 2)
```

### 7. Stop Service
| OS         | Termination Command |
| ---------- | ------------------- |
| Windows    | `Ctrl + C`          |
| Linux/macOS | `kill -9 PID`       |

## 🔧 Port Configuration (Modify in `server.php`)

| Port | Protocol       | Purpose                                           |
| ---- | -------------- | ------------------------------------------------- |
| 1935 | RTMP           | RTMP push, RTMP pull playback                     |
| 8501 | HTTP/WebSocket | HTTP-FLV push/pull / WS-FLV push/pull / WS-FLV live playback |
| 80   | HTTP           | Static file service + Web player pages + Web push test page |

## 🌐 Web Push Test Page

The project includes a built-in WebSocket push test page, allowing you to test push functionality directly in your browser without installing any push software.

### Access URL
```
http://127.0.0.1/push.html
```

### Usage Instructions
1. **Start Origin Server**: Ensure `php server.php` is running
2. **Open Push Page**: Browser access `http://127.0.0.1/push.html`
3. **Configure Push Parameters**:
  - Push URL: `ws://127.0.0.1:8501/live/stream` (pre-filled by default)
  - App Name: `live`
  - Stream Name: `stream`
4. **Choose Push Method**:
  - **Camera Push**: Click "Get Camera", authorize, then start pushing
  - **Screen Share Push**: Click "Share Screen", select window or tab to start pushing
  - **Local File Push**: Select local video file (supports MP4/FLV format), click push button
5. **Start Push**: Click "Start Push" button, the page will push audio/video data to the server in real-time
6. **Verify Live Stream**:
  - Open player page `http://127.0.0.1/index.html` to watch FLV live stream
  - Or use ffplay: `ffplay ws://127.0.0.1:8501/live/stream.flv`

### Push URL Format
```
# WebSocket-FLV Push URL
ws://127.0.0.1:8501/{app_name}/{stream_name}

# Examples
ws://127.0.0.1:8501/live/stream
ws://127.0.0.1:8501/live/test
```

> **Note**: Web push is based on WebSocket protocol and only supports H.264 video encoding and AAC/MP3 audio encoding. Ensure your browser supports the relevant codecs before pushing.

## 🚀 FLV Streaming Gateway (High Concurrency Live Distribution)

### Gateway Overview

Lightweight traffic distribution component supporting unlimited hierarchical cascading deployment. Pulls HTTP-FLV from upstream origin/upper gateway, caches stream headers and GOP keyframes for instant playback on new user connections, and replicates stream data to clients or child gateways. **Designed specifically for medium to high concurrency live streaming scenarios**, supports both horizontal and vertical scaling.

### Gateway Core Capabilities

- 📡 Single instance multi-stream concurrent forwarding, simultaneously distributing different channels
- 🔄 Unlimited hierarchical cascading, Level 1→Level 2→Level 3 gateway chain expansion
- ⚡ GOP pre-caching, new connections don't need to wait for keyframes, achieving instant playback
- 🔁 Automatic reconnection on upstream stream disconnect, transparent to end users
- 📊 Built-in runtime statistics, outputs online count and traffic every 10 seconds
- 🚀 **Horizontal Scaling**: Add gateway processes/instances at same level, linearly increasing concurrency
- 🚀 **Vertical Scaling**: Multi-level cascading, distributing single-point pressure
- 🧠 **Adaptive IO**: Linux automatically enables epoll, single process 20,000+ concurrency; Windows falls back to select for compatibility

### FLV Gateway Startup Commands

```bash
# 【Horizontal Scaling】Single layer multiple instances
php flvGateway.php 8080 http://origin_IP:8501
php flvGateway.php 8081 http://origin_IP:8501
php flvGateway.php 8082 http://origin_IP:8501

# 【Vertical Scaling】Multi-level cascading
php flvGateway.php 8080 http://origin_IP:8501      # Level 1 gateway
php flvGateway.php 8081 http://127.0.0.1:8080      # Level 2 gateway
php flvGateway.php 8082 http://127.0.0.1:8081      # Level 3 gateway

# 【Combined Scaling】Multi-level + Multiple instances per level
# Level 1 gateway cluster
php flvGateway.php 8080 http://origin_IP:8501
php flvGateway.php 8081 http://origin_IP:8501
# Level 2 gateway cluster (pulls from level 1)
php flvGateway.php 8180 http://127.0.0.1:8080
php flvGateway.php 8181 http://127.0.0.1:8081
```

### Gateway Playback URL Format

```
http://gateway_IP:port/{app_name}/{stream_name}.flv
```

Examples:
```
# Level 1 gateway
http://127.0.0.1:8080/live/stream.flv
# Level 2 gateway
http://127.0.0.1:8081/live/stream.flv
```

### Debug Logging

Add `$gateway->debug = true;` to the gateway startup script to enable full detailed runtime logs.

## 📁 Static File Gateway `fileGateway.php` (High Concurrency VOD Resource Hosting)

### Gateway Overview

Lightweight HTTP static file server providing unified hosting for all static resources. **For file-based protocols like HLS, fMP4, MP4, this is the recommended playback method**. Supports horizontal and vertical scaling, can support large-scale VOD concurrency.

### Core Capabilities

- 📁 Unified hosting for all static resources (recorded files + player pages)
- 🔗 **Horizontal Scaling**: Multiple instance deployment, load balanced traffic distribution
- 🔗 **Vertical Scaling**: Multi-level cascading (e.g., Nginx + fileGateway + backend storage)
- 📊 Built-in access logs for statistical analysis
- 🚀 Pure PHP implementation, lightweight with no dependencies
- 🧠 **Adaptive IO**: Linux epoll single process 20,000+ concurrency, Windows select compatible
- **💡 Best Practice**: Point HLS/fMP4/MP4 playback paths to this gateway cluster, origin only handles file writing for read/write separation

### Startup Commands (Multi-process/Multi-instance Sharding)

```bash
# Basic start (hosts current directory, port 8100)
php fileGateway.php 0.0.0.0 8100

# 【Horizontal Scaling】Multiple instance deployment
php fileGateway.php 0.0.0.0 8100
php fileGateway.php 0.0.0.0 8101
php fileGateway.php 0.0.0.0 8102

# Linux/macOS background execution of multiple instances
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

### Access URL Format

```
http://gateway_IP:port/{file_relative_path}
```

Examples:
```
# Web player pages (accessed via static gateway)
http://127.0.0.1:8100/index.html      # FLV live page
http://127.0.0.1:8100/play.html       # HLS live page
http://127.0.0.1:8100/mp4.html        # MP4 VOD page
http://127.0.0.1:8100/video.html      # FLV VOD page
http://127.0.0.1:8100/play_merge.html # fMP4 slice VOD page

# Recorded resource access
http://127.0.0.1:8100/hls/live/stream/index.m3u8
http://127.0.0.1:8100/mp4/live/stream/output_merge/init.mp4
http://127.0.0.1:8100/mp4/live/stream/output_merge/stream_full.mp4
http://127.0.0.1:8100/flv/live/stream/20240101_120000.flv
```

## 📡 Push Ingestion Tutorial

The project supports three mainstream push protocols: **RTMP, HTTP-FLV, and WebSocket-FLV**, compatible with OBS, FFmpeg, and browser-based WebSocket push methods.

### 1. RTMP Push
#### URL Format
```
rtmp://127.0.0.1:1935/{app_name}/{stream_name}
```
- `app_name`: e.g., `live`
- `stream_name`: e.g., `stream`
- Only supports English letters and numbers

#### Push Examples
1. **OBS Studio Push**
  1. Download and install [OBS Studio](https://obsproject.com/)
  2. Settings → Stream → Server: `rtmp://127.0.0.1:1935/live`
  3. Stream Key: `stream`
  4. Start Streaming

2. **FFmpeg Loop Push**
```bash
ffmpeg -re -stream_loop -1 -i "video.mp4" -vcodec h264 -acodec aac -f flv rtmp://127.0.0.1:1935/live/stream
```

### 2. HTTP-FLV Push
#### URL Format
```
http://127.0.0.1:8501/{app_name}/{stream_name}
```
- `app_name`, `stream_name` rules same as RTMP

#### Push Examples (FFmpeg)
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
- `app_name`, `stream_name` rules same as above

#### Push Methods

**1. Built-in Web Push Test Page (Recommended)**
```
http://127.0.0.1/push.html
```
Configure the push URL `ws://127.0.0.1:8501/live/stream` in the page, select camera, screen share, or local file to start pushing.

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
recorder.start(100); // Send data every 100ms
```

### 4. Client Push Tool
The project provides a client push tool supporting FLV/MP4 static file push:
```bash
# HTTP-FLV push
php pusher.php test.flv http://127.0.0.1:8501/live/stream 1.0 --no-reconnect
php pusher.php test.mp4 http://127.0.0.1:8501/live/stream 1.0 --no-reconnect
```

## 📺 Playback URLs & Players

### Live Stream URLs

| Protocol       | URL                                                    | Description                              | Distribution Recommendation |
| -------------- | ------------------------------------------------------ | ---------------------------------------- | ---------------------------- |
| RTMP           | `rtmp://127.0.0.1:1935/live/stream`                    | Native RTMP player, ffplay available     | Direct from origin |
| HTTP-FLV       | `http://127.0.0.1:8501/live/stream.flv`                | Browser low-latency playback, ffplay available | **Via FLV Gateway Cluster** |
| WebSocket-FLV  | `ws://127.0.0.1:8501/live/stream.flv`                  | WebSocket streaming (native browser support) | **Via FLV Gateway Cluster** |
| HLS            | `http://{fileGateway_IP}:8100/hls/live/stream/index.m3u8` | Android/iOS preferred                  | **Must use fileGateway** |

### VOD Playback URLs (After Recording Complete)

| File Type                      | URL (Must use fileGateway)                                                              | Description |
| ------------------------------ | --------------------------------------------------------------------------------------- | ----------- |
| Merged MP4 VOD                 | `http://{fileGateway_IP}:8100/mp4/live/stream/output_merge/stream_full.mp4`            | |
| Multiplexed fMP4 Slice VOD (MSE) | `http://{fileGateway_IP}:8100/mp4/live/stream/output_merge/init.mp4`                   | |
| Demultiplexed fMP4 VOD         | `http://{fileGateway_IP}:8100/mp4/live/stream/output_separate/audio_init.mp4`          | |
| Raw FLV VOD                    | `http://{fileGateway_IP}:8100/flv/live/stream/20240101_120000.flv`                      | |

> **High Concurrency Scenarios**: Must use static file gateway cluster (e.g., `127.0.0.1:8100/8101/8102`) with load balancing for read/write separation of static resources.

### Web Player Pages

| Page Purpose                   | URL (Recommended via fileGateway)                       | Description                                      |
| ------------------------------ | -------------------------------------------------------- | ------------------------------------------------ |
| FLV Live Playback              | `http://{fileGateway_IP}:8100/index.html`               | HTTP-FLV low-latency live                        |
| HLS Live Playback              | `http://{fileGateway_IP}:8100/play.html`                | HLS mobile-compatible live                       |
| Merged MP4 VOD                 | `http://{fileGateway_IP}:8100/mp4.html`                 | Complete MP4 file VOD                            |
| Raw FLV VOD                    | `http://{fileGateway_IP}:8100/video.html`               | FLV native file VOD                              |
| **fMP4 Slice VOD**             | `http://{fileGateway_IP}:8100/play_merge.html`          | **Supports both multiplexed and demultiplexed formats** |

### Command Line Player (ffplay)
The project is fully compatible with **ffplay** player for direct streaming:
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

# Play VOD FLV/MP4 files
ffplay http://127.0.0.1:8100/flv/live/stream/20240101_120000.flv
ffplay http://127.0.0.1:8100/mp4/live/stream/output_merge/stream_full.mp4
```

## 💾 Real-time Recording Description

### Recording Mechanism (Three Tasks Independent & Parallel)

When push starts, the origin simultaneously starts three **independent and parallel** recording tasks, non-blocking:

```
                    ┌─────────────────────────────────────────────────┐
                    │    RTMP / HTTP-FLV / WS-FLV Triple Protocol Push│
                    └─────────────────────┬───────────────────────────┘
                                          │
                    ┌─────────────────────┼───────────────────────────┐
                    │                     │                           │
                    ▼                     ▼                           ▼
            ┌───────────────┐     ┌───────────────┐           ┌───────────────┐
            │   FLV Record  │     │   fMP4 Slice  │           │   HLS Slice   │
            │  (Raw Stream) │     │  (Real-time)  │           │  (Real-time)  │
            └───────┬───────┘     └───────┬───────┘           └───────┬───────┘
                    │                     │                           │
                    ▼                     ▼                           ▼
            ┌───────────────┐     ┌───────────────┐           ┌───────────────┐
            │  Complete FLV │     │  fMP4 Slices  │           │  TS Slices    │
            │  File         │     │  (Live)       │           │  + m3u8 Index │
            │  (After Push) │     └───────┬───────┘           └───────────────┘
            └───────────────┘             │
                                          │ Auto-merge after push ends
                                          ▼
                                    ┌───────────────┐
                                    │  Complete MP4 │
                                    │  File (VOD)   │
                                    └───────────────┘
```

### Task Independence Description

| Recording Task | Real-time | Output                                    | Purpose                                      | Independent Switch |
| -------------- | --------- | ----------------------------------------- | -------------------------------------------- | ------------------ |
| **FLV Record** | Yes       | Complete FLV file                         | Raw format backup, VLC/ffplay playback       | `FLV_TO_RECORD`    |
| **fMP4 Slice** | Yes       | fMP4 slices → merged to MP4 after push    | Browser MSE playback, VOD playback           | `FLV_TO_MP4`       |
| **HLS Slice**  | Yes       | TS slices + m3u8                          | Mobile compatibility, HLS live streaming     | `FLV_TO_HLS`       |

## 📁 Project Directory Structure

```
rtmp_server/
├── flv/                              # FLV raw recorded files (FLV_TO_RECORD)
├── mp4/                              # MP4/fMP4 transcoding output (FLV_TO_MP4)
├── hls/                              # HLS TS slices + m3u8 index (FLV_TO_HLS)
├── MediaServer/                      # RTMP core protocol, push/pull session logic
├── Root/                             # Low-level async IO, Socket event driver (includes epoll adaptive)
├── SabreAMF/                         # AMF0/AMF3 encoding/decoding
├── server.php                        # Origin server entry point
├── fileGateway.php                   # Static file gateway (supports epoll, 20k+ concurrency)
├── flvGateway.php                    # FLV live gateway (supports epoll, 20k+ concurrency)
├── pusher.php                        # FLV static file push client
├── push.html                         # WebSocket push test page (browser push)
├── *.html                            # Web player pages
└── README.md
```

## 📈 Concurrency Performance Test

The following tests were completed in the same environment (**Docker container, ulimit -n 65535**) using the same stress testing script with 20,000 concurrent clients, each pulling stream for 5 seconds.

### Origin Server (RTMP Origin)
```
Current container pids.max: unknown
Starting batch: 1000 clients (20 batches total)
All clients started, waiting for completion...

===== Results =====
Success: 17,330
Failed: 2,670
```

### FLV Live Gateway
```
Current container pids.max: unknown
Starting batch: 1000 clients (20 batches total)
All clients started, waiting for completion...

===== Results =====
Success: 19,923
Failed: 77
```

### Static File Gateway
```
Concurrency: 20,000
Duration per client: 5s
Batch size: 1000

===== Results =====
Success: 20,000
Failed: 0
```

> **Explanation**:
> - The origin server, handling RTMP/HTTP-FLV/WS-FLV triple protocol push, multi-protocol remuxing, and other business logic, still stably supports **17,330** concurrent successes. The small number of failures is due to instantaneous port collisions during testing.
> - FLV gateway focuses purely on traffic forwarding, achieving **99.6%** success rate (19,923/20,000), approaching the single-machine TCP port pool limit.
> - Static file gateway is extremely lightweight, achieving **20,000 concurrent successes with zero failures**.
> - All components adapt automatically to the OS: **Linux automatically enables epoll, breaking through the traditional select 1024 limit**.

## ❓ FAQ

### 1. Why can a single process support 20,000+ concurrency?

- **Linux**: When the server detects that the `event` extension is installed, **automatically enables epoll event-driven model**, no longer limited by traditional `select`'s 1024 file descriptor limit. A single process easily handles 20,000+ connections.
- **Windows**: Due to lack of `event` extension, automatically falls back to `select` model, single process connection limit is limited (~256). It is recommended to deploy multiple instances.
- **Performance Test Results**: In Docker container (ulimit -n 65535), static file gateway achieves **20,000 concurrent connections with zero failures**, FLV gateway achieves 99.6% success rate.

### 2. How can gateways support higher concurrency?

| Scaling Method     | Description                                           | Example                                              |
| ------------------ | ----------------------------------------------------- | ---------------------------------------------------- |
| **Single Process** | Linux epoll mode: single process handles 20k+         | One fileGateway process handles 20,000 static requests |
| **Horizontal Scaling** | Multiple instances at same level, load balancing | 3 fileGateway instances → 60,000+ concurrency        |
| **Vertical Scaling**   | Multi-level cascading                              | Level 1 → Level 2 → Level 3 gateway...              |
| **Combined Scaling**   | Horizontal + vertical combined                     | 3 instances × 3 levels = theoretical 180,000+ concurrency |

### 3. When is gateway deployment needed?

| Concurrency Scenario            | Deployment Plan                                         |
| ------------------------------- | ------------------------------------------------------- |
| **Low** (< 500)                 | Origin only, origin built-in HTTP service directly exposed |
| **Medium** (500 – 5,000)        | Origin + Single-layer gateway (1–2 instances is enough) |
| **High** (> 5,000)              | Origin + Multi-level gateway cluster (each layer can scale horizontally) |

### 4. What's the difference between FLV Gateway and Static File Gateway?

| Gateway Type          | Purpose                      | Resource Types Handled                           | Scaling Method              |
| --------------------- | ---------------------------- | ------------------------------------------------ | --------------------------- |
| **FLV Live Gateway**  | Live stream distribution     | HTTP-FLV real-time streams                       | Horizontal + Vertical, supports cascading |
| **Static File Gateway** | Static resource hosting    | HLS/fMP4/MP4/FLV static files + Web player pages | Horizontal + Vertical, can combine with Nginx |

### 5. What are the advantages of WebSocket-FLV push?

- **Native browser support**: No plugins or push software installation needed, push directly from web page
- **Lowered push barrier**: Non-technical users can quickly test live streaming functionality via built-in test page
- **Mobile friendly**: Supports mobile browser camera push (requires HTTPS environment)
- **Coexists with HTTP-FLV**: Both protocols work independently without affecting each other

### 6. How to verify gateway concurrency capability?

```bash
# Use built-in stress test script (20000 concurrency)
sh play.sh

# Or use ab (Apache Bench) to test static file gateway
ab -n 10000 -c 500 http://127.0.0.1:8100/index.html

# Use wrk to test FLV gateway
wrk -t4 -c1000 -d30s http://127.0.0.1:8080/live/stream.flv
```

## Tool Package
The protocol conversion from this project has been extracted into an independent tool package `xiaosongshu/flv2mp4`, available at `https://github.com/2723659854/flv2mp4`, supporting flv, mp4, hls transcoding, flv and file gateways, and flv static file push client.

## 📄 License

This project is for learning and technical research purposes only. Users assume all risks for commercial deployment.

## ⚠️ Disclaimer

1. Some open source code is sourced from the open source community. If copyright is involved, please contact the author for removal.
2. The project is completely open source and free, for technical exchange only.
3. The author assumes no joint liability for any legal consequences arising from commercial/illegal use by users.

## 📧 Contact
- 📬 Email: 2723659854@qq.com
- 🐙 GitHub: [2723659854](https://github.com/2723659854)
