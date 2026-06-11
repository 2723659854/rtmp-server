# RTMP Server
<p align="center">
  <a href="./README.cn.md"><strong>🇨🇳 中文</strong></a> •
  <a href="./README.md"><strong>🇬🇧 English</strong></a>
</p>

> A lightweight RTMP live streaming server written purely in PHP. **No third-party streaming services required**, you can quickly build a private live streaming platform out of the box.
> **Epoll event-driven is automatically enabled on Linux, supporting 20,000+ concurrent connections with a single process. Falls back to Select mode on Windows for full compatibility.**

## 🏗️ System Architecture
```
                                                    [Streamer] OBS/FFmpeg
                                                         │
                                RTMP Push(1935)  /  HTTP-FLV Push(8501)
                                                         │
                                                         ▼
╔══════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════╗
║                                                  RTMP Origin Server (Core)                                                    ║
║                                                                                                                              ║
║  📥 Stream Ingest      RTMP / HTTP-FLV dual-protocol push stream & connection authentication                                ║
║  🔄 Protocol Conversion  RTMP / HTTP-FLV → HTTP-FLV / WebSocket-FLV / HLS / fMP4 / MP4                                       ║
║  💾 Real-time Recording  ┌──────────────┬──────────────┬──────────────┐                                                     ║
║                          │ FLV Record    │ fMP4 Segment │ HLS Segment  │  Three fully independent parallel tasks             ║
║                          │ (Raw Stream) │ (Live Split) │ (Live Split) │                                                     ║
║                          └──────────────┴──────────────┴──────────────┘                                                     ║
║  📤 Live Output        HTTP-FLV(8501) / WebSocket-FLV / HLS Live Stream / fMP4 Live Stream                                  ║
║  📦 VOD Production     Generate fMP4 segments in real time → Auto merge into complete MP4 after stream ends                  ║
║  📁 Static Service     Built-in HTTP service (Port 80) for static file access (for low-concurrency scenarios)                ║
╚══════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════╝
│
┌───────────────────────┼───────────────────────┐
│                       │                       │
▼                       ▼                       ▼
HTTP-FLV(8501)         HLS(TS/m3u8)           fMP4(Segments)
Live Stream            Static Files            Static Files
│                       │                       │
│                       │                       │
▼                       ▼                       ▼
┌─────────────────┐     ┌─────────────────────────────────────────────────┐
│  FLV Gateway Cluster │     │        Static File Gateway Cluster (fileGateway)       │
│                 │     │  🎯 Host: HLS / fMP4 / MP4 / FLV / Web Pages   │
│  ┌───────────┐  │     │                                                 │
│  │ L1 Gateway│  │     │  ┌───────────┐  ┌───────────┐  ┌───────────┐   │
│  │ (8080)    │  │     │  │ Node 1    │  │ Node 2    │  │ Node 3    │   │
│  └─────┬─────┘  │     │  │ (8100)    │  │ (8101)    │  │ (8102)    │   │
│        │        │     │  └─────┬─────┘  └─────┬─────┘  └─────┬─────┘   │
│  ┌─────┴─────┐  │     │        │              │              │         │
│  ▼     ▼     ▼  │     │        ▼              ▼              ▼         │
│ ┌───┐ ┌───┐ ┌───┐│     │   ┌──────────────────────────────────────┐    │
│ │Sub│ │Sub│ │Sub││     │   │         Client Endpoint               │    │
│ │Gtw│ │Gtw│ │Gtw││     │   │ HLS Player / MSE Player / VOD / ffplay│   │
│ └─┬─┘ └─┬─┘ └─┬─┘│     │   └──────────────────────────────────────┘    │
│   │     │     │  │     │                                                 │
│   ▼     ▼     ▼  │     └─────────────────────────────────────────────────┘
│ ┌──────────────┐ │
│ │    Client    │ │
│ │ FLV Player / ffplay │
│ └──────────────┘ │
└─────────────────┘
```

### Architecture Description
- **Origin Server**: The sole stream producer. Supports **RTMP and HTTP-FLV dual-protocol stream push**, stream pull and protocol transcoding. FLV recording, fMP4 segmentation and HLS segmentation run as completely independent parallel tasks without blocking each other.
- **Built-in Static Service**: Embedded HTTP server (default port 80) for static file access. **No extra gateways required for low-concurrency scenarios**.
- **Real-time Recording Mechanism**
    - **FLV Recording**: Save raw stream in real time, output complete FLV file after stream stops.
    - **fMP4 Segmentation**: Generate audio/video fMP4 segments in real time (supports combined & separate modes), auto merge into full MP4 when stream ends.
    - **HLS Segmentation**: Generate TS segments + m3u8 index for full mobile compatibility.
    - **Independent Switches**: Toggle each recording task separately in `server.php`.

- **FLV Live Gateway Cluster**: Lightweight stream forwarder. Pull HTTP-FLV from upstream, cache GOP frames for instant playback, and distribute streams to clients or sub-gateways.
    - Unlimited hierarchical cascading: L1 → L2 → L3 → ... → Clients
    - Horizontal scaling: Deploy multiple instances on the same layer with load balancing
    - High performance: Epoll on Linux for 20,000+ concurrent connections; Select fallback for Windows

- **Static File Gateway Cluster (Recommended)**: Lightweight HTTP server dedicated to static resources.
    - Supported resources: HLS (.m3u8/.ts), fMP4 (.m4s/.mp4), MP4 VOD, FLV recordings and web pages
    - Support horizontal scaling & hierarchical cascading (can work with Nginx reverse proxy)
    - High performance: Epoll on Linux for 20,000+ concurrent connections; Select fallback for Windows
    - Best Practice: Route HLS/fMP4/MP4 traffic to this cluster to separate read & write workloads.

- **Deployment Suggestions**
    - Low concurrency (< 500): Use origin built-in HTTP service only
    - Medium concurrency (500 – 5,000): Origin + single-layer gateway cluster (FLV Gateway or Static File Gateway)
    - High concurrency (> 5,000): Origin focuses on stream ingest, transcoding and recording; Deploy multi-layer FLV gateway cluster and static file gateway cluster for distribution. Scale each layer horizontally for higher load.

## ✨ Features
- 🎥 **Dual-protocol Streaming**: Full RTMP & HTTP-FLV push/pull stream implementation, compatible with mainstream streaming tools
- 📡 **HTTP-FLV / WebSocket-FLV**: Low-latency live streaming for web browsers, playable via ffplay
- 🧩 **Auto HLS Segmentation**: Generate m3u8 + TS in real time, fully compatible with mobile devices
- 📦 **fMP4 Live Segmentation & Auto Merge**: Real-time fMP4 segments, merged into complete MP4 after stream ends
- 🎬 **Dual fMP4 Modes**: Support combined audio/video segments and separate audio/video segments
- 💾 **Independent FLV Recording**: Persist original FLV raw stream, decoupled from MP4/HLS tasks
- 🎛️ **Separate Task Switches**: Enable/disable FLV recording, fMP4 segmentation and HLS segmentation individually
- 🖥️ **Built-in Web Players**: Ready-to-use pages for FLV / HLS / MP4 / fMP4 playback
- 🚀 **Cascadable FLV Gateway**: Multi-level distribution, GOP cache for instant play, auto reconnection on stream interruption
- 📁 **Static File Gateway**: Centralized hosting for recorded files and web pages for high-concurrency VOD
- 🎞️ **Wide Player Compatibility**: Works with ffplay, VLC, web browsers and mobile players
- 🐳 **One-click Docker Deployment**: Quickly launch test environment
- ⚡ **Pure Native PHP**: No dependency on third-party streaming services

## 📋 Environment Requirements
- PHP >= 8.1 (Run only in CLI mode)
- Required Extension: `sockets`
- Recommended Extension: `event` (Greatly improve concurrency with epoll on Linux)

## 🚀 Quick Start
### 1. Install Project
```bash
composer create-project xiaosongshu/rtmp_server
```

### 2. Configure Recording Switches (`server.php`)
```php
// Toggle three independent recording tasks
define('FLV_TO_RECORD', true);   // Enable real-time FLV recording
define('FLV_TO_MP4', true);      // Enable fMP4 segmentation and MP4 merging
define('FLV_TO_HLS', true);      // Enable HLS (TS) segmentation
```

### 3. Start Origin Server
```bash
php server.php
```

### 4. Access Playback (Low Concurrency, Use Origin Directly)
```bash
# Web playback pages (Origin built-in HTTP service)
http://127.0.0.1/index.html      # FLV Live Page
http://127.0.0.1/play.html       # HLS Live Page
http://127.0.0.1/mp4.html        # MP4 VOD Page
http://127.0.0.1/play_merge.html # fMP4 Segments Playback Page (Combined & Separate)
```

### 5. Deploy Static File Gateway Cluster (Medium / High Concurrency, Recommended)
> For high-concurrency access to HLS(.ts/.m3u8), fMP4(.m4s/.mp4), MP4 VOD files and web pages. A single gateway process supports 20,000+ connections on Linux. Deploy more instances for larger traffic.

```bash
# Start single instance
php fileGateway.php 0.0.0.0 8100

# Horizontal scaling: Multiple instances
php fileGateway.php 0.0.0.0 8100
php fileGateway.php 0.0.0.0 8101
php fileGateway.php 0.0.0.0 8102

# Run in background (Linux/macOS)
php fileGateway.php 0.0.0.0 8100 > /dev/null 2>&1 &

# Hierarchical scaling with Nginx reverse proxy
# Nginx L1 -> fileGateway L2 -> fileGateway L3 ...
```

Access example via static gateway:
```
http://127.0.0.1:8100/play.html       # HLS Play Page
http://127.0.0.1:8100/hls/live/stream/index.m3u8  # HLS Stream
```

### 6. Deploy FLV Live Gateway Cluster (Medium / High Concurrency)
> A single FLV gateway can stably serve nearly 20,000 playback connections on Linux.

```bash
# L1 Gateway: Pull stream from origin
php flvGateway.php 8080 http://OriginIP:8501

# Horizontal scaling: Multiple instances on same layer
php flvGateway.php 8081 http://OriginIP:8501
php flvGateway.php 8082 http://OriginIP:8501

# Hierarchical cascading
php flvGateway.php 8080 http://OriginIP:8501      # L1 Gateway
php flvGateway.php 8081 http://127.0.0.1:8080   # L2 Gateway (Pull from L1)
php flvGateway.php 8082 http://127.0.0.1:8081   # L3 Gateway (Pull from L2)
```

### 7. Stop Service
| OS          | Stop Command    |
| ----------- | --------------- |
| Windows     | `Ctrl + C`      |
| Linux/macOS | `kill -9 PID`   |

## 🔧 Port Configuration (Edit in `server.php`)
| Port  | Protocol        | Usage |
| ----- | --------------- | ----- |
| 1935  | RTMP            | RTMP stream push & pull |
| 8501  | HTTP/WebSocket  | HTTP-FLV / WS-FLV stream push & playback |
| 80    | HTTP            | Static files & web playback pages |

## 🚀 FLV Live Gateway (High-concurrency Stream Distribution)
### Gateway Introduction
Lightweight stream forwarder supporting unlimited hierarchical cascading. Pull HTTP-FLV stream from upstream, cache stream header and GOP frames to achieve instant playback, then forward data to clients or sub-gateways. Designed for large-scale live streaming scenarios, supports horizontal & vertical scaling.

### Core Capabilities
- Distribute multiple live channels concurrently in one process
- Unlimited hierarchical cascading
- GOP frame cache for instant playback
- Auto reconnection when upstream stream drops
- Runtime statistics: Online connections & traffic every 10 seconds
- Horizontal scaling: Add instances to boost capacity linearly
- Vertical scaling: Multi-layer cascading to reduce single-point pressure
- Adaptive I/O: Epoll on Linux (20k+ concurrent), Select fallback on Windows

### Startup Commands
```bash
# Horizontal scaling
php flvGateway.php 8080 http://OriginIP:8501
php flvGateway.php 8081 http://OriginIP:8501
php flvGateway.php 8082 http://OriginIP:8501

# Vertical cascading
php flvGateway.php 8080 http://OriginIP:8501
php flvGateway.php 8081 http://127.0.0.1:8080
php flvGateway.php 8082 http://127.0.0.1:8081

# Combined scaling (Multi-layer + Multi-instance per layer)
# L1 Cluster
php flvGateway.php 8080 http://OriginIP:8501
php flvGateway.php 8081 http://OriginIP:8501
# L2 Cluster
php flvGateway.php 8180 http://127.0.0.1:8080
php flvGateway.php 8181 http://127.0.0.1:8081
```

### Playback URL Format
```
http://GatewayIP:Port/{AppName}/{StreamName}.flv
```

Examples:
```
# L1 Gateway
http://127.0.0.1:8080/live/stream.flv
# L2 Gateway
http://127.0.0.1:8081/live/stream.flv
```

### Debug Log
Set `$gateway->debug = true;` in gateway script to enable full detailed logs.

## 📁 Static File Gateway `fileGateway.php` (High-concurrency VOD Hosting)
### Gateway Introduction
Lightweight HTTP server for static resources. **Recommended for HLS, fMP4 and MP4 VOD**. Supports horizontal & vertical scaling for massive concurrent access.

### Core Capabilities
- Centralized hosting for all static resources (recorded files + web pages)
- Horizontal scaling with load balancing
- Vertical cascading (Nginx + fileGateway + backend storage)
- Built-in access log for statistics
- Pure PHP implementation, lightweight with no extra dependencies
- Adaptive I/O: Epoll on Linux (20k+ concurrent), Select fallback on Windows
- Best Practice: Separate read/write workloads — origin only writes files, gateway serves files.

### Startup Commands
```bash
# Basic start (Serve current directory on port 8100)
php fileGateway.php 0.0.0.0 8100

# Horizontal scaling
php fileGateway.php 0.0.0.0 8100
php fileGateway.php 0.0.0.0 8101
php fileGateway.php 0.0.0.0 8102

# Run multiple instances in background (Linux/macOS)
php fileGateway.php 0.0.0.0 8100 > /dev/null 2>&1 &
php fileGateway.php 0.0.0.0 8101 > /dev/null 2>&1 &
php fileGateway.php 0.0.0.0 8102 > /dev/null 2>&1 &
```

### Nginx Reverse Proxy Example
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

### Access URL Format
```
http://GatewayIP:Port/{RelativeFilePath}
```

Examples:
```
# Web Pages
http://127.0.0.1:8100/index.html      # FLV Live
http://127.0.0.1:8100/play.html       # HLS Live
http://127.0.0.1:8100/mp4.html        # MP4 VOD
http://127.0.0.1:8100/video.html      # FLV VOD
http://127.0.0.1:8100/play_merge.html # fMP4 Segments Playback

# Recorded Resources
http://127.0.0.1:8100/hls/live/stream/index.m3u8
http://127.0.0.1:8100/mp4/live/stream/output_merge/init.mp4
http://127.0.0.1:8100/mp4/live/stream/output_merge/stream_full.mp4
http://127.0.0.1:8100/flv/live/stream/20240101_120000.flv
```

## 📡 Stream Push Guide
This project supports **RTMP** and **HTTP-FLV** two mainstream push protocols, compatible with OBS, FFmpeg and other streaming tools.

### 1. RTMP Stream Push
#### URL Format
```
rtmp://127.0.0.1:1935/{AppName}/{StreamName}
```
- `AppName`: e.g. `live`
- `StreamName`: e.g. `stream`
- Only letters and numbers are allowed.

#### Examples
##### OBS Studio
1. Download & install [OBS Studio](https://obsproject.com/)
2. Settings → Stream → Server: `rtmp://127.0.0.1:1935/live`
3. Stream Key: `stream`
4. Start Streaming

##### FFmpeg Loop Push
```bash
ffmpeg -re -stream_loop -1 -i "video.mp4"  -vcodec h264 -acodec aac -f flv  rtmp://127.0.0.1:1935/live/stream
```

### 2. HTTP-FLV Stream Push
#### URL Format
```
http://127.0.0.1:8501/{AppName}/{StreamName}
```
- Naming rules are the same as RTMP (letters & numbers only)

#### FFmpeg Examples
```bash
# Push local FLV file
ffmpeg -re -i test.flv -c:v libx264 -c:a aac -f flv http://127.0.0.1:8501/a/b

# Loop push local MP4 file
ffmpeg -re -stream_loop -1 -i video.mp4 -c:v libx264 -c:a aac -f flv http://127.0.0.1:8501/live/stream
```

## 📺 Playback URLs & Players
### Live Stream URLs
| Protocol       | URL Example | Description | Distribution Suggestion |
| -------------- | ----------- | ----------- | ----------------------- |
| RTMP           | `rtmp://127.0.0.1:1935/live/stream` | For native RTMP players / ffplay | Use origin directly |
| HTTP-FLV       | `http://127.0.0.1:8501/live/stream.flv` | Low-latency web playback / ffplay | **Use FLV Gateway Cluster** |
| WebSocket-FLV  | `ws://127.0.0.1:8501/live/stream.flv` | WebSocket streaming | **Use FLV Gateway Cluster** |
| HLS            | `http://{fileGateway_IP}:8100/hls/live/stream/index.m3u8` | Mobile-first | **Must use fileGateway** |

### VOD URLs (After Recording Finished)
| Resource Type | URL (Use fileGateway) |
| ------------- | --------------------- |
| Merged MP4 VOD | `http://{fileGateway_IP}:8100/mp4/live/stream/output_merge/stream_full.mp4` |
| Combined fMP4 Segments(MSE) | `http://{fileGateway_IP}:8100/mp4/live/stream/output_merge/init.mp4` |
| Separated Audio/Video fMP4 | `http://{fileGateway_IP}:8100/mp4/live/stream/output_separate/audio_init.mp4` |
| Original FLV VOD | `http://{fileGateway_IP}:8100/flv/live/stream/20240101_120000.flv` |

> For high-concurrency scenarios, always use static file gateway cluster with load balancing to separate read and write traffic.

### Web Playback Pages
| Page Purpose | URL (Recommended: fileGateway) |
| ------------ | ------------------------------- |
| FLV Live | `http://{fileGateway_IP}:8100/index.html` |
| HLS Live | `http://{fileGateway_IP}:8100/play.html` |
| Merged MP4 VOD | `http://{fileGateway_IP}:8100/mp4.html` |
| Original FLV VOD | `http://{fileGateway_IP}:8100/video.html` |
| fMP4 Segments Playback | `http://{fileGateway_IP}:8100/play_merge.html` |

### ffplay Command-line Playback
All stream types are fully compatible with **ffplay**:
```bash
# Play RTMP stream
ffplay rtmp://127.0.0.1:1935/live/stream

# Play origin HTTP-FLV stream
ffplay http://127.0.0.1:8501/live/stream.flv

# Play FLV gateway stream
ffplay http://127.0.0.1:8080/live/stream.flv

# Play HLS stream
ffplay http://127.0.0.1:8100/hls/live/stream/index.m3u8

# Play VOD files
ffplay http://127.0.0.1:8100/flv/live/stream/20240101_120000.flv
ffplay http://127.0.0.1:8100/mp4/live/stream/output_merge/stream_full.mp4
```

## 💾 Real-time Recording Details
### Parallel Recording Workflow
After stream push starts, three independent recording tasks run in parallel:
```
                    ┌─────────────────────────────────────────────────┐
                    │        RTMP / HTTP-FLV Dual Push Stream         │
                    └─────────────────────┬───────────────────────────┘
                                          │
                    ┌─────────────────────┼───────────────────────────┐
                    │                     │                           │
                    ▼                     ▼                           ▼
            ┌───────────────┐     ┌───────────────┐           ┌───────────────┐
            │   FLV Record  │      │  fMP4 Segment │           │  HLS Segment  │
            │  (Raw Stream) │      │ (Live Split)  │           │ (Live Split)  │
            └───────┬───────┘     └───────┬───────┘           └───────┬───────┘
                    │                     │                           │
                    ▼                     ▼                           ▼
            ┌───────────────┐     ┌───────────────┐           ┌───────────────┐
            │ Complete FLV  │     │ fMP4 Segments │           │ TS Segments   │
            │ (After Stream)│     │ (Live)        │           │ + m3u8 Index  │
            └───────────────┘     └───────┬───────┘           └───────────────┘
                                          │
                          Auto Merge After Stream Ends
                                          ▼
                                    ┌───────────────┐
                                    │ Complete MP4   │
                                    │ (For VOD)      │
                                    └───────────────┘
```

### Task Comparison
| Task | Real-time | Output | Usage | Toggle Constant |
| ---- | --------- | ------ | ----- | --------------- |
| FLV Recording | Yes | Full FLV file | Raw stream backup, VLC/ffplay playback | `FLV_TO_RECORD` |
| fMP4 Segmentation | Yes | fMP4 segments → Full MP4 | Web MSE playback, VOD | `FLV_TO_MP4` |
| HLS Segmentation | Yes | TS segments + m3u8 | Mobile live streaming | `FLV_TO_HLS` |

## 📁 Project Directory Structure
```
rtmp_server/
├── flv/                              # FLV recording files (FLV_TO_RECORD)
├── mp4/                              # MP4 & fMP4 files (FLV_TO_MP4)
├── hls/                              # HLS TS & m3u8 files (FLV_TO_HLS)
├── MediaServer/                      # RTMP core protocol & stream session logic
├── Root/                             # Low-level async IO & Socket event driver (epoll support)
├── SabreAMF/                         # AMF0/AMF3 codec
├── server.php                        # Origin server entry
├── fileGateway.php                   # Static file gateway (epoll, 20k+ concurrent)
├── flvGateway.php                    # FLV live gateway (epoll, 20k+ concurrent)
├── *.html                            # Web playback pages
└── README.md
```

## 📈 Performance Benchmark
All tests run inside Docker container with `ulimit -n 65535`. Total 20,000 concurrent clients, each pulls stream for 5 seconds.

### Origin Server
```
Current container pids.max: unknown
Launch batch: 1000 clients (Total 20 batches)
All clients started, waiting for completion...

===== Result =====
Success: 17,330
Fail: 2,670
```

### FLV Live Gateway
```
Current container pids.max: unknown
Launch batch: 1000 clients (Total 20 batches)
All clients started, waiting for completion...

===== Result =====
Success: 19,923
Fail: 77
```

### Static File Gateway
```
Concurrent: 20,000
Duration per client: 5s
Batch size: 1000

===== Result =====
Success: 20,000
Fail: 0
```

> Notes:
> - Origin server handles dual-protocol stream ingest and transcoding, still achieves 17,330 successful connections. Minor failures caused by temporary port contention.
> - FLV gateway focuses purely on stream forwarding, success rate reaches 99.6%.
> - Static file gateway is extremely lightweight, **100% success rate under 20,000 concurrency**.
> - All components adapt to OS: Epoll enabled on Linux to break 1024 fd limit; Select used on Windows.

## ❓ FAQ
### 1. Why a single process supports 20,000+ concurrent connections?
- **Linux**: If `event` extension is installed, **epoll event-driven** is enabled automatically, no longer limited by select's 1024 file descriptor cap.
- **Windows**: No `event` extension, falls back to Select. Single process limited to ~256 connections, deploy multiple instances for higher load.
- Benchmark: Static file gateway runs perfectly with 20,000 concurrent connections.

### 2. How to scale for higher concurrency?
| Scaling Method | Description | Example |
| -------------- | ----------- | ------- |
| Single Process High Performance | Epoll on Linux supports 20k+ connections per process | 1 fileGateway process handles 20,000 static requests |
| Horizontal Scaling | Multiple instances on the same layer + load balancing | 3 fileGateway instances → 60,000+ concurrency |
| Vertical Scaling | Multi-layer cascading | L1 Gateway → L2 Gateway → L3 Gateway ... |
| Combined Scaling | Horizontal + Vertical | 3 instances per layer × 3 layers → 180,000+ theoretical concurrency |

### 3. When to deploy gateways?
| Concurrency | Deployment Plan |
| ----------- | --------------- |
| Low (< 500) | Use origin built-in HTTP service only |
| Medium (500 – 5,000) | Origin + single-layer gateway cluster |
| High (> 5,000) | Origin + multi-layer gateway clusters |

### 4. Difference between FLV Gateway and Static File Gateway
| Gateway Type | Main Usage | Handled Resources | Scaling |
| ------------ | ---------- | ----------------- | ------- |
| FLV Live Gateway | Live stream distribution | HTTP-FLV live stream | Horizontal + Vertical cascading |
| Static File Gateway | Static resource hosting | HLS / fMP4 / MP4 / FLV files & web pages | Horizontal + Vertical, works with Nginx |

### 5. How to test concurrency performance?
```bash
# Use built-in stress test script (20000 concurrent)
sh play.sh

# Apache Bench for static file gateway
ab -n 10000 -c 500 http://127.0.0.1:8100/index.html

# wrk for FLV gateway
wrk -t4 -c1000 -d30s http://127.0.0.1:8080/live/stream.flv
```

## 📄 License
This project is for learning and technical research only. Users take full responsibility for commercial usage risks.

## ⚠️ Disclaimer
1. Part of the code is sourced from open-source communities. Please contact the author for removal if there are copyright issues.
2. This project is fully open-source and free for technical communication.
3. The author shall not be held liable for any legal consequences caused by illegal or commercial use.

## 📧 Contact
For technical consultation & feedback: **2723659854@qq.com**