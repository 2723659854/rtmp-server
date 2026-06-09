# RTMP Server

<p align="center">
  <a href="./README.cn.md"><strong>🇨🇳 Chinese</strong></a> •
  <a href="./README.md"><strong>🇬🇧 English</strong></a>
</p>

> A lightweight RTMP live streaming service written purely in PHP, **no third-party streaming server dependencies**, ready for out-of-the-box private live streaming platform setup.

## 🏗️ System Architecture

```
                                                    【Streaming Source】OBS/FFmpeg
                                                         │
                                                   RTMP Push(1935)
                                                         │
                                                         ▼
╔══════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════╗
║                                                      RTMP Origin Server (Core)                                                ║
║                                                                                                                              ║
║  📥 Stream Ingestion   RTMP reception, connection authentication                                                            ║
║  🔄 Protocol Conversion   RTMP → HTTP-FLV / WebSocket-FLV / HLS / fMP4 / MP4                                                  ║
║  💾 Real-time Recording   ┌──────────────┬──────────────┬──────────────┐                                                     ║
║               │  FLV Record  │  fMP4 Slice  │   HLS Slice  │  Three independent parallel tasks                               ║
║               │ (Real-time)  │ (Real-time)  │ (Real-time)  │                                                                   ║
║               └──────────────┴──────────────┴──────────────┘                                                                 ║
║  📤 Live Output   HTTP-FLV(8501) / WebSocket-FLV / HLS Live / fMP4 Live                                                      ║
║  📦 VOD Output    fMP4 segments generated in real-time → automatically merged into complete MP4 after stream ends           ║
║  📁 Static Service   Built-in HTTP server (port 80), direct static file access (for low concurrency scenarios)              ║
╚══════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════╝
                                 │
         ┌───────────────────────┼───────────────────────┐
         │                       │                       │
         ▼                       ▼                       ▼
   HTTP-FLV(8501)           HLS(TS/m3u8)           fMP4(Segments)
   Live Stream               Static Files            Static Files
         │                       │                       │
         │                       │                       │
         ▼                       ▼                       ▼
┌─────────────────┐     ┌─────────────────────────────────────────────────┐
│  FLV Gateway    │     │           Static File Gateway Cluster           │
│     Cluster     │     │         (fileGateway)                           │
│                 │     │   🎯 Hosts: HLS / fMP4 / MP4 / FLV / Web Pages   │
│  ┌───────────┐  │     │                                                 │
│  │ Level 1   │  │     │  ┌───────────┐  ┌───────────┐  ┌───────────┐   │
│  │ Gateway   │  │     │  │ Gateway   │  │ Gateway   │  │ Gateway   │   │
│  │ (8080)    │  │     │  │ Node 1    │  │ Node 2    │  │ Node 3    │   │
│  └─────┬─────┘  │     │  │ (8100)    │  │ (8101)    │  │ (8102)    │   │
│        │        │     │  └─────┬─────┘  └─────┬─────┘  └─────┬─────┘   │
│  ┌─────┴─────┐  │     │        │              │              │         │
│  ▼     ▼     ▼  │     │        ▼              ▼              ▼         │
│ ┌───┐ ┌───┐ ┌───┐│     │   ┌──────────────────────────────────────┐    │
│ │Lv2│ │Lv2│ │Lv2││     │   │           Client                      │    │
│ │GW │ │GW │ │GW ││     │   │   HLS Player / MSE Player / VOD Req    │    │
│ └─┬─┘ └─┬─┘ └─┬─┘│     │   └──────────────────────────────────────┘    │
│   │     │     │  │     │                                                 │
│   ▼     ▼     ▼  │     └─────────────────────────────────────────────────┘
│ ┌──────────────┐ │
│ │   Client     │ │
│ │ FLV Player   │ │
│ └──────────────┘ │
└─────────────────┘
```

### Architecture Explanation

- **Origin Server**: The sole stream production node, responsible for RTMP push/pull stream ingestion and multi-protocol remuxing. **FLV recording, fMP4 slicing, and HLS slicing are three completely independent parallel tasks**, non-blocking.

- **Origin Static Capability**: Built-in HTTP service (port 80) provides direct static file access. **No additional gateways needed for low concurrency scenarios** - works out of the box.

- **Real-time Recording Mechanism**:
    - **FLV Recording**: Real-time raw stream saving, produces complete FLV file after stream ends
    - **fMP4 Slicing**: Real-time audio/video fMP4 segment generation (supports both muxed and separate formats), automatically merges into complete MP4 after stream ends
    - **HLS Slicing**: Real-time TS segment + m3u8 index generation (mobile compatible)
    - **Independent Switches**: Users can configure each recording task independently in `server.php`

- **FLV Live Gateway Cluster**: Pure traffic forwarding service, pulls HTTP-FLV stream from upstream, caches GOP keyframes for instant playback, distributes to clients or downstream gateways.
    - **Unlimited cascading levels**: Level 1 → Level 2 → Level 3 → ... → Client
    - **Horizontal scaling**: Multiple gateway instances at same level, load balanced traffic distribution
    - **Single process limit**: ~256 connections on Windows, ~1024 on Linux (due to select event polling mechanism)

- **Static File Gateway Cluster (Recommended)**: Lightweight HTTP static file server, unified hosting for all static resources.
    - **Supported protocols**: HLS (.m3u8/.ts), fMP4 (.m4s/.mp4), MP4 VOD files, FLV recorded files, Web playback pages
    - **Horizontal scaling**: Multiple gateway instances at same level, linear concurrency improvement
    - **Vertical scaling**: Multi-level cascading via reverse proxy like Nginx
    - **Single process limit**: ~256 connections on Windows, ~1024 on Linux
    - **Best practice**: Route HLS/fMP4/MP4 playback paths to this gateway cluster for read/write separation

- **Deployment Recommendations**:
    - **Low Concurrency** (< 500 concurrent): Use origin built-in HTTP service directly, no additional gateways
    - **Medium Concurrency** (500 - 5000 concurrent): Origin + single-level gateway cluster (FLV gateway or static file gateway), multiple processes/instances with load balancer
    - **High Concurrency** (> 5000 concurrent): Origin dedicated to "push, protocol conversion, real-time recording", multi-level FLV gateway cluster + multi-level static file gateway cluster, each level can be horizontally scaled

### Concurrency Capability Explanation

| Component | Max Connections Per Process | Scaling Method | Theoretical Max Cluster Concurrency |
|-----------|----------------------------|----------------|--------------------------------------|
| RTMP Origin | ~1024 (Linux) | Multi-process not supported | ~1024 |
| FLV Gateway | ~1024 (Linux) | Multi-process + Multi-level + Horizontal | 1024 × N × M |
| Static File Gateway | ~1024 (Linux) | Multi-process + Multi-level + Horizontal | 1024 × N × M |

> **Actual concurrency depends on**: Server hardware specifications, network bandwidth, business logic complexity, PHP configuration, etc. The values above are theoretical maximums. It's recommended to reserve a 30% buffer in production.

## ✨ Features

- 🎥 **Complete RTMP Push/Pull**: Full protocol implementation, supports standard publish/play commands
- 📡 **HTTP-FLV / WebSocket-FLV**: Low-latency browser live streaming solution
- 🧩 **HLS Auto Segmentation**: Real-time m3u8 + TS generation, full platform mobile compatibility
- 📦 **fMP4 Real-time Slicing + Auto Merge**: Real-time fMP4 segment generation during live, auto-merges into complete MP4 after stream ends
- 🎬 **Dual fMP4 Format Support**: Simultaneously supports muxed (audio+video) and separate (audio/video independent) formats
- 💾 **Independent FLV Recording**: Real-time raw FLV stream saving, decoupled from fMP4/MP4
- 🎛️ **Independent Task Switches**: FLV recording, fMP4 slicing, HLS slicing can be independently enabled/disabled
- 🖥️ **Built-in Multiple Web Players**: Ready-to-use, supports FLV/HLS/MP4/Muxed fMP4/Separate fMP4 playback
- 🚀 **Cascadable FLV Gateway**: Unlimited level cascading, GOP cache for instant playback, auto-reconnect on stream disconnect
- 📁 **Static File Gateway**: Unified hosting for HLS/fMP4/MP4 recorded resources and web pages
- 🐳 **Docker One-click Deployment**: Quick test environment setup
- ⚡ **Native PHP Implementation**: No third-party streaming program dependencies

## 📋 Requirements

- PHP >= 8.1 (CLI mode only)
- Required extension: `sockets`
- Recommended: `pcntl` (Linux/macOS, for process management optimization)

## 🚀 Quick Start

### 1. Install Project
```bash
composer create-project xiaosongshu/rtmp_server
```

### 2. Configure Recording Switches (`server.php`)
```php
// Three independent recording task switches, can be enabled/disabled as needed
define('FLV_TO_RECORD', true);   // Whether to record FLV raw file in real-time
define('FLV_TO_MP4', true);      // Whether to generate fMP4 segments and merge to MP4
define('FLV_TO_HLS', true);      // Whether to generate HLS(TS) segments
```

### 3. Start Origin Server
```bash
php server.php
```

### 4. Access Playback (Low Concurrency - Direct Origin Access)
```bash
# Playback page access (origin built-in HTTP service)
http://127.0.0.1/index.html      # FLV live page
http://127.0.0.1/play.html       # HLS live page
http://127.0.0.1/mp4.html        # MP4 VOD page
http://127.0.0.1/play_merge.html # fMP4 segment VOD page (supports both muxed and separate formats)
```

### 5. Medium/High Concurrency: Deploy Static File Gateway Cluster (Recommended)

> **Use Case**: High concurrency access for HLS(.ts/.m3u8), fMP4(.m4s/.mp4), MP4 VOD files, and web pages. Effectively distributes origin load, supports horizontal + vertical scaling.

**Single Process Limit**: ~256 connections on Windows, ~1024 on Linux (due to select model limitation)

```bash
# Start multiple process instances (horizontal scaling)
php fileGateway.php 0.0.0.0 8100
php fileGateway.php 0.0.0.0 8101
php fileGateway.php 0.0.0.0 8102

# 【Linux/macOS】Run multiple instances in background
php fileGateway.php 0.0.0.0 8100 > /dev/null 2>&1 &
php fileGateway.php 0.0.0.0 8101 > /dev/null 2>&1 &
php fileGateway.php 0.0.0.0 8102 > /dev/null 2>&1 &

# Vertical scaling: Multi-level distribution via Nginx reverse proxy
# Level 1 Nginx -> Level 2 fileGateway (8100/8101/8102) -> Level 3 fileGateway ...
```

Access examples (via static file gateway):
```
http://127.0.0.1:8100/play.html       # HLS playback page via gateway
http://127.0.0.1:8100/hls/live/stream/index.m3u8  # HLS stream via gateway
```

### 6. Medium/High Concurrency: Deploy FLV Live Gateway Cluster

> **Use Case**: High concurrency distribution for HTTP-FLV real-time live streams.

```bash
# Level 1 gateway: Pull stream from origin
php flvGateway.php 8080 http://origin_IP:8501

# Horizontal scaling: Multiple gateway instances at same level
php flvGateway.php 8081 http://origin_IP:8501
php flvGateway.php 8082 http://origin_IP:8501

# Vertical scaling: Multi-level cascading
php flvGateway.php 8080 http://origin_IP:8501      # Level 1 gateway
php flvGateway.php 8081 http://127.0.0.1:8080      # Level 2 gateway (pulls from level 1)
php flvGateway.php 8082 http://127.0.0.1:8081      # Level 3 gateway (pulls from level 2)
```

### 7. Stop Services

| OS          | Stop Command      |
| ----------- | ----------------- |
| Windows     | `Ctrl + C`        |
| Linux/macOS | `kill -9 PID`     |

## 🔧 Port Configuration (Modify in `server.php`)

| Port | Protocol       | Purpose                                                    |
| ---- | -------------- | ---------------------------------------------------------- |
| 1935 | RTMP           | RTMP push/pull stream playback                            |
| 8501 | HTTP/WebSocket | HTTP-FLV / WS-FLV live playback / static web pages (not recommended) |
| 80   | HTTP           | Static file service + Web player pages                    |

## 🚀 FLV Streaming Gateway (High Concurrency Live Distribution)

### Gateway Overview

Lightweight traffic distribution component supporting unlimited level cascading. Pulls HTTP-FLV from upstream origin/gateway, caches stream headers and GOP keyframes for instant playback, distributes stream data to clients or child gateways. **Designed for medium/high concurrency live streaming scenarios**, supports horizontal + vertical scaling.

### Core Capabilities

- 📡 Single instance multi-stream concurrent forwarding, multiple channels simultaneously
- 🔄 Unlimited level cascading: Level 1 → Level 2 → Level 3 chain expansion
- ⚡ GOP pre-caching: New connections don't wait for keyframes, instant playback
- 🔁 Auto-reconnect on upstream disconnect, transparent to end users
- 📊 Built-in runtime statistics: Online users, upstream/downstream traffic every 10 seconds
- 🚀 **Horizontal scaling**: Add gateway processes/instances at same level, linear concurrency improvement
- 🚀 **Vertical scaling**: Multi-level cascading, distribute single point pressure

### Single Process Limit

| OS      | Max Connections | Explanation                                        |
| ------- | --------------- | -------------------------------------------------- |
| Windows | ~256            | Limited by select model, FD_SETSIZE limitation    |
| Linux   | ~1024           | Can be increased by recompiling PHP              |
| macOS   | ~1024           | Same as Linux                                      |

> **Production Recommendation**: When a single process reaches 70% of its connection limit, add more gateway instances for traffic distribution.

### FLV Gateway Start Commands

```bash
# 【Horizontal Scaling】Single level, multiple instances
php flvGateway.php 8080 http://origin_IP:8501
php flvGateway.php 8081 http://origin_IP:8501
php flvGateway.php 8082 http://origin_IP:8501

# 【Vertical Scaling】Multi-level cascading
php flvGateway.php 8080 http://origin_IP:8501      # Level 1 gateway
php flvGateway.php 8081 http://127.0.0.1:8080      # Level 2 gateway
php flvGateway.php 8082 http://127.0.0.1:8081      # Level 3 gateway

# 【Combined Scaling】Multi-level + multiple instances per level
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

Add `$gateway->debug = true;` to the gateway start script to enable full verbose runtime logs.

## 📁 Static File Gateway `fileGateway.php` (High Concurrency VOD Resource Hosting)

### Gateway Overview

Lightweight HTTP static file server, unified hosting for all static resources. **For file-based protocols like HLS, fMP4, MP4, this is the recommended playback approach**. Supports horizontal + vertical scaling, can handle large-scale VOD concurrency.

### Core Capabilities

- 📁 Unified hosting for all static resources (recorded files + web pages)
- 🔗 **Horizontal scaling**: Multiple instances, load balanced traffic distribution
- 🔗 **Vertical scaling**: Multi-level cascading (e.g., Nginx + fileGateway + backend storage)
- 📊 Built-in access logs for statistical analysis
- 🚀 Pure PHP implementation, lightweight with no dependencies
- **💡 Best Practice**: Route HLS/fMP4/MP4 playback paths to this gateway cluster, origin only writes files, achieving read/write separation

### Single Process Limit

| OS      | Max Connections | Explanation                                    |
| ------- | --------------- | ---------------------------------------------- |
| Windows | ~256            | Limited by select model                        |
| Linux   | ~1024           | Can be overcome by multi-process/multi-instance |

### Start Commands (Multi-process/Multi-instance Distribution)

```bash
# Basic start (host current directory, port 8100)
php fileGateway.php 0.0.0.0 8100

# 【Horizontal Scaling】Multi-instance deployment
php fileGateway.php 0.0.0.0 8100
php fileGateway.php 0.0.0.0 8101
php fileGateway.php 0.0.0.0 8102

# 【Vertical Scaling】Multi-level cascading (with Nginx)
# Nginx configuration example below

# Linux/macOS background multi-instance
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
http://gateway_IP:port/{relative_file_path}
```

Examples:
```
# Web playback pages (via static gateway)
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

## 📡 Push Stream Access Tutorial

### RTMP Push URL Format

```
rtmp://127.0.0.1:1935/{app_name}/{stream_name}
```

- `app_name`: Example `live`
- `stream_name`: Example `stream`
- Only supports alphanumeric naming

### Push Examples

#### OBS Studio Push
1. Download and install [OBS Studio](https://obsproject.com/)
2. Settings → Stream → Server: `rtmp://127.0.0.1:1935/live`
3. Stream Key: `stream`
4. Start streaming

#### FFmpeg Loop Push
```bash
ffmpeg -re -stream_loop -1 -i "video.mp4" -vcodec h264 -acodec aac -f flv rtmp://127.0.0.1:1935/live/stream
```

## 📺 Playback URL Summary

### Real-time Live URLs

| Protocol       | URL                                                       | Description                    | Distribution Suggestion                  |
| -------------- | --------------------------------------------------------- | ------------------------------ | ---------------------------------------- |
| RTMP           | `rtmp://127.0.0.1:1935/live/stream`                       | Native RTMP player             | Origin direct                           |
| HTTP-FLV       | `http://127.0.0.1:8501/live/stream.flv`                   | Low-latency browser playback   | **Via FLV Gateway Cluster**             |
| WebSocket-FLV  | `ws://127.0.0.1:8501/live/stream.flv`                     | WebSocket streaming playback   | **Via FLV Gateway Cluster**             |
| HLS            | `http://{fileGateway_IP}:8100/hls/live/stream/index.m3u8` | Android/iOS preferred          | **Must use fileGateway**                |

### VOD Playback URLs (After Recording)

| File Type                      | URL (Must use fileGateway)                                                              | Description                     |
| ------------------------------ | --------------------------------------------------------------------------------------- | ------------------------------- |
| Merged MP4 VOD                 | `http://{fileGateway_IP}:8100/mp4/live/stream/output_merge/stream_full.mp4`            |                                 |
| Muxed fMP4 Segment VOD (MSE)   | `http://{fileGateway_IP}:8100/mp4/live/stream/output_merge/init.mp4`                   |                                 |
| Separate Audio/Video fMP4 VOD  | `http://{fileGateway_IP}:8100/mp4/live/stream/output_separate/audio_init.mp4`          |                                 |
| Raw FLV VOD                    | `http://{fileGateway_IP}:8100/flv/live/stream/20240101_120000.flv`                     |                                 |

> **High Concurrency Scenarios**: Must use static file gateway cluster (e.g., `127.0.0.1:8100/8101/8102`) with load balancing for read/write separation.

### Web Playback Pages

| Page Purpose                     | URL (Recommended via fileGateway)                        | Description                                           |
| -------------------------------- | --------------------------------------------------------- | ----------------------------------------------------- |
| FLV Live Playback                | `http://{fileGateway_IP}:8100/index.html`                | HTTP-FLV low-latency live                            |
| HLS Live Playback                | `http://{fileGateway_IP}:8100/play.html`                 | HLS mobile-compatible live                           |
| Merged MP4 VOD                   | `http://{fileGateway_IP}:8100/mp4.html`                  | Complete MP4 file VOD                                |
| Raw FLV VOD                      | `http://{fileGateway_IP}:8100/video.html`                | FLV raw file VOD                                     |
| **fMP4 Segment VOD**             | `http://{fileGateway_IP}:8100/play_merge.html`           | **Supports both muxed and separate formats**         |

## 💾 Real-time Recording Explanation

### Recording Mechanism (Three Independent Parallel Tasks)

After push starts, the origin simultaneously starts three **independent parallel** recording tasks, non-blocking:

```
                    ┌─────────────────────────────────────────────────┐
                    │                 RTMP Push                       │
                    └─────────────────────┬───────────────────────────┘
                                          │
                    ┌─────────────────────┼───────────────────────────┐
                    │                     │                           │
                    ▼                     ▼                           ▼
            ┌───────────────┐     ┌───────────────┐           ┌───────────────┐
            │   FLV Record  │     │   fMP4 Slice  │           │   HLS Slice   │
            │  (Real-time)  │     │  (Real-time)  │           │  (Real-time)  │
            └───────┬───────┘     └───────┬───────┘           └───────┬───────┘
                    │                     │                           │
                    ▼                     ▼                           ▼
            ┌───────────────┐     ┌───────────────┐           ┌───────────────┐
            │  Complete FLV │     │  fMP4 Segment │           │  TS Segment   │
            │  (After Push) │     │   Set (Live)  │           │  + m3u8 Index │
            └───────────────┘     └───────┬───────┘           └───────────────┘
                                          │
                                          │ Auto-merge after stream ends
                                          ▼
                                    ┌───────────────┐
                                    │  Complete MP4 │
                                    │  (VOD)        │
                                    └───────────────┘
```

### Task Independence Explanation

| Recording Task | Real-time | Output                         | Purpose                          | Independent Switch    |
| -------------- | --------- | ------------------------------ | -------------------------------- | --------------------- |
| **FLV Record** | Yes       | Complete FLV file              | Raw format backup, VLC playback  | `FLV_TO_RECORD`       |
| **fMP4 Slice** | Yes       | fMP4 segments → merged to MP4  | Browser MSE playback, VOD        | `FLV_TO_MP4`          |
| **HLS Slice**  | Yes       | TS segments + m3u8             | Mobile compatibility, HLS live   | `FLV_TO_HLS`          |

## 📁 Project Directory Structure

```
rtmp_server/
├── flv/                              # FLV raw recorded files (FLV_TO_RECORD)
├── mp4/                              # MP4/fMP4 transcoding output (FLV_TO_MP4)
├── hls/                              # HLS TS segments + m3u8 index (FLV_TO_HLS)
├── MediaServer/                      # RTMP core protocol, push/pull session logic
├── Root/                             # Low-level async IO, socket event driver
├── SabreAMF/                         # AMF0/AMF3 codec (RTMP command parsing)
├── server.php                        # Origin start entry
├── fileGateway.php                   # Static file gateway
├── flvGateway.php                    # FLV live gateway
├── *.html                            # Web playback pages
└── README.md
```

## ❓ FAQ

### 1. Why does a single process only support 1024 connections?

- **Reason**: PHP's native socket select model is limited by the `FD_SETSIZE` macro definition (default 1024)
- **Solutions**:
    - Horizontal scaling: Deploy multiple gateway processes/instances
    - Vertical scaling: Multi-level cascading
    - Recompile PHP with increased `FD_SETSIZE` (not recommended, may cause stability issues)

### 2. How do gateways handle high concurrency?

| Scaling Method | Description                                    | Example                                                    |
| -------------- | ---------------------------------------------- | ---------------------------------------------------------- |
| **Horizontal** | Multiple instances at same level, load balancer | 3 fileGateway instances, 1024 connections each → 3072 concurrent |
| **Vertical**   | Multi-level cascading                         | Level 1 (1024) → Level 2 (1024×N) → Level 3...            |
| **Combined**   | Horizontal + vertical together                | 3 instances per level × 3 levels = theoretical 9216 concurrent |

### 3. When should gateways be deployed?

| Concurrency Scenario                     | Deployment Plan                                        |
| ---------------------------------------- | ------------------------------------------------------ |
| **Low** (< 500)                          | Origin only, built-in HTTP service                     |
| **Medium** (500 - 5000)                  | Origin + single-level gateway cluster (2-5 instances)  |
| **High** (> 5000)                        | Origin + multi-level gateway cluster (multiple instances per level) |

### 4. Difference between FLV Gateway and Static File Gateway?

| Gateway Type           | Purpose                   | Resource Types Handled                              | Scaling Method              |
| ---------------------- | ------------------------- | --------------------------------------------------- | --------------------------- |
| **FLV Gateway**        | Live stream distribution  | HTTP-FLV real-time streams                          | Horizontal + Vertical, cascadable |
| **Static File Gateway**| Static resource hosting   | HLS/fMP4/MP4/FLV static files + Web pages           | Horizontal + Vertical, with Nginx |

### 5. How to verify gateway concurrency capability?

```bash
# Test static file gateway with ab (Apache Bench)
ab -n 10000 -c 500 http://127.0.0.1:8100/test.html

# Test FLV gateway with wrk
wrk -t4 -c1000 -d30s http://127.0.0.1:8080/live/stream.flv
```

## 📄 License

This project is for learning and technical research purposes only. Commercial use risk is borne by the user.

## ⚠️ Disclaimer

1. Some open source code is taken from the open source community. For copyright concerns, please contact the author for removal.
2. The project is completely open source and free, for technical exchange only.
3. The author assumes no joint liability for any legal consequences arising from commercial/illegal use by users.

## 📧 Contact

Technical consultation, issue feedback email: **2723659854@qq.com**