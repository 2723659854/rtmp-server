# RTMP Server

<p align="center">
  <a href="./README.cn.md"><strong>🇨🇳 中文</strong></a> •
  <a href="./README.md"><strong>🇬🇧 English</strong></a>
</p>

> A lightweight RTMP live streaming service written in pure PHP, **with no third-party streaming media service dependencies**, ready to quickly build a private live streaming platform out of the box.

> Under Linux, the epoll event driver is automatically enabled, allowing a single process to easily handle **20,000+** concurrent connections; Windows falls back to select mode for compatibility.

> This project is an infrastructure layer — a production-grade RTMP streaming protocol stack and asynchronous network communication engine. Users need to build their own upper-layer applications.
---

## Table of Contents

- [Requirements](#requirements)
- [Quick Start](#quick-start)
- [Push URLs](#push-urls)
- [Play URLs](#play-urls)
- [Web Player Pages](#web-player-pages)
- [Directory Structure](#directory-structure)
- [System Architecture](#system-architecture)
- [Port Configuration](#port-configuration)
- [Recording Switch Configuration](#recording-switch-configuration)
- [Push Authentication](#push-authentication)
- [FLV Streaming Gateway](#flv-streaming-gateway)
- [Static File Gateway](#static-file-gateway)
- [Push Access Tutorial](#push-access-tutorial)
- [FAQ](#faq)
- [License](#license)
- [Contact](#contact)

---

## Requirements

| Dependency | Description |
|------------|-------------|
| PHP | >= 8.1 (CLI mode only) |
| `sockets` extension | **Required**, provides low-level socket communication |
| `event` extension | **Highly recommended**, greatly improves concurrency performance under Linux, automatically enables epoll mode |

> 💡 This project provides a Docker quick-start environment. Run `docker-compose up -d` to start with one command.

---

## Quick Start

### Installation

```bash
composer create-project xiaosongshu/rtmp_server
cd rtmp_server
```

### Start Origin Server

```bash
php server.php
```

Sample output:

```
[INFO] RTMP Server started on 0.0.0.0:1935
[INFO] HTTP-FLV/WS-FLV Server started on 0.0.0.0:8501
[INFO] HTTP Static Server started on 0.0.0.0:80
```

### Push Stream

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

#### Method 4: PHP Push

```bash
php pusher.php test.mp4 http://127.0.0.1:8501/live/stream
```

### Watch Live Stream

Open `http://127.0.0.1/index.html` to watch.

---

## Push URLs

| Protocol | URL Format | Example |
|----------|------------|---------|
| RTMP | `rtmp://host:1935/{app}/{stream}` | `rtmp://127.0.0.1:1935/live/stream` |
| HTTP-FLV | `http://host:8501/{app}/{stream}` | `http://127.0.0.1:8501/live/stream` |
| WebSocket-FLV | `ws://host:8501/{app}/{stream}` | `ws://127.0.0.1:8501/live/stream` |

> **Note**: `{app}` is the application name, `{stream}` is the channel name. Only English letters and numbers are supported.

---

## Play URLs

### Live Streaming

| Protocol | Play URL | Description |
|----------|----------|-------------|
| RTMP | `rtmp://127.0.0.1:1935/live/stream` | Native player / ffplay |
| HTTP-FLV | `http://127.0.0.1:8501/live/stream.flv` | Low-latency browser playback |
| WebSocket-FLV | `ws://127.0.0.1:8501/live/stream.flv` | Native WebSocket support in browsers |
| HLS | `http://127.0.0.1:80/hls/live/stream/index.m3u8` | Preferred for mobile devices |

### VOD Playback

Recorded files are located in the project root directory:

| File Type | File Path |
|-----------|-----------|
| Merged MP4 | `mp4/live/stream/output_merge/stream_full.mp4` |
| FLV Recording | `flv/live/stream/index.flv` |
| HLS Segments | `hls/live/stream/` |

Example access: `http://127.0.0.1:80/mp4/live/stream/output_merge/stream_full.mp4`

---

## Web Player Pages

### Player Pages

| Page | Purpose | Access URL |
|------|---------|------------|
| `index.html` | FLV low-latency live streaming | `http://127.0.0.1/index.html` |
| `play.html` | HLS mobile live streaming | `http://127.0.0.1/play.html` |
| `mp4.html` | MP4 VOD | `http://127.0.0.1/mp4.html` |
| `video.html` | FLV VOD | `http://127.0.0.1/video.html` |
| `play_merge.html` | fMP4 segment VOD | `http://127.0.0.1/play_merge.html` |

### Push Pages

| Page | Purpose | Access URL |
|------|---------|------------|
| `push.html` | Screen sharing push | `http://127.0.0.1/push.html` |
| `flv_push.html` | Local FLV/MP4 push | `http://127.0.0.1/flv_push.html` |
| `push_merge.html` | Multi-stream merge push | `http://127.0.0.1/push_merge.html` |
| `push_transcode.html` | Transcode live stream to other bitrates and push, adapting to different client network conditions | `http://127.0.0.1/push_transcode.html` |

### PHP Clients

| Script | Purpose | Example Command |
|--------|---------|-----------------|
| `pusher.php` | Push client | `php pusher.php video.mp4 http://127.0.0.1:8501/live/stream` |
| `puller.php` | Pull client | `php puller.php http://127.0.0.1:8501/live/stream.flv output.flv` |

---

## Directory Structure

```
rtmp_server/
├── flv/                        # FLV raw recording files
├── mp4/                        # MP4 / fMP4 transcoding output
├── hls/                        # HLS TS segments + m3u8 index
├── MediaServer/                # RTMP core protocol, push/pull session logic
├── record/                     # Pull client static file storage directory
├── Root/                       # Low-level async IO, Socket event driver
├── server.php                  # Origin server entry point
├── fileGateway.php             # Static file gateway
├── flvGateway.php              # FLV streaming gateway (supports ws-flv/http-flv)
├── puller.php                  # Pull client
├── pusher.php                  # Push client
├── push.html                   # Web push (screen sharing)
├── push_merge.html             # Web multi-stream merge push
├── push_transcode.html         # Web live transcoding push (multiple bitrates, freely selectable)
├── flv_push.html               # Web push (file)
├── auth_config.php             # Push authentication configuration
└── *.html                      # Web player pages
```

---

## System Architecture

```
                                                    【Push Client】OBS / FFmpeg
                                                         │
                                       RTMP Push(1935)  /  HTTP-FLV / WS-FLV Push(8501)
                                                         │
                                                         ▼
╔══════════════════════════════════════════════════════════════════════════════════════╗
║                              RTMP Origin Server (Core)                               ║
║                                                                                      ║
║  📥 Push Access    RTMP / HTTP-FLV / WebSocket-FLV three-protocol push, link auth    ║
║  🔄 Protocol Trans  RTMP / HTTP-FLV / WS-FLV → HTTP-FLV / WebSocket-FLV / HLS / fMP4 / MP4 ║
║  💾 Real-time Rec   ┌──────────┬──────────┬──────────┐                              ║
║                     │ FLV Rec  │ fMP4 Seg │ HLS Seg  │  Three independent parallel tasks ║
║                     │(raw stream)│(segments)│(segments)│                              ║
║                     └──────────┴──────────┴──────────┘                              ║
║  📤 Live Output     HTTP-FLV(8501) / WebSocket-FLV / HLS live stream / fMP4 live stream ║
║  📦 VOD Output      fMP4 segments generated in real-time → automatically merged to complete MP4 after stream ends ║
║  📁 Static Service  Origin built-in HTTP service (port 80), directly serves static files ║
╚══════════════════════════════════════════════════════════════════════════════════════╝
│
┌───────────────────┼───────────────────┐
│                   │                   │
▼                   ▼                   ▼
HTTP-FLV(8501)     HLS(TS/m3u8)       fMP4(segments)
Live stream output  Static files       Static files
│                   │                   │
│                   │                   │
▼                   ▼                   ▼
┌─────────────┐    ┌──────────────────────────────────────────┐
│ FLV Gateway  │    │        Static File Gateway Cluster      │
│   Cluster   │    │    🎯 Hosts: HLS / fMP4 / MP4 / FLV / Web Pages │
│ ┌─────────┐ │    │                                          │
│ │  Level 1 │ │    │ ┌───────┐ ┌───────┐ ┌───────┐           │
│ │ Gateway  │ │    │ │Gateway│ │Gateway│ │Gateway│           │
│ │ (8080)   │ │    │ │ (8100)│ │ (8101)│ │ (8102)│           │
│ └───┬─────┘ │    │ └──┬────┘ └──┬────┘ └──┬────┘           │
│     │       │    │    │        │        │                 │
│ ┌───┴───┐   │    │    ▼        ▼        ▼                 │
│ ▼   ▼   ▼   │    │ ┌──────────────────────────────────┐   │
│ ┌─┐ ┌─┐ ┌─┐ │    │ │         Client                   │   │
│ │S│ │S│ │S│ │    │ │ HLS Player / MSE / VOD / ffplay │   │
│ │u│ │u│ │u│ │    │ └──────────────────────────────────┘   │
│ │b│ │b│ │b│ │    │                                          │
│ └┬─┘ └┬─┘ └┬─┘ │    └──────────────────────────────────────────┘
│  │    │    │   │
│  ▼    ▼    ▼   │
│ ┌────────────┐ │
│ │  Client    │ │
│ │ FLV/ffplay │ │
│ └────────────┘ │
└─────────────────┘
```

### Architecture Description

- **Origin Server**: The sole stream production node, supporting **RTMP, HTTP-FLV, and WebSocket-FLV three-protocol push**, responsible for push/pull access and multi-protocol remuxing. **FLV recording, fMP4 segmentation, and HLS segmentation are three completely independent parallel tasks**, non-blocking to each other.

- **Origin Static Capability**: The origin has a built-in HTTP service (default port 80) that directly serves static files. **No additional gateway deployment is required for low-concurrency scenarios** — ready to use out of the box.

- **Real-time Recording Mechanism**:
  - **FLV Recording**: Saves raw stream in real-time, producing a complete FLV file after the stream ends.
  - **fMP4 Segmentation**: Generates audio/video fMP4 segments in real-time, automatically merging into a complete MP4 after the stream ends.
  - **HLS Segmentation**: Generates TS segments + m3u8 index in real-time, compatible with mobile playback.
  - **Independent Switches**: Users can configure whether to enable each recording task independently in `server.php`.

- **FLV Live Gateway Cluster**: A pure traffic forwarding service that pulls HTTP-FLV/WS-FLV streams from upstream, caches stream headers and GOP keyframes for instant playback on new client connections, and replicates stream data to downstream clients or sub-gateways.
  - Supports multi-level cascading: Level 1 Gateway → Level 2 Gateway → Level 3 Gateway → ... → Client (Level 1 recommended, maximum 2 levels; deeper levels increase latency and stutter).
  - Supports horizontal scaling: Deploy multiple gateway instances at the same level with load balancing (horizontal scaling recommended).
  - Linux epoll high performance: A single process can handle 20,000+ concurrent connections; Windows falls back to select model (these are lab test figures; actual performance depends on server configuration).
  - **Author's Recommendation**: For production high-concurrency scenarios, use gateways for all pull requests to reduce the load on the main origin server.

- **Static File Gateway Cluster**: A lightweight HTTP static file server that centrally hosts all static resources.
  - **Supported Protocols**: HLS (.m3u8/.ts), fMP4 (.m4s/.mp4), MP4 VOD files, FLV recording files, Web player pages.
  - Supports both horizontal and vertical scaling to handle large-scale VOD concurrency.
  - **Best Practice**: Point HLS/fMP4/MP4 playback paths to this gateway cluster for read-write separation of static resources.
  - **Author's Recommendation**: For production high-concurrency scenarios, use the static file gateway for all static file access to reduce the load on the main origin server.

### Deployment Recommendations

| Concurrency Scenario | Deployment Solution |
|----------------------|---------------------|
| Low (< 1000) | Directly use origin's built-in HTTP service, no additional gateways needed |
| Medium (1000 – 5,000) | Origin + single-layer gateway cluster |
| High (> 5,000) | Origin + multi-level FLV gateway cluster + multi-level static file gateway cluster |

---

## Port Configuration

Edit `server.php` to modify ports:

| Port | Protocol | Purpose |
|------|----------|---------|
| 1935 | RTMP | RTMP push/pull |
| 8501 | HTTP / WebSocket | HTTP-FLV / WS-FLV push/pull |
| 80 | HTTP | Static file service + Web pages |

---

## Recording Switch Configuration

Edit `server.php` to independently control the three recording tasks:

```php
define('FLV_TO_RECORD', true);   // Whether to record FLV raw files in real-time
define('FLV_TO_MP4', true);      // Whether to generate fMP4 segments in real-time and merge to MP4
define('FLV_TO_HLS', true);      // Whether to generate HLS (TS) segments in real-time
```

> The three tasks run independently in parallel without blocking each other.

---

## Push Authentication

### Overview

To prevent unauthorized pushes from overriding your live stream, the server uses **Stream Key** authentication. Only push requests with a valid Stream Key are allowed.

### Configuration

Edit `auth_config.php` to configure authentication:

```php
<?php
return [
    'enabled' => true,
    
    'publish' => [
        'require_auth' => true,
        'stream_keys' => [
            'live_123456',
            'stream_key_abc',
        ],
    ],
    
    'global' => [
        'allowed_apps' => ['live'],
        'deny_apps' => [],
    ],
];
```

### Push with Authentication

Use the `key` parameter in the push URL:

**RTMP Push:**

```bash
ffmpeg -re -stream_loop -1 -i video.mp4 -c:v libx264 -c:a aac -f flv \
  rtmp://127.0.0.1:1935/live/stream?key=live_123456
```

**OBS:**

- Server URL: `rtmp://127.0.0.1:1935/live/`
- Stream Key: `stream?key=live_123456`

**HTTP-FLV Push:**

```bash
ffmpeg -re -stream_loop -1 -i video.mp4 -c:v libx264 -c:a aac -f flv  http://127.0.0.1:8501/live/stream?key=live_123456
```

**WebSocket-FLV Push:**

```bash
php pusher.php test.flv "ws://127.0.0.1:8501/live/stream?key=live_123456"
```

> **Note**: Pull/playback does not require authentication.

### Security Best Practices

1. **Change Default Keys**: Always replace the default `stream_keys` with strong random strings
2. **Use HTTPS**: Use HTTPS for transmission in public networks to prevent credential interception
3. **Rotate Keys Regularly**: Periodically update `stream_keys`

---

## FLV Streaming Gateway

### Overview

A lightweight traffic distribution component that supports unlimited levels of cascading deployment. It pulls HTTP-FLV/WS-FLV streams from upstream origins/gateways, caches stream headers and GOP keyframes for instant playback on new connections, and replicates stream data to clients or sub-gateways. **Designed specifically for medium-to-high concurrency live streaming scenarios**, supports both horizontal and vertical scaling.

### Startup Commands

```bash
# Basic startup
php flvGateway.php 8080 http://origin-ip:8501
php flvGateway.php 8080 ws://origin-ip:8501

# Horizontal scaling: multiple instances at the same level
php flvGateway.php 8080 http://origin-ip:8501
php flvGateway.php 8081 http://origin-ip:8501
php flvGateway.php 8082 ws://origin-ip:8501

# Vertical scaling: multi-level cascading
php flvGateway.php 8080 http://origin-ip:8501        # Level 1 gateway
php flvGateway.php 8081 http://127.0.0.1:8080        # Level 2 gateway
php flvGateway.php 8082 ws://127.0.0.1:8081          # Level 3 gateway

# Linux/macOS background run
php flvGateway.php 8080 http://origin-ip:8501 > /dev/null 2>&1 &
```
In theory, gateways can be nested infinitely, but the author does not recommend this, as deeper levels increase latency and stutter. A single level is sufficient in theory.

### Play URLs

```
http://gateway-ip:port/{app}/{stream}.flv
ws://gateway-ip:port/{app}/{stream}.flv
```

Example: `http://127.0.0.1:8080/live/stream.flv` and `ws://127.0.0.1:8080/live/stream.flv`

---

## Static File Gateway

### Overview

A lightweight HTTP static file server that centrally hosts all static resources. **For file-based protocols like HLS, fMP4, and MP4, this is the recommended playback method.** Supports both horizontal and vertical scaling to handle large-scale VOD concurrency.

### Startup Commands

```bash
# Basic startup
php fileGateway.php 0.0.0.0 8100

# Horizontal scaling: multiple instances
php fileGateway.php 0.0.0.0 8100
php fileGateway.php 0.0.0.0 8101
php fileGateway.php 0.0.0.0 8102

# Linux/macOS background run
php fileGateway.php 0.0.0.0 8100 > /dev/null 2>&1 &
```

### Nginx Reverse Proxy Configuration

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
http://gateway-ip:port/{relative-file-path}
```

Examples:

```
http://127.0.0.1:8100/index.html
http://127.0.0.1:8100/hls/live/stream/index.m3u8
http://127.0.0.1:8100/mp4/live/stream/output_merge/stream_full.mp4
```

---

## Push Access Tutorial

### RTMP Push

**URL Format**: `rtmp://127.0.0.1:1935/{app}/{stream}`

**OBS Studio**:
- Server: `rtmp://127.0.0.1:1935/live`
- Stream Key: `stream`

**FFmpeg**:

```bash
ffmpeg -re -stream_loop -1 -i video.mp4 -c:v libx264 -c:a aac -f flv rtmp://127.0.0.1:1935/live/stream
```

**PHP Client**:

```bash
php pusher.php test.flv rtmp://127.0.0.1:1935/live/stream
php pusher.php video.mp4 rtmp://127.0.0.1:1935/live/stream
```

### HTTP-FLV Push

**URL Format**: `http://127.0.0.1:8501/{app}/{stream}`

**PHP Client**:

```bash
php pusher.php test.flv http://127.0.0.1:8501/live/stream
php pusher.php video.mp4 http://127.0.0.1:8501/live/stream
php pusher.php test.flv http://127.0.0.1:8501/live/stream 2.0
php pusher.php test.flv http://127.0.0.1:8501/live/stream 1.0 --no-reconnect
```

**FFmpeg**:

```bash
ffmpeg -re -stream_loop -1 -i video.mp4 -c:v libx264 -c:a aac -f flv http://127.0.0.1:8501/live/stream
```

### WebSocket-FLV Push

**URL Format**: `ws://127.0.0.1:8501/{app}/{stream}`

**Browser Push**: Open `http://127.0.0.1/push.html` or `http://127.0.0.1/flv_push.html`

**PHP Client**:

```bash
php pusher.php test.flv ws://127.0.0.1:8501/live/stream
```
### Browser Playback
This project provides web browser live playback without downloading third-party player software. You can refer to the page:
`http://127.0.0.1/index.html`

### Browser Push
This project provides web browser push without professional streaming software or third-party push tools. You can refer to the page:
`http://127.0.0.1/push.html`
ps: Browsers use ws-flv for both push and pull, with latency below 50ms.
### Stream Merging
This project uses web frontend to merge live streams, reducing reliance on dedicated hardware chips and software. You can refer to the page:
`http://127.0.0.1/push_merge.html`

### Live Transcoding

This project uses web frontend to achieve low-cost live transcoding, offering various combinations and bitrates, reducing reliance on dedicated hardware chips and software. You can refer to:
`http://127.0.0.1/push_transcode.html`

---

### PHP Pull Client
This project provides a PHP client for pulling streams. Reference commands:
```bash
php puller.php http://127.0.0.1:8501/live/stream.flv output.flv
php puller.php ws://127.0.0.1:8501/live/stream.flv output.flv
```
ps: The PHP push client `pusher.php` and pull client `puller.php` work together to help automate backend engineering. This project can operate independently without third-party software for a complete live streaming solution.


## FAQ

### Q1: Startup fails on Windows with a missing `event` extension error?

Windows does not support the `event` extension. The server will automatically fall back to the `sockets` extension with the select model. Ensure the `sockets` extension is installed and it will work properly.

### Q2: How do I check the server's running status?

After startup, the server outputs the following logs:

```
[INFO] RTMP Server started on 0.0.0.0:1935
[INFO] HTTP-FLV/WS-FLV Server started on 0.0.0.0:8501
[INFO] HTTP Static Server started on 0.0.0.0:80
```

### Q3: Push succeeded but playback is stuttering?

1. Check network latency
2. Reduce push bitrate or frame rate
3. Use the FLV gateway cluster to cache GOP

### Q4: How do I stop the server?

Close the terminal window running `php server.php`, or use `Ctrl+C`.

### Q5: Which push tools are supported?

All standard RTMP push tools are supported: OBS, FFmpeg, xSplit, mobile SDKs, etc.

---

## License

This project is open-sourced under the **Apache License**.

The code of this project is provided "AS IS", without warranty of any kind, express or implied, including but not limited to the warranties of merchantability, fitness for a particular purpose, and non-infringement. In no event shall the authors be liable for any claim, damages, or other liability, whether in an action of contract, tort, or otherwise, arising from, out of, or in connection with the software or the use or other dealings in the software.

For detailed disclaimers, please refer to the [LICENSE](LICENSE) file.

---

## Tool Package
Most of the features have been separated into a standalone tool package `xiaosongshu/flv2mp4` (https://github.com/2723659854/flv2mp4), which supports flv, mp4, fmp4, and hls format conversion, provides flv and file gateways, and offers PHP push/pull clients (supporting rtmp, http-flv, and ws-flv protocols).

## Contact

- 📬 Email: `2723659854@qq.com`
- 🐙 GitHub: [2723659854](https://github.com/2723659854)