# RTMP Server

<p align="center">
  <a href="./README.cn.md"><strong>🇨🇳 中文</strong></a> •
  <a href="./README.md"><strong>🇬🇧 English</strong></a>
</p>

> A lightweight RTMP live streaming server written in pure PHP, **with no third‑party streaming service dependencies**, enabling quick setup of a private live streaming platform.  
> **On Linux, the epoll event driver is automatically enabled, allowing a single process to easily handle 20,000+ concurrent connections. Windows falls back to the select model for compatibility.**

## 🏗️ System Architecture

```
                                                    【Publishing End】OBS/FFmpeg
                                                         │
                                                   RTMP Publish (1935)
                                                         │
                                                         ▼
╔══════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════╗
║                                                         RTMP Origin Server (Core)                                          ║
║                                                                                                                              ║
║  📥 Stream Ingestion   RTMP Reception, Link Authentication                                                                   ║
║  🔄 Protocol Conversion RTMP → HTTP-FLV / WebSocket-FLV / HLS / fMP4 / MP4                                                   ║
║  💾 Real‑time Recording  ┌──────────────┬──────────────┬──────────────┐                                                       ║
║                         │  FLV Record   │ fMP4 Segment │  HLS Segment │   Three independent parallel tasks                    ║
║                         │  (raw stream) │ (realtime)   │ (realtime)   │                                                       ║
║                         └──────────────┴──────────────┴──────────────┘                                                       ║
║  📤 Live Output   HTTP-FLV(8501) / WebSocket-FLV / HLS live / fMP4 live                                                      ║
║  📦 VOD Output    fMP4 segments generated in real time → automatically merged into a complete MP4 after the live stream ends  ║
║  📁 Static Service Origin built‑in HTTP server (port 80), can serve static files directly (suitable for low concurrency)      ║
╚══════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════╝
│
┌───────────────────────┼───────────────────────┐
│                       │                       │
▼                       ▼                       ▼
HTTP-FLV(8501)           HLS(TS/m3u8)           fMP4(Segments)
Live Stream Output        Static Files            Static Files
│                       │                       │
│                       │                       │
▼                       ▼                       ▼
┌─────────────────┐     ┌─────────────────────────────────────────────────┐
│  FLV Gateway    │     │              Static File Gateway Cluster          │
│  Cluster        │     │         🎯 Hosting: HLS / fMP4 / MP4 / FLV / Web  │
│                 │     │                                                 │
│  ┌───────────┐  │     │  ┌───────────┐  ┌───────────┐  ┌───────────┐   │
│  │ L1 Gateway │  │     │  │  Node 1   │  │  Node 2   │  │  Node 3   │   │
│  │  (8080)   │  │     │  │  (8100)   │  │  (8101)   │  │  (8102)   │   │
│  └─────┬─────┘  │     │  └─────┬─────┘  └─────┬─────┘  └─────┬─────┘   │
│        │        │     │        │              │              │         │
│  ┌─────┴─────┐  │     │        ▼              ▼              ▼         │
│  ▼     ▼     ▼  │     │   ┌──────────────────────────────────────┐    │
│ ┌───┐ ┌───┐ ┌───┐│     │   │          Clients                    │    │
│ │Sub│ │Sub│ │Sub││     │   │   HLS Player / MSE Player / VOD     │    │
│ │GW │ │GW │ │GW ││     │   └──────────────────────────────────────┘    │
│ └─┬─┘ └─┬─┘ └─┬─┘│     │                                                 │
│   │     │     │  │     └─────────────────────────────────────────────────┘
│   ▼     ▼     ▼  │
│ ┌──────────────┐ │
│ │   Clients    │ │
│ │ FLV Players  │ │
│ └──────────────┘ │
└─────────────────┘
```

### Architecture Overview

- **Origin Server**: The sole stream production node, responsible for RTMP ingest/play, multi‑protocol repackaging. **FLV recording, fMP4 segmentation and HLS segmentation run completely independently and in parallel**, without blocking each other.

- **Origin Static Capability**: The origin server has a built‑in HTTP service (default port 80) that can serve static files directly. **No additional gateway is needed for low‑concurrency scenarios** — it works out of the box.

- **Real‑time Recording Mechanism**:
    - **FLV Recording**: Saves the raw live stream in real time, producing a complete FLV file when the stream ends.
    - **fMP4 Segmentation**: Generates audio/video fMP4 segments in real time (supports both muxed and demuxed formats), and automatically merges them into a complete MP4 after the live stream finishes.
    - **HLS Segmentation**: Generates TS segments + m3u8 playlist in real time (mobile compatible).
    - **Independent Switches**: Users can enable/disable each recording task separately in `server.php`.

- **FLV Live Gateway Cluster**: A pure traffic forwarding service. It pulls HTTP‑FLV streams from upstream, caches GOP keyframes to achieve instant playback for new viewers, and distributes the stream data to end clients or downstream gateways.
    - **Unlimited Cascading**: L1 gateway → L2 gateway → L3 gateway → … → client.
    - **Horizontal Scaling**: Deploy multiple gateway instances at the same tier and distribute traffic via load balancing.
    - **Linux epoll High Performance**: A single process can handle 20,000+ concurrent connections; Windows falls back to the select model.

- **Static File Gateway Cluster (Recommended)**: A lightweight HTTP static file server that centrally hosts all static resources.
    - **Supported Protocols**: HLS (.m3u8/.ts), fMP4 (.m4s/.mp4), MP4 VOD files, FLV recordings, web playback pages.
    - **Horizontal Scaling**: Deploy multiple gateway instances at the same tier, linearly increasing concurrency.
    - **Vertical Cascading**: Multi‑level traffic distribution via reverse proxies such as Nginx.
    - **Linux epoll High Performance**: A single process can handle 20,000+ concurrent connections; Windows falls back to the select model.
    - **Best Practice**: Point HLS/fMP4/MP4 playback URLs to this gateway cluster, achieving read‑write separation for static resources.

- **Deployment Recommendations**:
    - **Low Concurrency** (< 500): Use the origin’s built‑in HTTP service directly; no extra gateway is needed.
    - **Medium Concurrency** (500 – 5,000):
        - Origin + single‑layer gateway cluster (FLV gateway or Static File gateway).
        - A single gateway process is usually sufficient; multiple instances are not required.
    - **High Concurrency** (> 5,000):
        - Origin focuses on “ingest, protocol conversion, real‑time recording”.
        - **FLV Gateway Multi‑tier Cluster**: L1 gateway → L2 gateway → client.
        - **Static File Gateway Multi‑tier Cluster**: L1 gateway → L2 gateway → client.
        - Each gateway tier can be scaled horizontally, linearly increasing concurrency.

## ✨ Features

- 🎥 **Full RTMP Ingest & Play**: Complete protocol implementation, supporting standard publish / play commands.
- 📡 **HTTP-FLV / WebSocket-FLV**: Low‑latency live streaming solution for browsers.
- 🧩 **Automatic HLS Segmentation**: Generates m3u8 + TS in real time, compatible with all mobile platforms.
- 📦 **Real‑time fMP4 Segmentation + Auto‑Merge**: Generates fMP4 segments during the live stream and automatically merges them into a complete MP4 after the stream ends.
- 🎬 **Dual fMP4 Format Support**: Simultaneously supports both muxed (audio+video) and demuxed (separate audio/video) segment formats.
- 💾 **Independent FLV Recording**: Saves the raw FLV stream in real time, decoupled from fMP4/MP4.
- 🎛️ **Independent Task Switches**: FLV recording, fMP4 segmentation, and HLS segmentation can be individually enabled/disabled.
- 🖥️ **Built‑in Web Players**: Ready to use out of the box, supporting FLV, HLS, MP4, muxed fMP4, and demuxed fMP4 playback.
- 🚀 **Cascadable FLV Streaming Gateway**: Unlimited tier distribution, GOP cache for instant playback, automatic upstream reconnection — built for high‑concurrency live scenarios.
- 📁 **Static File Gateway**: Centrally hosts HLS/fMP4/MP4 recordings and web playback pages, supporting high‑concurrency VOD.
- 🐳 **Docker One‑Click Deployment**: Quickly spin up a test environment.
- ⚡ **Pure Native PHP Implementation**: No third‑party streaming software dependencies.

## 📋 Requirements

- PHP >= 8.1 (CLI mode only)
- Required extension: `sockets`
- Recommended extension: `event` (dramatically improves concurrency on Linux by enabling epoll)

## 🚀 Quick Start

### 1. Install the project
```bash
composer create-project xiaosongshu/rtmp_server
```

### 2. Configure recording switches (`server.php`)
```php
// Three independent recording task switches, can be enabled/disabled as needed
define('FLV_TO_RECORD', true);   // Whether to record raw FLV in real time
define('FLV_TO_MP4', true);      // Whether to generate fMP4 segments and merge into MP4
define('FLV_TO_HLS', true);      // Whether to generate HLS (TS) segments
```

### 3. Start the origin server
```bash
php server.php
```

### 4. Access playback (low‑concurrency scenarios use the origin directly)
```bash
# Playback page URLs (origin built‑in HTTP service)
http://127.0.0.1/index.html      # FLV live page
http://127.0.0.1/play.html       # HLS live page
http://127.0.0.1/mp4.html        # MP4 VOD page
http://127.0.0.1/play_merge.html # fMP4 segment VOD page (supports both muxed and demuxed formats)
```

### 5. Medium / High Concurrency: Deploy the Static File Gateway Cluster (Recommended)

> **Use case**: High‑concurrency access to HLS (.ts/.m3u8), fMP4 (.m4s/.mp4), MP4 VOD files, and web pages.  
> On Linux a single gateway process can handle 20,000+ connections; for even higher loads, scale horizontally with multiple instances.

```bash
# Start a single instance (epoll handles high concurrency directly)
php fileGateway.php 0.0.0.0 8100

# [Horizontal Scaling] Multi‑instance deployment (for extreme concurrency or multi‑server load balancing)
php fileGateway.php 0.0.0.0 8100
php fileGateway.php 0.0.0.0 8101
php fileGateway.php 0.0.0.0 8102

# Run in background on Linux/macOS
php fileGateway.php 0.0.0.0 8100 > /dev/null 2>&1 &

# Vertical scaling: multi‑level distribution via Nginx reverse proxy
# Tier 1 Nginx -> Tier 2 fileGateway (8100/8101/8102) -> Tier 3 fileGateway ...
```

Example access (via the static file gateway):
```
http://127.0.0.1:8100/play.html       # Access HLS playback page through the gateway
http://127.0.0.1:8100/hls/live/stream/index.m3u8  # Access HLS stream through the gateway
```

### 6. Medium / High Concurrency: Deploy the FLV Live Gateway Cluster

> On Linux, a single FLV gateway process can stably support nearly 20,000 concurrent viewers.

```bash
# L1 gateway: pull from the origin
php flvGateway.php 8080 http://origin-ip:8501

# Horizontal scaling: multiple instances at the same tier
php flvGateway.php 8081 http://origin-ip:8501
php flvGateway.php 8082 http://origin-ip:8501

# Vertical scaling: multi‑level cascade
php flvGateway.php 8080 http://origin-ip:8501      # L1 gateway
php flvGateway.php 8081 http://127.0.0.1:8080       # L2 gateway (pulls from L1)
php flvGateway.php 8082 http://127.0.0.1:8081       # L3 gateway (pulls from L2)
```

### 7. Stopping the Service
| Operating System | Command       |
| ---------------- | ------------- |
| Windows          | `Ctrl + C`    |
| Linux/macOS      | `kill -9 PID` |

## 🔧 Port Configuration (modify in `server.php`)

| Port | Protocol       | Purpose                                                                           |
|------|---------------|-----------------------------------------------------------------------------------|
| 1935 | RTMP          | RTMP ingest, RTMP playback                                                        |
| 8501 | HTTP/WebSocket| HTTP-FLV / WS-FLV live playback / static web pages can also be accessed (not recommended) |
| 80   | HTTP          | Static file service + Web player pages                                            |

## 🚀 FLV Streaming Gateway (High‑Concurrency Live Distribution)

### Gateway Overview

A lightweight traffic distribution component that supports unlimited hierarchical cascading. It pulls HTTP‑FLV streams from the upstream origin / upper‑level gateway, caches the stream header and GOP keyframes for instant playback, and replicates the stream data to downstream clients or child gateways. **Designed specifically for medium and high concurrency live scenarios**, supporting both horizontal and vertical scaling.

### Core Capabilities

- 📡 Multi‑stream concurrent forwarding on a single instance, simultaneously carrying different channel distributions.
- 🔄 Unlimited cascading, L1→L2→L3 gateway chain expansion.
- ⚡ GOP pre‑caching enables new connections to start playback instantly without waiting for a keyframe.
- 🔁 Automatic upstream reconnection on stream disconnection, transparent to end users.
- 📊 Built‑in runtime statistics, outputting online viewers and upload/download traffic every 10 seconds.
- 🚀 **Horizontal Scaling**: Add gateway processes/instances at the same tier, linearly increasing concurrency.
- 🚀 **Vertical Scaling**: Multi‑level cascading to disperse single‑point pressure.
- 🧠 **Adaptive I/O**: Automatically enables epoll on Linux (20,000+ concurrent connections per process); falls back to select on Windows for compatibility.

### FLV Gateway Startup Commands

```bash
# [Horizontal Scaling] Single tier, multiple instances
php flvGateway.php 8080 http://origin-ip:8501
php flvGateway.php 8081 http://origin-ip:8501
php flvGateway.php 8082 http://origin-ip:8501

# [Vertical Scaling] Multi‑level cascade
php flvGateway.php 8080 http://origin-ip:8501      # L1 gateway
php flvGateway.php 8081 http://127.0.0.1:8080       # L2 gateway
php flvGateway.php 8082 http://127.0.0.1:8081       # L3 gateway

# [Combined Scaling] Multi‑level + multiple instances per level
# L1 gateway cluster
php flvGateway.php 8080 http://origin-ip:8501
php flvGateway.php 8081 http://origin-ip:8501
# L2 gateway cluster (pulls from L1 gateways)
php flvGateway.php 8180 http://127.0.0.1:8080
php flvGateway.php 8181 http://127.0.0.1:8081
```

### Gateway Playback URL Format

```
http://gateway-ip:port/{app}/{stream}.flv
```

Example:
```
# L1 gateway
http://127.0.0.1:8080/live/stream.flv
# L2 gateway
http://127.0.0.1:8081/live/stream.flv
```

### Debug Logging

Set `$gateway->debug = true;` in the gateway startup script to enable full detailed runtime logs.

## 📁 Static File Gateway `fileGateway.php` (High‑Concurrency VOD Resource Hosting)

### Gateway Overview

A lightweight HTTP static file server that centrally hosts all static resources. **For file‑based protocols such as HLS, fMP4, and MP4, this is the recommended playback method**. It supports horizontal and vertical scaling, capable of handling massive VOD concurrency.

### Core Capabilities

- 📁 Centrally hosts all static resources (recorded files + playback pages).
- 🔗 **Horizontal Scaling**: Multi‑instance deployment with load‑balanced traffic.
- 🔗 **Vertical Scaling**: Multi‑level cascading (e.g., Nginx + fileGateway + backend storage).
- 📊 Built‑in access logs for analysis.
- 🚀 Pure PHP implementation, lightweight with no dependencies.
- 🧠 **Adaptive I/O**: Linux epoll supports 20,000+ concurrent connections per process; Windows select for compatibility.
- **💡 Best Practice**: Point HLS/fMP4/MP4 playback URLs to this gateway cluster; the origin only writes files, achieving read‑write separation.

### Startup Commands (Multi‑Process / Multi‑Instance Distribution)

```bash
# Basic startup (host current directory, port 8100)
php fileGateway.php 0.0.0.0 8100

# [Horizontal Scaling] Multi‑instance deployment
php fileGateway.php 0.0.0.0 8100
php fileGateway.php 0.0.0.0 8101
php fileGateway.php 0.0.0.0 8102

# Run multiple instances in background on Linux/macOS
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
http://gateway-ip:port/{relative_file_path}
```

Examples:
```
# Web playback pages (accessed through the static gateway)
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

## 📡 Publishing Tutorial

### RTMP Publish URL Format

```
rtmp://127.0.0.1:1935/{app}/{stream}
```

- `app`: e.g. `live`
- `stream`: e.g. `stream`
- Only English letters and digits are supported.

### Publishing Examples

#### OBS Studio
1. Download and install [OBS Studio](https://obsproject.com/).
2. Settings → Stream → Server: `rtmp://127.0.0.1:1935/live`
3. Stream Key: `stream`
4. Start Streaming.

#### FFmpeg Loop Push
```bash
ffmpeg -re -stream_loop -1 -i "video.mp4" -vcodec h264 -acodec aac -f flv rtmp://127.0.0.1:1935/live/stream
```

## 📺 Playback URL Summary

### Live Streaming URLs

| Protocol       | URL                                                                 | Description                           | Distribution Recommendation                |
| -------------- | ------------------------------------------------------------------- | ------------------------------------- | ----------------------------------------- |
| RTMP           | `rtmp://127.0.0.1:1935/live/stream`                                 | Native RTMP player                    | Directly provided by the origin           |
| HTTP-FLV       | `http://127.0.0.1:8501/live/stream.flv`                             | Low‑latency browser playback          | **Distribute via FLV gateway cluster**    |
| WebSocket-FLV  | `ws://127.0.0.1:8501/live/stream.flv`                               | WebSocket streaming playback          | **Distribute via FLV gateway cluster**    |
| HLS            | `http://{fileGateway_IP}:8100/hls/live/stream/index.m3u8`           | Preferred for Android/iOS mobile      | **Must be distributed via fileGateway**   |

### VOD Playback URLs (after recording finishes)

| File Type                     | Access URL (must go through fileGateway)                                        | Description |
| ----------------------------- | ------------------------------------------------------------------------------- | ----------- |
| Merged MP4 VOD                | `http://{fileGateway_IP}:8100/mp4/live/stream/output_merge/stream_full.mp4`    |             |
| Muxed fMP4 Segment VOD (MSE)  | `http://{fileGateway_IP}:8100/mp4/live/stream/output_merge/init.mp4`            |             |
| Demuxed fMP4 VOD              | `http://{fileGateway_IP}:8100/mp4/live/stream/output_separate/audio_init.mp4`   |             |
| Raw FLV VOD                   | `http://{fileGateway_IP}:8100/flv/live/stream/20240101_120000.flv`              |             |

> **For high concurrency**: You must use the static file gateway cluster (e.g., `127.0.0.1:8100/8101/8102`) and distribute traffic through load balancing to achieve static resource read‑write separation.

### Web Player Pages

| Page Purpose                              | Access URL (recommended via fileGateway)              | Description                                                  |
| ----------------------------------------- | ----------------------------------------------------- | ------------------------------------------------------------ |
| FLV Live Playback                         | `http://{fileGateway_IP}:8100/index.html`             | HTTP‑FLV low‑latency live                                    |
| HLS Live Playback                         | `http://{fileGateway_IP}:8100/play.html`              | HLS mobile‑compatible live                                   |
| Merged MP4 VOD                            | `http://{fileGateway_IP}:8100/mp4.html`               | Full MP4 file VOD                                            |
| Raw FLV VOD                               | `http://{fileGateway_IP}:8100/video.html`             | Native FLV file VOD                                          |
| **fMP4 Segment VOD**                      | `http://{fileGateway_IP}:8100/play_merge.html`        | **Supports both muxed and demuxed segment playback**         |

## 💾 Real‑time Recording Details

### Recording Mechanism (Three Independent Parallel Tasks)

When publishing starts, the origin simultaneously starts three **independent and parallel** recording tasks, without blocking each other:

```
                    ┌─────────────────────────────────────────────────┐
                    │                  RTMP Publish                    │
                    └─────────────────────┬───────────────────────────┘
                                          │
                    ┌─────────────────────┼───────────────────────────┐
                    │                     │                           │
                    ▼                     ▼                           ▼
            ┌───────────────┐     ┌───────────────┐           ┌───────────────┐
            │   FLV Record  │     │  fMP4 Segment │           │   HLS Segment │
            │  (raw stream) │     │   (realtime)  │           │   (realtime)  │
            └───────┬───────┘     └───────┬───────┘           └───────┬───────┘
                    │                     │                           │
                    ▼                     ▼                           ▼
            ┌───────────────┐     ┌───────────────┐           ┌───────────────┐
            │  Complete FLV │     │  fMP4 Segment │           │  TS Segment   │
            │  (after stream│     │   Set (during │           │  Set + m3u8   │
            │   ends)       │     │    stream)    │           │   index       │
            └───────────────┘     └───────┬───────┘           └───────────────┘
                                          │
                                          │ Auto‑merge after stream ends
                                          ▼
                                    ┌───────────────┐
                                    │  Complete MP4 │
                                    │  (VOD playback)│
                                    └───────────────┘
```

### Task Independence

| Recording Task   | Realtime  | Output                                    | Purpose                          | Independent Switch    |
| ---------------- | --------- | ----------------------------------------- | -------------------------------- | --------------------- |
| **FLV Recording**| Realtime  | Complete FLV file                         | Raw format backup, VLC playback | `FLV_TO_RECORD`       |
| **fMP4 Segment** | Realtime  | fMP4 segments → merged into MP4 after stream | Browser MSE playback, VOD       | `FLV_TO_MP4`          |
| **HLS Segment**  | Realtime  | TS segments + m3u8                        | Mobile compatibility, HLS live  | `FLV_TO_HLS`          |

## 📁 Project Directory Structure

```
rtmp_server/
├── flv/                              # Raw FLV recordings (FLV_TO_RECORD)
├── mp4/                              # MP4/fMP4 conversion products (FLV_TO_MP4)
├── hls/                              # HLS TS segments + m3u8 index (FLV_TO_HLS)
├── MediaServer/                      # RTMP core protocol, publish/play session logic
├── Root/                             # Low‑level async I/O, socket event driver (includes epoll adaptation)
├── SabreAMF/                         # AMF0/AMF3 codec
├── server.php                        # Origin startup entry
├── fileGateway.php                   # Static file gateway (supports epoll, 20k+ concurrency)
├── flvGateway.php                    # FLV live gateway (supports epoll, 20k+ concurrency)
├── *.html                            # Web player pages
└── README.md
```

## 📈 Concurrency Performance Benchmarks

The following tests were all performed in the **same Docker container environment with `ulimit -n 65535`**, using the same stress‑testing script to simulate 20,000 concurrent clients, each pulling the stream for 5 seconds.

### Main Server (RTMP Origin)
```
Current container pids.max: unknown
Launching batch: 1000 clients (20 batches total)
All clients launched, waiting for completion...

===== Results =====
Success: 17,330
Failure: 2,670
```

### FLV Live Gateway
```
Current container pids.max: unknown
Launching batch: 1000 clients (20 batches total)
All clients launched, waiting for completion...

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

> **Notes**:
> - The main server, because it carries RTMP ingest, multi‑protocol repackaging and other business logic, still stably handled **17,330** concurrent successes as a single process. The few failures were caused by instantaneous port collisions during the test.
> - The FLV gateway, focused purely on stream forwarding, achieved a **99.6%** success rate (19,923/20,000), approaching the upper limit of the single‑machine TCP port pool.
> - The static file gateway is extremely lightweight: **20,000 concurrent connections, all successful, zero failures**.
> - All components adapt to the operating system: **on Linux, epoll is automatically enabled, breaking through the traditional 1024 limit of select**.

## ❓ FAQ

### 1. How can a single process support 20,000+ concurrent connections?

- **Linux**: When the server detects that the `event` extension is installed, it **automatically enables the epoll event‑driven model**, which is no longer limited by the traditional `select` 1024 file descriptor cap. A single process can easily handle 20,000+ connections.
- **Windows**: Because the `event` extension is unavailable, it automatically falls back to the `select` model, where a single process has a limited number of connections (~256). Deploying multiple instances is recommended.
- **Benchmark Proof**: In a Docker container (ulimit -n 65535), the static file gateway achieved **20,000 concurrent connections with zero failures**, and the FLV gateway had a 99.6% success rate.

### 2. How can the gateway support even higher concurrency?

| Scaling Method       | Description                                                     | Example                                                        |
| -------------------- | --------------------------------------------------------------- | -------------------------------------------------------------- |
| **Single Process High Performance** | Linux epoll mode: a single process handles 20k+           | One fileGateway process handles 20,000 static requests         |
| **Horizontal Scaling**| Deploy multiple instances at the same tier, load balanced | 3 fileGateway instances → 60,000+ concurrent                   |
| **Vertical Scaling** | Multi‑level cascading                                          | L1 gateway → L2 gateway → L3 gateway …                         |
| **Combined Scaling** | Horizontal + vertical                                           | 3 instances per tier × 3 tiers = theoretically 180,000+ concurrent |

### 3. When do I need to deploy a gateway?

| Concurrency Scenario             | Deployment Plan                                                   |
| -------------------------------- | ----------------------------------------------------------------- |
| **Low** (< 500)                  | Origin only; origin’s built‑in HTTP service serves directly       |
| **Medium** (500 – 5,000)         | Origin + single‑layer gateway cluster (1‑2 instances are enough)  |
| **High** (> 5,000)               | Origin + multi‑tier gateway cluster (each tier can scale horizontally) |

### 4. What’s the difference between the FLV gateway and the static file gateway?

| Gateway Type           | Purpose                       | Resource Types Handled                              | Scaling Method                  |
| ---------------------- | ----------------------------- | --------------------------------------------------- | ------------------------------ |
| **FLV Live Gateway**   | Live stream distribution      | HTTP‑FLV real‑time streams                          | Horizontal + vertical, cascadable |
| **Static File Gateway**| Static resource central hosting | HLS/fMP4/MP4/FLV static files + Web player pages | Horizontal + vertical, can combine with Nginx |

### 5. How can I verify the gateway’s concurrency capacity?

```bash
# Use the built‑in stress test script (20000 concurrent)
sh play.sh

# Or use ab (Apache Bench) to test the static file gateway
ab -n 10000 -c 500 http://127.0.0.1:8100/index.html

# Use wrk to test the FLV gateway
wrk -t4 -c1000 -d30s http://127.0.0.1:8080/live/stream.flv
```

## 📄 License

This project is intended solely for learning and technical research. Commercial deployment risks are borne by the user.

## ⚠️ Disclaimer

1. Some open‑source code originates from the community; if copyright is involved, please contact the author for removal.
2. The project is completely open source and free, for technical exchange only.
3. The author assumes no joint liability for any legal consequences arising from commercial or illegal use by the user.

## 📧 Contact

Technical consultation, problem feedback email: **2723659854@qq.com**