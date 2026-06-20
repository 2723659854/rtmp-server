# RTMP Server
<p align="center">
  <a href="./README.cn.md"><strong>🇨🇳 中文</strong></a> •
  <a href="./README.md"><strong>🇬🇧 English</strong></a>
</p>
A lightweight RTMP live streaming server implemented in pure PHP, with **no third-party streaming media service dependencies**. Set up a private live streaming platform out of the box.

> On Linux, epoll event-driven mode is automatically enabled, easily handling **20,000+** concurrent connections with a single process. Falls back to select mode on Windows for compatibility.

---

## Table of Contents

- [Environment Requirements](#environment-requirements)
- [Quick Start (Get Live Streaming Running in 5 Minutes)](#quick-start-get-live-streaming-running-in-5-minutes)
- [Push Stream Addresses](#push-stream-addresses)
- [Playback Addresses](#playback-addresses)
- [Web Playback Pages](#web-playback-pages)
- [Recording Switch Configuration](#recording-switch-configuration)
- [System Architecture](#system-architecture)
- [FLV Streaming Media Gateway](#flv-streaming-media-gateway-high-concurrency-live-distribution)
- [Static File Gateway](#static-file-gateway-filegatewayphp-high-concurrency-vod-asset-hosting)
- [Push Stream Integration Tutorial (Detailed)](#push-stream-integration-tutorial-detailed)
- [Command-Line Playback Tools (ffplay)](#command-line-playback-tools-ffplay)
- [Port Configuration](#port-configuration)
- [Directory Structure](#directory-structure)
- [Concurrency Performance Benchmark](#concurrency-performance-benchmark)
- [Related Toolkits](#related-toolkits)
- [FAQ](#faq)
- [License & Disclaimer](#license--disclaimer)
- [Contact](#contact)

---

## Environment Requirements

| Requirement | Description |
|--------|------|
| PHP | >= 8.1 (CLI mode only) |
| `sockets` extension | **Required**, provides underlying Socket communication capabilities |
| `event` extension | **Strongly recommended**, significantly boosts concurrency performance on Linux by automatically enabling epoll mode |

> 💡 This project provides a Docker environment for quick setup. Run `docker-compose up -d` to start with one command.

---

## Quick Start (Get Live Streaming Running in 5 Minutes)

### 1. Installation

```bash
composer create-project xiaosongshu/rtmp_server
cd rtmp_server
```

### 2. Start the Origin Server

```bash
php server.php
```

The following output indicates a successful start:

```
[INFO] RTMP Server started on 0.0.0.0:1935
[INFO] HTTP-FLV/WS-FLV Server started on 0.0.0.0:8501
[INFO] HTTP Static Server started on 0.0.0.0:80
```

### 3. Push a Stream (Choose One of Four Methods)

#### Method 1: Browser Push (No Software Installation Required)

- Open `http://127.0.0.1/push.html` and click "Start Push".
- Or open `http://127.0.0.1/flv_push.html`, select an MP4/FLV static file, and click "Start Push".

#### Method 2: FFmpeg Push

```bash
ffmpeg -re -stream_loop -1 -i video.mp4 -c:v libx264 -c:a aac -f flv rtmp://127.0.0.1:1935/live/stream
```

#### Method 3: OBS Push

- Server: `rtmp://127.0.0.1:1935/live/`
- Stream Key: `stream`
- Save and click "Start Streaming".

#### Method 4: PHP Push

```bash
# Push a static file (supports FLV/MP4)
php pusher.php test.mp4 http://127.0.0.1:8501/live/stream
```

### 4. Watch the Live Stream

Open `http://127.0.0.1/index.html` to watch.

> 🎉 Congratulations! You've completed a full live streaming loop!

---

## Push Stream Addresses

| Protocol | Address Format | Example |
|------|---------|------|
| RTMP | `rtmp://127.0.0.1:1935/{app}/{stream}` | `rtmp://127.0.0.1:1935/live/stream` |
| HTTP-FLV | `http://127.0.0.1:8501/{app}/{stream}` | `http://127.0.0.1:8501/live/stream` |
| WebSocket-FLV | `ws://127.0.0.1:8501/{app}/{stream}` | `ws://127.0.0.1:8501/live/stream` |

> **Note**: `{app}` is the application name (e.g., `live`), and `{stream}` is the channel name (e.g., `stream`). Only letters and numbers are supported.

---

## Playback Addresses

### Live Streaming

| Protocol | Playback Address | Description |
|------|---------|------|
| RTMP | `rtmp://127.0.0.1:1935/live/stream` | Native players / ffplay |
| HTTP-FLV | `http://127.0.0.1:8501/live/stream.flv` | Low-latency browser playback |
| WebSocket-FLV | `ws://127.0.0.1:8501/live/stream.flv` | Native browser WebSocket support |
| HLS | `http://127.0.0.1:80/hls/live/stream/index.m3u8` | Preferred for mobile |

### VOD Playback (After Recording Completes)

Recorded files are located in the project root directory:

| File Type | File Path |
|---------|---------|
| Merged MP4 | `mp4/live/stream/output_merge/stream_full.mp4` |
| FLV Recording | `flv/live/stream/index.flv` |
| HLS Segments | `hls/live/stream/` |

Access example: `http://127.0.0.1:80/mp4/live/stream/output_merge/stream_full.mp4`

---

## Web Playback Pages

| Page | Purpose | Access URL |
|------|------|---------|
| `index.html` | FLV low-latency live streaming | `http://127.0.0.1/index.html` |
| `play.html` | HLS mobile live streaming | `http://127.0.0.1/play.html` |
| `mp4.html` | MP4 VOD | `http://127.0.0.1/mp4.html` |
| `video.html` | FLV VOD | `http://127.0.0.1/video.html` |
| `play_merge.html` | fMP4 segmented VOD | `http://127.0.0.1/play_merge.html` |

### Web Push Pages

| Page | Purpose | Access URL |
|------|------|---------|
| `push.html` | Screen sharing push | `http://127.0.0.1/push.html` |
| `flv_push.html` | Local FLV/MP4 pseudo-live push | `http://127.0.0.1/flv_push.html` |

### PHP Push Client

| Script | Purpose | Command Example |
|------|------|---------|
| `pusher.php` | Local FLV/MP4 pseudo-live push | `php pusher.php video.mp4 http://127.0.0.1:8501/live/stream` |

---

## Recording Switch Configuration

Edit `server.php` to independently control the on/off switches for the three recording tasks:

```php
define('FLV_TO_RECORD', true);   // Enable real-time FLV raw file recording
define('FLV_TO_MP4', true);      // Enable real-time fMP4 segment generation and MP4 merging
define('FLV_TO_HLS', true);      // Enable real-time HLS (TS) segment generation
```

> The three tasks run independently and in parallel, without blocking each other.

---

## System Architecture

```
                                                    [Pusher] OBS / FFmpeg
                                                         │
                                       RTMP Push(1935)  /  HTTP-FLV / WS-FLV Push(8501)
                                                         │
                                                         ▼
╔══════════════════════════════════════════════════════════════════════════════════════╗
║                              RTMP Origin Main Server (Core)                          ║
║                                                                                      ║
║  📥 Push Ingest    RTMP / HTTP-FLV / WebSocket-FLV three-protocol push, auth        ║
║  🔄 Protocol Conv  RTMP / HTTP-FLV / WS-FLV → HTTP-FLV / WebSocket-FLV / HLS / fMP4 ║
║  💾 Real-time Rec  ┌──────────┬──────────┬──────────┐                               ║
║                    │ FLV Rec  │ fMP4 Seg │ HLS Seg  │  Three independent tasks       ║
║                    │ (raw)    │ (real)   │ (real)   │                               ║
║                    └──────────┴──────────┴──────────┘                               ║
║  📤 Live Output    HTTP-FLV(8501) / WebSocket-FLV / HLS live / fMP4 live            ║
║  📦 VOD Output     fMP4 segments generated in real-time → auto-merged into MP4       ║
║  📁 Static Service Built-in HTTP server (port 80), direct static file access        ║
╚══════════════════════════════════════════════════════════════════════════════════════╝
│
┌───────────────────┼───────────────────┐
│                   │                   │
▼                   ▼                   ▼
HTTP-FLV(8501)     HLS(TS/m3u8)       fMP4(segments)
Live Output        Static Files        Static Files
│                   │                   │
│                   │                   │
▼                   ▼                   ▼
┌─────────────┐    ┌──────────────────────────────────────────┐
│ FLV Gateway │    │          Static File Gateway Cluster      │
│   Cluster   │    │    🎯 Hosting: HLS / fMP4 / MP4 / FLV /  │
│             │    │              Web Pages                    │
│ ┌─────────┐ │    │                                          │
│ │Level-1  │ │    │ ┌───────┐ ┌───────┐ ┌───────┐           │
│ │Gateway  │ │    │ │ GW 1  │ │ GW 2  │ │ GW 3  │           │
│ │(8080)   │ │    │ │(8100) │ │(8101) │ │(8102) │           │
│ └───┬─────┘ │    │ └──┬────┘ └──┬────┘ └──┬────┘           │
│     │       │    │    │        │        │                 │
│ ┌───┴───┐   │    │    ▼        ▼        ▼                 │
│ ▼   ▼   ▼   │    │ ┌──────────────────────────────────┐   │
│ ┌─┐ ┌─┐ ┌─┐ │    │ │          Client                  │   │
│ │S│ │S│ │S│ │    │ │ HLS Player / MSE / VOD / ffplay  │   │
│ │u│ │u│ │u│ │    │ └──────────────────────────────────┘   │
│ │b│ │b│ │b│ │    │                                          │
│ │ │ │ │ │ │ │    └──────────────────────────────────────────┘
│ └┬─┘ └┬─┘ └┬─┘ │
│  │    │    │   │
│  ▼    ▼    ▼   │
│ ┌────────────┐ │
│ │   Client   │ │
│ │FLV / ffplay│ │
│ └────────────┘ │
└─────────────────┘
```

### Architecture Notes

- **Origin Server**: The sole stream production node, supporting **RTMP, HTTP-FLV, and WebSocket-FLV three-protocol push**, handling push/pull ingest and multi-protocol repackaging. The three recording tasks — **FLV recording, fMP4 segmentation, and HLS segmentation** — run completely independently and in parallel without blocking each other.

- **Origin Static Capability**: The origin server has a built-in HTTP service (default port 80) that can directly serve static files. **No additional gateway deployment is needed for low-concurrency scenarios** — works out of the box.

- **Real-time Recording Mechanism**:
  - **FLV Recording**: Saves the raw stream in real time, producing a complete FLV file after the stream ends.
  - **fMP4 Segmentation**: Generates audio/video fMP4 segments in real time (supports both mixed and separate segment formats), auto-merged into a complete MP4 after the stream ends.
  - **HLS Segmentation**: Generates TS segments + m3u8 index in real time, compatible with mobile playback.
  - **Independent Switches**: Users can configure whether to enable each recording task individually in `server.php`.

- **FLV Live Gateway Cluster**: A pure stream relay service that pulls HTTP-FLV streams upstream, caches GOP keyframes for instant playback, and distributes downstream to end clients or subordinate gateways.
  - Supports unlimited hierarchical cascading: Level-1 → Level-2 → Level-3 → ... → Client.
  - Supports horizontal scaling: Deploy multiple gateway instances at the same level with load-balanced traffic.
  - Linux epoll high performance: A single process can handle 20,000+ concurrent connections; Windows falls back to select model.

- **Static File Gateway Cluster (Recommended)**: A lightweight HTTP static file server that uniformly hosts all static assets.
  - **Applicable Protocols**: HLS (.m3u8/.ts), fMP4 (.m4s/.mp4), MP4 VOD files, FLV recorded files, Web playback pages.
  - Supports horizontal scaling: Deploy multiple gateway instances at the same level to linearly increase concurrency capacity.
  - Supports vertical scaling: Use reverse proxies like Nginx to distribute traffic across multiple tiers of static file gateways.
  - Linux epoll high performance: A single process can handle 20,000+ concurrent connections; Windows falls back to select model.
  - **Best Practice**: Point HLS/fMP4/MP4 playback paths to this gateway cluster for read/write separation of static assets.

- **Deployment Recommendations**:

| Concurrency Scenario | Deployment Plan |
|---------|---------|
| Low concurrency (< 500) | Use the origin server's built-in HTTP service directly, no additional gateway needed |
| Medium concurrency (500 – 5,000) | Origin + single-tier gateway cluster (FLV gateway or Static File gateway) |
| High concurrency (> 5,000) | Origin + multi-tier FLV gateway cluster + multi-tier Static File gateway cluster |

---

## FLV Streaming Media Gateway (High-Concurrency Live Distribution)

### Gateway Overview

A lightweight traffic distribution component supporting unlimited hierarchical cascading deployment. Pulls HTTP-FLV from the upstream origin/parent gateway, caches stream headers and GOP keyframes for instant playback for new viewers, and replicates stream data to downstream clients or child gateways. **Designed specifically for medium to high-concurrency live streaming scenarios**, supporting both horizontal and vertical scaling.

### Startup Commands

```bash
# Basic startup (pull from origin stream)
php flvGateway.php 8080 http://origin-server-ip:8501

# [Horizontal Scaling] Multiple instances at the same level
php flvGateway.php 8080 http://origin-server-ip:8501
php flvGateway.php 8081 http://origin-server-ip:8501
php flvGateway.php 8082 http://origin-server-ip:8501

# [Vertical Scaling] Multi-level cascading
php flvGateway.php 8080 http://origin-server-ip:8501        # Level-1 gateway
php flvGateway.php 8081 http://127.0.0.1:8080               # Level-2 gateway (pulls from level-1)
php flvGateway.php 8082 http://127.0.0.1:8081               # Level-3 gateway (pulls from level-2)

# Linux/macOS background running
php flvGateway.php 8080 http://origin-server-ip:8501 > /dev/null 2>&1 &
```

### Playback Address

```
http://gateway-ip:port/{app}/{stream}.flv
```

Example: `http://127.0.0.1:8080/live/stream.flv`

---

## Static File Gateway `fileGateway.php` (High-Concurrency VOD Asset Hosting)

### Gateway Overview

A lightweight HTTP static file server that uniformly hosts all static assets. **This is the recommended playback method for file-based protocols like HLS, fMP4, and MP4**. Supports both horizontal and vertical scaling, capable of handling large-scale VOD concurrency.

### Startup Commands

```bash
# Basic startup (serve current directory, port 8100)
php fileGateway.php 0.0.0.0 8100

# [Horizontal Scaling] Multiple instances
php fileGateway.php 0.0.0.0 8100
php fileGateway.php 0.0.0.0 8101
php fileGateway.php 0.0.0.0 8102

# Linux/macOS background running
php fileGateway.php 0.0.0.0 8100 > /dev/null 2>&1 &
```

### Nginx Reverse Proxy Configuration Example

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

### Access Address

```
http://gateway-ip:port/{relative_file_path}
```

Examples:

```
http://127.0.0.1:8100/index.html
http://127.0.0.1:8100/hls/live/stream/index.m3u8
http://127.0.0.1:8100/mp4/live/stream/output_merge/stream_full.mp4
```

---

## Push Stream Integration Tutorial (Detailed)

### I. RTMP Push

#### Address Format

```
rtmp://127.0.0.1:1935/{app}/{stream}
```

#### Push Examples

**OBS Studio:**

- Server: `rtmp://127.0.0.1:1935/live`
- Stream Key: `stream`

**FFmpeg:**

```bash
ffmpeg -re -stream_loop -1 -i video.mp4 -c:v libx264 -c:a aac -f flv rtmp://127.0.0.1:1935/live/stream
```

**PHP Client**
```bash
# Push FLV file (RTMP protocol)
php pusher.php test.flv rtmp://127.0.0.1:1935/live/stream

# Push MP4 file with automatic conversion to FLV format (RTMP protocol)
php pusher.php video.mp4 rtmp://127.0.0.1:1935/live/stream
```

### II. HTTP-FLV Push

#### Address Format

```
http://127.0.0.1:8501/{app}/{stream}
```

#### PHP Client Push

```bash
# Loop push FLV file (default)
php pusher.php test.flv http://127.0.0.1:8501/live/stream

# Loop push MP4 file (auto-convert to FLV format)
php pusher.php video.mp4 http://127.0.0.1:8501/live/stream

# 2x speed push
php pusher.php test.flv http://127.0.0.1:8501/live/stream 2.0

# Push once without reconnecting
php pusher.php test.flv http://127.0.0.1:8501/live/stream 1.0 --no-reconnect
```

#### FFmpeg Push

```bash
ffmpeg -re -stream_loop -1 -i video.mp4 -c:v libx264 -c:a aac -f flv http://127.0.0.1:8501/live/stream
```

### III. WebSocket-FLV Push

#### Address Format

```
ws://127.0.0.1:8501/{app}/{stream}
```

#### Browser Push (Recommended)

Open `http://127.0.0.1/push.html` or `http://127.0.0.1/flv_push.html`.

#### PHP Client Push

```bash
# Push FLV file (default)
php pusher.php test.flv ws://127.0.0.1:8501/live/stream

# Push MP4 file (auto-convert to FLV format)
php pusher.php video.mp4 ws://127.0.0.1:8501/live/stream

# 2x speed push
php pusher.php test.flv ws://127.0.0.1:8501/live/stream 2.0

# Push once without reconnecting
php pusher.php test.flv ws://127.0.0.1:8501/live/stream 1.0 --no-reconnect

# Push FLV file (RTMP protocol)
php pusher.php test.flv rtmp://127.0.0.1:1935/live/stream

# Push MP4 file with automatic conversion to FLV format (RTMP protocol)
php pusher.php video.mp4 rtmp://127.0.0.1:1935/live/stream
```

#### FFmpeg Push

```bash
ffmpeg -re -i video.mp4 -c:v libx264 -c:a aac -f flv - | websocat -b ws://127.0.0.1:8501/live/stream
```

---

## Command-Line Playback Tools (ffplay)

```bash
# RTMP stream
ffplay rtmp://127.0.0.1:1935/live/stream

# HTTP-FLV stream
ffplay http://127.0.0.1:8501/live/stream.flv

# WebSocket-FLV stream
ffplay ws://127.0.0.1:8501/live/stream.flv

# FLV gateway relayed stream
ffplay http://127.0.0.1:8080/live/stream.flv

# HLS stream
ffplay http://127.0.0.1:8100/hls/live/stream/index.m3u8

# VOD files
ffplay http://127.0.0.1:8100/flv/live/stream/index.flv
ffplay http://127.0.0.1:8100/mp4/live/stream/output_merge/stream_full.mp4
```

> 💡 **Recommended Player**: Use [VLC](https://www.videolan.org/) for testing playback. It is a professional-grade player that supports various media formats.

---

## Port Configuration

Edit `server.php` to modify ports:

| Port | Protocol | Purpose |
|------|------|------|
| 1935 | RTMP | RTMP push and pull |
| 8501 | HTTP / WebSocket | HTTP-FLV / WS-FLV push and pull |
| 80 | HTTP | Static file service + Web pages |

---

## Directory Structure

```
rtmp_server/
├── flv/                        # FLV raw recording files
├── mp4/                        # MP4 / fMP4 transcoded output
├── hls/                        # HLS TS segments + m3u8 index
├── MediaServer/                # RTMP core protocol, push/pull session logic
├── Root/                       # Low-level async IO, Socket event-driven
├── server.php                  # Origin server entry point
├── fileGateway.php             # Static file gateway
├── flvGateway.php              # FLV live gateway
├── pusher.php                  # FLV/MP4 push client
├── push.html                   # Web push (screen sharing)
├── flv_push.html               # Web push (FLV/MP4 push page)
├── *.html                      # Web playback pages
└── README.md
```

---

## Concurrency Performance Benchmark

> The following tests were conducted in a **Docker container with `ulimit -n 65535`**, with 20,000 concurrent clients, each pulling the stream continuously for 5 seconds.

| Component | Successful Connections | Failed Connections | Success Rate |
|------|-----------|-----------|--------|
| Main Server (Origin) | 17,330 | 2,670 | 86.7% |
| FLV Live Gateway | 19,923 | 77 | 99.6% |
| Static File Gateway | 20,000 | 0 | 100% |

> **Notes**:
> - The main server handles three-protocol push, multi-protocol repackaging, and other tasks, stably supporting 17,330 concurrent connections with a single process.
> - The FLV gateway focuses on pure stream relay with a 99.6% success rate.
> - The static file gateway is extremely lightweight, achieving 100% success with 20,000 concurrent connections.
> - **Epoll is automatically enabled on Linux**, breaking through the 1024 limit of select.

---

## Related Toolkits

Independent protocol conversion toolkit: [xiaosongshu/flv2mp4](https://github.com/2723659854/flv2mp4)

Supports FLV, MP4, and HLS transcoding, FLV gateway, static file gateway, and FLV/MP4 static file push clients (supporting ws-flv, http-flv, and rtmp protocols).

---

## FAQ

### 1. How can a single process support 20,000+ concurrent connections?

- **Linux**: When the `event` extension is detected, **epoll is automatically enabled**, breaking through the 1024 limit of select.
- **Windows**: Falls back to select model; deploying multiple instances is recommended.
- Verified: The static file gateway achieved 20,000 concurrent connections with zero failures.

### 2. When do I need to deploy a gateway?

| Concurrency Scenario | Deployment Plan |
|---------|---------|
| Low concurrency (< 500) | Origin server only is sufficient |
| Medium concurrency (500 – 5,000) | Origin + single-tier gateway (1–2 instances) |
| High concurrency (> 5,000) | Origin + multi-tier gateway cluster |

### 3. What is the difference between the FLV gateway and the static file gateway?

| Gateway | Purpose | Resources Served |
|------|------|-----------|
| FLV Live Gateway | Live stream distribution | HTTP-FLV real-time streams |
| Static File Gateway | Static asset hosting | HLS / fMP4 / MP4 / FLV + Web pages |

### 4. What are the advantages of WebSocket-FLV push?

- Native browser support, no software installation required.
- Quick testing via built-in test pages.
- Supports mobile browser camera push (requires HTTPS).

---

## License & Disclaimer

- This project is intended for learning and technical research only; commercial use risks are assumed by the user.
- Some open-source code is taken from the open-source community. If copyright issues arise, please contact the author for removal.
- The project is fully open-source and free, for technical exchange only.
- The author assumes no joint liability for any legal consequences arising from commercial or illegal use by users.

---

## Contact

- 📬 Email: `2723659854@qq.com`
- 🐙 GitHub: [2723659854](https://github.com/2723659854)