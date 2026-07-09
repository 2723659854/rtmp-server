# RTMP Server
<p align="center">
  <a href="./README.cn.md"><strong>🇨🇳 Chinese Docs</strong></a> •
  <a href="./README.md"><strong>🇬🇧 English Docs</strong></a>
</p>

> A pure PHP self-developed lightweight RTMP live streaming service with **zero third-party media dependencies such as FFmpeg and Nginx**, enabling quick setup of a private live streaming platform out of the box.
> On Linux environments, the `event` extension is automatically enabled for epoll event-driven I/O; on Windows, it automatically falls back to the select I/O model, ensuring full cross-platform compatibility.
> **Project positioning: underlying infrastructure** — fully self-developed RTMP/HTTP-FLV/WS-FLV protocol stack and asynchronous network engine; business management, permissions, playback management, and other upper-layer applications require developers to extend and implement themselves.

---

## Table of Contents
- [Requirements](#requirements)
- [Quick Start](#quick-start)
- [Push/Pull Stream Address Specification](#pushpull-stream-address-specification)
- [Live & VOD Access URLs](#live--vod-access-urls)
- [Web Page Usage Guide](#web-page-usage-guide)
- [Project Directory Structure](#project-directory-structure)
- [System Architecture](#system-architecture)
- [Port Constants Configuration](#port-constants-configuration)
- [Recording Task Switches](#recording-task-switches)
- [Multi-Process Worker Configuration (IPC Stream Sync Core)](#multi-process-worker-configuration-ipc-stream-sync-core)
- [Push Stream Authentication Configuration](#push-stream-authentication-configuration)
- [FLV Live Distribution Gateway](#flv-live-distribution-gateway)
- [Static File HTTP Gateway](#static-file-http-gateway)
- [Multi-Way Push/Pull Stream Integration Guide](#multi-way-pushpull-stream-integration-guide)
- [Live Stream Forwarding Guide](#live-stream-forwarding-guide)
- [Cluster Deployment Architecture for 100,000+ Concurrent Connections](#cluster-deployment-architecture-for-100000-concurrent-connections)
- [FAQ](#faq)
- [Open Source License](#open-source-license)
- [Companion Toolkit](#companion-toolkit)
- [Contact](#contact)

---

## Requirements
| Dependency | Mandatory Requirement Description |
|------------|-----------------------------------|
| PHP | >= 8.1, CLI mode only, FPM is not supported |
| sockets extension | **Strictly required**, the foundation for underlying TCP/WS/RTMP communication |
| event extension | Highly recommended on Linux to enable epoll high-concurrency event model; on Windows without this extension, automatically falls back to select |

> Quick environment setup: The project includes a `docker-compose.yml` file; run `docker-compose up -d` to start the complete runtime environment with one command.

---

## Quick Start
### 1. Project Installation
```bash
composer create-project xiaosongshu/rtmp_server
cd rtmp_server
```

### 2. Start the Origin Main Service
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
#### Method 1: Browser-Based No-Software Pushing
- Screen real-time push: `http://127.0.0.1/push.html`
- Local MP4/FLV file loop push: `http://127.0.0.1/flv_push.html`

#### Method 2: FFmpeg Standard Pushing
```bash
ffmpeg -re -stream_loop -1 -i video.mp4 -c:v libx264 -c:a aac -f flv rtmp://127.0.0.1:1935/live/stream
```

#### Method 3: OBS Studio Pushing
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
### Push Addresses (Unified Format for OBS/FFmpeg/PHP/Web)
| Protocol | Standard Format | Example Address |
|----------|-----------------|-----------------|
| RTMP | `rtmp://host:1935/{app}/{stream}` | `rtmp://127.0.0.1:1935/live/stream` |
| HTTP-FLV | `http://host:8501/{app}/{stream}` | `http://127.0.0.1:8501/live/stream` |
| WebSocket-FLV | `ws://host:8501/{app}/{stream}` | `ws://127.0.0.1:8501/live/stream` |

> Field constraints: `{app}` application name and `{stream}` channel name only allow English letters, numbers, and underscores; special symbols and Chinese characters are prohibited.

### Live & VOD Access URLs
#### Real-time Live Playback URLs
| Protocol | Access URL | Use Case |
|----------|------------|----------|
| RTMP | `rtmp://127.0.0.1:1935/live/stream` | ffplay, desktop professional players |
| HTTP-FLV | `http://127.0.0.1:8501/live/stream.flv` | PC browser low-latency live streaming |
| WebSocket-FLV | `ws://127.0.0.1:8501/live/stream.flv` | Browser native WebSocket MSE playback |
| HLS | `http://127.0.0.1:80/hls/live/stream/index.m3u8` | Mobile devices, WeChat built-in browser |

#### Recorded VOD Playback URLs
Recording files are persistently stored in the project root directory; complete files are automatically generated after the live stream ends:

| File Type                | Storage Path | Access Example |
|--------------------------|--------------|----------------|
| Complete Merged FMP4     | `mp4/live/stream/output_merge/stream_full.mp4` | `http://127.0.0.1/mp4/live/stream/output_merge/stream_full.mp4` |
| Standerd MP4             | `mp4/live/stream/index.mp4`   | `http://127.0.0.1/mp4/live/stream/index.mp4` |
| Raw FLV Recording File   | `flv/live/stream/index.flv` | `http://127.0.0.1/flv/live/stream/index.flv` |
| HLS TS Segment Directory | `hls/live/stream/` | Directly use m3u8 index URL for playback |

PS: Standard MP4 files are generated automatically only when both FLV screen recording and MP4 transcoding are enabled simultaneously. Of course, you can also manually transcode FLV to MP4 using the toolkit xiaosongshu/flv2mp4.

---

## Web Page Usage Guide
### Live Playback Pages
| Page File | Description | Access URL |
|-----------|-------------|------------|
| index.html | HTTP-FLV low-latency live player | http://127.0.0.1/index.html |
| play.html | HLS mobile-optimized player | http://127.0.0.1/play.html |
| mp4.html | MP4 VOD dedicated page | http://127.0.0.1/mp4.html |
| video.html | FLV VOD player | http://127.0.0.1/video.html |
| play_merge.html | fMP4 segmented VOD page | http://127.0.0.1/play_merge.html |

### Web Push Pages
| Page File | Description | Access URL |
|-----------|-------------|------------|
| push.html | Browser screen capture push (WS-FLV) | http://127.0.0.1/push.html |
| flv_push.html | Local MP4/FLV file loop push | http://127.0.0.1/flv_push.html |
| push_merge.html | Multi-view live compositing push | http://127.0.0.1/push_merge.html |
| push_transcode.html | Frontend multi-bitrate transcoding push for weak networks | http://127.0.0.1/push_transcode.html |

### PHP Built-in Push/Pull Client Scripts
| Script | Function | Example Command |
|--------|----------|-----------------|
| pusher.php | Command-line file push client | `php pusher.php video.mp4 http://127.0.0.1:8501/live/stream` |
| puller.php | Command-line pull and record client | `php puller.php http://127.0.0.1:8501/live/stream.flv output.flv` |

---

## Project Directory Structure
```
rtmp_server/
├── config/                     # Global config: ports, multi-process, recording, push auth
├── flv/                        # Real-time FLV raw stream recording storage
├── mp4/                        # fMP4 segments & complete MP4 merged after stream ends
├── hls/                        # HLS TS segments, m3u8 index files
├── MediaServer/                # RTMP/FLV/WS-FLV core protocol stack, session management
├── Root/                       # Underlying async I/O, Socket event-driven engine
├── record/                     # Client-side static page resources
├── server.php                  # RTMP origin main service entry point
├── flvGateway.php              # FLV live distribution gateway startup script
├── fileGateway.php             # HLS/MP4/static resource HTTP gateway
├── forward.php                 # Live stream forwarding client
├── pusher.php                  # PHP push client
├── puller.php                  # PHP pull client
├── auth_config.php             # Push auth standalone configuration
├── *.html                      # All web push/pull and playback pages
├── docker-compose.yml          # Docker one-click deployment config
└── LICENSE                     # Apache 2.0 open source license file
```

---

## System Architecture
```
                                                    【External Pushers】OBS / FFmpeg / Web
                                                         │
                                   RTMP(1935) / HTTP-FLV/WS-FLV(8501) Push Access
                                                         │
                                                         ▼
╔══════════════════════════════════════════════════════════════════════════════════════╗
║                         RTMP Origin Main Service (Stream Production Core)             ║
║                                                                                      ║
║  📥 Push/Pull Access: RTMP / HTTP-FLV / WS-FLV triple protocol compatible, built-in auth ║
║  🔄 Protocol Transmuxing: Output HTTP-FLV / WS-FLV / HLS / fMP4 / MP4                ║
║  💾 Parallel recording tasks (completely non-blocking, individually toggleable)      ║
║        ┌──────────┬──────────┬──────────┐                                            ║
║        │ FLV raw   │ fMP4 real-│ HLS TS   │                                            ║
║        │ recording │ time     │ segments │                                            ║
║        └──────────┴──────────┴──────────┘                                            ║
║  📤 Real-time stream output: HTTP-FLV, WS-FLV, HLS live streams                      ║
║  📦 VOD artifacts: fMP4 segment cache, automatic complete MP4 merge after stream ends║
║  📁 Built-in static HTTP service (port 80): no extra gateway needed for low concurrency ║
╚══════════════════════════════════════════════════════════════════════════════════════╝
│
┌───────────────────┼───────────────────┐
│                   │                   │
▼                   ▼                   ▼
HTTP-FLV real-time  HLS static segment   fMP4 static segment
│                   │                   │
▼                   ▼                   ▼
┌─────────────┐    ┌──────────────────────────────────────────┐
│ FLV Gateway │    │        Static File Gateway Cluster       │
│ Cluster     │    │    Hosted: HLS/fMP4/MP4/FLV/web static  │
│             │    │                                          │
│ ┌─────────┐ │    │ ┌───────┐ ┌───────┐ ┌───────┐           │
│ │Primary   │ │    │ │GW1    │ │GW2    │ │GW3    │           │
│ │Gateway   │ │    │ │(8100) │ │(8101) │ │(8102) │           │
│ │(8080)    │ │    │ └──┬────┘ └──┬────┘ └──┬────┘           │
│ └───┬─────┘ │    │    │        │        │                 │
│     │       │    │    ▼        ▼        ▼                 │
│ ┌───┴───┐   │    │ ┌──────────────────────────────────┐   │
│ ▼   ▼   ▼   │    │ │End-user Player Clients           │   │
│ ┌─┐ ┌─┐ ┌─┐ │    │ │MSE/HLS Player/ffplay/Browser    │   │
│ │S│ │S│ │S│ │    │ └──────────────────────────────────┘   │
│ │G│ │G│ │G│ │    │                                          │
│ └┬─┘ └┬─┘ └┬─┘ │    └──────────────────────────────────────────┘
│  │    │    │   │
│  ▼    ▼    ▼   │
│ ┌────────────┐ │
│ │Live Viewers│ │
│ │FLV Players │ │
│ └────────────┘ │
└─────────────────┘
```

### Architecture Details
1. **Origin Main Service (Sole Stream Producer)**
   All external push streams uniformly access the origin, completing protocol parsing, authentication, multi-format transmuxing, and parallel recording; FLV recording, fMP4 slicing, and HLS slicing are three completely isolated threads that do not block each other.
   For low-concurrency scenarios, the built-in port 80 static service can be used directly without deploying additional gateways.

2. **FLV Live Distribution Gateway**
   No transcoding logic; only forwards traffic and caches GOP keyframes for instant player startup; supports horizontal scaling and multi-level cascading (production environment recommends at most two levels; more levels increase latency); Linux epoll for high concurrency; Windows is for testing only.
   In high-concurrency scenarios, all player pull requests go through the gateway to reduce connection pressure on the origin main process.

3. **Static File Gateway Cluster**
   Dedicated hosting for HLS, MP4, FLV, frontend pages, and other static resources, achieving read-write separation; must be deployed for large-scale VOD scenarios to prevent the origin from being overwhelmed by file I/O requests.

4. **Integrated Live Streaming Tools**
   This project supports pure PHP client push, pull, and live stream forwarding, as well as web frontend push, playback, transcoding, and compositing. Supports single-process/multi-process switching, as well as the personalized media resource toolkit `xiaosongshu/flv2mp4`.

### Deployment Recommendations by Concurrency Level
| Concurrency Level | Recommended Deployment |
|-------------------|------------------------|
| Low (<1000 concurrent viewers) | Start only the origin `server.php` with built-in ports 80 and 8501; no gateways needed |
| Medium (1000 ~ 5000 viewers) | Origin + single-layer FLV gateway cluster + single-layer static file gateway cluster; Nginx load balancing |
| High/Large-scale events (>5000 viewers) | Origin + multi-layer FLV gateway and static gateway clusters with front-end load balancing; events with tens of thousands of viewers must use commercial CDN edge distribution; do not let a single server carry all traffic |

---

## Port Constants Configuration
Modify `config/app.php` to adjust global service ports; built-in constants:
```php
/** HTTP-FLV / WebSocket-FLV main service port */
define('BASE_FLV_PORT', 8501);
/** RTMP standard 1935 port */
define('BASE_RTMP_PORT', 1935);
/** Built-in static web page and VOD HTTP port */
define('BASE_WEB_PORT', 80);
```

## Recording Task Switches
`config/app.php` provides independent control over three recording tasks without interference:
```php
define('FLV_TO_RECORD', true);   // Enable real-time raw FLV stream recording
define('FLV_TO_MP4', true);      // Enable fMP4 segmentation, auto-merge complete MP4 after stream ends
define('FLV_TO_HLS', true);      // Enable HLS TS segment generation
```

## Multi-Process Worker Configuration (IPC Stream Sync Core)
### Principle
Under the PHP CLI multi-process model, each Worker process has isolated memory; when a single process receives a push stream, other Workers cannot access the stream data. Therefore, **stream synchronization must be achieved via IPC (Inter-Process Communication)**.
This project does not use traditional system IPC such as shared memory or pipes. Instead, it implements a custom local TCP Socket IPC solution: allocates a set of internal communication ports, and the receiving Worker actively forwards the complete stream data to all other Workers via a built-in TCP client, enabling full stream data sharing across all processes.

### Configuration Code `config/app.php`
```php
/** Master switch: enable multi-process Worker mode */
define('ENABLE_MULTI_PROCESS', true);
/** Number of Worker processes; recommended not to exceed server CPU physical cores */
define('WORKER_COUNT', 3);
/** Starting value for inter-process TCP communication ports, auto-allocated sequentially 8502, 8503... */
define('COPY_PORT_START', 8502);
```
> When multi-process is disabled (`ENABLE_MULTI_PROCESS=false`), the process count and internal communication port configuration become ineffective; the service runs in single-process mode without IPC stream synchronization.

### Multi-Process Port Load Balancing Rules
1. Linux: System supports port reuse; multiple Workers can simultaneously listen on the main FLV port 8501; the kernel automatically distributes player connections evenly across Workers;
2. Windows: Although the system supports `SO_REUSEADDR` port reuse, new TCP connections are only ever assigned to the first process that binds to port 8501; native load balancing is not possible. Nginx reverse proxy can be used to distribute traffic across internal communication ports (8502+);
3. Internal IPC ports are externally accessible for pulling streams, enabling manual load balancing on Windows.

### Platform Performance Limitations
- Linux: epoll I/O model; a single process supports thousands of concurrent long connections; multi-process can fully utilize multi-core CPUs; the preferred choice for production;
- Windows: Underlying select model has extremely low concurrency limits (~256 connections per process); intended only for local development and testing; do not deploy in production.

## Push Stream Authentication Configuration
### Description
Prevents unauthorized streams from overwriting live channels; only push requests carrying a valid stream key are allowed; players do not require authentication for pulling.
Configuration file `config/auth.php`
```php
<?php
return [
    'enabled' => false, // Auth master switch
    'publish' => [
        'require_auth' => true, // Force key verification for push streams
        'stream_keys' => [
            'live_123456',
            'stream_key_abc',
        ],
    ],
    'global' => [
        'allowed_apps' => ['live'], // Allowed application names
        'deny_apps' => [],
    ],
];
```

### Authenticated Push Address Format
Carry the key via URL parameter `key`:
1. RTMP
```bash
ffmpeg -re -i video.mp4 -f flv rtmp://127.0.0.1:1935/live/stream?key=live_123456
```
2. OBS Stream Key: `stream?key=live_123456`
3. HTTP-FLV
```bash
ffmpeg -re -i video.mp4 -f flv http://127.0.0.1:8501/live/stream?key=live_123456
```
4. WS-FLV PHP Client
```bash
php pusher.php test.flv "ws://127.0.0.1:8501/live/stream?key=live_123456"
```

### Security Best Practices
1. Replace default keys with random strings longer than 32 characters;
2. Enable HTTPS/WSS in public network deployments to prevent plaintext key sniffing;
3. Rotate stream keys regularly to reduce leakage risk.
4. Authentication is disabled by default; enable it manually if needed.

## FLV Live Distribution Gateway
### Overview
A lightweight traffic forwarding service that pulls HTTP-FLV/WS-FLV streams from upstream origins and caches GOP keyframes for instant player startup; supports horizontal scaling and multi-level cascading to offload origin concurrency pressure.

### Startup Commands
```bash
# Basic single instance
php flvGateway.php 8080 http://127.0.0.1:8501
php flvGateway.php 8080 ws://127.0.0.1:8501

# Horizontal scaling with multiple instances on same layer
php flvGateway.php 8080 http://127.0.0.1:8501
php flvGateway.php 8081 http://127.0.0.1:8501
php flvGateway.php 8082 ws://127.0.0.1:8501

# Multi-level cascading (not recommended to exceed two levels)
php flvGateway.php 8080 http://127.0.0.1:8501    # Level 1 gateway
php flvGateway.php 8081 http://127.0.0.1:8080     # Level 2 gateway

# Linux background silent run
php flvGateway.php 8080 http://127.0.0.1:8501 > /dev/null 2>&1 &
```

### Gateway Playback Address Format
```
http://GatewayIP:Port/{app}/{stream}.flv
ws://GatewayIP:Port/{app}/{stream}.flv
```
Example: `http://127.0.0.1:8080/live/stream.flv`

## Static File HTTP Gateway
### Overview
An independent static resource HTTP service that hosts HLS, MP4, FLV, and frontend pages, separating file I/O from live streaming business to improve VOD stability under high concurrency.

### Startup Commands
```bash
# Single instance
php fileGateway.php 0.0.0.0 8100

# Multiple instances for horizontal scaling
php fileGateway.php 0.0.0.0 8100
php fileGateway.php 0.0.0.0 8101
php fileGateway.php 0.0.0.0 8102

# Linux background run
php fileGateway.php 0.0.0.0 8100 > /dev/null 2>&1 &
```

### Nginx Load Balancing Reverse Proxy Example
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

### Resource Access URL Examples
```
http://127.0.0.1:8100/index.html
http://127.0.0.1:8100/hls/live/stream/index.m3u8
http://127.0.0.1:8100/mp4/live/stream/output_merge/stream_full.mp4
```

## Multi-Way Push/Pull Stream Integration Guide
### RTMP Push
OBS, FFmpeg, and PHP clients all support the standard RTMP protocol; address format: `rtmp://host:1935/{app}/{stream}`

### HTTP-FLV Push
Suitable for command-line and programmatic automated pushing; address: `http://host:8501/{app}/{stream}`

### WebSocket-FLV Push
A browser-native push solution with latency as low as 50ms; use the built-in `push.html` page.

### PHP Pull Script
Used for server-side backup pulling and cross-server forwarding:
```bash
php puller.php http://127.0.0.1:8501/live/stream.flv output.flv
php puller.php ws://127.0.0.1:8501/live/stream.flv output.flv
```

## Live Stream Forwarding Guide
This project provides live stream forwarding functionality, allowing forwarding to multiple destination servers with support for `rtmp/ws-flv/http-flv` protocols. See `forward.php` for detailed commands; example forwarding command:
```bash
php forward.php http://127.0.0.1:8501/a/b.flv "rtmp://127.0.0.1:1935/c/d,ws://127.0.0.1:8501/c/e,http://127.0.0.1:8501/c/f" 
```
The above command forwards the live stream `http://127.0.0.1:8501/a/b.flv` to `rtmp://127.0.0.1:1935/c/d`, `ws://127.0.0.1:8501/c/e`, and `http://127.0.0.1:8501/c/f`. Of course, you can also forward to any other platform that supports rtmp, ws-flv, or http-flv.

### Engineering Recommendations
`pusher.php` / `puller.php` can be integrated into backend scheduled tasks to implement automated pull-forwarding and backup recording without relying on third-party tools, completing a full PHP live streaming business loop.

---

## Cluster Deployment Architecture for 100,000+ Concurrent Connections

```
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│                              【Layer 1: Multi-Streamer Push Layer】                    │
│                                                                                         │
│   Streamer A (OBS/Web/FFmpeg)   Streamer B (OBS/Web/FFmpeg)  Streamer N (OBS/Web/FFmpeg)│
│            │                              │                             │               │
│      ┌─────┼─────┐                 ┌─────┼─────┐                ┌─────┼─────┐        │
│      ▼     ▼     ▼                 ▼     ▼     ▼                ▼     ▼     ▼        │
│    [Node1][Node2][Node3]         [Node1][Node2][Node3]        [Node1][Node2][Node3] │
│        (Push to multiple origin nodes simultaneously for streamer-side failover)     │
└─────────────────────────────────────────────────────────────────────────────────────────┘
                                          │
                                          │ RTMP/HTTP‑FLV/WS‑FLV ingest
                                          ▼
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│                        【Layer 2: Origin Node Cluster (Stream Production Core)】        │
│                                                                                         │
│    ┌─────────────┐   ┌─────────────┐   ┌─────────────┐   ┌─────────────┐             │
│    │ Origin Node A│   │ Origin Node B│   │ Origin Node C│   │ Origin Node D│             │
│    │ server.php  │   │ server.php  │   │ server.php  │   │ server.php  │             │
│    │ (multi‑proc)│   │ (multi‑proc)│   │ (multi‑proc)│   │ (multi‑proc)│             │
│    │ rec/segment │   │ rec/segment │   │ rec/segment │   │ rec/segment │             │
│    └─────┬───────┘   └─────┬───────┘   └─────┬───────┘   └─────┬───────┘             │
│          │                 │                 │                 │                      │
│          └────────┬────────┴─────────────────┴────────┬────────┘                      │
│                   │                                   │                               │
│              ┌────▼────┐                         ┌────▼────┐                          │
│              │ forward │                         │ forward │  ← automatic stream sync │
│              │ sync    │                         │ sync    │    (pull → push)        │
│              └────┬────┘                         └────┬────┘                          │
│                   └──────────────┬────────────────────┘                               │
│                                  │                                                    │
│                    (All origin nodes back each other up; if any fails, others continue)│
└──────────────────────────────────┼────────────────────────────────────────────────────┘
                                   │
                                   │ forward pulls (from origin, pushes to edge)
                                   ▼
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│                       【Layer 3: Edge Node Cluster (Distribution & Caching)】           │
│                                                                                         │
│    ┌─────────────┐   ┌─────────────┐   ┌─────────────┐   ┌─────────────┐             │
│    │ Edge Node X │   │ Edge Node Y │   │ Edge Node Z │   │ Edge Node W │             │
│    │ server.php  │   │ server.php  │   │ server.php  │   │ server.php  │             │
│    │ (multi‑proc)│   │ (multi‑proc)│   │ (multi‑proc)│   │ (multi‑proc)│             │
│    │ rec/segment │   │ rec/segment │   │ rec/segment │   │ rec/segment │             │
│    └─────┬───────┘   └─────┬───────┘   └─────┬───────┘   └─────┬───────┘             │
│          │                 │                 │                 │                      │
│          └────────┬────────┴─────────────────┴────────┬────────┘                      │
│                   │                                   │                               │
│              ┌────▼────┐                         ┌────▼────┐                          │
│              │ forward │                         │ forward │  ← pulls from origin,     │
│              │ sync    │                         │ sync    │    caches GOP            │
│              └─────────┘                         └─────────┘                          │
│                                                                                         │
│  ★ Dynamic role switching: any node can be promoted to origin (accept pushes) or       │
│    demoted to edge (only pull and distribute) on demand.                               │
│  ★ All nodes record independently, providing multiple backup copies for reliability.   │
└──────────────────────────────────┼────────────────────────────────────────────────────┘
                                   │
                     ┌─────────────┴─────────────┐
                     │                           │
                     ▼                           ▼
┌────────────────────────────┐ ┌────────────────────────────┐
│      【Layer 4: Gateway Layer】 │      【Layer 4: Gateway Layer】 │
│                            │ │                            │
│    flvGateway Cluster      │ │   fileGateway Cluster      │
│  ┌─────┐ ┌─────┐ ┌─────┐ │ │  ┌─────┐ ┌─────┐ ┌─────┐ │
│  │ GW1 │ │ GW2 │ │ GW3 │ │ │  │ GW1 │ │ GW2 │ │ GW3 │ │
│  └──┬──┘ └──┬──┘ └──┬──┘ │ │  └──┬──┘ └──┬──┘ └──┬──┘ │
│     │       │       │     │ │     │       │       │     │
│     └───────┼───────┘     │ │     └───────┼───────┘     │
│             │             │ │             │             │
│   (HTTP‑FLV/WS‑FLV)       │ │ (HLS/MP4/FLV VOD & static)│
└─────────────┼─────────────┘ └─────────────┼─────────────┘
              │                             │
              └─────────────┬───────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│                            【Layer 5: Viewer Terminals】                                │
│                                                                                         │
│   PC Browser (MSE/FLV.js)   Mobile (HLS)   ffplay/pro players   WebSocket players     │
│                                                                                         │
│   ★ Viewers connect to the nearest edge gateway; load balancing (DNS/Nginx) routes     │
│     them to the optimal node automatically.                                           │
└─────────────────────────────────────────────────────────────────────────────────────────┘
```

---

### Core Architecture Design Notes

#### 1. Streamer‑Side High Availability (Disaster Recovery)
- **Multi‑destination pushing**: Streamers can push to multiple origin nodes (e.g., nodes A, B, C) simultaneously. If any origin node fails, other nodes still hold the stream, and viewers experience no interruption.
- **Automatic failover at the streamer**: OBS/FFmpeg can be configured with backup push URLs for primary‑backup switching; web clients can use JavaScript to push to multiple destinations.

#### 2. Origin Node Cluster (Stream Production Core)
- **Active‑active deployment**: All origin nodes are active and capable of accepting pushes. They synchronise stream data with each other via `forward.php`, ensuring every origin node holds a complete stream copy.
- **Automatic failover**: If any origin node goes down, the remaining nodes continue to serve, and the forwarding sync links automatically reconnect without service interruption.
- **Parallel recording**: Each origin node independently performs FLV/fMP4/HLS recording, creating multiple physical backups to prevent single‑point storage loss.

#### 3. Edge Node Cluster (Distribution and Caching)
- **Latency‑aware pulling**: Edge nodes pull live streams from origin nodes via `forward.php` and cache GOP keyframes, enabling low‑latency, instant‑start playback for viewers.
- **Elastic scaling**: Edge nodes can be dynamically added or removed based on concurrent load, supporting horizontal scaling (e.g., for traffic spikes).
- **Flexible role switching**: Origin and edge nodes share the same codebase. Through configuration adjustments, any node can be promoted to an origin (accepting pushes) or demoted to an edge (only pulling and distributing), allowing on‑demand resource allocation.

#### 4. Gateway Distribution Layer
- **flvGateway Cluster**: Dedicated to HTTP‑FLV/WS‑FLV real‑time streams. It performs no transcoding, only pure forwarding, and leverages GOP caching for instant start. Supports multi‑level cascading and horizontal scaling to handle massive player connections.
- **fileGateway Cluster**: Independently hosts HLS segments, MP4 VOD files, static pages, and other resources. Separation from dynamic stream traffic prevents file I/O from blocking live streaming services.

#### 5. Viewer Terminals
- **Multi‑protocol support**: RTMP, HTTP‑FLV, WS‑FLV, and HLS are all supported, covering PC, mobile, and web platforms.
- **Intelligent routing**: Via DNS round‑robin, Nginx reverse proxy, or Global Server Load Balancing (GSLB), viewer requests are directed to the nearest or least‑loaded edge node for optimal experience.

#### 6. Data Flow
1. **Push**: Streamer → (multi‑path) → origin node cluster → `forward` synchronises to all origin nodes.
2. **Pull (edge)**: Edge nodes → `forward` pulls from any origin node → caches → serves local viewers.
3. **Playback**: Viewers → load balancer → flvGateway/fileGateway → edge node (or origin) → receives stream data.
4. **Recording**: All nodes (origin and edge) perform recording according to configuration; final merged MP4 files are generated for VOD playback.

#### 7. Disaster Recovery and Backup Mechanisms
- **Node‑level failover**: If any single node (origin or edge) fails, the forwarding clients automatically reconnect to other alive nodes; stream data is not interrupted.
- **Regional failover**: If an entire data centre goes down, DNS can switch to a standby data centre (requiring multiple cluster deployments), enabling cross‑region high availability.
- **Recording redundancy**: Each node stores its recordings independently; for important live events, multiple nodes can be selected to record simultaneously to guarantee data integrity.

#### 8. Scalability and Concurrency Capacity
- **Horizontal scaling**: Every layer supports adding more nodes to distribute load without restarting existing services.
- **100K+ concurrency**: Edge nodes and the gateway layer can scale out massively. Combined with CDN edge acceleration, the system can support 100,000+ concurrent viewers (provided sufficient bandwidth and server resources).
- **Performance optimisation**: On Linux, the `event` extension (epoll) drives each node to handle thousands of persistent connections (depending on server specifications); the multi‑node cluster increases concurrency linearly.

#### 9. Deployment Recommendations
- Data synchronisation between nodes is accomplished via the built‑in `forward.php` relay client. This tool can pull streams from any source (RTMP/HTTP‑FLV/WS‑FLV) and push them to one or more target nodes simultaneously, and it supports carrying authentication parameters (e.g., `key`) during push. Developers can write scheduling scripts based on actual network topology and business requirements—for example, combining health checks, load balancing policies, or business rules—to dynamically configure pull source addresses, target node lists, and forwarding parameters, thus achieving automated stream synchronisation across nodes.
- The role switching between origin and edge nodes also relies on external scheduling logic. It is recommended to monitor node system status (e.g., CPU load, memory usage, active connections, push stream count, etc.) or external traffic distribution policies, and trigger scripts to adjust node roles dynamically, thereby enabling elastic scaling, failover, and disaster recovery switching. The entire scheduling system can be customised for different scenarios, providing a highly flexible production‑grade deployment solution.

---

## FAQ
### Q1: Missing event extension on Windows startup?
Windows does not have the event extension; the service automatically switches to the select I/O model. Only the `sockets` extension is required for normal operation; no additional handling is needed.

### Q2: How to verify the service started successfully?
Three listening logs in the terminal output indicate success: RTMP 1935, FLV 8501, and static port 80.

### Q3: Push succeeds but playback is persistently laggy?
1. Push bitrate or resolution is too high; reduce bitrate/frame rate for testing;
2. Server CPU is fully loaded; enable multi-process to leverage multiple cores;
3. No FLV gateway deployed under high concurrency; excessive player connections consume origin resources;
4. Insufficient server upstream bandwidth; limit concurrent viewer count.

### Q4: How to stop the service?
Press `Ctrl + C` in the terminal to send a termination signal, or simply close the terminal window.

### Q5: Which third-party push software is supported?
Fully compatible with standard RTMP clients: OBS Studio, FFmpeg, xSplit, and mobile RTMP push SDKs.

## Open Source License
This project is licensed under the **Apache License 2.0**.
The software is provided "as is", without any express or implied warranties. The developers are not liable for any direct, indirect, or consequential damages arising from the use of this program. For the full terms, see the `LICENSE` file in the project root directory.

## Companion Toolkit
The underlying codec and transmuxing capabilities are extracted as an independent toolkit: [xiaosongshu/flv2mp4](https://github.com/2723659854/flv2mp4)
Provides FLV/MP4/fMP4/HLS conversions, standalone push/pull clients, and gateway components; can be independently imported into third-party PHP projects.

## Contact
- Email: 2723659854@qq.com
- GitHub: https://github.com/2723659854