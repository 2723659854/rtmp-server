# RTMP Server

A lightweight RTMP live streaming server written in pure PHP, **with zero third-party streaming service dependencies**. Set up your own private live streaming platform out of the box.

> On Linux, epoll event-driven I/O is automatically enabled, effortlessly handling **20,000+** concurrent connections in a single process. Falls back to select mode on Windows to ensure compatibility.

---

## Table of Contents

- [Requirements](#requirements)
- [Quick Start (Get Streaming in 5 Minutes)](#quick-start-get-streaming-in-5-minutes)
- [Publishing URLs](#publishing-urls)
- [Playback URLs](#playback-urls)
- [Web Playback Pages](#web-playback-pages)
- [Recording Configuration](#recording-configuration)
- [System Architecture](#system-architecture)
- [FLV Streaming Gateway](#flv-streaming-gateway-high-concurrency-live-distribution)
- [Static File Gateway](#static-file-gateway-filegatewayphp-high-concurrency-vod-resource-hosting)
- [Publishing Guide (Detailed)](#publishing-guide-detailed)
- [Command-Line Playback (ffplay)](#command-line-playback-ffplay)
- [Port Configuration](#port-configuration)
- [Directory Structure](#directory-structure)
- [Concurrency Benchmarks](#concurrency-benchmarks)
- [Related Toolkits](#related-toolkits)
- [FAQ](#faq)
- [License & Disclaimer](#license--disclaimer)
- [Contact](#contact)

---

## Requirements

| Dependency | Description |
|------------|-------------|
| PHP | >= 8.1 (CLI mode only) |
| `sockets` extension | **Required** – provides low-level socket communication capabilities |
| `event` extension | **Highly recommended** – greatly improves concurrency performance on Linux by automatically enabling epoll mode |

> 💡 A pre-configured Docker environment is available. Run `docker-compose up -d` to start everything with a single command.

---

## Quick Start (Get Streaming in 5 Minutes)

### 1. Installation

```bash
composer create-project xiaosongshu/rtmp_server
cd rtmp_server
```

### 2. Start the Origin Server

```bash
php server.php
```

The following output indicates a successful startup:

```
[INFO] RTMP Server started on 0.0.0.0:1935
[INFO] HTTP-FLV/WS-FLV Server started on 0.0.0.0:8501
[INFO] HTTP Static Server started on 0.0.0.0:80
```

### 3. Publish a Stream (Choose One)

#### Option 1: Browser Publishing (No Software Required)

- Open `http://127.0.0.1/push.html` and click "Start Publishing".
- Alternatively, open `http://127.0.0.1/flv_push.html`, select an MP4/FLV file, and click "Start Publishing".

#### Option 2: FFmpeg Publishing

```bash
ffmpeg -re -stream_loop -1 -i video.mp4 -c:v libx264 -c:a aac -f flv rtmp://127.0.0.1:1935/live/stream
```

#### Option 3: OBS Publishing

- Server: `rtmp://127.0.0.1:1935/live/`
- Stream Key: `stream`
- Save and click "Start Streaming".

#### Option 4: PHP Publishing

```bash
# Push a static file (FLV/MP4 supported)
php pusher.php test.mp4 http://127.0.0.1:8501/live/stream
```

### 4. Watch the Live Stream

Open `http://127.0.0.1/index.html` to watch.

> 🎉 Congratulations! You have completed a full live streaming loop!

---

## Publishing URLs

| Protocol | URL Format | Example |
|----------|-----------|---------|
| RTMP | `rtmp://127.0.0.1:1935/{app}/{stream}` | `rtmp://127.0.0.1:1935/live/stream` |
| HTTP-FLV | `http://127.0.0.1:8501/{app}/{stream}` | `http://127.0.0.1:8501/live/stream` |
| WebSocket-FLV | `ws://127.0.0.1:8501/{app}/{stream}` | `ws://127.0.0.1:8501/live/stream` |

> **Note**: `{app}` is the application name (e.g., `live`), and `{stream}` is the channel name (e.g., `stream`). Only alphanumeric characters are supported.

---

## Playback URLs

### Live Streaming

| Protocol | Playback URL | Description |
|----------|-------------|-------------|
| RTMP | `rtmp://127.0.0.1:1935/live/stream` | Native players / ffplay |
| HTTP-FLV | `http://127.0.0.1:8501/live/stream.flv` | Low-latency browser playback |
| WebSocket-FLV | `ws://127.0.0.1:8501/live/stream.flv` | Native browser WebSocket support |
| HLS | `http://127.0.0.1:80/hls/live/stream/index.m3u8` | Preferred for mobile devices |

### VOD Playback (After Recording Completes)

Recorded files are located in the project root directory:

| File Type | File Path |
|-----------|-----------|
| Merged MP4 | `mp4/live/stream/output_merge/stream_full.mp4` |
| FLV Recording | `flv/live/stream/index.flv` |
| HLS Segments | `hls/live/stream/` |

Access example: `http://127.0.0.1:80/mp4/live/stream/output_merge/stream_full.mp4`

---

## Web Playback Pages

| Page | Purpose | URL |
|------|---------|-----|
| `index.html` | FLV low-latency live streaming | `http://127.0.0.1/index.html` |
| `play.html` | HLS mobile live streaming | `http://127.0.0.1/play.html` |
| `mp4.html` | MP4 VOD playback | `http://127.0.0.1/mp4.html` |
| `video.html` | FLV VOD playback | `http://127.0.0.1/video.html` |
| `play_merge.html` | fMP4 segmented VOD playback | `http://127.0.0.1/play_merge.html` |

### Web Publishing Pages

| Page | Purpose | URL |
|------|---------|-----|
| `push.html` | Screen sharing publishing | `http://127.0.0.1/push.html` |
| `flv_push.html` | Local FLV/MP4 pseudo-live publishing | `http://127.0.0.1/flv_push.html` |

### PHP Publishing Client

| Script | Purpose | Command Example |
|--------|---------|-----------------|
| `pusher.php` | Local FLV/MP4 pseudo-live publishing | `php pusher.php video.mp4 http://127.0.0.1:8501/live/stream` |

---

## Recording Configuration

Edit `server.php` to independently toggle the three recording tasks:

```php
define('FLV_TO_RECORD', true);   // Enable real-time FLV raw file recording
define('FLV_TO_MP4', true);      // Enable real-time fMP4 segmentation and MP4 merging
define('FLV_TO_HLS', true);      // Enable real-time HLS (TS) segmentation
```

> All three tasks run independently and in parallel without blocking each other.

---

## System Architecture

```
                                                    [Publishing] OBS / FFmpeg
                                                         │
                                       RTMP(1935)  /  HTTP-FLV / WS-FLV(8501)
                                                         │
                                                         ▼
╔══════════════════════════════════════════════════════════════════════════════════════╗
║                              RTMP Origin Server (Core)                                ║
║                                                                                      ║
║  📥 Stream Input   RTMP / HTTP-FLV / WebSocket-FLV triple-protocol publishing & auth  ║
║  🔄 Transmuxing    RTMP / HTTP-FLV / WS-FLV → HTTP-FLV / WebSocket-FLV / HLS / fMP4 / MP4 ║
║  💾 Recording      ┌──────────┬──────────┬──────────┐                                ║
║                    │   FLV    │   fMP4   │   HLS    │  Three independent parallel tasks ║
║                    │  (raw)   │(segments)│(segments)│                                ║
║                    └──────────┴──────────┴──────────┘                                ║
║  📤 Live Output    HTTP-FLV(8501) / WebSocket-FLV / HLS live / fMP4 live             ║
║  📦 VOD Artifacts  fMP4 segments generated in real-time → auto-merged into MP4 post-stream ║
║  📁 Static Server  Built-in HTTP server (port 80) for direct static file access (low concurrency) ║
╚══════════════════════════════════════════════════════════════════════════════════════╝
│
┌───────────────────┼───────────────────┐
│                   │                   │
▼                   ▼                   ▼
HTTP-FLV(8501)     HLS(TS/m3u8)       fMP4(segments)
Live Stream        Static Files        Static Files
│                   │                   │
│                   │                   │
▼                   ▼                   ▼
┌─────────────┐    ┌──────────────────────────────────────────┐
│ FLV Gateway │    │        Static File Gateway Cluster         │
│   Cluster   │    │    🎯 Hosts: HLS / fMP4 / MP4 / FLV / Web │
│             │    │                                          │
│ ┌─────────┐ │    │ ┌───────┐ ┌───────┐ ┌───────┐           │
│ │  Level 1│ │    │ │ GW 1  │ │ GW 2  │ │ GW 3  │           │
│ │ (8080)  │ │    │ │(8100) │ │(8101) │ │(8102) │           │
│ └───┬─────┘ │    │ └──┬────┘ └──┬────┘ └──┬────┘           │
│     │       │    │    │        │        │                 │
│ ┌───┴───┐   │    │    ▼        ▼        ▼                 │
│ ▼   ▼   ▼   │    │ ┌──────────────────────────────────┐   │
│ ┌─┐ ┌─┐ ┌─┐ │    │ │          Client                   │   │
│ │S│ │S│ │S│ │    │ │ HLS Player / MSE / VOD / ffplay  │   │
│ │G│ │G│ │G│ │    │ └──────────────────────────────────┘   │
│ └┬─┘ └┬─┘ └┬─┘ │    │                                          │
│  │    │    │   │    └──────────────────────────────────────────┘
│  ▼    ▼    ▼   │
│ ┌────────────┐ │
│ │   Client   │ │
│ │FLV / ffplay│ │
│ └────────────┘ │
└─────────────────┘
```

### Architecture Overview

- **Origin Server**: The sole stream production node, supporting **RTMP, HTTP-FLV, and WebSocket-FLV triple-protocol publishing**. Handles stream publishing, playback, and multi-protocol transmuxing. **FLV recording, fMP4 segmentation, and HLS segmentation run completely independently in parallel** without blocking each other.

- **Built-in Static Serving**: The origin server includes a built-in HTTP server (default port 80) for direct static file access. **No additional gateway is needed for low-concurrency scenarios**—truly out of the box.

- **Real-Time Recording Mechanism**:
    - **FLV Recording**: Saves the raw stream in real time, producing a complete FLV file once the stream ends.
    - **fMP4 Segmentation**: Generates audio/video fMP4 fragments in real time (supports both muxed and demuxed modes), automatically merged into a complete MP4 after the stream ends.
    - **HLS Segmentation**: Generates TS segments and m3u8 playlist in real time, compatible with mobile playback.
    - **Independent Toggles**: Each recording task can be individually enabled or disabled in `server.php`.

- **FLV Streaming Gateway Cluster**: A pure traffic forwarding service that pulls HTTP-FLV streams from upstream, caches GOP keyframes for instant playback on new connections, and distributes stream data to downstream clients or sub-gateways.
    - Supports unlimited cascading levels: Level 1 → Level 2 → Level 3 → ... → Client.
    - Supports horizontal scaling: Deploy multiple gateway instances at the same level with load balancing.
    - Linux epoll high performance: Handles 20,000+ concurrent connections per process; compatible with select model on Windows.

- **Static File Gateway Cluster (Recommended)**: A lightweight HTTP static file server that centrally hosts all static resources.
    - **Supported Protocols**: HLS (.m3u8/.ts), fMP4 (.m4s/.mp4), MP4 VOD files, FLV recordings, web playback pages.
    - Supports horizontal scaling: Deploy multiple instances at the same level for linear concurrency improvement.
    - Supports vertical scaling: Use Nginx or similar reverse proxies for multi-tier traffic distribution.
    - Linux epoll high performance: Handles 20,000+ concurrent connections per process; compatible with select model on Windows.
    - **Best Practice**: Point HLS/fMP4/MP4 playback paths to this gateway cluster for read/write separation of static resources.

- **Deployment Recommendations**:

| Concurrency Scenario | Deployment Strategy |
|----------------------|---------------------|
| Low (< 500) | Origin server built-in HTTP only, no extra gateway needed |
| Medium (500 – 5,000) | Origin + single-tier gateway cluster (FLV or Static File) |
| High (> 5,000) | Origin + multi-tier FLV gateway cluster + multi-tier static file gateway cluster |

---

## FLV Streaming Gateway (High-Concurrency Live Distribution)

### Overview

A lightweight traffic distribution component supporting unlimited cascading deployment. It pulls HTTP-FLV streams from the origin server or an upstream gateway, caches stream headers and GOP keyframes for instant playback on new connections, and replicates stream data to downstream clients or sub-gateways. **Designed for medium to high-concurrency live streaming scenarios** with both horizontal and vertical scalability.

### Startup Commands

```bash
# Basic startup (pull from origin server)
php flvGateway.php 8080 http://origin-ip:8501

# [Horizontal Scaling] Multiple instances at the same level
php flvGateway.php 8080 http://origin-ip:8501
php flvGateway.php 8081 http://origin-ip:8501
php flvGateway.php 8082 http://origin-ip:8501

# [Vertical Scaling] Multi-level cascading
php flvGateway.php 8080 http://origin-ip:8501      # Level 1 Gateway
php flvGateway.php 8081 http://127.0.0.1:8080      # Level 2 Gateway (pulls from Level 1)
php flvGateway.php 8082 http://127.0.0.1:8081      # Level 3 Gateway (pulls from Level 2)

# Linux/macOS background execution
php flvGateway.php 8080 http://origin-ip:8501 > /dev/null 2>&1 &
```

### Playback URL

```
http://gateway-ip:port/{app}/{stream}.flv
```

Example: `http://127.0.0.1:8080/live/stream.flv`

---

## Static File Gateway `fileGateway.php` (High-Concurrency VOD Resource Hosting)

### Overview

A lightweight HTTP static file server that centrally hosts all static resources. **This is the recommended playback method for file-based protocols such as HLS, fMP4, and MP4**. Supports horizontal and vertical scaling for large-scale VOD concurrency.

### Startup Commands

```bash
# Basic startup (serve current directory, port 8100)
php fileGateway.php 0.0.0.0 8100

# [Horizontal Scaling] Multiple instances
php fileGateway.php 0.0.0.0 8100
php fileGateway.php 0.0.0.0 8101
php fileGateway.php 0.0.0.0 8102

# Linux/macOS background execution
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

### Access URL

```
http://gateway-ip:port/{relative-file-path}
```

Examples:

```
http://127.0.0.1:8100/index.html
http://127.0.0.1:8100/hls/live/stream/index.m3u8
http://127.0.0.1:8100/mp4/live/stream/output_merge/stream_full.mp4
```

---

## Publishing Guide (Detailed)

### 1. RTMP Publishing

#### URL Format

```
rtmp://127.0.0.1:1935/{app}/{stream}
```

#### Examples

**OBS Studio:**

- Server: `rtmp://127.0.0.1:1935/live`
- Stream Key: `stream`

**FFmpeg:**

```bash
ffmpeg -re -stream_loop -1 -i video.mp4 -c:v libx264 -c:a aac -f flv rtmp://127.0.0.1:1935/live/stream
```

### 2. HTTP-FLV Publishing

#### URL Format

```
http://127.0.0.1:8501/{app}/{stream}
```

#### PHP Client Publishing

```bash
# Loop publishing of an FLV file (default)
php pusher.php test.flv http://127.0.0.1:8501/live/stream

# Loop publishing of an MP4 file (auto-converted to FLV format)
php pusher.php video.mp4 http://127.0.0.1:8501/live/stream

# 2x speed publishing
php pusher.php test.flv http://127.0.0.1:8501/live/stream 2.0

# Publish once without reconnecting
php pusher.php test.flv http://127.0.0.1:8501/live/stream 1.0 --no-reconnect
```

#### FFmpeg Publishing

```bash
ffmpeg -re -stream_loop -1 -i video.mp4 -c:v libx264 -c:a aac -f flv http://127.0.0.1:8501/live/stream
```

### 3. WebSocket-FLV Publishing

#### URL Format

```
ws://127.0.0.1:8501/{app}/{stream}
```

#### Browser Publishing (Recommended)

Open `http://127.0.0.1/push.html` or `http://127.0.0.1/flv_push.html`.

#### PHP Client Publishing

```bash
# Loop publishing of an FLV file (default)
php pusher.php test.flv ws://127.0.0.1:8501/live/stream

# Loop publishing of an MP4 file (auto-converted to FLV format)
php pusher.php video.mp4 ws://127.0.0.1:8501/live/stream

# 2x speed publishing
php pusher.php test.flv ws://127.0.0.1:8501/live/stream 2.0

# Publish once without reconnecting
php pusher.php test.flv ws://127.0.0.1:8501/live/stream 1.0 --no-reconnect
```

#### FFmpeg Publishing

```bash
ffmpeg -re -i video.mp4 -c:v libx264 -c:a aac -f flv - | websocat -b ws://127.0.0.1:8501/live/stream
```

---

## Command-Line Playback (ffplay)

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

> 💡 **Recommended Player**: Use [VLC](https://www.videolan.org/) for testing playback. It is a professional-grade media player that supports all common media formats.

---

## Port Configuration

Edit `server.php` to modify ports:

| Port | Protocol | Purpose |
|------|----------|---------|
| 1935 | RTMP | RTMP publishing and playback |
| 8501 | HTTP / WebSocket | HTTP-FLV / WS-FLV publishing and playback |
| 80 | HTTP | Static file serving + web pages |

---

## Directory Structure

```
rtmp_server/
├── flv/                        # FLV raw recording files
├── mp4/                        # MP4 / fMP4 transcoded artifacts
├── hls/                        # HLS TS segments + m3u8 playlists
├── MediaServer/                # RTMP core protocol, publishing/playback session logic
├── Root/                       # Low-level async I/O, socket event-driven engine
├── SabreAMF/                   # AMF0 / AMF3 encoding and decoding
├── server.php                  # Origin server entry point
├── fileGateway.php             # Static file gateway
├── flvGateway.php              # FLV streaming gateway
├── pusher.php                  # FLV/MP4 publishing client
├── push.html                   # Web publishing (screen sharing)
├── flv_push.html               # Web publishing (FLV/MP4 publishing page)
├── *.html                      # Web playback pages
└── README.md
```

---

## Concurrency Benchmarks

> All tests were conducted in a **Docker container with `ulimit -n 65535`**, simulating 20,000 concurrent clients, each pulling a stream for 5 seconds.

| Component | Successful | Failed | Success Rate |
|-----------|-----------|--------|--------------|
| Origin Server | 17,330 | 2,670 | 86.7% |
| FLV Streaming Gateway | 19,923 | 77 | 99.6% |
| Static File Gateway | 20,000 | 0 | 100% |

> **Notes**:
> - The origin server handles triple-protocol publishing and multi-protocol transmuxing, stably supporting 17,330 concurrent connections per process.
> - The FLV gateway focuses purely on stream forwarding, achieving a 99.6% success rate.
> - The static file gateway is extremely lightweight, achieving 20,000 successful connections with zero failures.
> - **Epoll is automatically enabled on Linux**, breaking through the 1024-connection limit of select.

---

## Related Toolkits

Standalone protocol conversion toolkit: [xiaosongshu/flv2mp4](https://github.com/2723659854/flv2mp4)

Supports FLV, MP4, and HLS transcoding; FLV gateway and static file gateway; and FLV/MP4 static file publishing client.

---

## FAQ

### 1. How can a single process support 20,000+ concurrent connections?

- **Linux**: When the `event` extension is detected, **epoll is automatically enabled**, breaking through select's 1024-connection limit.
- **Windows**: Falls back to select model; deploying multiple instances is recommended.
- Benchmarked: static file gateway achieves 20,000 concurrent connections with zero failures.

### 2. When should I deploy a gateway?

| Concurrency Scenario | Deployment Strategy |
|----------------------|---------------------|
| Low (< 500) | Origin server only |
| Medium (500 – 5,000) | Origin + single-tier gateway (1–2 instances) |
| High (> 5,000) | Origin + multi-tier gateway cluster |

### 3. What is the difference between the FLV Gateway and the Static File Gateway?

| Gateway | Purpose | Resources Served |
|---------|---------|------------------|
| FLV Streaming Gateway | Live stream distribution | HTTP-FLV real-time streams |
| Static File Gateway | Static resource hosting | HLS / fMP4 / MP4 / FLV + web pages |

### 4. What are the advantages of WebSocket-FLV publishing?

- Native browser support — no software installation required.
- Quick testing available through the built-in test pages.
- Supports mobile browser camera publishing (HTTPS required).

---

## License & Disclaimer

- This project is intended for learning and technical research purposes only. Users assume all risks associated with commercial deployment.
- Portions of the open-source code are sourced from the community. If any copyright concerns arise, please contact the author for removal.
- This project is fully open-source and free of charge, intended solely for technical exchange.
- The author assumes no joint liability for any legal consequences arising from commercial or illegal use by any user.

---

## Contact

- 📬 Email: `2723659854@qq.com`
- 🐙 GitHub: [2723659854](https://github.com/2723659854)
