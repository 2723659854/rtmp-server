# RTMP Server

<p align="center">
  <a href="./README.cn.md"><strong>🇨🇳 中文</strong></a> •
  <a href="./README.md"><strong>🇬🇧 English</strong></a>
</p>

> A lightweight RTMP live streaming service written in pure PHP, **with no third-party streaming media dependencies** — ready to use out of the box for quickly building a private live streaming platform.

> On Linux environments, the epoll event driver is automatically enabled, allowing a single process to easily handle **20,000+** concurrent connections; Windows environments fall back to select mode for compatibility.

---

## Table of Contents

- [Requirements](#requirements)
- [Quick Start](#quick-start)
- [Push Stream URLs](#push-stream-urls)
- [Playback URLs](#playback-urls)
- [Web Player Pages](#web-player-pages)
- [Directory Structure](#directory-structure)
- [System Architecture](#system-architecture)
- [Port Configuration](#port-configuration)
- [Recording Switch Configuration](#recording-switch-configuration)
- [Push Stream Authentication](#push-stream-authentication)
- [FLV Streaming Gateway](#flv-streaming-gateway)
- [Static File Gateway](#static-file-gateway)
- [Push Stream Integration Tutorial](#push-stream-integration-tutorial)
- [FAQ](#faq)
- [License](#license)
- [Contact](#contact)

---

## Requirements

| Dependency | Description |
|------------|-------------|
| PHP | >= 8.1 (CLI command-line mode only) |
| `sockets` extension | **Required**, provides underlying Socket communication capabilities |
| `event` extension | **Highly recommended**, greatly improves concurrency performance on Linux with automatic epoll mode |

> 💡 This project provides a Docker quick-start environment — simply run `docker-compose up -d` to get started with one command.

---

## Quick Start

### Installation

```bash
composer create-project xiaosongshu/rtmp_server
cd rtmp_server
```

### Start the Origin Server

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

#### Method 1: Browser Push Stream (No software installation required)

- Open `http://127.0.0.1/push.html` and click "Start Push".
- Or open `http://127.0.0.1/flv_push.html`, select an MP4/FLV static file, and click "Start Push".

#### Method 2: FFmpeg Push Stream

```bash
ffmpeg -re -stream_loop -1 -i video.mp4 -c:v libx264 -c:a aac -f flv rtmp://127.0.0.1:1935/live/stream
```

#### Method 3: OBS Push Stream

- Server URL: `rtmp://127.0.0.1:1935/live/`
- Stream Key: `stream`

#### Method 4: PHP Push Stream

```bash
php pusher.php test.mp4 http://127.0.0.1:8501/live/stream
```

### Watch Live Stream

Open `http://127.0.0.1/index.html` to watch.

---

## Push Stream URLs

| Protocol | URL Format | Example |
|----------|------------|---------|
| RTMP | `rtmp://host:1935/{app}/{stream}` | `rtmp://127.0.0.1:1935/live/stream` |
| HTTP-FLV | `http://host:8501/{app}/{stream}` | `http://127.0.0.1:8501/live/stream` |
| WebSocket-FLV | `ws://host:8501/{app}/{stream}` | `ws://127.0.0.1:8501/live/stream` |

> **Note**: `{app}` is the application name, `{stream}` is the channel name. Only English letters and numbers are supported.

---

## Playback URLs

### Live Streaming

| Protocol | Playback URL | Description |
|----------|--------------|-------------|
| RTMP | `rtmp://127.0.0.1:1935/live/stream` | Native player / ffplay |
| HTTP-FLV | `http://127.0.0.1:8501/live/stream.flv` | Low-latency browser playback |
| WebSocket-FLV | `ws://127.0.0.1:8501/live/stream.flv` | Native browser WebSocket support |
| HLS | `http://127.0.0.1:80/hls/live/stream/index.m3u8` | Preferred for mobile devices |

### VOD Playback

Recorded files are located in the project root directory:

| File Type | File Path |
|-----------|-----------|
| Merged MP4 | `mp4/live/stream/output_merge/stream_full.mp4` |
| FLV Recording | `flv/live/stream/index.flv` |
| HLS Segments | `hls/live/stream/` |

Access example: `http://127.0.0.1:80/mp4/live/stream/output_merge/stream_full.mp4`

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

### Push Stream Pages

| Page | Purpose | Access URL |
|------|---------|------------|
| `push.html` | Screen sharing push stream | `http://127.0.0.1/push.html` |
| `flv_push.html` | Local FLV/MP4 push stream | `http://127.0.0.1/flv_push.html` |
| `push_merge.html` | Multi-channel merged push stream | `http://127.0.0.1/push_merge.html` |

### PHP Clients

| Script | Purpose | Command Example |
|--------|---------|-----------------|
| `pusher.php` | Push stream client | `php pusher.php video.mp4 http://127.0.0.1:8501/live/stream` |
| `puller.php` | Pull stream client | `php puller.php http://127.0.0.1:8501/live/stream.flv output.flv` |

---

## Directory Structure

```
rtmp_server/
├── flv/                        # FLV raw recording files
├── mp4/                        # MP4 / fMP4 transcoding outputs
├── hls/                        # HLS TS segments + m3u8 index
├── MediaServer/                # RTMP core protocol, push/pull session logic
├── record/                     # Pull stream client static file storage directory
├── Root/                       # Underlying async IO, Socket event driver
├── server.php                  # Origin server entry point
├── fileGateway.php             # Static file gateway
├── flvGateway.php              # FLV live streaming gateway
├── puller.php                  # Pull stream client
├── pusher.php                  # Push stream client
├── push.html                   # Web push stream (screen sharing)
├── push_merge.html             # Web multi-channel merged push stream
├── flv_push.html               # Web push stream (file)
├── auth_config.php             # Push stream authentication configuration
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
║                              RTMP Origin Server (Core)                                 ║
║                                                                                      ║
║  📥 Push Stream In    RTMP / HTTP-FLV / WebSocket-FLV three-protocol ingestion with auth ║
║  🔄 Protocol Trans    RTMP / HTTP-FLV / WS-FLV → HTTP-FLV / WebSocket-FLV / HLS / fMP4 / MP4 ║
║  💾 Real-time Record  ┌──────────┬──────────┬──────────┐                            ║
║                       │ FLV Rec  │ fMP4 Seg │ HLS Seg  │  Three independent parallel tasks ║
║                       │ (raw)    │ (segments)│ (segments)│                               ║
║                       └──────────┴──────────┴──────────┘                            ║
║  📤 Live Output       HTTP-FLV(8501) / WebSocket-FLV / HLS live / fMP4 live          ║
║  📦 VOD Output        fMP4 segments generated in real-time → auto-merged to MP4      ║
║  📁 Static Service    Built-in HTTP service (port 80) serving static files           ║
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
│ FLV Gateway │    │       Static File Gateway Cluster        │
│   Cluster   │    │    🎯 Hosts: HLS / fMP4 / MP4 / FLV / Web │
│             │    │                                          │
│ ┌─────────┐ │    │ ┌───────┐ ┌───────┐ ┌───────┐           │
│ │ Tier 1   │ │    │ │GW 1   │ │GW 2   │ │GW 3   │           │
│ │ (8080)   │ │    │ │(8100) │ │(8101) │ │(8102) │           │
│ └───┬─────┘ │    │ └──┬────┘ └──┬────┘ └──┬────┘           │
│     │       │    │    │        │        │                 │
│ ┌───┴───┐   │    │    ▼        ▼        ▼                 │
│ ▼   ▼   ▼   │    │ ┌──────────────────────────────────┐   │
│ ┌─┐ ┌─┐ ┌─┐ │    │ │         Client (Client)           │   │
│ │S│ │S│ │S│ │    │ │ HLS Player / MSE / VOD / ffplay  │   │
│ │G│ │G│ │G│ │    │ └──────────────────────────────────┘   │
│ └┬─┘ └┬─┘ └┬─┘ │    │                                          │
│  │    │    │   │    └──────────────────────────────────────────┘
│  ▼    ▼    ▼   │
│ ┌────────────┐ │
│ │   Client   │ │
│ │ FLV/ffplay │ │
│ └────────────┘ │
└─────────────────┘
```

### Architecture Description

- **Origin Server**: The sole stream production node, supporting **RTMP, HTTP-FLV, and WebSocket-FLV three-protocol push streams**, responsible for push/pull stream access and multi-protocol remuxing. **FLV recording, fMP4 segmentation, and HLS segmentation are three completely independent parallel tasks** that do not block each other.

- **Origin Static Capability**: The origin server has a built-in HTTP service (default port 80) that can directly serve static files. **No additional gateway deployment is required for low-concurrency scenarios** — it works out of the box.

- **Real-time Recording Mechanism**:
  - **FLV Recording**: Saves the raw stream in real-time, producing a complete FLV file after the live stream ends.
  - **fMP4 Segmentation**: Generates audio/video fMP4 segments in real-time, automatically merged into a complete MP4 after the live stream ends.
  - **HLS Segmentation**: Generates TS segments + m3u8 index in real-time, compatible with mobile playback.
  - **Independent Switches**: Users can configure whether to enable each recording task separately in `server.php`.

- **FLV Live Streaming Gateway Cluster**: A pure traffic forwarding service that pulls HTTP-FLV streams upstream, caches GOP key frames for instant playback on new user connections, and replicates stream data to downstream clients or child gateways. **Specifically designed for medium-to-high concurrency live streaming scenarios**, supporting both horizontal and vertical scaling.

  - Supports unlimited tiered cascading: Tier 1 Gateway → Tier 2 Gateway → Tier 3 Gateway → ... → Client.
  - Supports horizontal scaling: Deploy multiple gateway instances at the same tier with load balancing to distribute traffic.
  - Linux epoll high performance: Single process can handle 20,000+ concurrent connections; Windows compatible with select model.

- **Static File Gateway Cluster**: Lightweight HTTP static file server that centrally hosts all static resources.
  - **Supported Protocols**: HLS (.m3u8/.ts), fMP4 (.m4s/.mp4), MP4 VOD files, FLV recording files, Web player pages.
  - Supports both horizontal and vertical scaling to handle large-scale VOD concurrency.
  - **Best Practice**: Point HLS/fMP4/MP4 playback paths to this gateway cluster for read-write separation of static resources.

### Deployment Recommendations

| Concurrency Scenario | Deployment Solution |
|----------------------|---------------------|
| Low (< 500) | Direct use of origin built-in HTTP service, no additional gateway needed |
| Medium (500 – 5,000) | Origin + single-tier gateway cluster |
| High (> 5,000) | Origin + FLV gateway multi-tier cluster + Static file gateway multi-tier cluster |

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
define('FLV_TO_MP4', true);      // Whether to generate fMP4 segments and merge to MP4 in real-time
define('FLV_TO_HLS', true);      // Whether to generate HLS (TS) segments in real-time
```

> The three tasks run independently and in parallel without blocking each other.

---

## Push Stream Authentication

### Overview

To prevent unauthorized push streams from overwriting your live stream, the server uses **Stream Key** authentication. Only push requests with a valid Stream Key are allowed.

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

### Push Stream with Authentication

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
ffmpeg -re -stream_loop -1 -i video.mp4 -c:v libx264 -c:a aac -f flv \
  http://127.0.0.1:8501/live/stream?key=live_123456
```

**WebSocket-FLV Push:**

```bash
php pusher.php test.flv "ws://127.0.0.1:8501/live/stream?key=live_123456"
```

> **Note**: Pull/playback does not require authentication.

### Security Best Practices

1. **Change default keys**: Be sure to replace the default `stream_keys` with strong random strings
2. **Use HTTPS**: Use HTTPS for transmission in public environments to prevent credential interception
3. **Rotate keys regularly**: Update `stream_keys` periodically

---

## FLV Streaming Gateway

### Introduction

A lightweight traffic distribution component that supports unlimited tiered cascading deployment. It pulls HTTP-FLV from the upstream origin/parent gateway, caches stream headers and GOP key frames for instant playback on new user connections, and replicates stream data to downstream clients or child gateways. **Specifically designed for medium-to-high concurrency live streaming scenarios**, supporting both horizontal and vertical scaling.

### Startup Commands

```bash
# Basic startup
php flvGateway.php 8080 http://origin-ip:8501

# Horizontal scaling: multiple instances at the same tier
php flvGateway.php 8080 http://origin-ip:8501
php flvGateway.php 8081 http://origin-ip:8501
php flvGateway.php 8082 http://origin-ip:8501

# Vertical scaling: multi-tier cascading
php flvGateway.php 8080 http://origin-ip:8501        # Tier 1 gateway
php flvGateway.php 8081 http://127.0.0.1:8080        # Tier 2 gateway
php flvGateway.php 8082 http://127.0.0.1:8081        # Tier 3 gateway

# Linux/macOS background execution
php flvGateway.php 8080 http://origin-ip:8501 > /dev/null 2>&1 &
```

### Playback URL

```
http://gateway-ip:port/{app}/{stream}.flv
```

Example: `http://127.0.0.1:8080/live/stream.flv`

---

## Static File Gateway

### Introduction

A lightweight HTTP static file server that centrally hosts all static resources. **For file-based protocols like HLS, fMP4, and MP4, this is the recommended playback method**. Supports both horizontal and vertical scaling to handle large-scale VOD concurrency.

### Startup Commands

```bash
# Basic startup
php fileGateway.php 0.0.0.0 8100

# Horizontal scaling: multiple instances
php fileGateway.php 0.0.0.0 8100
php fileGateway.php 0.0.0.0 8101
php fileGateway.php 0.0.0.0 8102

# Linux/macOS background execution
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

### Access URL

```
http://gateway-ip:port/{file-relative-path}
```

Examples:

```
http://127.0.0.1:8100/index.html
http://127.0.0.1:8100/hls/live/stream/index.m3u8
http://127.0.0.1:8100/mp4/live/stream/output_merge/stream_full.mp4
```

---

## Push Stream Integration Tutorial

### RTMP Push Stream

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

### HTTP-FLV Push Stream

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

### WebSocket-FLV Push Stream

**URL Format**: `ws://127.0.0.1:8501/{app}/{stream}`

**Browser Push**: Open `http://127.0.0.1/push.html` or `http://127.0.0.1/flv_push.html`

**PHP Client**:

```bash
php pusher.php test.flv ws://127.0.0.1:8501/live/stream
```

---

## FAQ

### Q1: Startup fails on Windows with error about missing `event` extension?

The `event` extension is not supported on Windows. The server will automatically fall back to the `sockets` extension with the select model. Make sure the `sockets` extension is installed and it will work properly.

### Q2: How do I check the server running status?

After startup, the server will output the following logs:

```
[INFO] RTMP Server started on 0.0.0.0:1935
[INFO] HTTP-FLV/WS-FLV Server started on 0.0.0.0:8501
[INFO] HTTP Static Server started on 0.0.0.0:80
```

### Q3: Push stream succeeds but playback is choppy?

1. Check network latency
2. Reduce push stream bitrate or frame rate
3. Use the FLV gateway cluster to cache GOP

### Q4: How do I stop the server?

Simply close the terminal window running `php server.php`, or use `Ctrl+C`.

### Q5: Which push stream tools are supported?

All standard RTMP push tools are supported: OBS, FFmpeg, xSplit, mobile SDKs, etc.

---

## License

This project is licensed under the **MIT License**.

The code of this project is provided "AS IS", without warranty of any kind, express or implied, including but not limited to the warranties of merchantability, fitness for a particular purpose, and non-infringement. In no event shall the authors be liable for any claim, damages, or other liability arising from, out of, or in connection with the software or the use or other dealings in the software.

For detailed disclaimer, please refer to the [LICENSE](LICENSE) file.

---

## Contact

- 📬 Email: `2723659854@qq.com`
- 🐙 GitHub: [2723659854](https://github.com/2723659854)