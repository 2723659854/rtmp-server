# RTMP Server

<p align="center">
  <a href="./README.cn.md"><strong>🇨🇳 中文</strong></a> •
  <a href="./README.md"><strong>🇬🇧 English</strong></a>
</p>

> A lightweight RTMP live streaming server written in pure PHP, with **no third-party streaming service dependencies**. Deploy your private live streaming platform out of the box.
> **Automatically enables epoll event-driven I/O on Linux, easily handling 20,000+ concurrent connections in a single process. Falls back to select mode on Windows for compatibility.**

## 🏗️ System Architecture

```
                                                    【Publishing】OBS/FFmpeg
                                                         │
                                       RTMP Push(1935)  /  HTTP-FLV Push(8501)
                                                         │
                                                         ▼
╔══════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════╗
║                                                      RTMP Origin Server (Core)                                                   ║
║                                                                                                                                    ║
║  📥 Stream Ingest   RTMP / HTTP-FLV dual-protocol publishing, link authentication                                                 ║
║  🔄 Protocol Conv   RTMP / HTTP-FLV → HTTP-FLV / WebSocket-FLV / HLS / fMP4 / MP4                                                  ║
║  💾 Live Recording  ┌──────────────┬──────────────┬──────────────┐                                                                  ║
║                     │  FLV Record  │  fMP4 Segs   │  HLS Segs    │  Three independent parallel tasks                                ║
║                     │  (real-time) │  (real-time) │  (real-time) │                                                                    ║
║                     └──────────────┴──────────────┴──────────────┘                                                                  ║
║  📤 Live Output     HTTP-FLV(8501) / WebSocket-FLV / HLS Live / fMP4 Live                                                          ║
║  📦 VOD Production  fMP4 segments generated in real-time → auto-merged into complete MP4 after stream ends                          ║
║  📁 Static Serving  Origin server built-in HTTP service (port 80), direct static file access (for low-concurrency scenarios)        ║
╚══════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════╝
│
┌───────────────────────┼───────────────────────┐
│                       │                       │
▼                       ▼                       ▼
HTTP-FLV(8501)          HLS(TS/m3u8)           fMP4(segments)
Real-time Stream        Static Files            Static Files
│                       │                       │
│                       │                       │
▼                       ▼                       ▼
┌─────────────────┐     ┌─────────────────────────────────────────────────┐
│  FLV Gateway    │     │            Static File Gateway Cluster           │
│    Cluster      │     │         🎯 Hosting: HLS / fMP4 / MP4 / FLV / Web│
│                 │     │                                                 │
│  ┌───────────┐  │     │  ┌───────────┐  ┌───────────┐  ┌───────────┐   │
│  │  Tier 1   │  │     │  │  Node 1   │  │  Node 2   │  │  Node 3   │   │
│  │  (8080)   │  │     │  │  (8100)   │  │  (8101)   │  │  (8102)   │   │
│  └─────┬─────┘  │     │  └─────┬─────┘  └─────┬─────┘  └─────┬─────┘   │
│        │        │     │        │              │              │         │
│  ┌─────┴─────┐  │     │        ▼              ▼              ▼         │
│  ▼     ▼     ▼  │     │   ┌──────────────────────────────────────┐    │
│ ┌───┐ ┌───┐ ┌───┐│     │   │              Clients               │    │
│ │Sub│ │Sub│ │Sub││     │   │   HLS Player / MSE Player / VOD / ffplay │    │
│ │GW │ │GW │ │GW ││     │   └──────────────────────────────────────┘    │
│ └─┬─┘ └─┬─┘ └─┬─┘│     │                                                 │
│   │     │     │  │     └─────────────────────────────────────────────────┘
│   ▼     ▼     ▼  │
│ ┌──────────────┐ │
│ │   Clients    │ │
│ │ FLV Player / ffplay │
│ └──────────────┘ │
└─────────────────┘
```

### Architecture Overview

- **Origin Server**: The sole stream production node, supporting **RTMP and HTTP-FLV dual-protocol publishing**. Handles ingest, multi-protocol repackaging. **FLV recording, fMP4 segmentation, and HLS segmentation run as three fully independent parallel tasks** with zero blocking.

- **Origin Static Capability**: The origin server includes a built-in HTTP service (default port 80) for direct static file access. **No additional gateway deployment needed for low-concurrency scenarios** — ready to use out of the box.

- **Live Recording Mechanism**:
  - **FLV Recording**: Saves raw streams in real-time, producing a complete FLV file after the stream ends
  - **fMP4 Segmentation**: Generates audio/video fMP4 segments in real-time (supports both merged and separate segment formats), auto-merged into a complete MP4 after the stream ends
  - **HLS Segmentation**: Generates TS segments + m3u8 index in real-time (mobile compatible)
  - **Independent Toggles**: Each recording task can be independently enabled/disabled in `server.php`

- **FLV Live Gateway Cluster**: A pure stream relay service. Pulls HTTP-FLV streams from upstream, caches GOP keyframes for instant playback on new connections, and distributes to end clients or downstream gateways.
  - **Unlimited Cascade**: Tier 1 → Tier 2 → Tier 3 → ... → Clients
  - **Horizontal Scaling**: Deploy multiple gateway instances at the same tier, distribute traffic via load balancing
  - **Linux epoll High Performance**: Single process handles 20,000+ concurrent connections, Windows falls back to select model

- **Static File Gateway Cluster (Recommended)**: Lightweight HTTP static file server, unified hosting for all static resources.
  - **Supported Protocols**: HLS (.m3u8/.ts), fMP4 (.m4s/.mp4), MP4 VOD files, FLV recording files, Web player pages
  - **Horizontal Scaling**: Deploy multiple gateway instances at the same tier, linearly increasing concurrency capacity
  - **Vertical Scaling**: Multi-tier traffic distribution via reverse proxies such as Nginx
  - **Linux epoll High Performance**: Single process handles 20,000+ concurrent connections, Windows falls back to select model
  - **Best Practice**: Point HLS/fMP4/MP4 playback paths to this gateway cluster for static resource read-write separation

- **Deployment Recommendations**:
  - **Low Concurrency** (< 500 concurrent): Use the origin server's built-in HTTP service directly, no additional gateways needed
  - **Medium Concurrency** (500 – 5,000 concurrent):
    - Origin + single-tier gateway cluster (FLV Gateway or Static File Gateway)
    - A single gateway process is usually sufficient, no multi-instance needed
  - **High Concurrency** (> 5,000 concurrent):
    - Origin focuses on "publishing, protocol conversion, live recording"
    - **FLV Gateway Multi-tier Cluster**: Tier 1 → Tier 2 → Clients
    - **Static File Gateway Multi-tier Cluster**: Tier 1 → Tier 2 → Clients
    - Each tier supports horizontal scaling, linearly increasing concurrency capacity

## ✨ Features

- 🎥 **Dual-Protocol Publishing**: Full implementation of standard RTMP push/pull + HTTP-FLV push/pull, compatible with mainstream streaming tools
- 📡 **HTTP-FLV / WebSocket-FLV**: Low-latency browser streaming solution, supports direct playback via ffplay and other players
- 🧩 **HLS Auto-Segmentation**: Real-time m3u8 + TS generation, full platform mobile compatibility
- 📦 **fMP4 Real-time Segmentation + Auto-Merge**: Generates fMP4 segments in real-time during live streaming, auto-merged into complete MP4 after stream ends
- 🎬 **Dual fMP4 Format Support**: Supports both merged audio/video segments and separate audio/video segments
- 💾 **FLV Independent Recording**: Saves raw FLV streams in real-time, decoupled from fMP4/MP4
- 🎛️ **Independent Task Toggles**: FLV recording, fMP4 segmentation, and HLS segmentation can each be independently enabled/disabled
- 🖥️ **Built-in Multiple Web Players**: Ready to use out of the box, supports FLV/HLS/MP4/Merged fMP4/Separate fMP4 playback
- 🚀 **Cascading FLV Streaming Gateway**: Unlimited tier relay, GOP-cached instant startup, auto-reconnect on stream loss, designed for high-concurrency live scenarios
- 📁 **Static File Gateway**: Unified hosting for HLS/fMP4/MP4 recorded resources and playback pages, designed for high-concurrency VOD scenarios
- 🎞️ **Cross-Platform Playback Compatibility**: Supports ffplay, VLC, browsers, mobile players, and other mainstream playback terminals
- 🐳 **Docker One-Click Deployment**: Quickly spin up a test environment
- ⚡ **Native Pure PHP Implementation**: No third-party streaming software dependencies

## 📋 Requirements

- PHP >= 8.1 (CLI mode only)
- Required extension: `sockets`
- Recommended extension: `event` (significantly improves concurrency on Linux by automatically enabling epoll)

## 🚀 Quick Start

### 1. Install
```bash
composer create-project xiaosongshu/rtmp_server
```

### 2. Configure Recording Toggles (`server.php`)
```php
// Three independent recording task toggles, enable/disable as needed
define('FLV_TO_RECORD', true);   // Enable real-time FLV raw file recording
define('FLV_TO_MP4', true);      // Enable real-time fMP4 segmentation and MP4 merging
define('FLV_TO_HLS', true);      // Enable real-time HLS (TS) segmentation
```

### 3. Start Origin Server
```bash
php server.php
```

### 4. Access Playback (use origin directly for low-concurrency scenarios)
```bash
# Player page access (origin built-in HTTP service)
http://127.0.0.1/index.html      # FLV live page
http://127.0.0.1/play.html       # HLS live page
http://127.0.0.1/mp4.html        # MP4 VOD page
http://127.0.0.1/play_merge.html # fMP4 segment VOD page (supports merged/separate formats)
```

### 5. Medium/High Concurrency: Deploy Static File Gateway Cluster (Recommended)

> **Use Case**: High-concurrency access for HLS(.ts/.m3u8), fMP4(.m4s/.mp4), MP4 VOD files, and Web pages.
> A single gateway process on Linux handles 20,000+ connections. For higher loads, scale horizontally with multiple instances.

```bash
# Start single instance (handles high concurrency directly under epoll)
php fileGateway.php 0.0.0.0 8100

# 【Horizontal Scaling】Multi-instance deployment (for extreme concurrency or multi-server load balancing)
php fileGateway.php 0.0.0.0 8100
php fileGateway.php 0.0.0.0 8101
php fileGateway.php 0.0.0.0 8102

# Linux/macOS background running
php fileGateway.php 0.0.0.0 8100 > /dev/null 2>&1 &

# Vertical Scaling: Multi-tier distribution via Nginx reverse proxy
# Tier 1 Nginx -> Tier 2 fileGateway (8100/8101/8102) -> Tier 3 fileGateway ...
```

Access examples (via Static File Gateway):
```
http://127.0.0.1:8100/play.html       # Access HLS player page via gateway
http://127.0.0.1:8100/hls/live/stream/index.m3u8  # Access HLS stream via gateway
```

### 6. Medium/High Concurrency: Deploy FLV Live Gateway Cluster

> A single FLV gateway process on Linux stably supports nearly 20,000 concurrent viewers.

```bash
# Tier 1 Gateway: Pull from origin
php flvGateway.php 8080 http://ORIGIN_IP:8501

# Horizontal Scaling: Deploy multiple instances at the same tier
php flvGateway.php 8081 http://ORIGIN_IP:8501
php flvGateway.php 8082 http://ORIGIN_IP:8501

# Vertical Scaling: Multi-tier cascade
php flvGateway.php 8080 http://ORIGIN_IP:8501      # Tier 1
php flvGateway.php 8081 http://127.0.0.1:8080       # Tier 2 (pulls from Tier 1)
php flvGateway.php 8082 http://127.0.0.1:8081       # Tier 3 (pulls from Tier 2)
```

### 7. Stop Service
| OS          | Command        |
| ----------- | -------------- |
| Windows     | `Ctrl + C`     |
| Linux/macOS | `kill -9 PID`  |

## 🔧 Port Configuration (Modify in `server.php`)

| Port  | Protocol        | Purpose                                               |
| ----- | --------------- | ----------------------------------------------------- |
| 1935  | RTMP            | RTMP publishing and playback                          |
| 8501  | HTTP/WebSocket  | HTTP-FLV publishing/playback / WS-FLV live streaming  |
| 80    | HTTP            | Static file service + Web player pages                |

## 🚀 FLV Streaming Gateway (High-Concurrency Live Distribution)

### Gateway Introduction

A lightweight stream distribution component supporting unlimited cascade deployment. Pulls HTTP-FLV from upstream origin/gateway, caches stream headers and GOP keyframes for instant startup on new connections, and replicates stream data to downstream clients or sub-gateways. **Designed for medium to high-concurrency live scenarios**, supports both horizontal and vertical scaling.

### Gateway Core Capabilities

- 📡 Multi-stream concurrent relay in a single instance, handling different channel distributions simultaneously
- 🔄 Unlimited cascade, Tier 1 → Tier 2 → Tier 3 chained expansion
- ⚡ GOP pre-caching, no keyframe wait for new connections, instant playback
- 🔁 Automatic reconnection on upstream stream loss, transparent to end users
- 📊 Built-in runtime statistics, outputs viewer count and traffic every 10 seconds
- 🚀 **Horizontal Scaling**: Add gateway processes/instances at the same tier, linearly increasing concurrency
- 🚀 **Vertical Scaling**: Multi-tier cascade, distributing single-point pressure
- 🧠 **Adaptive I/O**: Linux auto-enables epoll, single process 20,000+ concurrent; Windows falls back to select for compatibility

### FLV Gateway Startup Commands

```bash
# 【Horizontal Scaling】Single tier, multiple instances
php flvGateway.php 8080 http://ORIGIN_IP:8501
php flvGateway.php 8081 http://ORIGIN_IP:8501
php flvGateway.php 8082 http://ORIGIN_IP:8501

# 【Vertical Scaling】Multi-tier cascade
php flvGateway.php 8080 http://ORIGIN_IP:8501      # Tier 1
php flvGateway.php 8081 http://127.0.0.1:8080       # Tier 2
php flvGateway.php 8082 http://127.0.0.1:8081       # Tier 3

# 【Combined Scaling】Multi-tier + multi-instance per tier
# Tier 1 Gateway Cluster
php flvGateway.php 8080 http://ORIGIN_IP:8501
php flvGateway.php 8081 http://ORIGIN_IP:8501
# Tier 2 Gateway Cluster (pulls from Tier 1)
php flvGateway.php 8180 http://127.0.0.1:8080
php flvGateway.php 8181 http://127.0.0.1:8081
```

### Gateway Playback URL Format

```
http://GATEWAY_IP:PORT/{app}/{channel}.flv
```

Example:
```
# Tier 1 Gateway
http://127.0.0.1:8080/live/stream.flv
# Tier 2 Gateway
http://127.0.0.1:8081/live/stream.flv
```

### Debug Logging

Add `$gateway->debug = true;` in the gateway startup script to enable full detailed runtime logs.

## 📁 Static File Gateway `fileGateway.php` (High-Concurrency VOD Resource Hosting)

### Gateway Introduction

A lightweight HTTP static file server for unified hosting of all static resources. **This is the recommended playback method for file-based protocols such as HLS, fMP4, and MP4**. Supports horizontal and vertical scaling to handle large-scale VOD concurrency.

### Core Capabilities

- 📁 Unified hosting for all static resources (recorded files + playback pages)
- 🔗 **Horizontal Scaling**: Multi-instance deployment with load-balanced traffic distribution
- 🔗 **Vertical Scaling**: Multi-tier cascade (e.g., Nginx + fileGateway + backend storage)
- 📊 Built-in access logs for statistical analysis
- 🚀 Pure PHP implementation, lightweight with no dependencies
- 🧠 **Adaptive I/O**: Linux epoll, single process 20,000+ concurrent; Windows select compatible
- **💡 Best Practice**: Point HLS/fMP4/MP4 playback paths to this gateway cluster, letting the origin server focus solely on writing files — achieving read-write separation

### Startup Commands (Multi-process/Multi-instance Distribution)

```bash
# Basic startup (host current directory, port 8100)
php fileGateway.php 0.0.0.0 8100

# 【Horizontal Scaling】Multi-instance deployment
php fileGateway.php 0.0.0.0 8100
php fileGateway.php 0.0.0.0 8101
php fileGateway.php 0.0.0.0 8102

# Linux/macOS background multi-instance
php fileGateway.php 0.0.0.0 8100 > /dev/null 2>&1 &
php fileGateway.php 0.0.0.0 8101 > /dev/null 2>&1 &
php fileGateway.php 0.0.0.0 8102 > /dev/null 2>&1 &
```

### Nginx Reverse Proxy Configuration Example

```nginx
upstream filegateway_cluster {
    # Horizontal Scaling: multiple fileGateway instances
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
http://GATEWAY_IP:PORT/{relative_file_path}
```

Example:
```
# Web player pages (accessed via static gateway)
http://127.0.0.1:8100/index.html      # FLV live page
http://127.0.0.1:8100/play.html       # HLS live page
http://127.0.0.1:8100/mp4.html        # MP4 VOD page
http://127.0.0.1:8100/video.html      # FLV VOD page
http://127.0.0.1:8100/play_merge.html # fMP4 segment VOD page

# Recorded resource access
http://127.0.0.1:8100/hls/live/stream/index.m3u8
http://127.0.0.1:8100/mp4/live/stream/output_merge/init.mp4
http://127.0.0.1:8100/mp4/live/stream/output_merge/stream_full.mp4
http://127.0.0.1:8100/flv/live/stream/20240101_120000.flv
```

## 📡 Publishing Guide

The project supports both **RTMP** and **HTTP-FLV** mainstream publishing protocols, compatible with OBS, FFmpeg, and various streaming tools.

### 1. RTMP Publishing
#### URL Format
```
rtmp://127.0.0.1:1935/{app}/{channel}
```
- `app`: e.g., `live`
- `channel`: e.g., `stream`
- Only alphanumeric characters supported

#### Publishing Examples
1. **OBS Studio**
  1. Download and install [OBS Studio](https://obsproject.com/)
  2. Settings → Stream → Server: `rtmp://127.0.0.1:1935/live`
  3. Stream Key: `stream`
  4. Start Streaming

2. **FFmpeg Loop Publishing**
```bash
ffmpeg -re -stream_loop -1 -i "video.mp4" -vcodec h264 -acodec aac -f flv rtmp://127.0.0.1:1935/live/stream
```

### 2. HTTP-FLV Publishing
#### URL Format
```
http://127.0.0.1:8501/{app}/{channel}
```
- `app` and `channel` follow the same rules as RTMP, alphanumeric only

#### Publishing Examples (FFmpeg)
```bash
# Local FLV file publishing
ffmpeg -re -i test.flv -c:v libx264 -c:a aac -f flv http://127.0.0.1:8501/live/stream

# Local MP4 file loop publishing
ffmpeg -re -stream_loop -1 -i video.mp4 -c:v libx264 -c:a aac -f flv http://127.0.0.1:8501/live/stream
```
You can also use the built-in client pusher provided by this project (FLV/MP4 static files only):
```bash
php pusher.php test.flv http://127.0.0.1:8501/live/stream 1.0 --no-reconnect
```
or
```bash
php pusher.php test.mp4 http://127.0.0.1:8501/live/stream 1.0 --no-reconnect
```
## 📺 Playback URL Summary & Tools

### Live Streaming URLs

| Protocol       | URL                                                         | Description                           | Distribution Recommendation |
| -------------- | ----------------------------------------------------------- | ------------------------------------- | --------------------------- |
| RTMP           | `rtmp://127.0.0.1:1935/live/stream`                        | Native RTMP players, ffplay supported | Direct from origin          |
| HTTP-FLV       | `http://127.0.0.1:8501/live/stream.flv`                     | Low-latency browser playback, ffplay  | **Distribute via FLV Gateway Cluster** |
| WebSocket-FLV  | `ws://127.0.0.1:8501/live/stream.flv`                       | WebSocket streaming playback          | **Distribute via FLV Gateway Cluster** |
| HLS            | `http://{fileGateway_IP}:8100/hls/live/stream/index.m3u8`   | Preferred for Android/iOS mobile      | **Must distribute via fileGateway** |

### VOD Playback URLs (After Recording Completes)

| File Type                    | Access URL (must go through fileGateway)                             | Description |
| ---------------------------- | -------------------------------------------------------------------- | ----------- |
| Merged MP4 VOD               | `http://{fileGateway_IP}:8100/mp4/live/stream/output_merge/stream_full.mp4`  | |
| Merged fMP4 Segment VOD (MSE)| `http://{fileGateway_IP}:8100/mp4/live/stream/output_merge/init.mp4`         | |
| Separate Audio/Video fMP4 VOD| `http://{fileGateway_IP}:8100/mp4/live/stream/output_separate/audio_init.mp4`| |
| Raw FLV VOD                  | `http://{fileGateway_IP}:8100/flv/live/stream/20240101_120000.flv`           | |

> **For high-concurrency scenarios**: You must use the Static File Gateway Cluster (e.g., `127.0.0.1:8100/8101/8102`), distributing traffic via load balancing to achieve static resource read-write separation.

### Web Player Pages

| Page Purpose                     | Access URL (recommended via fileGateway)       | Description                                      |
| -------------------------------- | ---------------------------------------------- | ------------------------------------------------ |
| FLV Live Playback                | `http://{fileGateway_IP}:8100/index.html`      | HTTP-FLV low-latency live                        |
| HLS Live Playback                | `http://{fileGateway_IP}:8100/play.html`       | HLS mobile-compatible live                       |
| Merged MP4 VOD                   | `http://{fileGateway_IP}:8100/mp4.html`        | Complete MP4 file VOD                            |
| Raw FLV VOD                      | `http://{fileGateway_IP}:8100/video.html`      | FLV native file VOD                              |
| **fMP4 Segment VOD**             | `http://{fileGateway_IP}:8100/play_merge.html` | **Supports both merged and separate segment playback** |

### Command-Line Playback Tools (ffplay)
The project is fully compatible with **ffplay** player, can directly pull various stream URLs:
```bash
# Play RTMP stream
ffplay rtmp://127.0.0.1:1935/live/stream

# Play origin HTTP-FLV stream
ffplay http://127.0.0.1:8501/live/stream.flv

# Play FLV gateway relayed stream
ffplay http://127.0.0.1:8080/live/stream.flv

# Play HLS stream
ffplay http://127.0.0.1:8100/hls/live/stream/index.m3u8

# Play VOD FLV/MP4 files
ffplay http://127.0.0.1:8100/flv/live/stream/20240101_120000.flv
ffplay http://127.0.0.1:8100/mp4/live/stream/output_merge/stream_full.mp4
```

## 💾 Live Recording Details

### Recording Mechanism (Three Independent Parallel Tasks)

When publishing begins, the origin server simultaneously starts three **independent parallel** recording tasks with zero blocking:

```
                    ┌─────────────────────────────────────────────────┐
                    │        RTMP / HTTP-FLV Dual-Protocol Ingest     │
                    └─────────────────────┬───────────────────────────┘
                                          │
                    ┌─────────────────────┼───────────────────────────┐
                    │                     │                           │
                    ▼                     ▼                           ▼
            ┌───────────────┐     ┌───────────────┐           ┌───────────────┐
            │ FLV Recording │     │ fMP4 Segments │           │ HLS Segments  │
            │  (real-time)  │     │  (real-time)  │           │  (real-time)  │
            └───────┬───────┘     └───────┬───────┘           └───────┬───────┘
                    │                     │                           │
                    ▼                     ▼                           ▼
            ┌───────────────┐     ┌───────────────┐           ┌───────────────┐
            │ Complete FLV  │     │ fMP4 Segments │           │ TS Segments   │
            │ (stream ends) │     │  (during live)│           │ + m3u8 index  │
            └───────────────┘     └───────┬───────┘           └───────────────┘
                                          │
                                          │ Auto-merge after stream ends
                                          ▼
                                    ┌───────────────┐
                                    │ Complete MP4  │
                                    │ (VOD playback)│
                                    └───────────────┘
```

### Task Independence

| Recording Task  | Real-time  | Output                     | Purpose                       | Toggle              |
| --------------- | ---------- | -------------------------- | ----------------------------- | ------------------- |
| **FLV Record**  | Yes        | Complete FLV file          | Raw format backup, VLC/ffplay | `FLV_TO_RECORD`     |
| **fMP4 Segs**   | Yes        | fMP4 segments → merged MP4 | Browser MSE playback, VOD     | `FLV_TO_MP4`        |
| **HLS Segs**    | Yes        | TS segments + m3u8         | Mobile compatibility, HLS live| `FLV_TO_HLS`        |

## 📁 Project Directory Structure

```
rtmp_server/
├── flv/                              # FLV raw recording files (FLV_TO_RECORD)
├── mp4/                              # MP4/fMP4 transcoded output (FLV_TO_MP4)
├── hls/                              # HLS TS segments + m3u8 index (FLV_TO_HLS)
├── MediaServer/                      # RTMP core protocol, publish/play session logic
├── Root/                             # Low-level async I/O, Socket event-driven (includes epoll adaptive)
├── SabreAMF/                         # AMF0/AMF3 codec
├── server.php                        # Origin server entry point
├── fileGateway.php                   # Static file gateway (epoll supported, 20k+ concurrent)
├── flvGateway.php                    # FLV live gateway (epoll supported, 20k+ concurrent)
├── pusher.php                        # FLV Static File Push Client
├── *.html                            # Web player pages
└── README.md
```

## 📈 Concurrency Performance Benchmarks

All tests below were conducted in the **same Docker container environment with `ulimit -n 65535`**, using the same stress test script with 20,000 concurrent clients, each pulling streams for 5 seconds.

### Origin Server (RTMP Origin)
```
Current container pids.max: unknown
Starting batch: 1000 clients (total 20 batches)
All clients started, waiting for completion...

===== Results =====
Success: 17,330
Failed: 2,670
```

### FLV Live Gateway
```
Current container pids.max: unknown
Starting batch: 1000 clients (total 20 batches)
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

> **Notes**:
> - The origin server handles RTMP/HTTP-FLV dual-protocol publishing, multi-protocol repackaging, and other business logic, yet a single process still stably achieves **17,330** successful connections. Minor failures are due to instantaneous port collisions during testing.
> - FLV Gateway, focused purely on stream relay, achieves a **99.6% success rate** (19,923/20,000), approaching the single-machine TCP port pool limit.
> - Static File Gateway is extremely lightweight, achieving **20,000 concurrent connections with zero failures**.
> - All components adapt to the operating system: **epoll is automatically enabled on Linux, breaking through the traditional select 1024 limit**.

## ❓ FAQ

### 1. How can a single process support 20,000+ concurrent connections?

- **Linux**: When the server detects the `event` extension is installed, it **automatically enables the epoll event-driven model**, no longer constrained by the traditional `select` 1024 file descriptor limit. A single process easily handles 20,000+ connections.
- **Windows**: Since the `event` extension is unavailable, it automatically falls back to `select` model, with limited connections per process (~256). Deploying multiple instances is recommended.
- **Performance Test**: In a Docker container (ulimit -n 65535), the Static File Gateway achieved **20,000 concurrent with zero failures**, FLV Gateway achieved 99.6% success rate.

### 2. How can gateways support even higher concurrency?

| Scaling Method | Description | Example |
|---------------|-------------|---------|
| **Single Process High Performance** | Linux epoll mode, single process handles 20k+ | One fileGateway process handles 20,000 static requests |
| **Horizontal Scaling** | Multiple instances at the same tier, load balanced | 3 fileGateway instances → 60,000+ concurrent |
| **Vertical Scaling** | Multi-tier cascade | Tier 1 → Tier 2 → Tier 3... |
| **Combined Scaling** | Horizontal + Vertical combined | 3 instances per tier × 3 tiers = theoretically 180,000+ concurrent |

### 3. When do I need to deploy gateways?

| Concurrency Scenario                   | Deployment Plan                                      |
| -------------------------------------- | ---------------------------------------------------- |
| **Low** (< 500)                        | Origin server only, built-in HTTP service directly exposed |
| **Medium** (500 – 5,000)               | Origin + single-tier gateway (1-2 instances)         |
| **High** (> 5,000)                     | Origin + multi-tier gateway cluster (each tier horizontally scalable) |

### 4. What's the difference between FLV Gateway and Static File Gateway?

| Gateway Type        | Purpose                 | Resource Types Handled                     | Scaling Method              |
| ------------------- | ----------------------- | ------------------------------------------ | --------------------------- |
| **FLV Live Gateway**| Live stream distribution| HTTP-FLV real-time streams                 | Horizontal + Vertical, cascade supported |
| **Static File Gateway**| Static resource hosting| HLS/fMP4/MP4/FLV static files + Web pages  | Horizontal + Vertical, can integrate with Nginx |

### 5. How to verify gateway concurrency capacity?

```bash
# Use built-in stress test script (20,000 concurrent)
sh play.sh

# Or use ab (Apache Bench) to test Static File Gateway
ab -n 10000 -c 500 http://127.0.0.1:8100/index.html

# Use wrk to test FLV Gateway
wrk -t4 -c1000 -d30s http://127.0.0.1:8080/live/stream.flv
```

## Toolkit
The protocol conversion functionality of this project has been extracted into a standalone toolkit `xiaosongshu/flv2mp4`, available at `https://github.com/2723659854/flv2mp4`. It supports FLV, MP4, HLS transcoding, FLV and File gateways, as well as an FLV static file push client.

## 📄 License

This project is limited to learning and technical research use. Commercial deployment risks are borne by the user.

## ⚠️ Disclaimer

1. Some open-source code originates from the open-source community. If copyright is involved, please contact the author for removal.
2. This project is fully open-source and free, intended only for technical exchange.
3. The author assumes no joint liability for any legal consequences arising from commercial or illegal use by users.

## 📧 Contact
- 📬 Email: 2723659854@qq.com
- 🐙 GitHub: [2723659854](https://github.com/2723659854)