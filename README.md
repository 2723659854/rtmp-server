# RTMP Server
<p align="center">
  <a href="./README.cn.md"><strong>🇨🇳 中文文档</strong></a> •
  <a href="./README.md"><strong>🇬🇧 English Docs</strong></a>
</p>

> A pure PHP self-developed lightweight RTMP live streaming service, **zero dependence on third-party streaming media tools such as FFmpeg and Nginx**, enabling rapid setup of a private live streaming platform out of the box.
> On Linux, the `event` extension is automatically enabled for epoll event-driven I/O; on Windows, it automatically falls back to the select I/O model, ensuring full platform compatibility.
> **Project positioning: underlying infrastructure** – fully self-developed RTMP/HTTP-FLV/WS-FLV protocol stacks and asynchronous networking engine; upper-layer applications such as business management, permissions, and playback management need to be extended and developed by developers themselves.

---
## Table of Contents (Revised)

- [Environment Dependencies](#environment-dependencies)
- [Quick Start](#quick-start)
- [Push/Pull Stream Address Specification](#pushpull-stream-address-specification)
- [Live & VoD Access URLs](#live--vod-access-urls)
- [Page/Script Usage Instructions](#pagescript-usage-instructions)
- [Project Directory Structure](#project-directory-structure)
- [Overall System Architecture](#overall-system-architecture)
- [Port Constant Configuration](#port-constant-configuration)
- [Recording Task Switch Configuration](#recording-task-switch-configuration)
- [Multi‑Process Worker Configuration (IPC Stream Sync Core)](#multi-process-worker-configuration-ipc-stream-sync-core)
- [Push Stream Authentication Configuration](#push-stream-authentication-configuration)
- [FLV Live Distribution Gateway](#flv-live-distribution-gateway)
- [Static File HTTP Gateway](#static-file-http-gateway)
- [Tutorials for Multiple Push/Pull Methods](#tutorials-for-multiple-pushpull-methods)
- [Live Stream Forwarding Tutorial](#live-stream-forwarding-tutorial)
- [Cluster Deployment Architecture for 100,000+ Concurrent Connections](#cluster-deployment-architecture-for-100000-concurrent-connections)
- [FAQ](#faq)
- [Open Source License](#open-source-license)
- [Affiliated Toolkits](#affiliated-toolkits)
- [Contact](#contact)
---

## Environment Dependencies
| Dependency | Mandatory Requirements |
|------------|------------------------|
| PHP | >= 8.1, CLI mode only, FPM not supported |
| sockets extension | **Strictly required**, foundation for TCP/WS/RTMP communication |
| event extension | Strongly recommended on Linux to enable epoll high‑concurrency event model; on Windows, if missing, it automatically falls back to select |

> Quick environment setup: the project includes a `docker-compose.yml` file. Run `docker-compose up -d` to start a complete runtime environment with one command.

---

## Quick Start
### 1. Project Installation
```bash
composer create-project xiaosongshu/rtmp_server
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
#### Method 1: Browser‑based push without extra software
- Screen real‑time push: `http://127.0.0.1/push.html`
- Local MP4/FLV file loop push: `http://127.0.0.1/flv_push.html`

#### Method 2: Standard FFmpeg push
```bash
ffmpeg -re -stream_loop -1 -i video.mp4 -c:v libx264 -c:a aac -f flv rtmp://127.0.0.1:1935/live/stream
```

#### Method 3: OBS Studio push
- Server: `rtmp://127.0.0.1:1935/live/`
- Stream Key: `stream`

#### Method 4: Built‑in PHP push client
```bash
php pusher.php test.mp4 http://127.0.0.1:8501/live/stream
```

### 4. Quick Live Viewing
Open in browser: `http://127.0.0.1/index.html`

---

## Push/Pull Stream Address Specification
### Push Addresses (OBS/FFmpeg/PHP/Web unified format)
| Protocol | Standard Format | Example Address |
|----------|-----------------|------------------|
| RTMP | `rtmp://host:1935/{app}/{stream}` | `rtmp://127.0.0.1:1935/live/stream` |
| HTTP-FLV | `http://host:8501/{app}/{stream}` | `http://127.0.0.1:8501/live/stream` |
| WebSocket-FLV | `ws://host:8501/{app}/{stream}` | `ws://127.0.0.1:8501/live/stream` |

> Field constraints: `{app}` (application name) and `{stream}` (channel name) may only contain English letters, digits, and underscores; special characters and Chinese are prohibited.

### Live & VoD Access URLs
#### Live Playback Addresses
| Protocol | Access URL | Use Case |
|----------|------------|----------|
| RTMP | `rtmp://127.0.0.1:1935/live/stream` | ffplay, desktop professional players |
| HTTP-FLV | `http://127.0.0.1:8501/live/stream.flv` | Low‑latency PC browser playback |
| WebSocket-FLV | `ws://127.0.0.1:8501/live/stream.flv` | Browser native WebSocket MSE playback |
| HLS-TS | `http://127.0.0.1:80/hls/live/stream/index.m3u8` | Mobile devices, WeChat built‑in browser |
| HLS-FMP4 (muxed audio/video) | `http://127.0.0.1:80/mp4/live/stream/output_merge/index.m3u8` | Mainstream desktop browsers, mobile devices, WeChat built‑in browser, ffplay, VLC, etc. |
| HLS-FMP4 (demuxed audio/video) | `http://127.0.0.1:80/mp4/live/stream/output_separate/index.m3u8` | Mainstream desktop browsers, mobile devices, WeChat built‑in browser, ffplay, VLC, etc. |

#### Recorded VoD Playback Addresses
Recorded files are persistently stored in the project root. After the live stream ends, the complete file is automatically generated:

| File Type | Storage Path | Access Example |
|-----------|--------------|----------------|
| Standard transcoded MP4 | `mp4/live/stream/index.mp4` | `http://127.0.0.1/mp4/live/stream/index.mp4` |
| Raw FLV recording | `flv/live/stream/index.flv` | `http://127.0.0.1/flv/live/stream/index.flv` |
| HLS TS segments directory | `hls/live/stream/index.m3u8` | `http://127.0.0.1:80/hls/live/stream/index.m3u8` |
| HLS-FMP4 muxed audio/video segments | `mp4/live/stream/output_merge/index.m3u8` | `http://127.0.0.1:80/mp4/live/stream/output_merge/index.m3u8` |
| HLS-FMP4 demuxed audio/video segments | `mp4/live/stream/output_separate/index.m3u8` | `http://127.0.0.1:80/mp4/live/stream/output_separate/index.m3u8` |

Note: Standard MP4 files are generated only when multi‑process FLV recording is enabled and the FLV file is automatically transcoded to standard MP4 after recording. Alternatively, you can use the toolkit `xiaosongshu/flv2mp4` to manually transcode FLV to MP4.

---

## Page/Script Usage Instructions
### Live/VoD Playback Pages
| Page File | Description | Access URL |
|-----------|-------------|------------|
| index.html | HTTP‑FLV low‑latency live player | http://127.0.0.1/index.html |
| play.html | HLS mobile‑optimized player | http://127.0.0.1/play.html |
| mp4.html | MP4 VoD dedicated page | http://127.0.0.1/mp4.html |
| video.html | FLV VoD player | http://127.0.0.1/video.html |
| play_merge.html | fMP4 segmented live/VoD page (native JS) | http://127.0.0.1/play_merge.html |
| mse.html | fMP4 segmented live/VoD page (hls.js) | http://127.0.0.1/mse.html |

### Web‑Based Push Pages
| Page File | Description | Access URL |
|-----------|-------------|------------|
| push.html | Browser screen capture push (WS‑FLV) | http://127.0.0.1/push.html |
| flv_push.html | Local MP4/FLV file loop push | http://127.0.0.1/flv_push.html |
| push_merge.html | Multi‑stream composition push | http://127.0.0.1/push_merge.html |
| push_transcode.html | Front‑end multi‑bitrate transcoding push for weak networks | http://127.0.0.1/push_transcode.html |

### PHP Built‑in Push/Pull Client Scripts
| Script | Function | Command Example |
|--------|----------|-----------------|
| pusher.php | Command‑line file push client | `php pusher.php video.mp4 http://127.0.0.1:8501/live/stream` |
| puller.php | Command‑line pull and record client | `php puller.php http://127.0.0.1:8501/live/stream.flv output.flv` |

### PHP Built‑in Relay Client Script
| Script | Function | Command Example |
|--------|----------|-----------------|
| forward.php | Command‑line live stream relay client | `php forward.php ws://127.0.0.1:8501/a/b.flv rtmp://127.0.0.1:1935/c/d` |

### PHP Built‑in Gateway Client Scripts
| Script | Function | Command Example |
|--------|----------|-----------------|
| fileGateway.php | Command‑line file gateway client | `php fileGateway.php 0.0.0.0 8100` |
| flvGateway.php | Command‑line FLV gateway client | `php flvGateway.php 8080 http://127.0.0.1:8501` |

### PHP Live Startup Script
| Script | Function | Command Example |
|--------|----------|-----------------|
| server.php | Start live service from command line | `php server.php` |

---

## Project Directory Structure
```
rtmp_server/
├── config/                     # Global configuration: ports, multi‑process, recording, push authentication
├── flv/                        # Real‑time raw FLV recording storage
├── mp4/                        # fMP4 segments & complete MP4 merged after stream ends
├── hls/                        # HLS TS segments, m3u8 index files
├── MediaServer/                # Core RTMP/FLV/WS‑FLV protocol stacks, session management
├── Root/                       # Low‑level async I/O, socket event‑driven engine
├── record/                     # Client‑side static page resources
├── server.php                  # RTMP origin main service entry
├── flvGateway.php              # FLV live distribution gateway startup script
├── fileGateway.php             # HLS/MP4/static resource HTTP gateway
├── forward.php                 # Live stream relay client
├── pusher.php                  # PHP push client
├── puller.php                  # PHP pull client
├── auth_config.php             # Push authentication standalone config
├── *.html                      # All Web push/pull/playback pages
├── docker-compose.yml          # Docker one‑click deployment config
└── LICENSE                     # Apache 2.0 open source license
```

---

## Overall System Architecture
```
                                         【External Pushers】OBS / FFmpeg / Web
                                                         │
                                   RTMP(1935) / HTTP‑FLV/WS‑FLV(8501) push access
                                                         │
                                                         ▼
╔══════════════════════════════════════════════════════════════════════════════════════╗
║                         RTMP Origin Main Service (Stream Production Core)            ║
║                                                                                      ║
║  📥 Push/Pull Access: RTMP / HTTP‑FLV / WS‑FLV triple‑protocol compatible, with     ║
║     built‑in push authentication                                                    ║
║  🔄 Protocol Transmuxing: Raw stream output to HTTP‑FLV / WS‑FLV / HLS / fMP4 / MP4 ║
║  💾 Parallel recording tasks (completely non‑blocking, individually toggleable)     ║
║        ┌──────────┬──────────┬──────────┐                                          ║
║        │ FLV raw  │ fMP4 real‑│ HLS TS  │                                          ║
║        │ recording│ time     │ segments│                                          ║
║        │          │ segments │          │                                          ║
║        └──────────┴──────────┴──────────┘                                          ║
║  📤 Real‑time stream output: distributes HTTP‑FLV, WS‑FLV, HLS live streams        ║
║  📦 VoD artifacts: fMP4 segment cache, auto‑merge to complete MP4 after stream ends║
║  📁 Built‑in static HTTP service (port 80): provides direct page and VoD file      ║
║     access without additional gateway in low‑concurrency scenarios                 ║
╚══════════════════════════════════════════════════════════════════════════════════════╝
│
┌───────────────────┼───────────────────┐
│                   │                   │
▼                   ▼                   ▼
HTTP‑FLV real‑time  HLS static          fMP4 static
stream              segment files       segment files
│                   │                   │
▼                   ▼                   ▼
┌─────────────┐    ┌──────────────────────────────────────────┐
│ FLV Live    │    │        Static File Gateway Cluster       │
│ Gateway     │    │          (fileGateway)                   │
│ Cluster     │    │     Hosted resources: HLS/fMP4/MP4/FLV/  │
│             │    │     web static assets                    │
│ ┌─────────┐ │    │                                          │
│ │Primary   │ │    │ ┌───────┐ ┌───────┐ ┌───────┐         │
│ │Gateway   │ │    │ │GW 1   │ │GW 2   │ │GW 3   │         │
│ │(port8080)│ │    │ │(8100) │ │(8101) │ │(8102) │         │
│ └───┬─────┘ │    │ └──┬────┘ └──┬────┘ └──┬────┘         │
│     │       │    │    │        │        │                 │
│ ┌───┴───┐   │    │    ▼        ▼        ▼                 │
│ ▼   ▼   ▼   │    │ ┌──────────────────────────────────┐   │
│ ┌─┐ ┌─┐ ┌─┐ │    │ │End‑user player clients           │   │
│ │S│ │S│ │S│ │    │ │MSE/HLS players/ffplay/browsers   │   │
│ │u│ │u│ │u│ │    │ └──────────────────────────────────┘   │
│ │b│ │b│ │b│ │    │                                          │
│ └┬─┘ └┬─┘ └┬─┘ │    └──────────────────────────────────────────┘
│  │    │    │   │
│  ▼    ▼    ▼   │
│ ┌────────────┐ │
│ │Live viewer │ │
│ │FLV players │ │
│ └────────────┘ │
└─────────────────┘
```

### Architecture Detailed Description
1. **Origin Main Service (the sole stream producer)**
   All external pushes are ingested by the origin, where protocol parsing, authentication, multi‑format transmuxing, and parallel recording are performed. The three recording tasks – FLV recording, fMP4 slicing, and HLS slicing – run in completely isolated threads without blocking each other.
   In low‑concurrency scenarios, the built‑in port‑80 static service can be used directly without deploying extra gateways.

2. **FLV Live Distribution Gateway**
   No transcoding logic; it only forwards traffic and caches GOP key frames for instant player start‑up. Supports horizontal scaling and multi‑level cascading (production environment recommended max 2 levels, more levels increase latency). Linux uses epoll for high concurrency; Windows is for testing only.
   In high‑concurrency scenarios, all player pull requests should go through the gateway to relieve connection pressure on the origin main process.

3. **Static File Gateway Cluster**
   Dedicated to hosting HLS, MP4, FLV, front‑end pages and other static resources, achieving read‑write separation. Must be deployed for large‑scale VoD scenarios to prevent the origin from being overwhelmed by file I/O requests.

4. **Integrated Live Tooling**
   This project provides pure PHP client push, pull, and live relay capabilities, as well as web‑based front‑end push, playback, transcoding, and stream mixing. It supports single‑process/multi‑process switching and includes a personalized media toolkit `xiaosongshu/flv2mp4`.

### Deployment Recommendations by Concurrency Level
| Concurrency Level | Recommended Deployment |
|-------------------|------------------------|
| Low (online viewers < 1000) | Run only `server.php` (origin), using built‑in ports 80 and 8501; no gateway needed |
| Medium (1000 ~ 5000 online) | Origin + single‑layer FLV gateway cluster + single‑layer static file gateway cluster, with Nginx load balancing |
| High / Large‑event live (>5000 online) | Origin + multi‑layer FLV and static gateway clusters, front‑end load balancing; for 10,000+ events, must incorporate commercial CDN edge distribution – do not rely on a single server for all traffic |

---

## Port Constant Configuration
Modify `config/app.php` to adjust global service ports. Built‑in constant definitions:
```php
/** HTTP‑FLV / WebSocket‑FLV main service port */
define('BASE_FLV_PORT', 8501);
/** RTMP standard 1935 port */
define('BASE_RTMP_PORT', 1935);
/** Built‑in static web page and VoD file HTTP port */
define('BASE_WEB_PORT', 80);
```

## Recording Task Switch Configuration
In `config/app.php`, three recording tasks are independently controlled without interference:
```php
define('FLV_TO_RECORD', true);   // Enable real‑time raw FLV recording
define('FLV_TO_MP4', true);      // Enable fMP4 segmentation
define('FLV_TO_HLS', true);      // Enable HLS TS segment generation
```

## Multi Process Worker Configuration Ipc Stream Sync Core
### Principle
In the PHP CLI multi‑process model, each worker process has isolated memory. When a single process receives a push stream, other workers cannot read that stream data. Therefore, **IPC (Inter‑Process Communication) is mandatory** to synchronise live streams across processes.
Instead of using traditional system IPC such as shared memory or pipes, this project implements a custom local TCP Socket IPC solution: it allocates a set of internal communication ports. The worker that receives the stream actively replicates the full stream data via a built‑in TCP client and forwards it to all other workers, achieving full stream data sharing among all processes.

### Configuration in `config/app.php`
```php
/** Master switch: enable multi‑process worker mode */
define('ENABLE_MULTI_PROCESS', true);
/** Number of worker processes, recommended not to exceed CPU physical cores */
define('WORKER_COUNT', 3);
/** Starting port for inter‑process TCP communication, automatically assigns 8502, 8503... */
define('COPY_PORT_START', 8502);
```
> When multi‑process is disabled (`ENABLE_MULTI_PROCESS=false`), the number of processes and internal communication ports are ignored, and the service runs in single‑process mode without IPC stream sync.

### Load Balancing Rules for Multi‑Process Ports
1. Linux: the system supports port reuse; multiple workers can simultaneously listen on the main FLV port 8501, and the kernel automatically distributes player connections evenly among workers.
2. Windows: although `SO_REUSEADDR` port reuse is supported, new TCP connections will only be assigned to the first process that bound port 8501, so native load balancing is not possible. You can use Nginx to reverse‑proxy the internal communication ports (8502+) for traffic distribution.
3. Internal IPC ports are externally accessible for pull requests and can be used for manual load balancing on Windows.

### Platform Performance Limitations
- Linux: epoll I/O model supports thousands of concurrent long‑connections per process; multi‑process can fully utilise multi‑core CPUs – the preferred production environment.
- Windows: the underlying select model has a very low concurrency limit (~256 connections per process) – only for local development and debugging, never for production deployment.

## Push Stream Authentication Configuration
### Overview
Prevents unauthorised streams from overriding a live room. Only push requests carrying a valid stream key are allowed. Playback pull currently has no built‑in authentication; developers can implement referer/token validation at the gateway or reverse‑proxy layer.
Configuration file `config/auth.php`
```php
<?php
return [
    'enabled' => false, // Master authentication switch
    'publish' => [
        'require_auth' => true, // Enforce key validation for push
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
Pass the key via URL parameter `key`:
1. RTMP
```bash
ffmpeg -re -i video.mp4 -f flv rtmp://127.0.0.1:1935/live/stream?key=live_123456
```
2. OBS stream key: `stream?key=live_123456`
3. HTTP‑FLV
```bash
ffmpeg -re -i video.mp4 -f flv http://127.0.0.1:8501/live/stream?key=live_123456
```
4. WS‑FLV PHP client
```bash
php pusher.php test.flv "ws://127.0.0.1:8501/live/stream?key=live_123456"
```

### Security Best Practices
1. Replace default keys with random strings of 32 characters or more.
2. In public environments, enable HTTPS/WSS to avoid key interception in plaintext.
3. Rotate stream keys periodically to reduce the risk of leakage.
4. Authentication is disabled by default; enable it if needed.

Note: After modifying any of the above configurations, restart the service for changes to take effect.

---

## FLV Live Distribution Gateway
### Overview
A lightweight traffic forwarding service that pulls HTTP‑FLV/WS‑FLV streams from the upstream origin, caches GOP key frames for instant player start‑up, supports horizontal scaling and multi‑level cascading to share the origin’s concurrent load.
The gateway can pull from either HTTP‑FLV or WS‑FLV sources and provides both HTTP‑FLV and WS‑FLV playback addresses to clients.

### Startup Commands
```bash
# Basic single instance
php flvGateway.php 8080 http://127.0.0.1:8501
php flvGateway.php 8080 ws://127.0.0.1:8501

# Horizontal scaling with multiple instances
php flvGateway.php 8080 http://127.0.0.1:8501
php flvGateway.php 8081 http://127.0.0.1:8501
php flvGateway.php 8082 ws://127.0.0.1:8501

# Multi‑level cascading (not recommended beyond 2 levels)
php flvGateway.php 8080 http://127.0.0.1:8501    # Level‑1 gateway
php flvGateway.php 8081 http://127.0.0.1:8080     # Level‑2 gateway

# Run in background on Linux
php flvGateway.php 8080 http://127.0.0.1:8501 > /dev/null 2>&1 &
```

### Gateway Playback Address Format
```
http://gateway_IP:port/{app}/{stream}.flv
ws://gateway_IP:port/{app}/{stream}.flv
```
Example: `http://127.0.0.1:8080/live/stream.flv`

## Static File HTTP Gateway
### Overview
An independent static resource HTTP service that hosts HLS, MP4, FLV, and front‑end pages, separating file I/O from live streaming traffic to improve stability under high‑concurrency VoD access.

### Startup Commands
```bash
# Single instance
php fileGateway.php 0.0.0.0 8100

# Multiple instances for horizontal scaling
php fileGateway.php 0.0.0.0 8100
php fileGateway.php 0.0.0.0 8101
php fileGateway.php 0.0.0.0 8102

# Run in background on Linux
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

### Resource Access Examples
```
http://127.0.0.1:8100/index.html
http://127.0.0.1:8100/hls/live/stream/index.m3u8
http://127.0.0.1:8100/mp4/live/stream/index.mp4
```

## Tutorials for Multiple Push/Pull Methods
### RTMP Push
OBS, FFmpeg, and the PHP client all support the standard RTMP protocol. Address format: `rtmp://host:1935/{app}/{stream}`

### HTTP‑FLV Push
Ideal for command‑line or automated programmatic pushing. Address: `http://host:8501/{app}/{stream}`

### WebSocket‑FLV Push
A browser‑native push solution with latency as low as 50ms. Use the built‑in `push.html` page.

### PHP Pull Script
Used for server‑side pulling, backup, or cross‑server forwarding:
```bash
php puller.php http://127.0.0.1:8501/live/stream.flv output.flv
php puller.php ws://127.0.0.1:8501/live/stream.flv output.flv
```

## Live Stream Forwarding Tutorial
This project provides live stream forwarding capabilities – you can relay a live stream to multiple servers, supporting RTMP/WS‑FLV/HTTP‑FLV protocols for both pull and push. See `forward.php` for detailed usage. Example forwarding command:
```bash
php forward.php http://127.0.0.1:8501/a/b.flv "rtmp://127.0.0.1:1935/c/d,ws://127.0.0.1:8501/c/e,http://127.0.0.1:8501/c/f" 
```
The above command pulls the stream from `http://127.0.0.1:8501/a/b.flv` and pushes it to `rtmp://127.0.0.1:1935/c/d`, `ws://127.0.0.1:8501/c/e`, and `http://127.0.0.1:8501/c/f`. You can also push to any other platform that supports RTMP, WS‑FLV, or HTTP‑FLV.

### Engineering Recommendations
`pusher.php` / `puller.php` / `forward.php` can be integrated into custom scripts to automate pull‑and‑relay, backup recording, etc., without third‑party tools, completing a full PHP live streaming workflow.

---

## Cluster Deployment Architecture for 100,000+ Concurrent Connections

```
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│                           【Layer 1: Multi‑Broadcaster Push Layer】                     │
│                                                                                         │
│   Broadcaster A (OBS/Web/FFmpeg)  Broadcaster B (OBS/Web/FFmpeg)  Broadcaster N (...)  │
│            │                              │                             │               │
│      ┌─────┼─────┐                 ┌─────┼─────┐                ┌─────┼─────┐        │
│      ▼     ▼     ▼                 ▼     ▼     ▼                ▼     ▼     ▼        │
│    [Node1][Node2][Node3]        [Node1][Node2][Node3]        [Node1][Node2][Node3] │
│     (push to multiple origin nodes simultaneously for push‑side disaster recovery)   │
└─────────────────────────────────────────────────────────────────────────────────────────┘
                                          │
                                          │ RTMP/HTTP‑FLV/WS‑FLV push access
                                          ▼
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│                       【Layer 2: Origin Node Cluster (Stream Production Core)】         │
│                                                                                         │
│    ┌─────────────┐   ┌─────────────┐   ┌─────────────┐   ┌─────────────┐             │
│    │ Origin A    │   │ Origin B    │   │ Origin C    │   │ Origin D    │             │
│    │ server.php  │   │ server.php  │   │ server.php  │   │ server.php  │             │
│    │ (multi‑proc)│   │ (multi‑proc)│   │ (multi‑proc)│   │ (multi‑proc)│             │
│    │ record/seg  │   │ record/seg  │   │ record/seg  │   │ record/seg  │             │
│    └─────┬───────┘   └─────┬───────┘   └─────┬───────┘   └─────┬───────┘             │
│          │                 │                 │                 │                      │
│          └────────┬────────┴─────────────────┴────────┬────────┘                      │
│                   │                                   │                               │
│              ┌────▼────┐                         ┌────▼────┐                          │
│              │ forward │                         │ forward │  ← automatic stream sync │
│              │ sync    │                         │ sync    │    (pull→push)          │
│              └────┬────┘                         └────┬────┘                          │
│                   └──────────────┬────────────────────┘                               │
│                                  │                                                    │
│                (all origin nodes back each other up; if any fails, others continue)   │
└──────────────────────────────────┼────────────────────────────────────────────────────┘
                                   │
                                   │ forward pull (from origin, push to edge)
                                   ▼
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│                       【Layer 3: Edge Node Cluster (Distribution & Cache)】             │
│                                                                                         │
│    ┌─────────────┐   ┌─────────────┐   ┌─────────────┐   ┌─────────────┐             │
│    │ Edge X      │   │ Edge Y      │   │ Edge Z      │   │ Edge W      │             │
│    │ server.php  │   │ server.php  │   │ server.php  │   │ server.php  │             │
│    │ (multi‑proc)│   │ (multi‑proc)│   │ (multi‑proc)│   │ (multi‑proc)│             │
│    │ record/seg  │   │ record/seg  │   │ record/seg  │   │ record/seg  │             │
│    └─────┬───────┘   └─────┬───────┘   └─────┬───────┘   └─────┬───────┘             │
│          │                 │                 │                 │                      │
│          └────────┬────────┴─────────────────┴────────┬────────┘                      │
│                   │                                   │                               │
│              ┌────▼────┐                         ┌────▼────┐                          │
│              │ forward │                         │ forward │  ← auto pull from origin,│
│              │ sync    │                         │ sync    │    cache                  │
│              └─────────┘                         └─────────┘                          │
│                                                                                         │
│  ★ Dynamic role switching: any node can be upgraded to origin (accept push) or         │
│    downgraded to edge at any time                                                      │
│  ★ All nodes independently record, providing multi‑copy backup for high reliability   │
└──────────────────────────────────┼────────────────────────────────────────────────────┘
                                   │
                     ┌─────────────┴─────────────┐
                     │                           │
                     ▼                           ▼
┌────────────────────────────┐ ┌────────────────────────────┐
│   【Layer 4: Gateway Layer】│ │   【Layer 4: Gateway Layer】│
│                            │ │                            │
│    flvGateway Cluster      │ │    fileGateway Cluster     │
│  ┌─────┐ ┌─────┐ ┌─────┐ │ │  ┌─────┐ ┌─────┐ ┌─────┐ │
│  │GW1  │ │GW2  │ │GW3  │ │ │  │GW1  │ │GW2  │ │GW3  │ │
│  └──┬──┘ └──┬──┘ └──┬──┘ │ │  └──┬──┘ └──┬──┘ └──┬──┘ │
│     │       │       │     │ │     │       │       │     │
│     └───────┼───────┘     │ │     └───────┼───────┘     │
│             │             │ │             │             │
│    (HTTP‑FLV/WS‑FLV)      │ │   (HLS/MP4/FLV VoD/static pages)│
└─────────────┼─────────────┘ └─────────────┼─────────────┘
              │                             │
              └─────────────┬───────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│                            【Layer 5: Viewers / End Users】                             │
│                                                                                         │
│   PC browsers (MSE/FLV.js)   Mobile (HLS)   ffplay/pro players   WebSocket players    │
│                                                                                         │
│   ★ Viewers connect to the nearest edge gateway, automatically routed to optimal nodes │
│     via load balancing (DNS or Nginx)                                                  │
└─────────────────────────────────────────────────────────────────────────────────────────┘
```

---

### Core Architectural Explanations

#### 1. Push‑Layer High Availability (Disaster Recovery)
- **Multi‑destination push**: Broadcasters can push simultaneously to multiple origin nodes (e.g., A, B, C). If one node fails, others still hold the stream, and viewers experience no outage.
- **Automatic failover on pusher side**: OBS/FFmpeg can be configured with backup push addresses for primary‑backup switching; Web clients can push to multiple destinations via JavaScript.

#### 2. Origin Node Cluster (Stream Production Core)
- **Active‑active deployment**: All origin nodes are active, can accept pushes, and synchronise stream data with each other via `forward.php`, ensuring every origin node has a complete copy of the stream.
- **Automatic failover**: If any origin node goes down, the others continue to serve, and forwarding sync links automatically reconnect with no service interruption.
- **Parallel recording**: Each origin node independently performs FLV/fMP4/HLS recording, creating multiple physical backups and preventing single‑point storage loss.

#### 3. Edge Node Cluster (Stream Distribution & Cache)
- **Proximity pull**: Edge nodes pull live streams from origin nodes via `forward.php`, caching GOP key frames to provide low‑latency, instant‑start playback for viewers.
- **Elastic scaling**: Add or remove edge nodes dynamically based on concurrency, supporting horizontal scaling (e.g., for traffic spikes).
- **Flexible role switching**: Origin and edge nodes use identical code; you can upgrade an edge node to origin (accept pushes) or downgrade an origin to edge (pull‑only) by configuration, enabling resource allocation on demand.

#### 4. Gateway Distribution Layer
- **flvGateway cluster**: Designed specifically for HTTP‑FLV/WS‑FLV real‑time streams – no transcoding, pure forwarding, with GOP caching for instant start‑up. Supports multi‑level cascading and horizontal scaling to handle massive player connections.
- **fileGateway cluster**: Dedicated to hosting HLS segments, MP4 VoD files, static pages, and other resources, separating file I/O from dynamic stream services to prevent blocking.

#### 5. Viewer Endpoints
- **Multi‑protocol support**: RTMP, HTTP‑FLV, WS‑FLV, HLS – compatible with PC, mobile, Web, and all platforms.
- **Intelligent routing**: Use DNS round‑robin, Nginx reverse proxy, or GSLB to direct viewer requests to the nearest or least‑loaded edge node for optimal experience.

#### 6. Data Flow
1. **Push**: Broadcaster → (multi‑destination) → Origin cluster → `forward` sync to all origin nodes.
2. **Pull (edge)**: Edge nodes → `forward` pull from any origin node → cache → serve local viewers.
3. **Playback**: Viewers → load balancer → flvGateway/fileGateway → edge (or origin) node → receive stream data.
4. **Recording**: All nodes (origin/edge) record according to configuration, eventually merging to MP4 for VoD playback.

#### 7. Disaster Recovery and Backup Mechanisms
- **Node‑level failover**: If any single node (origin or edge) fails, forwarding clients automatically reconnect to other live nodes – stream data continues uninterrupted.
- **Region‑level failover**: If an entire data centre goes down, DNS can switch to a backup data centre (requires multiple clusters) for cross‑region high availability.
- **Recording backup**: Each node stores its recording files independently. For critical live events, multiple nodes can record simultaneously to ensure no data loss.

#### 8. Scalability and Concurrency Capacity
- **Horizontal scaling**: All layers support horizontal scaling – add nodes to share load without restarting existing services.
- **100,000+ concurrency**: Edge nodes and gateway layers can scale out massively; combined with CDN edge acceleration, they can support 100,000+ concurrent viewers (bandwidth and server resources permitting).
- **Performance optimisation**: On Linux, the event extension (epoll) drives each node to handle thousands of long connections (depending on server specs); multiple nodes linearly increase overall concurrency capacity.

#### 9. Deployment Recommendations
- Stream synchronisation between nodes is accomplished by the built‑in `forward.php` relay client. This tool can pull RTMP/HTTP‑FLV/WS‑FLV streams from any source and simultaneously push to one or more target nodes, while also supporting authentication parameters (e.g., key) in the push. Developers can write scheduling scripts based on actual network topology and business needs (e.g., health checks, load balancing strategies, or business rules) to dynamically configure pull sources, target node lists, and forwarding parameters, thus automating stream synchronisation among nodes. Role switching between origin and edge nodes similarly relies on external scheduling logic: it is recommended to monitor node system status (CPU load, memory usage, active connections, number of pushed streams, etc.) or external traffic distribution policies, and trigger scripts to dynamically adjust node roles, enabling elastic scaling, failover, and disaster recovery. The entire scheduling system can be customised to fit real‑world scenarios, providing a highly flexible production‑grade deployment solution.

---

## FAQ
### Q1: Missing event extension on Windows?
Windows does not have the event extension; the service automatically falls back to the select I/O model. Only the `sockets` extension is required and it will run normally – no extra steps needed.

### Q2: How can I confirm the service started successfully?
Three listening logs in the terminal indicate success: RTMP on 1935, FLV on 8501, and static HTTP on port 80.

### Q3: Push succeeds but playback stutters?
1. The push bitrate or resolution is too high – try lowering bitrate/frame rate.
2. Server CPU is saturated – enable multi‑process to utilise multiple cores.
3. High concurrency without FLV gateway – too many player connections consume origin resources.
4. Insufficient server upload bandwidth – limit concurrent viewers.

### Q4: How do I stop the service?
Press `Ctrl + C` in the terminal to send a termination signal, or simply close the terminal window.

### Q5: Which third‑party push software is supported?
Fully compatible with standard RTMP clients: OBS Studio, FFmpeg, xSplit, mobile RTMP push SDKs.

## Open Source License
This project is licensed under the **Apache License 2.0**.
The software is provided “as is”, without warranty of any kind, express or implied. The developers are not liable for any direct, indirect, or consequential damages arising from the use of this software. The full terms are available in the `LICENSE` file in the project root.

## Affiliated Toolkits
The underlying codec and transmuxing capabilities have been extracted into a separate toolkit: [xiaosongshu/flv2mp4](https://github.com/2723659854/flv2mp4)
It provides conversions among FLV/MP4/fMP4/HLS, standalone push/pull clients, and gateway components, and can be imported into other third‑party PHP projects.

## Contact
- Email: 2723659854@qq.com
- GitHub: https://github.com/2723659854