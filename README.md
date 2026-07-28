# RTMP Server

<p align="center">
  <a href="./README.cn.md"><strong>🇨🇳 中文文档</strong></a> •
  <a href="./README.md"><strong>🇬🇧 English Docs</strong></a>
</p>

> A lightweight RTMP live streaming service built in pure PHP, **zero dependencies on FFmpeg, Nginx, or other third-party streaming software**, enabling rapid deployment of private live streaming platforms out of the box.
> On Linux environments, the `event` extension is automatically enabled for epoll event-driven architecture; on Windows, it automatically falls back to the select I/O model, ensuring full platform compatibility.
> **Project positioning: underlying infrastructure** — complete self-developed RTMP/HTTP-FLV/WS-FLV protocol stack and asynchronous network engine; upper-layer applications such as business management, authentication, and playback management need to be extended and developed by developers.
> The project supports H.264 **decoding** + **scaling** + **watermarking** + **encoding**, enabling re-encoding of FLV, MP4, and HLS streams to adapt to different bitrates.

---
## Table of Contents

- [Requirements](#requirements)
- [Quick Start](#quick-start)
- [Push/Pull Stream Address Specification](#pushpull-stream-address-specification)
- [Live & VOD Access Addresses](#live--vod-access-addresses)
- [Page/Script Usage Guide](#pagescript-usage-guide)
- [Project Directory Structure](#project-directory-structure)
- [Overall System Architecture](#overall-system-architecture)
- [Port Constants Configuration](#port-constants-configuration)
- [Recording Task Toggle Configuration](#recording-task-toggle-configuration)
- [Multi-Process Worker Configuration (IPC Stream Sync Core)](#multi-process-worker-configuration-ipc-stream-sync-core)
- [Push Stream Authentication Configuration](#push-stream-authentication-configuration)
- [FLV Live Distribution Gateway](#flv-live-distribution-gateway)
- [Static File HTTP Gateway](#static-file-http-gateway)
- [Multi-Method Push/Pull Stream Access Tutorial](#multi-method-pullpush-stream-access-tutorial)
- [Live Relay Tutorial](#live-relay-tutorial)
- [100K+ Concurrent Cluster Deployment Architecture](#100k-concurrent-cluster-deployment-architecture)
- [Multi-Bitrate Support](#multi-bitrate-support)
- [FAQ](#faq)
- [License](#license)
- [Companion Toolkit](#companion-toolkit)
- [Contact](#contact)
---

## Requirements
| Dependency | Requirement Description |
|--------|------------|
| PHP | >= 8.1, CLI mode only, FPM not supported |
| sockets extension | **Mandatory**, provides underlying TCP/WS/RTMP communication |
| event extension | **Strongly recommended** on Linux for epoll high-concurrency event model; Windows automatically falls back to select when not installed |

> Quick deployment: The project includes a `docker-compose.yml` file — run `docker-compose up -d` to start the complete runtime environment with one command.

---

## Quick Start
### 1. Project Installation
```bash
composer create-project xiaosongshu/rtmp_server
```

### 2. Start the Origin Server
```bash
php server.php
```

Successful startup output example:
```
[INFO] RTMP Server started on 0.0.0.0:1935
[INFO] HTTP-FLV/WS-FLV Server started on 0.0.0.0:8501
[INFO] HTTP Static Server started on 0.0.0.0:80
```

### 3. Quick Push Stream Test
#### Method 1: Browser-based Push (No Software Required)
- Screen real-time push: `http://127.0.0.1/push.html`
- Local MP4/FLV file loop push: `http://127.0.0.1/flv_push.html`

#### Method 2: FFmpeg Standard Push
```bash
ffmpeg -re -stream_loop -1 -i video.mp4 -c:v libx264 -c:a aac -f flv rtmp://127.0.0.1:1935/live/stream
```

#### Method 3: OBS Studio Push
- Server: `rtmp://127.0.0.1:1935/live/`
- Stream Key: `stream`

#### Method 4: Built-in PHP Push Client
```bash
php pusher.php test.mp4 http://127.0.0.1:8501/live/stream
```

### 4. Quick Live Viewing
Browser access: `http://127.0.0.1/index.html`

---

## Push/Pull Stream Address Specification
### Push Stream Addresses (Unified Format for OBS/FFmpeg/PHP/Web)
| Protocol | Standard Format | Example |
|------|---------|---------|
| RTMP | `rtmp://host:1935/{app}/{stream}` | `rtmp://127.0.0.1:1935/live/stream` |
| HTTP-FLV | `http://host:8501/{app}/{stream}` | `http://127.0.0.1:8501/live/stream` |
| WebSocket-FLV | `ws://host:8501/{app}/{stream}` | `ws://127.0.0.1:8501/live/stream` |

> Field constraints: `{app}` application name and `{stream}` channel name may only contain English letters, numbers, and underscores; special characters and Chinese are prohibited.

### Live & VOD Access Addresses
#### Real-Time Live Playback Addresses
| Protocol | Address | Use Case |
|-------------------|------------------------------------------------------------------|------------------------------------|
| RTMP              | `rtmp://127.0.0.1:1935/live/stream`                              | ffplay, desktop professional players |
| HTTP-FLV          | `http://127.0.0.1:8501/live/stream.flv`                          | Low-latency live streaming on PC browsers |
| WebSocket-FLV     | `ws://127.0.0.1:8501/live/stream.flv`                            | Native WebSocket MSE playback in browsers |
| HLS-TS            | `http://127.0.0.1:80/hls/live/stream/index.m3u8`                 | Mobile devices, WeChat built-in browser |
| HLS-FMP4 (Mixed A/V) | `http://127.0.0.1:80/mp4/live/stream/output_merge/index.m3u8`    | Mainstream desktop browsers, mobile, WeChat built-in browser, ffplay, VLC, etc. |
| HLS-FMP4 (Separate A/V) | `http://127.0.0.1:80/mp4/live/stream/output_separate/index.m3u8` | Mainstream desktop browsers, mobile, WeChat built-in browser, ffplay, VLC, etc. |

#### Recorded VOD Playback Addresses
Recorded files are persistently stored in the project root directory, automatically generated upon stream end:

| File Type | Storage Path | Access Example |
|------------------|------------------------------|-------------------------------------------------------------------|
| Standard MP4 file | `mp4/live/stream/index.mp4`  | `http://127.0.0.1/mp4/live/stream/index.mp4`                      |
| Raw FLV recording | `flv/live/stream/index.flv`  | `http://127.0.0.1/flv/live/stream/index.flv`                      |
| HLS TS segment dir | `hls/live/stream/index.m3u8` | `http://127.0.0.1:80/hls/live/stream/index.m3u8`                  |
| HLS-FMP4 Mixed A/V | `mp4/live/stream/output_merge/index.m3u8`    | `http://127.0.0.1:80/mp4/live/stream/output_merge/index.m3u8`     |
| HLS-FMP4 Separate A/V | `mp4/live/stream/output_separate/index.m3u8` | `http://127.0.0.1:80/mp4/live/stream/output_separate/index.m3u8`  |

Note: Standard MP4 files are only automatically generated from FLV recordings when multi-process and FLV recording are both enabled. You can also manually convert FLV to MP4 using the `xiaosongshu/flv2mp4` toolkit.

---

## Page/Script Usage Guide
### Live/VOD Playback Pages
| Page File | Function | Access URL |
|-----------------|------------------------|----------------------------------|
| index.html      | HTTP-FLV low-latency live player | http://127.0.0.1/index.html      |
| play.html       | HLS mobile-optimized player | http://127.0.0.1/play.html       |
| mp4.html        | MP4 VOD player | http://127.0.0.1/mp4.html        |
| video.html      | FLV VOD player | http://127.0.0.1/video.html      |
| play_merge.html | fMP4 fragmented live/VOD player (native JS) | http://127.0.0.1/play_merge.html |
| mse.html        | fMP4 fragmented live/VOD player (hls.js) | http://127.0.0.1/mse.html        |

### Web Push Pages
| Page File | Function | Access URL |
|---------|---------|---------|
| push.html | Browser screen capture push (WS-FLV) | http://127.0.0.1/push.html |
| flv_push.html | Local MP4/FLV file loop push | http://127.0.0.1/flv_push.html |
| push_merge.html | Multi-stream merge push | http://127.0.0.1/push_merge.html |
| push_transcode.html | Frontend multi-bitrate transcoding push for weak networks | http://127.0.0.1/push_transcode.html |

### PHP Built-in Push/Pull Client Scripts
| Script | Function | Example Command |
|------|------|---------|
| pusher.php | Command-line file push client | `php pusher.php video.mp4 http://127.0.0.1:8501/live/stream` |
| puller.php | Command-line pull recording client | `php puller.php http://127.0.0.1:8501/live/stream.flv output.flv` |

### PHP Built-in Relay Client Scripts
| Script | Function | Example Command |
|-------------|--------------|---------|
| forward.php | Command-line live data relay client | `php forward.php ws://127.0.0.1:8501/a/b.flv rtmp://127.0.0.1:1935/c/d` |

### PHP Built-in Gateway Client Scripts
| Script | Function | Example Command |
|-----------------|-------------|------------------------------------|
| fileGateway.php | Command-line file gateway client | `php fileGateway.php 0.0.0.0 8100` |
| flvGateway.php  | Command-line FLV gateway client | `php flvGateway.php 8080 http://127.0.0.1:8501` |

### PHP Live Startup Scripts
| Script | Function | Example Command |
|------------|-----------|------------------|
| server.php | Command-line live service startup | `php server.php` |
---

## Project Directory Structure
```
rtmp_server/
├── config/                     # Global config: ports, multi-process, recording, auth
├── flv/                        # FLV raw stream recording storage
├── mp4/                        # fMP4 segments & merged MP4 after stream ends
├── hls/                        # HLS TS segments, m3u8 index files
├── MediaServer/                # RTMP/FLV/WS-FLV core protocol stack, session management
├── Root/                       # Low-level async I/O, Socket event driver
├── record/                     # Client-side static page assets
├── server.php                  # RTMP origin server entry point
├── flvGateway.php              # FLV live distribution gateway
├── fileGateway.php             # HLS/MP4/static resources HTTP gateway
├── forward.php                 # Live relay client
├── pusher.php                  # PHP push client
├── puller.php                  # PHP pull client
├── encode.php                  # FLV to HLS multi-bitrate client
├── watermark.php               # Watermark generation tool
├── *.html                      # All Web push/pull/playback pages
├── docker-compose.yml          # Docker one-click deployment config
└── LICENSE                     # Apache 2.0 license file
```

---

## Overall System Architecture
```
                                    【External Pushers】OBS / FFmpeg / Web
                                         │
                                   RTMP(1935) / HTTP-FLV/WS-FLV(8501) Push Access
                                         │
                                         ▼
╔══════════════════════════════════════════════════════════════════════════════════════╗
║                         RTMP Origin Server (Stream Production Core)                 ║
║                                                                                      ║
║  📥 Push/Pull Access: RTMP / HTTP-FLV / WS-FLV, built-in auth validation           ║
║  🔄 Protocol Transmuxing: Output HTTP-FLV / WS-FLV / HLS / fMP4 / MP4              ║
║  💾 Parallel Recording Tasks (non-blocking, independently toggleable)               ║
║        ┌──────────┬──────────┬──────────┐                                            ║
║        │FLV Record │fMP4 Seg. │HLS Seg. │                                            ║
║        └──────────┴──────────┴──────────┘                                            ║
║  📤 Live Output: HTTP-FLV, WS-FLV, HLS live streams                                 ║
║  📦 VOD Assets: fMP4 segments cached, merged to MP4 after stream ends               ║
║  📁 Built-in HTTP static service (port 80): direct page/VOD access, no gateway needed║
╚══════════════════════════════════════════════════════════════════════════════════════╝
│
┌───────────────────┼───────────────────┐
│                   │                   │
▼                   ▼                   ▼
HTTP-FLV Live     HLS Static Segments  fMP4 Static Segments
│                   │                   │
▼                   ▼                   ▼
┌─────────────┐    ┌──────────────────────────────────────────┐
│ FLV Gateway │    │       Static File Gateway Cluster        │
│   Cluster   │    │    (fileGateway)                        │
│ ┌─────────┐ │    │    Hosts: HLS/fMP4/MP4/FLV/web assets  │
│ │Level 1   │ │    │ ┌───────┐ ┌───────┐ ┌───────┐         │
│ │(port8080)│ │    │ │Node1  │ │Node2  │ │Node3  │         │
│ └───┬─────┘ │    │ │(8100) │ │(8101) │ │(8102) │         │
│     │       │    │ └──┬────┘ └──┬────┘ └──┬────┘         │
│ ┌───┴───┐   │    │    │        │        │                 │
│ ▼   ▼   ▼   │    │    ▼        ▼        ▼                 │
│ ┌─┐ ┌─┐ ┌─┐ │    │ ┌──────────────────────────────────┐   │
│ │S│ │S│ │S│ │    │ │End-user Players                │   │
│ │1│ │2│ │3│ │    │ │MSE/HLS/ffplay/browsers         │   │
│ └┬─┘ └┬─┘ └┬─┘ │    │ └──────────────────────────────────┘   │
│  │    │    │   │    └──────────────────────────────────────────┘
│  ▼    ▼    ▼   │
│ ┌────────────┐ │
│ │Live Viewers│ │
│ │FLV Players │ │
│ └────────────┘ │
└─────────────────┘
```

### Architecture Details
1. **Origin Server (Sole Stream Producer)**
   All external push streams are ingested here, completing protocol parsing, authentication, multi-format transmuxing, and parallel recording. FLV, fMP4, and HLS recording threads are completely isolated and non-blocking.
   For low-concurrency scenarios, the built-in port 80 static service can be used directly without additional gateways.

2. **FLV Live Distribution Gateway**
   No transcoding — pure forwarding with GOP keyframe caching for instant playback. Supports horizontal scaling and multi-level cascading (max 2 levels recommended for production; more levels increase latency). Linux epoll for high concurrency; Windows for testing only.
   In high-concurrency scenarios, all player pull requests go through the gateway to reduce connection pressure on the origin server.

3. **Static File Gateway Cluster**
   Dedicated hosting for HLS, MP4, FLV, and frontend pages to separate read/write operations. Required for large-scale VOD scenarios to prevent the origin from being overwhelmed by file I/O.

4. **Integrated Live Toolkit**
   The project supports pure PHP push, pull, and relay clients, as well as web-based push, playback, transcoding, and stream merging. Supports both single-process and multi-process modes, plus the `xiaosongshu/flv2mp4` media toolkit.

### Concurrency Deployment Recommendations
| Scale | Recommended Setup |
|---------|------------|
| Low (< 1,000 viewers) | Origin server only (`server.php`), use built-in ports 80 and 8501, no gateways |
| Medium (1,000 ~ 5,000 viewers) | Origin + single-layer FLV gateway cluster + single-layer static file gateway cluster, with Nginx load balancing |
| High / Large events (>5,000 viewers) | Origin + multi-layer FLV/static gateway clusters with frontend load balancing; for 10k+ events, must use commercial CDN edge distribution — never serve all traffic from a single server |

---

## Port Constants Configuration
Edit `config/app.php` to adjust global service ports:
```php
/** HTTP-FLV / WebSocket-FLV main service port */
define('BASE_FLV_PORT', 8501);
/** RTMP standard port 1935 */
define('BASE_RTMP_PORT', 1935);
/** Built-in static web/VOD HTTP port */
define('BASE_WEB_PORT', 80);
```

## Recording Task Toggle Configuration
`config/app.php` independently controls three recording tasks:
```php
define('FLV_TO_RECORD', true);   // Enable raw FLV recording
define('FLV_TO_MP4', true);      // Enable fMP4 segmentation
define('FLV_TO_HLS', true);      // Enable HLS TS segmentation
```

## Multi-Process Worker Configuration (IPC Stream Sync Core)
### Principle
In PHP CLI multi-process mode, each Worker has isolated memory. When one process receives a push stream, others cannot access it, so **IPC (Inter-Process Communication) is required to sync live streams**.
This project uses a custom TCP Socket-based IPC over traditional methods like shared memory or pipes: it allocates a set of internal communication ports, and the receiving Worker actively forwards the stream data to all other Workers via a built-in TCP client, ensuring full stream data sharing across all processes.

### Configuration in `config/app.php`
```php
/** Master switch: enable multi-process Worker mode */
define('ENABLE_MULTI_PROCESS', true);
/** Worker count — recommended not to exceed CPU cores */
define('WORKER_COUNT', 3);
/** Starting port for inter-process TCP communication, auto-allocates 8502, 8503... */
define('COPY_PORT_START', 8502);
```
> When multi-process is disabled (`ENABLE_MULTI_PROCESS=false`), worker count and IPC port settings are ignored, and the service runs in single-process mode without IPC sync.

### Port Load Balancing Rules
1. Linux: Supports port reuse — multiple Workers can listen on the same 8501 FLV port. The kernel distributes player connections across Workers automatically.
2. Windows: Supports `SO_REUSEADDR`, but new TCP connections are always assigned to the first process that bound to 8501, preventing native load balancing. Use Nginx to reverse-proxy internal ports (8502+) for load distribution.
3. Internal IPC ports are externally accessible for pull requests, useful for manual load balancing on Windows.

### Platform Performance Notes
- Linux: epoll I/O model — single process supports thousands of concurrent connections; multi-process makes full use of multi-core CPUs. Production environment preferred.
- Windows: select model has low concurrency limits (~256 connections per process) — for local development only. Do not deploy in production on Windows.

## Push Stream Authentication Configuration
### Description
Prevents unauthorized streams from overwriting live channels — only push requests with valid stream keys are allowed. Playback pull auth is not currently built-in; developers can implement referer/token validation at the gateway or reverse-proxy layer.

Configuration file `config/auth.php`:
```php
<?php
return [
    'enabled' => false, // Master auth switch
    'publish' => [
        'require_auth' => true, // Require key for push streams
        'stream_keys' => [
            'live_123456',
            'stream_key_abc',
        ],
    ],
    'global' => [
        'allowed_apps' => ['live'], // Allowed app names
        'deny_apps' => [],
    ],
];
```

### Authenticated Push Address Format
Include key via URL parameter:
1. RTMP
```bash
ffmpeg -re -i video.mp4 -f flv rtmp://127.0.0.1:1935/live/stream?key=live_123456
```
2. OBS Stream Key: `stream?key=live_123456`
3. HTTP-FLV
```bash
ffmpeg -re -i video.mp4 -f flv http://127.0.0.1:8501/live/stream?key=live_123456
```
4. WS-FLV PHP client
```bash
php pusher.php test.flv "ws://127.0.0.1:8501/live/stream?key=live_123456"
```

### Security Best Practices
1. Replace default keys with random strings of 32+ characters.
2. Enable HTTPS/WSS in public environments to prevent plaintext key interception.
3. Rotate stream keys periodically to reduce leakage risk.
4. Authentication is disabled by default — enable it if needed.

> Note: Any changes to the above configs require a service restart to take effect.

---

## FLV Live Distribution Gateway
### Overview
Lightweight forwarding service that pulls HTTP-FLV/WS-FLV streams from upstream origin, caches GOP keyframes for instant playback, and supports horizontal scaling and multi-level cascading to share origin load.
The gateway supports pulling from HTTP-FLV or WS-FLV sources and uniformly provides both HTTP-FLV and WS-FLV playback addresses.

### Startup Commands
```bash
# Basic single instance
php flvGateway.php 8080 http://127.0.0.1:8501
php flvGateway.php 8080 ws://127.0.0.1:8501

# Horizontal scaling multiple instances
php flvGateway.php 8080 http://127.0.0.1:8501
php flvGateway.php 8081 http://127.0.0.1:8501
php flvGateway.php 8082 ws://127.0.0.1:8501

# Multi-level cascading (max 2 levels recommended)
php flvGateway.php 8080 http://127.0.0.1:8501    # Level 1
php flvGateway.php 8081 http://127.0.0.1:8080     # Level 2

# Linux background silent mode
php flvGateway.php 8080 http://127.0.0.1:8501 > /dev/null 2>&1 &
```

### Gateway Playback Address Format
```
http://gateway_ip:port/{app}/{stream}.flv
ws://gateway_ip:port/{app}/{stream}.flv
```
Example: `http://127.0.0.1:8080/live/stream.flv`

## Static File HTTP Gateway
### Overview
A standalone HTTP service for static resources, hosting HLS, MP4, FLV, and frontend pages. Separates file I/O from live streaming to improve VOD stability under high concurrency.

### Startup Commands
```bash
# Single instance
php fileGateway.php 0.0.0.0 8100

# Horizontal scaling
php fileGateway.php 0.0.0.0 8100
php fileGateway.php 0.0.0.0 8101
php fileGateway.php 0.0.0.0 8102

# Linux background mode
php fileGateway.php 0.0.0.0 8100 > /dev/null 2>&1 &
```

### Nginx Load Balancing Example
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

### Resource Access Examples
```
http://127.0.0.1:8100/index.html
http://127.0.0.1:8100/hls/live/stream/index.m3u8
http://127.0.0.1:8100/mp4/live/stream/index.mp4
```

## Multi-Method Pull/Push Stream Access Tutorial
### RTMP Push
OBS, FFmpeg, and PHP clients all support standard RTMP: `rtmp://host:1935/{app}/{stream}`

### HTTP-FLV Push
Suitable for command-line or automated push: `http://host:8501/{app}/{stream}`

### WebSocket-FLV Push
Native browser push with latency as low as 50ms; use the built-in `push.html` page.

### PHP Pull Script
For server-side backup and cross-server relay:
```bash
php puller.php http://127.0.0.1:8501/live/stream.flv output.flv
php puller.php ws://127.0.0.1:8501/live/stream.flv output.flv
```

## Live Relay Tutorial
This project supports live stream relaying to multiple servers over `rtmp/ws-flv/http-flv`. See `forward.php` for details. Example:
```bash
php forward.php http://127.0.0.1:8501/a/b.flv "rtmp://127.0.0.1:1935/c/d,ws://127.0.0.1:8501/c/e,http://127.0.0.1:8501/c/f" 
```
This relays `http://127.0.0.1:8501/a/b.flv` to `rtmp://127.0.0.1:1935/c/d`, `ws://127.0.0.1:8501/c/e`, and `http://127.0.0.1:8501/c/f`. You can also relay to any platform supporting RTMP, WS-FLV, or HTTP-FLV.

### Engineering Recommendations
`pusher.php`/`puller.php`/`forward.php` can be integrated into custom scripts for automated pull-relay and backup recording, creating a complete PHP live streaming workflow without third-party tools.

---

## 100K+ Concurrent Cluster Deployment Architecture

```
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│                         【Layer 1: Multi-Pusher Layer】                                 │
│                                                                                         │
│     Streamer A (OBS/Web/FFmpeg)   Streamer B (OBS/Web/FFmpeg)   Streamer N (...)      │
│            │                              │                             │               │
│      ┌─────┼─────┐                 ┌─────┼─────┐                ┌─────┼─────┐        │
│      ▼     ▼     ▼                 ▼     ▼     ▼                ▼     ▼     ▼        │
│    [Node1] [Node2] [Node3]       [Node1] [Node2] [Node3]      [Node1] [Node2] [Node3] │
│     (Push to multiple origin nodes simultaneously for redundancy)                    │
└─────────────────────────────────────────────────────────────────────────────────────────┘
                                          │
                                          │ RTMP/HTTP-FLV/WS-FLV Push Access
                                          ▼
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│                      【Layer 2: Origin Node Cluster (Stream Production)】               │
│                                                                                         │
│    ┌─────────────┐   ┌─────────────┐   ┌─────────────┐   ┌─────────────┐             │
│    │  Origin A   │   │  Origin B   │   │  Origin C   │   │  Origin D   │             │
│    │ server.php  │   │ server.php  │   │ server.php  │   │ server.php  │             │
│    │ (multi-proc)│   │ (multi-proc)│   │ (multi-proc)│   │ (multi-proc)│             │
│    │ rec/seg     │   │ rec/seg     │   │ rec/seg     │   │ rec/seg     │             │
│    └─────┬───────┘   └─────┬───────┘   └─────┬───────┘   └─────┬───────┘             │
│          │                 │                 │                 │                      │
│          └────────┬────────┴─────────────────┴────────┬────────┘                      │
│                   │                                   │                               │
│              ┌────▼────┐                         ┌────▼────┐                          │
│              │ forward │                         │ forward │  ← Auto-sync streams      │
│              │ sync    │                         │ sync    │                          │
│              └────┬────┘                         └────┬────┘                          │
│                   └──────────────┬────────────────────┘                               │
│                                  │                                                    │
│                    (All origins back each other up; any failure continues service)    │
└──────────────────────────────────┼────────────────────────────────────────────────────┘
                                   │
                                   │ forward pulls from origin, pushes to edge
                                   ▼
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│                     【Layer 3: Edge Node Cluster (Distribution & Cache)】               │
│                                                                                         │
│    ┌─────────────┐   ┌─────────────┐   ┌─────────────┐   ┌─────────────┐             │
│    │  Edge X     │   │  Edge Y     │   │  Edge Z     │   │  Edge W     │             │
│    │ server.php  │   │ server.php  │   │ server.php  │   │ server.php  │             │
│    │ (multi-proc)│   │ (multi-proc)│   │ (multi-proc)│   │ (multi-proc)│             │
│    │ rec/seg     │   │ rec/seg     │   │ rec/seg     │   │ rec/seg     │             │
│    └─────┬───────┘   └─────┬───────┘   └─────┬───────┘   └─────┬───────┘             │
│          │                 │                 │                 │                      │
│          └────────┬────────┴─────────────────┴────────┬────────┘                      │
│                   │                                   │                               │
│              ┌────▼────┐                         ┌────▼────┐                          │
│              │ forward │                         │ forward │  ← Pull from origin, cache │
│              │ sync    │                         │ sync    │                          │
│              └─────────┘                         └─────────┘                          │
│                                                                                         │
│  ★ Any node can be upgraded to origin (accept push) or downgraded to edge (pull only)  │
│  ★ All nodes record independently for multi-copy backup                               │
└──────────────────────────────────┼────────────────────────────────────────────────────┘
                                   │
                     ┌─────────────┴─────────────┐
                     │                           │
                     ▼                           ▼
┌────────────────────────────┐ ┌────────────────────────────┐
│   【Layer 4: Gateways】     │ │   【Layer 4: Gateways】     │
│                            │ │                            │
│     flvGateway Cluster     │ │     fileGateway Cluster    │
│  ┌─────┐ ┌─────┐ ┌─────┐ │ │  ┌─────┐ ┌─────┐ ┌─────┐ │
│  │G1   │ │G2   │ │G3   │ │ │  │G1   │ │G2   │ │G3   │ │
│  └──┬──┘ └──┬──┘ └──┬──┘ │ │  └──┬──┘ └──┬──┘ └──┬──┘ │
│     │       │       │     │ │     │       │       │     │
│     └───────┼───────┘     │ │     └───────┼───────┘     │
│             │             │ │             │             │
│   (HTTP-FLV/WS-FLV)       │ │ (HLS/MP4/FLV/VOD/Static) │
└─────────────┼─────────────┘ └─────────────┼─────────────┘
              │                             │
              └─────────────┬───────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│                        【Layer 5: Viewers】                                              │
│                                                                                         │
│   PC Browser (MSE/FLV.js)    Mobile (HLS)    ffplay/Players   WebSocket Player        │
│                                                                                         │
│   ★ Viewers connect to nearest edge gateway via DNS/Nginx/GSLB load balancing          │
└─────────────────────────────────────────────────────────────────────────────────────────┘
```

---

### Architecture Design Notes

#### 1. High Availability Push Layer (Disaster Recovery)
- **Multi-path push**: Streamers push to multiple origin nodes simultaneously; if one fails, others continue serving with zero viewer impact.
- **Auto failover**: OBS/FFmpeg can configure backup push addresses for primary-backup switching; Web clients can push via JavaScript to multiple destinations.

#### 2. Origin Node Cluster (Stream Production Core)
- **Active-active deployment**: All origin nodes are active, receive pushes, and sync streams via `forward.php`, ensuring each node has a full stream copy.
- **Auto failover**: Any origin failure triggers automatic reconnection of sync links; other nodes continue service without interruption.
- **Parallel recording**: Each origin node independently records FLV/fMP4/HLS, creating multiple backups to prevent single-point storage loss.

#### 3. Edge Node Cluster (Distribution & Cache)
- **Proximity pulling**: Edge nodes pull from origin via `forward.php` and cache GOP keyframes for low-latency instant playback.
- **Elastic scaling**: Add or remove edge nodes dynamically based on load.
- **Flexible roles**: Any node can be promoted to origin (accept pushes) or demoted to edge (pull only) by configuration.

#### 4. Gateway Distribution Layer
- **flvGateway Cluster**: Dedicated to HTTP-FLV/WS-FLV live streams — no transcoding, pure forwarding with GOP caching for instant playback. Supports cascading and horizontal scaling.
- **fileGateway Cluster**: Dedicated to HLS segments, MP4 VOD, static pages — isolates file I/O from live streaming.

#### 5. Viewer Clients
- **Multi-protocol coverage**: RTMP, HTTP-FLV, WS-FLV, HLS — supports all platforms.
- **Smart routing**: DNS round-robin, Nginx reverse proxy, or GSLB to route viewers to the nearest/lowest-load edge node.

#### 6. Data Flow
1. **Push**: Streamer → (multi-path) → Origin cluster → `forward` syncs to all origins.
2. **Pull (Edge)**: Edge → `forward` pulls from origin → caches → serves local viewers.
3. **Playback**: Viewer → Load balancer → flvGateway/fileGateway → Edge (or Origin) → stream data.
4. **Recording**: All nodes record per configuration; merged to MP4 for VOD after stream end.

#### 7. Disaster Recovery & Backup
- **Node-level**: Any node failure triggers automatic reconnection to other live nodes.
- **Region-level**: If entire data center fails, DNS can switch to a backup site (requires multiple clusters).
- **Recording backup**: Each node stores recordings independently; for critical streams, multiple nodes can record simultaneously.

#### 8. Scalability & Concurrency
- **Horizontal scaling**: All layers support adding nodes without restart.
- **100K+ concurrency**: Edge nodes + gateways scale horizontally; with CDN edge acceleration, supports 100K+ concurrent viewers (bandwidth and server resources permitting).
- **Performance**: Linux epoll enables thousands of connections per node (varies by server config); multi-node clusters scale linearly.

#### 9. Deployment Recommendations
- Node-to-node data sync is handled by the built-in `forward.php` relay client. It supports pulling from any RTMP/HTTP-FLV/WS-FLV source and pushing to one or multiple targets, with authentication parameters (e.g., key) supported.
  Developers can write scheduling scripts based on actual network topology and business needs — integrating health checks, load balancing policies, or business rules — to dynamically configure pull sources, target lists, and forwarding parameters for automated stream sync.
  Origin/edge role switching also relies on external scheduling logic: monitor node metrics (CPU, memory, active connections, push count, etc.) or external traffic distribution policies to trigger script-based role adjustments for elastic scaling, failover, and disaster recovery. The entire scheduling system can be customized for highly flexible production-grade deployment.
---

## Multi-Bitrate Support

This project provides built-in multi-bitrate transcoding capabilities, supporting the conversion of Baseline Profile FLV files into multi-resolution HLS streams to adapt to different network environments and mobile devices.

> ⚠️ **Performance Notice**  
- The current multi-bitrate module is implemented in pure PHP and **performance is limited**. It is intended **only for small file offline transcoding** or **functional verification**.  
- Due to the computationally intensive nature of H.264 re-encoding, which requires significant processing time, it is strictly prohibited to use this module in production environments for real-time live streaming. For professional adaptive bitrate transcoding, please use mature tools such as FFmpeg.
- This feature depends on the `xiaosongshu/flv2mp4` toolkit, which is installed with this project — no additional setup required.

---

### Usage

For detailed configuration, please refer to the `encode.php` example file. Run the following command to transcode FLV to HLS:

```bash
php encode.php
```

- 📌 **Version Requirement**: This feature requires `xiaosongshu/flv2mp4` version **>= 1.4.4**.
- 📌 The `xiaosongshu/flv2mp4` toolkit supports re-encoding for FLV/MP4 and FLV to HLS transcoding, as well as watermark overlay. For more usage, please refer to the toolkit documentation.

---

### Use Cases

| Scenario | Recommended |
|------|----------|
| Local testing / functional verification | ✅ Recommended |
| Small file offline transcoding (< 10MB) | ✅ Acceptable |
| Real-time live stream transcoding | ❌ Not recommended |
| High-concurrency / large-scale production | ❌ Strictly prohibited |

---

**Note**: This module is for learning and evaluation purposes only. Please do not use it in production environments. For high-performance transcoding solutions, consider using FFmpeg or professional transcoding services.

---

### Watermark Tool
You can use the built-in tool to generate text watermarks. For detailed configuration, please refer to `watermark.php`. Run the following command to generate a watermark file:

```bash
php watermark.php
```

A sample watermark file `watermark_80x16.yuv` is already prepared for you.

## FAQ
### Q1: Missing event extension on Windows?
The event extension is not available on Windows — the service automatically uses the select I/O model. Just install the `sockets` extension and it will run normally.

### Q2: How to confirm the service started successfully?
Successful startup is indicated by three log lines in the terminal: RTMP 1935, FLV 8501, HTTP static 80.

### Q3: Push works but playback is laggy/stuttering?
1. Push bitrate/resolution is too high — reduce bitrate/framerate for testing.
2. Server CPU is maxed out — enable multi-process to use all cores.
3. High concurrency without FLV gateway — many player connections are consuming origin resources.
4. Insufficient server upstream bandwidth — limit concurrent viewers.

### Q4: How to stop the service?
Press `Ctrl + C` in the terminal to send a termination signal, or simply close the terminal window.

### Q5: Which third-party push software is supported?
Fully compatible with all standard RTMP clients: OBS Studio, FFmpeg, xSplit, and mobile RTMP SDKs.

## License
This project is released under the **Apache License 2.0**.
The software is provided "AS IS", without warranty of any kind. The developer assumes no liability for direct, indirect, or consequential damages arising from the use of this software. For full terms, see the `LICENSE` file in the project root.

## Companion Toolkit
The underlying codec and container conversion capabilities have been extracted into a separate toolkit: [xiaosongshu/flv2mp4](https://github.com/2723659854/flv2mp4)
It provides FLV/MP4/fMP4/HLS interconversion, standalone push/pull clients, and gateway components, and can be used independently in third-party PHP projects.

## Contact
- Email: 2723659854@qq.com
- GitHub: https://github.com/2723659854