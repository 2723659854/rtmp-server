# RTMP Server

<p align="center">
  <a href="./README.cn.md"><strong>🇨🇳 中文</strong></a> •
  <a href="./README.md"><strong>🇬🇧 English</strong></a>
</p>

> A lightweight RTMP live streaming server written in pure PHP, **with no third-party streaming media service dependencies**. Deploy a private live streaming platform quickly and out of the box.

> On Linux, the epoll event driver is automatically enabled, allowing a single process to easily handle **20,000+** concurrent connections. On Windows, it falls back to the select model, ensuring compatibility.

---

## Table of Contents

- [Environment Dependencies](#environment-dependencies)
- [Quick Start](#quick-start)
- [Publishing Addresses](#publishing-addresses)
- [Playback Addresses](#playback-addresses)
- [Web Playback Pages](#web-playback-pages)
- [Directory Structure](#directory-structure)
- [System Architecture](#system-architecture)
- [Port Configuration](#port-configuration)
- [Recording Switch Configuration](#recording-switch-configuration)
- [Publishing Authentication](#publishing-authentication)
- [FLV Streaming Gateway](#flv-streaming-gateway)
- [Static File Gateway](#static-file-gateway)
- [Publishing Access Guide](#publishing-access-guide)
- [FAQ](#faq)
- [Open Source License](#open-source-license)
- [Contact](#contact)

---

## Environment Dependencies

| Dependency | Description |
|--------|------|
| PHP | >= 8.1 (CLI command-line mode only) |
| `sockets` extension | **Required**, provides underlying Socket communication capabilities |
| `event` extension | **Strongly recommended**, significantly improves concurrency performance under Linux by automatically enabling epoll mode |

> 💡 This project provides a Docker quick-setup environment. Run `docker-compose up -d` for a one-click start.

---

## Quick Start

### Installation

```bash
composer create-project xiaosongshu/rtmp_server
cd rtmp_server
```

### Start the Origin Server Service

```bash
php server.php
```

Example Output:

```
[INFO] RTMP Server started on 0.0.0.0:1935
[INFO] HTTP-FLV/WS-FLV Server started on 0.0.0.0:8501
[INFO] HTTP Static Server started on 0.0.0.0:80
```

### Publishing

#### Method 1: Browser Publishing (No software installation required)

- Open `http://127.0.0.1/push.html` and click "Start Publishing".
- Or open `http://127.0.0.1/flv_push.html`, select an MP4/FLV static file, and click "Start Publishing".

#### Method 2: FFmpeg Publishing

```bash
ffmpeg -re -stream_loop -1 -i video.mp4 -c:v libx264 -c:a aac -f flv rtmp://127.0.0.1:1935/live/stream
```

#### Method 3: OBS Publishing

- Server Address: `rtmp://127.0.0.1:1935/live/`
- Stream Key: `stream`

#### Method 4: PHP Publishing

```bash
php pusher.php test.mp4 http://127.0.0.1:8501/live/stream
```

### Watch the Live Stream

Open `http://127.0.0.1/index.html` to watch.

---

## Publishing Addresses

| Protocol | Address Format | Example |
|------|---------|------|
| RTMP | `rtmp://host:1935/{app}/{stream}` | `rtmp://127.0.0.1:1935/live/stream` |
| HTTP-FLV | `http://host:8501/{app}/{stream}` | `http://127.0.0.1:8501/live/stream` |
| WebSocket-FLV | `ws://host:8501/{app}/{stream}` | `ws://127.0.0.1:8501/live/stream` |

> **Note**: `{app}` is the application name, `{stream}` is the channel name. Only English letters and numbers are supported.

---

## Playback Addresses

### Live Streaming

| Protocol | Playback Address | Description |
|------|---------|------|
| RTMP | `rtmp://127.0.0.1:1935/live/stream` | Native player / ffplay |
| HTTP-FLV | `http://127.0.0.1:8501/live/stream.flv` | Low-latency browser playback |
| WebSocket-FLV | `ws://127.0.0.1:8501/live/stream.flv` | Native browser WebSocket support |
| HLS | `http://127.0.0.1:80/hls/live/stream/index.m3u8` | Preferred for mobile devices |

### VOD Playback

Recording files are located under the project root directory:

| File Type | File Path |
|---------|---------|
| Merged MP4 | `mp4/live/stream/output_merge/stream_full.mp4` |
| FLV Recording | `flv/live/stream/index.flv` |
| HLS Segments | `hls/live/stream/` |

Access Example: `http://127.0.0.1:80/mp4/live/stream/output_merge/stream_full.mp4`

---

## Web Playback Pages

### Playback Pages

| Page | Purpose | Access URL |
|------|------|---------|
| `index.html` | FLV Low-latency live stream | `http://127.0.0.1/index.html` |
| `play.html` | HLS Mobile live stream | `http://127.0.0.1/play.html` |
| `mp4.html` | MP4 VOD | `http://127.0.0.1/mp4.html` |
| `video.html` | FLV VOD | `http://127.0.0.1/video.html` |
| `play_merge.html` | fMP4 segmented VOD | `http://127.0.0.1/play_merge.html` |

### Publishing Pages

| Page                  | Purpose                                             | Access URL |
|-----------------------|-----------------------------------------------------|---------|
| `push.html`           | Screen sharing publishing                           | `http://127.0.0.1/push.html` |
| `flv_push.html`       | Local FLV/MP4 file publishing                       | `http://127.0.0.1/flv_push.html` |
| `push_merge.html`     | Multi-stream merge publishing                       | `http://127.0.0.1/push_merge.html` |
| `push_transcode.html` | Transcode and publish live stream to various bitrates, adapting to different client network conditions | `http://127.0.0.1/push_transcode.html` |

### PHP Clients

| Script | Purpose | Command Example |
|------|------|---------|
| `pusher.php` | Publishing client | `php pusher.php video.mp4 http://127.0.0.1:8501/live/stream` |
| `puller.php` | Pulling client | `php puller.php http://127.0.0.1:8501/live/stream.flv output.flv` |

---

## Directory Structure

```
rtmp_server/
├── flv/                        # FLV raw recording files
├── mp4/                        # MP4 / fMP4 transcoded products
├── hls/                        # HLS TS segments + m3u8 index
├── MediaServer/                # RTMP core protocol, publishing/playback session logic
├── record/                     # Static file storage directory for pulling client
├── Root/                       # Low-level async IO, Socket event driver
├── server.php                  # Origin server startup entry point
├── fileGateway.php             # Static file gateway
├── flvGateway.php              # FLV live streaming gateway
├── puller.php                  # Stream pulling client
├── pusher.php                  # Stream publishing client
├── push.html                   # Web publishing (screen sharing)
├── push_merge.html             # Web multi-stream merge publishing
├── push_transcode.html         # Web live stream transcoding publishing (multiple bitrates, freely selectable)
├── flv_push.html               # Web publishing (file)
├── auth_config.php             # Publishing authentication configuration
└── *.html                      # Web playback pages
```

---

## System Architecture

```
                                                    [Publisher] OBS / FFmpeg
                                                         │
                                       RTMP Push(1935)  /  HTTP-FLV / WS-FLV Push(8501)
                                                         │
                                                         ▼
╔══════════════════════════════════════════════════════════════════════════════════════╗
║                              RTMP Origin Main Server (Core)                          ║
║                                                                                      ║
║  📥 Publishing Access   Three-protocol push (RTMP / HTTP-FLV / WebSocket-FLV), link auth║
║  🔄 Protocol Conversion    RTMP / HTTP-FLV / WS-FLV → HTTP-FLV / WebSocket-FLV / HLS / fMP4 / MP4 ║
║  💾 Real-time Recording    ┌──────────┬──────────┬──────────┐                                    ║
║                │ FLV Rec   │ fMP4 Seg  │ HLS Seg  │  Three independent parallel tasks           ║
║                │ (live raw)│ (live seg)│ (live seg)│                                  ║
║                └──────────┴──────────┴──────────┘                                    ║
║  📤 Live Streaming Output    HTTP-FLV(8501) / WebSocket-FLV / HLS Live / fMP4 Live      ║
║  📦 VOD Products   fMP4 segments generated in real-time → automatically merged into full MP4 after stream ends ║
║  📁 Static Service    Origin server built-in HTTP service (port 80), can directly provide static file access ║
╚══════════════════════════════════════════════════════════════════════════════════════╝
│
┌───────────────────┼───────────────────┐
│                   │                   │
▼                   ▼                   ▼
HTTP-FLV(8501)     HLS(TS/m3u8)       fMP4(Segments)
Real-time Output    Static Files       Static Files
│                   │                   │
│                   │                   │
▼                   ▼                   ▼
┌─────────────┐    ┌──────────────────────────────────────────┐
│ FLV Gateway Cluster │    │          Static File Gateway Cluster (fileGateway)      │
│             │    │    🎯 Hosting: HLS / fMP4 / MP4 / FLV / Web Pages │
│ ┌─────────┐ │    │                                          │
│ │ Level 1  │ │    │ ┌───────┐ ┌───────┐ ┌───────┐           │
│ │ Gateway  │ │    │ │ GW 1  │ │ GW 2  │ │ GW 3  │           │
│ │ (8080)  │ │    │ │(8100) │ │(8101) │ │(8102) │           │
│ └───┬─────┘ │    │ └──┬────┘ └──┬────┘ └──┬────┘           │
│     │       │    │    │        │        │                 │
│ ┌───┴───┐   │    │    ▼        ▼        ▼                 │
│ ▼   ▼   ▼   │    │ ┌──────────────────────────────────┐   │
│ ┌─┐ ┌─┐ ┌─┐ │    │ │          Client                 │   │
│ │S│ │S│ │S│ │    │ │ HLS Player / MSE / VOD / ffplay │   │
│ │u│ │u│ │u│ │    │ └──────────────────────────────────┘   │
│ │b│ │b│ │b│ │    │                                          │
│ │G│ │G│ │G│ │    └──────────────────────────────────────────┘
│ │W│ │W│ │W│ │
│ └┬─┘ └┬─┘ └┬─┘ │
│  │    │    │   │
│  ▼    ▼    ▼   │
│ ┌────────────┐ │
│ │    Client    │ │
│ │ FLV / ffplay│ │
│ └────────────┘ │
└─────────────────┘
```

### Architecture Details

- **Origin Service**: The sole stream production node, supports **RTMP, HTTP-FLV, WebSocket-FLV three-protocol publishing**, responsible for push/pull access and multi-protocol re-packaging. **FLV recording, fMP4 segmentation, and HLS segmentation tasks run completely independently in parallel**, without blocking each other.

- **Origin Static Capability**: The origin server has a built-in HTTP service (default port 80), which can directly provide static file access. **No additional gateway deployment is needed for low-concurrency scenarios**, ready to use out of the box.

- **Real-time Recording Mechanism**:
  - **FLV Recording**: Saves the raw live stream in real-time, obtaining a complete FLV file after the stream ends.
  - **fMP4 Segmentation**: Generates audio/video fMP4 segments in real-time, automatically merged into a complete MP4 after the stream ends.
  - **HLS Segmentation**: Generates TS segments + m3u8 index in real-time, compatible with mobile playback.
  - **Independent Switches**: Users can configure whether to enable each recording task separately in `server.php`.

- **FLV Live Gateway Cluster**: A pure traffic forwarding service, pulls HTTP-FLV streams upstream, caches GOP keyframes for instant playback start.
  - Supports infinite cascading: Level 1 Gateway → Level 2 Gateway → Level 3 Gateway → ... → Client.
  - Supports horizontal scaling: Deploy multiple gateway instances at the same level, distribute traffic via load balancing.
  - Linux epoll high performance: A single process can handle 20,000+ concurrent connections; Windows compatible select model.

- **Static File Gateway Cluster**: A lightweight HTTP static file server, uniformly hosting all static resources.
  - **Applicable Protocols**: HLS (.m3u8/.ts), fMP4 (.m4s/.mp4), MP4 VOD files, FLV recording files, Web playback pages.
  - Supports horizontal and vertical scaling, capable of supporting large-scale VOD concurrency.
  - **Best Practice**: Point HLS/fMP4/MP4 playback paths to this gateway cluster to achieve read/write separation for static resources.

### Deployment Recommendations

| Concurrency Scenario  | Deployment Plan |
|-----------------------|---------|
| Low (< 1000)          | Use the origin server's built-in HTTP service directly, no extra gateways needed |
| Medium (1000 – 5,000) | Origin server + single-layer gateway cluster |
| High (> 5,000)        | Origin server + FLV gateway multi-level cluster + static file gateway multi-level cluster |

---

## Port Configuration

Edit `server.php` to modify ports:

| Port | Protocol | Purpose |
|------|------|------|
| 1935 | RTMP | RTMP publishing and playback |
| 8501 | HTTP / WebSocket | HTTP-FLV / WS-FLV publishing and playback |
| 80 | HTTP | Static file service + Web pages |

---

## Recording Switch Configuration

Edit `server.php` to independently control the switches for three recording tasks:

```php
define('FLV_TO_RECORD', true);   // Whether to record FLV raw files in real-time
define('FLV_TO_MP4', true);      // Whether to generate fMP4 segments and merge into MP4 in real-time
define('FLV_TO_HLS', true);      // Whether to generate HLS (TS) segments in real-time
```

> The three tasks run independently and in parallel, without blocking each other.

---

## Publishing Authentication

### Overview

To prevent unauthorized publishing that could overwrite your live stream, the server uses **Stream Key** authentication. Only publishing requests carrying a valid Stream Key will be allowed.

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

### Publishing with Authentication

Use the `key` parameter in the publishing URL:

**RTMP Publishing:**

```bash
ffmpeg -re -stream_loop -1 -i video.mp4 -c:v libx264 -c:a aac -f flv \
  rtmp://127.0.0.1:1935/live/stream?key=live_123456
```

**OBS:**

- Server Address: `rtmp://127.0.0.1:1935/live/`
- Stream Key: `stream?key=live_123456`

**HTTP-FLV Publishing:**

```bash
ffmpeg -re -stream_loop -1 -i video.mp4 -c:v libx264 -c:a aac -f flv \
  http://127.0.0.1:8501/live/stream?key=live_123456
```

**WebSocket-FLV Publishing:**

```bash
php pusher.php test.flv "ws://127.0.0.1:8501/live/stream?key=live_123456"
```

> **Note**: Pulling/playback does not require authentication.

### Security Best Practices

1. **Change Default Keys**: Always replace the default `stream_keys` with strong random strings.
2. **Use HTTPS**: Use HTTPS for transmission in public network environments to prevent credential interception.
3. **Rotate Keys Periodically**: Update `stream_keys` regularly.

---

## FLV Streaming Gateway

### Introduction

A lightweight traffic distribution component supporting infinite cascading deployment. It pulls HTTP-FLV from the upstream origin server/superior gateway, caches stream headers and GOP keyframes for instant playback for new users, and copies stream data down to clients or sub-gateways. **Designed specifically for medium to high concurrency live streaming scenarios**, supporting both horizontal and vertical scaling.

### Startup Commands

```bash
# Basic startup
php flvGateway.php 8080 http://OriginServerIP:8501

# Horizontal scaling: Multiple instances at the same level
php flvGateway.php 8080 http://OriginServerIP:8501
php flvGateway.php 8081 http://OriginServerIP:8501
php flvGateway.php 8082 http://OriginServerIP:8501

# Vertical scaling: Multi-level cascading
php flvGateway.php 8080 http://OriginServerIP:8501        # Level 1 Gateway
php flvGateway.php 8081 http://127.0.0.1:8080             # Level 2 Gateway
php flvGateway.php 8082 http://127.0.0.1:8081             # Level 3 Gateway

# Linux/macOS background running
php flvGateway.php 8080 http://OriginServerIP:8501 > /dev/null 2>&1 &
```

### Playback Address

```
http://GatewayIP:Port/{AppName}/{ChannelName}.flv
```

Example: `http://127.0.0.1:8080/live/stream.flv`

---

## Static File Gateway

### Introduction

A lightweight HTTP static file server, uniformly hosting all static resources. **This is the recommended playback method for file-based protocols like HLS, fMP4, MP4, etc.** Supports horizontal and vertical scaling, capable of supporting large-scale VOD concurrency.

### Startup Commands

```bash
# Basic startup
php fileGateway.php 0.0.0.0 8100

# Horizontal scaling: Multi-instance deployment
php fileGateway.php 0.0.0.0 8100
php fileGateway.php 0.0.0.0 8101
php fileGateway.php 0.0.0.0 8102

# Linux/macOS background running
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
http://GatewayIP:Port/{RelativeFilePath}
```

Examples:

```
http://127.0.0.1:8100/index.html
http://127.0.0.1:8100/hls/live/stream/index.m3u8
http://127.0.0.1:8100/mp4/live/stream/output_merge/stream_full.mp4
```

---

## Publishing Access Guide

### RTMP Publishing

**Address Format**: `rtmp://127.0.0.1:1935/{AppName}/{ChannelName}`

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

### HTTP-FLV Publishing

**Address Format**: `http://127.0.0.1:8501/{AppName}/{ChannelName}`

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

### WebSocket-FLV Publishing

**Address Format**: `ws://127.0.0.1:8501/{AppName}/{ChannelName}`

**Browser Publishing**: Open `http://127.0.0.1/push.html` or `http://127.0.0.1/flv_push.html`

**PHP Client**:

```bash
php pusher.php test.flv ws://127.0.0.1:8501/live/stream
```

---

## FAQ

### Q1: Startup fails on Windows, prompting that the `event` extension is missing?

The Windows environment does not support the `event` extension. The server will automatically fall back to the `sockets` extension + select model. Ensure the `sockets` extension is installed to run normally.

### Q2: How to check the server running status?

The server outputs the following logs after startup:

```
[INFO] RTMP Server started on 0.0.0.0:1935
[INFO] HTTP-FLV/WS-FLV Server started on 0.0.0.0:8501
[INFO] HTTP Static Server started on 0.0.0.0:80
```

### Q3: Publishing is successful, but playback is choppy?

1. Check network latency.
2. Lower the publishing bitrate or frame rate.
3. Use the FLV gateway cluster to cache GOPs.

### Q4: How to stop the server?

Close the terminal window running `php server.php` directly, or use `Ctrl+C`.

### Q5: Which publishing tools are supported?

All standard RTMP publishing tools are supported: OBS, FFmpeg, xSplit, mobile publishing SDKs, etc.

---

## Open Source License

This project is open-sourced under the **MIT License**.

The code of this project is provided "AS IS," without warranty of any kind, express or implied, including but not limited to the warranties of merchantability, fitness for a particular purpose, and non-infringement. In no event shall the authors be liable for any direct, indirect, incidental, special, punitive, or consequential damages arising from the use of this software.

Please refer to the [LICENSE](LICENSE) file for detailed disclaimer terms.

---

## Toolkit
Most of the functionality of the current project has been separated into a standalone toolkit `xiaosongshu/flv2mp4` (https://github.com/2723659854/flv2mp4), supporting FLV, MP4, fMP4, HLS format conversion, providing FLV and file gateways, and providing PHP push/pull stream clients (supporting RTMP, HTTP-FLV, WS-FLV protocols).

## Contact

- 📬 Email: `2723659854@qq.com`
- 🐙 GitHub: [2723659854](https://github.com/2723659854)