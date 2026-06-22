# RTMP Server
<p align="center">
  <a href="./README.cn.md"><strong>🇨🇳 中文</strong></a> •
  <a href="./README.md"><strong>🇬🇧 English</strong></a>
</p>

> A lightweight RTMP live streaming service written in pure PHP, **no third-party streaming media service dependencies**, ready to quickly build a private live streaming platform out-of-the-box.

> On Linux environments, the epoll event driver is automatically enabled, allowing a single process to easily handle **20,000+** concurrent connections; on Windows, it falls back to select mode to ensure compatibility.

---

## Table of Contents

- [Environment Requirements](#environment-requirements)
- [Quick Start (Go Live in 5 Minutes)](#quick-start-go-live-in-5-minutes)
- [Push URLs](#push-urls)
- [Playback URLs](#playback-urls)
- [Web Playback Pages](#web-playback-pages)
- [Recording Configuration](#recording-configuration)
- [System Architecture](#system-architecture)
- [FLV Streaming Gateway](#flv-streaming-gateway-high-concurrency-live-distribution)
- [Static File Gateway](#static-file-gateway-filegatewayphp-high-concurrency-vod-resource-hosting)
- [Push Access Tutorial (Detailed)](#push-access-tutorial-detailed)
- [Command Line Player (ffplay)](#command-line-player-ffplay)
- [Port Configuration](#port-configuration)
- [Directory Structure](#directory-structure)
- [Concurrency Performance Benchmarks](#concurrency-performance-benchmarks)
- [Related Toolkits](#related-toolkits)
- [FAQ](#faq)
- [Open Source License & Disclaimer](#open-source-license--disclaimer)
- [Contact](#contact)

---

## Environment Requirements

| Dependency | Description |
|------------|-------------|
| PHP | >= 8.1 (CLI mode only) |
| `sockets` extension | **Required**, provides underlying Socket communication capabilities |
| `event` extension | **Highly recommended**, greatly improves concurrency performance on Linux with automatic epoll mode |

> 💡 This project provides a Docker quick setup environment. Run `docker-compose up -d` to start with one command.

---

## Quick Start (Go Live in 5 Minutes)

### 1. Installation

```bash
composer create-project xiaosongshu/rtmp_server
cd rtmp_server
```

### 2. Start the Origin Service

```bash
php server.php
```

Success is indicated by the following output:

```
[INFO] RTMP Server started on 0.0.0.0:1935
[INFO] HTTP-FLV/WS-FLV Server started on 0.0.0.0:8501
[INFO] HTTP Static Server started on 0.0.0.0:80
```

### 3. Push Stream (Choose One of Four)

#### Method 1: Browser Push (No Software Installation Required)

- Open `http://127.0.0.1/push.html` and click "Start Push".
- Or open `http://127.0.0.1/flv_push.html`, select an MP4/FLV static file, and click "Start Push".

#### Method 2: FFmpeg Push

```bash
ffmpeg -re -stream_loop -1 -i video.mp4 -c:v libx264 -c:a aac -f flv rtmp://127.0.0.1:1935/live/stream
```

#### Method 3: OBS Push

- Server URL: `rtmp://127.0.0.1:1935/live/`
- Stream Key: `stream`
- Save and click "Start Streaming".

#### Method 4: PHP Push

```bash
# Push using a static file (supports FLV/MP4)
php pusher.php test.mp4 http://127.0.0.1:8501/live/stream
```

### 4. Watch Live Stream

Open `http://127.0.0.1/index.html` to watch.

> 🎉 You have now completed a full live streaming workflow!

---

## Push URLs

| Protocol | URL Format | Example |
|----------|-----------|---------|
| RTMP | `rtmp://127.0.0.1:1935/{app}/{stream}` | `rtmp://127.0.0.1:1935/live/stream` |
| HTTP-FLV | `http://127.0.0.1:8501/{app}/{stream}` | `http://127.0.0.1:8501/live/stream` |
| WebSocket-FLV | `ws://127.0.0.1:8501/{app}/{stream}` | `ws://127.0.0.1:8501/live/stream` |

> **Note**: `{app}` is the application name (e.g., `live`), `{stream}` is the channel name (e.g., `stream`). Only English letters and numbers are supported.

---

## Playback URLs

### Live Streaming

| Protocol | Playback URL | Description |
|----------|-------------|-------------|
| RTMP | `rtmp://127.0.0.1:1935/live/stream` | Native player / ffplay |
| HTTP-FLV | `http://127.0.0.1:8501/live/stream.flv` | Low-latency browser playback |
| WebSocket-FLV | `ws://127.0.0.1:8501/live/stream.flv` | Native browser WebSocket support |
| HLS | `http://127.0.0.1:80/hls/live/stream/index.m3u8` | Preferred for mobile devices |

### VOD Playback (After Recording)

Recorded files are located in the project root directory:

| File Type | File Path |
|-----------|-----------|
| Merged MP4 | `mp4/live/stream/output_merge/stream_full.mp4` |
| FLV Recording | `flv/live/stream/index.flv` |
| HLS Segments | `hls/live/stream/` |

Access example: `http://127.0.0.1:80/mp4/live/stream/output_merge/stream_full.mp4`

---

## Web Playback Pages

| Page | Purpose | Access URL |
|------|---------|------------|
| `index.html` | FLV low-latency live streaming | `http://127.0.0.1/index.html` |
| `play.html` | HLS mobile live streaming | `http://127.0.0.1/play.html` |
| `mp4.html` | MP4 VOD | `http://127.0.0.1/mp4.html` |
| `video.html` | FLV VOD | `http://127.0.0.1/video.html` |
| `play_merge.html` | fMP4 segment VOD | `http://127.0.0.1/play_merge.html` |

### Web Push Pages

| Page | Purpose | Access URL |
|------|---------|------------|
| `push.html` | Screen sharing push | `http://127.0.0.1/push.html` |
| `flv_push.html` | Local FLV/MP4 pseudo-live push | `http://127.0.0.1/flv_push.html` |

### PHP Push Clients

| Script | Purpose | Command Example |
|--------|---------|-----------------|
| `pusher.php` | Local FLV/MP4 pseudo-live push | `php pusher.php video.mp4 http://127.0.0.1:8501/live/stream` |

---

## Recording Configuration

Edit `server.php` to independently control the three recording tasks:

```php
define('FLV_TO_RECORD', true);   // Whether to record FLV raw files in real-time
define('FLV_TO_MP4', true);      // Whether to generate fMP4 segments and merge to MP4 in real-time
define('FLV_TO_HLS', true);      // Whether to generate HLS (TS) segments in real-time
```

> The three tasks run independently and in parallel without blocking each other.

---

## System Architecture

```
                                                    【Push Client】OBS / FFmpeg
                                                         │
                                       RTMP Push(1935)  /  HTTP-FLV / WS-FLV Push(8501)
                                                         │
                                                         ▼
╔══════════════════════════════════════════════════════════════════════════════════════╗
║                              RTMP Origin Server (Core)                                ║
║                                                                                      ║
║  📥 Push Access    RTMP / HTTP-FLV / WebSocket-FLV three-protocol push, link auth    ║
║  🔄 Protocol Conversion  RTMP / HTTP-FLV / WS-FLV → HTTP-FLV / WebSocket-FLV / HLS / fMP4 / MP4 ║
║  💾 Real-time Recording  ┌──────────┬──────────┬──────────┐                          ║
║                         │ FLV Rec  │ fMP4 Seg │ HLS Seg  │  Three independent parallel tasks ║
║                         │ (raw)    │ (segments)│ (segments)│                          ║
║                         └──────────┴──────────┴──────────┘                          ║
║  📤 Live Output      HTTP-FLV(8501) / WebSocket-FLV / HLS Live / fMP4 Live           ║
║  📦 VOD Output       fMP4 segments generated in real-time → auto-merge to MP4 after stream ends ║
║  📁 Static Service    Origin built-in HTTP service (port 80), serves static files directly (for low-concurrency scenarios) ║
╚══════════════════════════════════════════════════════════════════════════════════════╝
│
┌───────────────────┼───────────────────┐
│                   │                   │
▼                   ▼                   ▼
HTTP-FLV(8501)     HLS(TS/m3u8)       fMP4(Segments)
Live Output         Static Files       Static Files
│                   │                   │
│                   │                   │
▼                   ▼                   ▼
┌─────────────┐    ┌──────────────────────────────────────────┐
│ FLV Gateway  │    │       Static File Gateway Cluster (fileGateway)  │
│   Cluster    │    │    🎯 Hosts: HLS / fMP4 / MP4 / FLV / Web Pages │
│             │    │                                          │
│ ┌─────────┐ │    │ ┌───────┐ ┌───────┐ ┌───────┐           │
│ │ Tier 1   │ │    │ │Gateway│ │Gateway│ │Gateway│           │
│ │ Gateway  │ │    │ │(8100) │ │(8101) │ │(8102) │           │
│ │ (8080)   │ │    │ └──┬────┘ └──┬────┘ └──┬────┘           │
│ └───┬─────┘ │    │    │        │        │                 │
│     │       │    │    ▼        ▼        ▼                 │
│ ┌───┴───┐   │    │ ┌──────────────────────────────────┐   │
│ ▼   ▼   ▼   │    │ │          Client                  │   │
│ ┌─┐ ┌─┐ ┌─┐ │    │ │ HLS Player / MSE / VOD / ffplay │   │
│ │S│ │S│ │S│ │    │ └──────────────────────────────────┘   │
│ │u│ │u│ │u│ │    │                                          │
│ │b│ │b│ │b│ │    └──────────────────────────────────────────┘
│ │G│ │G│ │G│ │
│ │w│ │w│ │w│ │
│ └┬─┘ └┬─┘ └┬─┘ │
│  │    │    │   │
│  ▼    ▼    ▼   │
│ ┌────────────┐ │
│ │   Client   │ │
│ │ FLV / ffplay│ │
│ └────────────┘ │
└─────────────────┘
```

### Architecture Description

- **Origin Service**: The only stream production node, supporting **RTMP, HTTP-FLV, WebSocket-FLV three-protocol push**, responsible for push/pull access and multi-protocol remuxing. **FLV recording, fMP4 segmenting, and HLS segmenting run completely independently and in parallel**, without blocking each other.

- **Origin Static Capability**: The origin has a built-in HTTP service (default port 80) that can serve static files directly. **No additional gateway is required for low-concurrency scenarios**, ready to use out-of-the-box.

- **Real-time Recording Mechanism**:
  - **FLV Recording**: Saves raw streams in real-time, producing a complete FLV file after the stream ends.
  - **fMP4 Segmenting**: Generates audio/video fMP4 segments in real-time (supports both multiplexed and separate segment formats), automatically merged into a complete MP4 after the stream ends.
  - **HLS Segmenting**: Generates TS segments + m3u8 index in real-time, compatible with mobile playback.
  - **Independent Switches**: Users can configure whether to enable each recording task independently in `server.php`.

- **FLV Live Gateway Cluster**: Pure traffic forwarding service that pulls HTTP-FLV streams from upstream, caches GOP keyframes for instant playback on player connection, and distributes stream data downstream to end clients or sub-gateways.
  - Supports unlimited tiered cascading: Tier 1 Gateway → Tier 2 Gateway → Tier 3 Gateway → ... → Clients.
  - Supports horizontal scaling: Deploy multiple gateway instances at the same tier with load balancing for traffic distribution.
  - Linux epoll high performance: Single process can handle 20,000+ concurrent connections; Windows compatible with select model.

- **Static File Gateway Cluster (Recommended)**: Lightweight HTTP static file server that centrally hosts all static resources.
  - **Supported protocols**: HLS (.m3u8/.ts), fMP4 (.m4s/.mp4), MP4 VOD files, FLV recorded files, Web playback pages.
  - Supports horizontal scaling: Deploy multiple gateway instances at the same tier for linear concurrency improvement.
  - Supports vertical scaling: Use Nginx or other reverse proxies for multi-tier traffic distribution to static file gateways.
  - Linux epoll high performance: Single process can handle 20,000+ concurrent connections; Windows compatible with select model.
  - **Best Practice**: Point HLS/fMP4/MP4 playback paths to this gateway cluster for read-write separation of static resources.

- **Deployment Recommendations**:

| Concurrency Scenario | Deployment Solution |
|---------------------|---------------------|
| Low (< 500) | Origin built-in HTTP service only, no additional gateway needed |
| Medium (500 – 5,000) | Origin + single-tier gateway cluster (FLV Gateway or Static File Gateway) |
| High (> 5,000) | Origin + multi-tier FLV Gateway cluster + multi-tier Static File Gateway cluster |

---

## FLV Streaming Gateway (High-Concurrency Live Distribution)

### Gateway Overview

A lightweight traffic distribution component supporting unlimited tiered cascading deployment. It pulls HTTP-FLV from upstream origins/upper-tier gateways, caches stream headers and GOP keyframes for instant playback on new user connections, and duplicates stream data to downstream clients or sub-gateways. **Designed specifically for medium to high-concurrency live streaming scenarios**, supporting both horizontal and vertical scaling.

### Start Commands

```bash
# Basic start (pull from origin)
php flvGateway.php 8080 http://origin-IP:8501

# 【Horizontal Scaling】Multiple instances at same tier
php flvGateway.php 8080 http://origin-IP:8501
php flvGateway.php 8081 http://origin-IP:8501
php flvGateway.php 8082 http://origin-IP:8501

# 【Vertical Scaling】Multi-tier cascading
php flvGateway.php 8080 http://origin-IP:8501        # Tier 1 gateway
php flvGateway.php 8081 http://127.0.0.1:8080     # Tier 2 gateway (pulls from tier 1)
php flvGateway.php 8082 http://127.0.0.1:8081     # Tier 3 gateway (pulls from tier 2)

# Run in background on Linux/macOS
php flvGateway.php 8080 http://origin-IP:8501 > /dev/null 2>&1 &
```

### Playback URL

```
http://gateway-IP:port/{app}/{stream}.flv
```

Example: `http://127.0.0.1:8080/live/stream.flv`

---

## Static File Gateway `fileGateway.php` (High-Concurrency VOD Resource Hosting)

### Gateway Overview

A lightweight HTTP static file server that centrally hosts all static resources. **For file-based protocols like HLS, fMP4, and MP4, this is the recommended playback method**. Supports both horizontal and vertical scaling, capable of handling large-scale VOD concurrency.

### Start Commands

```bash
# Basic start (hosts current directory, port 8100)
php fileGateway.php 0.0.0.0 8100

# 【Horizontal Scaling】Multiple instances
php fileGateway.php 0.0.0.0 8100
php fileGateway.php 0.0.0.0 8101
php fileGateway.php 0.0.0.0 8102

# Run in background on Linux/macOS
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

### Access URLs

```
http://gateway-IP:port/{relative-file-path}
```

Examples:

```
http://127.0.0.1:8100/index.html
http://127.0.0.1:8100/hls/live/stream/index.m3u8
http://127.0.0.1:8100/mp4/live/stream/output_merge/stream_full.mp4
```

---

## Push Access Tutorial (Detailed)

### 1. RTMP Push

#### URL Format

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

### 2. HTTP-FLV Push

#### URL Format

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

### 3. WebSocket-FLV Push

#### URL Format

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

## Command Line Player (ffplay)

```bash
# RTMP stream
ffplay rtmp://127.0.0.1:1935/live/stream

# HTTP-FLV stream
ffplay http://127.0.0.1:8501/live/stream.flv

# WebSocket-FLV stream
ffplay ws://127.0.0.1:8501/live/stream.flv

# FLV Gateway forwarded stream
ffplay http://127.0.0.1:8080/live/stream.flv

# HLS stream
ffplay http://127.0.0.1:8100/hls/live/stream/index.m3u8

# VOD files
ffplay http://127.0.0.1:8100/flv/live/stream/index.flv
ffplay http://127.0.0.1:8100/mp4/live/stream/output_merge/stream_full.mp4
```

> 💡 **Recommended Player**: Use [VLC](https://www.videolan.org/) for testing playback. It is a professional media player supporting various formats.

## PHP Client Pull Stream
This client pulls streams and saves them as static FLV files, supporting http-flv, ws-flv, and rtmp protocols.
```bash
php puller.php http://127.0.0.1:8501/live/stream.flv output.flv 0 --no-reconnect
php puller.php ws://127.0.0.1:8501/live/stream.flv output.flv 0 --no-reconnect
php puller.php rtmp://127.0.0.1:1935/live/stream output.flv 0 --no-reconnect
```

---

## Port Configuration

Edit `server.php` to modify ports:

| Port | Protocol | Purpose |
|------|----------|---------|
| 1935 | RTMP | RTMP push/pull |
| 8501 | HTTP / WebSocket | HTTP-FLV / WS-FLV push/pull |
| 80 | HTTP | Static file service + Web pages |

---

## Directory Structure

```
rtmp_server/
├── flv/                        # FLV raw recorded files
├── mp4/                        # MP4 / fMP4 transcoding outputs
├── hls/                        # HLS TS segments + m3u8 index
├── MediaServer/                # RTMP core protocol, push/pull session logic
├── record/                     # Directory where the pull client stores static files
├── Root/                       # Underlying async IO, Socket event driver
├── server.php                  # Origin server startup entry
├── fileGateway.php             # Static file gateway
├── flvGateway.php              # FLV live gateway
├── puller.php                  # Pull client (ws-flv/http-flv/rtmp)
├── pusher.php                  # FLV/MP4 push client
├── push.html                   # Web push (screen sharing)
├── flv_push.html               # Web push (FLV/MP4 push page)
├── *.html                      # Web playback pages
└── README.md
```

---

## Concurrency Performance Benchmarks

> The following tests were completed within a **Docker container with `ulimit -n 65535`**, with 20,000 concurrent clients, each continuously pulling streams for 5 seconds.

| Component | Successful Connections | Failed Connections | Success Rate |
|-----------|------------------------|--------------------|--------------|
| Origin Server | 17,330 | 2,670 | 86.7% |
| FLV Live Gateway | 19,923 | 77 | 99.6% |
| Static File Gateway | 20,000 | 0 | 100% |

> **Notes**:
> - The origin server supports 17,330 concurrent connections stably in a single process due to handling three-protocol push, multi-protocol remuxing, and other business logic.
> - The FLV Gateway focuses purely on stream forwarding with a 99.6% success rate.
> - The Static File Gateway is extremely lightweight with 20,000 concurrent connections all successful.
> - **epoll is automatically enabled on Linux**, breaking through the select 1024 limit.

---

## Related Toolkits

Protocol conversion standalone toolkit: [xiaosongshu/flv2mp4](https://github.com/2723659854/flv2mp4)

Supports FLV, MP4, HLS transcoding, FLV Gateway, Static File Gateway, and FLV/MP4 static file push clients (supporting ws-flv, http-flv, rtmp protocols), with built-in pull client (supporting ws-flv, http-flv, rtmp protocols).

---

## FAQ

### 1. Why can a single process support 20,000+ concurrent connections?

- **Linux**: After detecting the `event` extension, **epoll is automatically enabled**, no longer limited by select's 1024 limit.
- **Windows**: Falls back to select model, deploying multiple instances is recommended.
- Testing shows the Static File Gateway achieves 20,000 concurrent connections with zero failures.

### 2. When is it necessary to deploy gateways?

| Concurrency Scenario | Deployment Solution |
|---------------------|---------------------|
| Low (< 500) | Origin only is sufficient |
| Medium (500 – 5,000) | Origin + single-tier gateway (1–2 instances) |
| High (> 5,000) | Origin + multi-tier gateway cluster |

### 3. What is the difference between FLV Gateway and Static File Gateway?

| Gateway | Purpose | Resources Handled |
|---------|---------|-------------------|
| FLV Live Gateway | Live stream distribution | HTTP-FLV live streams |
| Static File Gateway | Static resource hosting | HLS / fMP4 / MP4 / FLV + Web pages |

### 4. What are the advantages of WebSocket-FLV push?

- Native browser support, no software installation required.
- Quick testing through built-in test pages.
- Supports mobile browser camera push (requires HTTPS).

---

## Open Source License

This project is open-sourced under [Apache License 2.0](http://www.apache.org/licenses/LICENSE-2.0).

You are free to use, modify, and distribute the code for any purpose, including commercial use, subject to the terms and conditions of the Apache License 2.0. For details, please refer to the [LICENSE](LICENSE) file.

### Third-Party Open Source Components

This project uses the following open source components, each governed by its own license:

| Component | License | Copyright |
| :--- | :--- | :--- |
| SabreAMF | [BSD 3-Clause](https://opensource.org/licenses/BSD-3-Clause) | Copyright (C) 2006-2009 Rooftop Solutions |
| media-server | [MIT](https://opensource.org/licenses/MIT) | Copyright (c) 2023 wy3 |

For complete copyright notices and disclaimers, please refer to the [NOTICE](NOTICE) file.

### Disclaimer

THIS SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

For detailed disclaimer terms, please refer to the [LICENSE](LICENSE) file.
---

## Contact

- 📬 Email: `2723659854@qq.com`
- 🐙 GitHub: [2723659854](https://github.com/2723659854)