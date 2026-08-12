# RTMP Server
<p align="center">
  <a href="./README.cn.md"><strong>🇨🇳 中文文档</strong></a> •
  <a href="./README.md"><strong>🇬🇧 English Docs</strong></a>
</p>

> A pure PHP self-developed lightweight RTMP live streaming service, **zero dependency on third-party streaming media tools like FFmpeg and Nginx**, enabling rapid setup of private live streaming platforms out of the box.
> On Linux, the `event` extension is automatically enabled for epoll event-driven I/O; on Windows, it gracefully degrades to the select I/O model, ensuring full platform compatibility.
> **Project positioning: underlying infrastructure** – complete self-developed RTMP/HTTP-FLV/WS-FLV/WEBRTC protocol stacks and asynchronous network engine; business management, authentication, playback, and other upper-layer applications need to be extended by developers.
> The project supports H.264 **decoding** + **scaling** + **watermarking** + **encoding**, and can re-encode FLV, MP4, and HLS at different bitrates to adapt to various network environments and devices.

---
## Table of Contents

- [Environment Dependencies](#environment-dependencies)
- [Quick Start](#quick-start)
- [Push/Pull Stream Address Specification](#pushpull-stream-address-specification)
- [Live & VOD Access URLs](#live--vod-access-urls)
- [Page/Script Usage Guide](#pagescript-usage-guide)
- [Project Directory Structure](#project-directory-structure)
- [System Overall Architecture](#system-overall-architecture)
- [Port Constants Configuration](#port-constants-configuration)
- [Recording Task Switch Configuration](#recording-task-switch-configuration)
- [Multi‑process Worker Configuration (IPC Stream Sync Core)](#multi-process-worker-configuration-ipc-stream-sync-core)
- [Push Stream Authentication Configuration](#push-stream-authentication-configuration)
- [FLV Live Distribution Gateway](#flv-live-distribution-gateway)
- [Static File HTTP Gateway](#static-file-http-gateway)
- [Multi‑method Push/Pull Stream Access Tutorial](#multi-method-pushpull-stream-access-tutorial)
- [Live Relay/Forwarding Tutorial](#live-relayforwarding-tutorial)
- [Cluster Deployment Architecture for 100,000+ Concurrent Users](#cluster-deployment-architecture-for-100000-concurrent-users)
- [Multi‑bitrate Support](#multi-bitrate-support)
- [WEBRTC](#webrtc)
- [FAQ](#faq)
- [License](#license)
- [Companion Toolkits](#companion-toolkits)
- [Contact](#contact)
---

## Environment Dependencies
| Dependency | Hard Requirement Description |
|------------|-------------------------------|
| PHP | >= 8.1, CLI mode only, FPM not supported |
| sockets extension | **Mandatory** – foundation for underlying TCP/WS/RTMP communication |
| event extension | Highly recommended on Linux – enables epoll high‑concurrency event model; on Windows, if missing, it automatically falls back to select |

> Quick environment setup: the project includes a `docker-compose.yml` file; run `docker-compose up -d` to spin up the complete runtime environment with one command.

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

Example successful startup output:
```
[INFO] RTMP Server started on 0.0.0.0:1935
[INFO] HTTP-FLV/WS-FLV Server started on 0.0.0.0:8501
[INFO] HTTP Static Server started on 0.0.0.0:80
```

### 3. Quick Push Stream Test
#### Method 1: Browser‑based push without additional software
- Real‑time screen push: `http://127.0.0.1/push.html`
- Loop push of local MP4/FLV files: `http://127.0.0.1/flv_push.html`

#### Method 2: FFmpeg standard push
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

### 4. Quick Playback
Open in browser: `http://127.0.0.1/index.html`

---

## Push/Pull Stream Address Specification
### Push URLs (Unified for OBS/FFmpeg/PHP/Web)
| Protocol | Standard Format | Example |
|----------|----------------|---------|
| RTMP | `rtmp://host:1935/{app}/{stream}` | `rtmp://127.0.0.1:1935/live/stream` |
| HTTP-FLV | `http://host:8501/{app}/{stream}` | `http://127.0.0.1:8501/live/stream` |
| WebSocket-FLV | `ws://host:8501/{app}/{stream}` | `ws://127.0.0.1:8501/live/stream` |

> Field constraints: `{app}` (application name) and `{stream}` (channel name) allow only English letters, digits, and underscores; special characters and Chinese are prohibited.

### Live & VOD Access URLs
#### Real‑time Live Playback URLs
| Protocol | Access URL | Use Case |
|----------|------------|----------|
| RTMP | `rtmp://127.0.0.1:1935/live/stream` | ffplay, desktop professional players |
| HTTP-FLV | `http://127.0.0.1:8501/live/stream.flv` | Low‑latency live on PC browsers |
| WebSocket-FLV | `ws://127.0.0.1:8501/live/stream.flv` | Native WebSocket MSE playback in browsers |
| HLS-TS | `http://127.0.0.1:80/hls/live/stream/index.m3u8` | Mobile devices, WeChat built‑in browser |
| HLS-FMP4 (audio‑video merged segments) | `http://127.0.0.1:80/mp4/live/stream/output_merge/index.m3u8` | Mainstream desktop browsers, mobile, WeChat built‑in browser, ffplay, VLC, etc. |
| HLS-FMP4 (audio‑video separate segments) | `http://127.0.0.1:80/mp4/live/stream/output_separate/index.m3u8` | Mainstream desktop browsers, mobile, WeChat built‑in browser, ffplay, VLC, etc. |

#### Recorded VOD Playback URLs
Recorded files are persistently stored under the project root; complete files are automatically generated after the live stream ends:

| File Type | Storage Path | Access Example |
|-----------|--------------|----------------|
| Standard transcoded MP4 | `mp4/live/stream/index.mp4` | `http://127.0.0.1/mp4/live/stream/index.mp4` |
| Raw FLV recording | `flv/live/stream/index.flv` | `http://127.0.0.1/flv/live/stream/index.flv` |
| HLS TS segment directory | `hls/live/stream/index.m3u8` | `http://127.0.0.1:80/hls/live/stream/index.m3u8` |
| HLS-FMP4 audio‑video merged segments | `mp4/live/stream/output_merge/index.m3u8` | `http://127.0.0.1:80/mp4/live/stream/output_merge/index.m3u8` |
| HLS-FMP4 audio‑video separate segments | `mp4/live/stream/output_separate/index.m3u8` | `http://127.0.0.1:80/mp4/live/stream/output_separate/index.m3u8` |

Note: The standard MP4 file is automatically generated only when FLV recording is enabled under multi‑process mode. You can also manually convert FLV to MP4 using the toolkit `xiaosongshu/flv2mp4`.

---

## Page/Script Usage Guide
### Live/VOD Playback Pages
| Page File | Description | Access URL |
|-----------|-------------|------------|
| index.html | HTTP-FLV low‑latency live player | http://127.0.0.1/index.html |
| play.html | HLS mobile‑adapted player | http://127.0.0.1/play.html |
| mp4.html | MP4 VOD dedicated page | http://127.0.0.1/mp4.html |
| video.html | FLV VOD player | http://127.0.0.1/video.html |
| play_merge.html | fMP4 segment live/VOD page (native JS) | http://127.0.0.1/play_merge.html |
| mse.html | fMP4 segment live/VOD page (hls.js) | http://127.0.0.1/mse.html |

### Web‑based Push Pages
| Page File | Description | Access URL |
|-----------|-------------|------------|
| push.html | Browser screen capture push (WS-FLV) | http://127.0.0.1/push.html |
| flv_push.html | Loop push of local MP4/FLV files | http://127.0.0.1/flv_push.html |
| push_merge.html | Multi‑stream composition push | http://127.0.0.1/push_merge.html |
| push_transcode.html | Frontend multi‑bitrate transcoding push for weak networks | http://127.0.0.1/push_transcode.html |

### Built‑in PHP Push/Pull Client Scripts
| Script | Function | Example Command |
|--------|----------|-----------------|
| pusher.php | Command‑line file push client | `php pusher.php video.mp4 http://127.0.0.1:8501/live/stream` |
| puller.php | Command‑line pull/record client | `php puller.php http://127.0.0.1:8501/live/stream.flv output.flv` |

### Built‑in PHP Relay Client Scripts
| Script | Function | Example Command |
|--------|----------|-----------------|
| forward.php | Command‑line live data relay client | `php forward.php ws://127.0.0.1:8501/a/b.flv rtmp://127.0.0.1:1935/c/d` |

### Built‑in PHP Gateway Client Scripts
| Script | Function | Example Command |
|--------|----------|-----------------|
| fileGateway.php | Command‑line file gateway client | `php fileGateway.php 0.0.0.0 8100` |
| flvGateway.php | Command‑line FLV gateway client | `php flvGateway.php 8080 http://127.0.0.1:8501` |

### PHP Live Startup Script
| Script | Function | Example Command |
|--------|----------|-----------------|
| server.php | Start live service from command line | `php server.php` |
---

## Project Directory Structure
```
rtmp_server/
├── config/                     # Global config: ports, multi‑process, recording, push auth
├── flv/                        # Real‑time FLV raw stream recording storage
├── mp4/                        # fMP4 segments & merged complete MP4 after stream ends
├── hls/                        # HLS TS segments, m3u8 index files
├── MediaServer/                # RTMP/FLV/WS‑FLV core protocol stack, session management
├── Root/                       # Underlying async I/O, socket event engine
├── record/                     # Client‑side static page assets
├── server.php                  # RTMP origin main service entry
├── flvGateway.php              # FLV live distribution gateway startup script
├── fileGateway.php             # HLS/MP4/static resource HTTP gateway
├── forward.php                 # Live relay client
├── pusher.php                  # PHP push client
├── puller.php                  # PHP pull client
├── encode.php                  # FLV to HLS multi‑bitrate client
├── watermark.php               # Watermark generation tool
├── webrtc.php                  # WebRTC startup file
├── webrtc                      # WebRTC push/play pages
├── *.html                      # All web push/pull/play pages
├── docker-compose.yml          # Docker one‑click deployment config
└── LICENSE                     # Apache 2.0 license file
```

---

## System Overall Architecture
```
                                                    【External Pushers】 OBS / FFmpeg / Web
                                                         │
                                   RTMP(1935) / HTTP‑FLV/WS‑FLV(8501) push ingress
                                                         │
                                                         ▼
╔══════════════════════════════════════════════════════════════════════════════════════╗
║                              RTMP Origin Main Service (Stream Production Core)       ║
║                                                                                      ║
║  📥 Push/Pull Access: RTMP / HTTP‑FLV / WS‑FLV triple‑protocol compatible, built‑in push auth validation ║
║  🔄 Protocol Transmuxing: raw stream output to HTTP‑FLV / WS‑FLV / HLS / fMP4 / MP4  ║
║  💾 Parallel Recording Tasks (completely non‑blocking, individually toggleable)       ║
║        ┌──────────┬──────────┬──────────┐                                            ║
║        │ FLV raw  │ fMP4 real│ HLS TS   │                                            ║
║        │ recording│‑time segm│ segments │                                            ║
║        └──────────┴──────────┴──────────┘                                            ║
║  📤 Real‑time Stream Output: distributes HTTP‑FLV, WS‑FLV, HLS live streams          ║
║  📦 VOD Artifacts: fMP4 segment cache, auto‑merged to complete MP4 after stream ends ║
║  📁 Built‑in HTTP static service (port 80): no extra gateway needed for low‑concurrency scenarios, directly serves pages and VOD files ║
╚══════════════════════════════════════════════════════════════════════════════════════╝
│
┌───────────────────┼───────────────────┐
│                   │                   │
▼                   ▼                   ▼
HTTP‑FLV real‑time  HLS static segments  fMP4 static segments
│                   │                   │
▼                   ▼                   ▼
┌─────────────┐    ┌──────────────────────────────────────────┐
│ FLV Live    │    │       Static File Gateway Cluster        │
│ Gateway     │    │     (fileGateway)                        │
│ Cluster     │    │     Hosted resources: HLS/fMP4/MP4/FLV/  │
│ ┌─────────┐ │    │     web static assets                   │
│ │Level 1   │ │    │                                          │
│ │Gateway   │ │    │ ┌───────┐ ┌───────┐ ┌───────┐           │
│ │(port8080)│ │    │ │GW 1   │ │GW 2   │ │GW 3   │           │
│ └───┬─────┘ │    │ │(8100) │ │(8101) │ │(8102) │           │
│     │       │    │ └──┬────┘ └──┬────┘ └──┬────┘           │
│ ┌───┴───┐   │    │    │        │        │                 │
│ ▼   ▼   ▼   │    │    ▼        ▼        ▼                 │
│ ┌─┐ ┌─┐ ┌─┐ │    │ ┌──────────────────────────────────┐   │
│ │S│ │S│ │S│ │    │ │End‑user player clients           │   │
│ │u│ │u│ │u│ │    │ │MSE/HLS player/ffplay/browser     │   │
│ │b│ │b│ │b│ │    │ └──────────────────────────────────┘   │
│ └┬─┘ └┬─┘ └┬─┘ │    │                                          │
│  │    │    │   │    └──────────────────────────────────────────┘
│  ▼    ▼    ▼   │
│ ┌────────────┐ │
│ │Live viewers│ │
│ │(FLV player)│ │
│ └────────────┘ │
└─────────────────┘
```

### Architecture Detailed Description
1. **Origin Main Service (sole stream producer)**
   All external pushes are ingested by the origin, which handles protocol parsing, authentication, multi‑format transmuxing, and parallel recording; the three recording tasks (FLV recording, fMP4 slicing, HLS slicing) are completely isolated threads that do not block each other.
   In low‑concurrency scenarios, the built‑in port 80 static service can be used directly without deploying additional gateways.

2. **FLV Live Distribution Gateway**
   No transcoding logic – only traffic forwarding and GOP key‑frame caching for instant startup on players; supports horizontal scaling and multi‑level cascading (recommended at most two levels in production, as more levels increase latency); Linux epoll for high concurrency, Windows for testing only.
   In high‑concurrency scenarios, all player pull requests go through the gateway, reducing connection pressure on the origin main process.

3. **Static File Gateway Cluster**
   Dedicated to hosting static resources such as HLS, MP4, FLV, and frontend pages, achieving read‑write separation; must be deployed for large‑scale VOD scenarios to prevent file I/O from saturating the origin.

4. **Integrated Live Tooling**
   The project supports pure‑PHP client push, pull, and live relay, and provides web‑based push, playback, transcoding, and stream composition. It supports single‑process/multi‑process switching and the personalised media toolkit `xiaosongshu/flv2mp4`.

### Deployment Recommendations by Concurrency
| Concurrency Level | Recommended Deployment |
|-------------------|-------------------------|
| Low concurrency (< 1000 online viewers) | Only start the origin `server.php`, using built‑in ports 80 and 8501 – no gateways needed. |
| Medium concurrency (1000 ~ 5000 online) | Origin + single‑layer FLV gateway cluster + single‑layer static file gateway cluster, with Nginx load balancing. |
| High concurrency / large‑scale events (>5000 online) | Origin + multi‑layer FLV gateway and static gateway clusters, front‑end load balancing; for 10k+ events, commercial CDN edge distribution is mandatory – do not let a single server handle all traffic. |

---

## Port Constants Configuration
Modify `config/app.php` to adjust global service ports. Built‑in constants:
```php
/** HTTP‑FLV / WebSocket‑FLV main service port */
define('BASE_FLV_PORT', 8501);
/** RTMP standard port 1935 */
define('BASE_RTMP_PORT', 1935);
/** Built‑in static web and VOD file HTTP port */
define('BASE_WEB_PORT', 80);
```

## Recording Task Switch Configuration
`config/app.php` independently controls three recording tasks without interference:
```php
define('FLV_TO_RECORD', true);   // Enable real‑time raw FLV recording
define('FLV_TO_MP4', true);      // Enable fMP4 segmentation
define('FLV_TO_HLS', true);      // Enable HLS TS segment generation
```

## Multi‑process Worker Configuration (IPC Stream Sync Core)
### Principle
Under PHP CLI multi‑process model, each worker process has isolated memory. When a single process receives a push stream, other workers cannot access the stream data, so **stream sync via IPC (Inter‑Process Communication) is mandatory**.
This project does not use traditional system IPC like shared memory or pipes. Instead, it implements a custom local TCP Socket IPC scheme: it allocates a set of internal communication ports; the worker that receives the stream actively forwards the complete stream data to all other workers via the built‑in TCP client, achieving full‑process stream data sharing.

### Configuration in `config/app.php`
```php
/** Master switch: enable multi‑process worker mode */
define('ENABLE_MULTI_PROCESS', true);
/** Number of worker processes – recommended not to exceed CPU physical cores */
define('WORKER_COUNT', 3);
/** Starting port for inter‑process TCP communication, automatically assigned 8502, 8503... */
define('COPY_PORT_START', 8502);
```
> When multi‑process is disabled (`ENABLE_MULTI_PROCESS=false`), the worker count and internal communication port configuration become invalid; the service runs in single‑process mode with no IPC stream sync.

### Multi‑process Port Load‑balancing Rules
1. Linux: the system supports port reuse (SO_REUSEPORT), allowing multiple workers to listen on the main FLV port 8501 simultaneously; the kernel automatically distributes player connections among workers.
2. Windows: although `SO_REUSEADDR` is supported, new TCP connections will only be assigned to the first process that bound to port 8501 – native load balancing is not available; you can use Nginx reverse‑proxy to the internal communication ports (8502+) to distribute traffic.
3. Internal IPC ports are externally accessible for pull streams, useful for manual load balancing on Windows.

### Platform Performance Limitations
- Linux: epoll I/O model – a single process can handle thousands of concurrent long connections; multi‑process can fully utilise multi‑core CPUs – the preferred choice for production.
- Windows: the underlying select model has a very low concurrency limit (about 256 connections per process) – only for local development and debugging; do not deploy in production.

## Push Stream Authentication Configuration
### Description
Prevents unauthorised streams from overwriting live channels; only push requests carrying a valid stream key are accepted. Playback pull currently has no built‑in authentication; developers can implement referer/token validation at the gateway or reverse‑proxy layer.
Configuration file `config/auth.php`:
```php
<?php
return [
    'enabled' => false, // Master auth switch
    'publish' => [
        'require_auth' => true, // Enforce stream key validation for push
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

### Authenticated Push URL Format
Carry the key via the URL parameter `key`:
1. RTMP
```bash
ffmpeg -re -i video.mp4 -f flv rtmp://127.0.0.1:1935/live/stream?key=live_123456
```
2. OBS stream key: `stream?key=live_123456`
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
2. Enable HTTPS/WSS in public deployments to prevent plain‑text key sniffing.
3. Rotate stream keys periodically to reduce leakage risk.
4. Authentication is disabled by default; enable it if needed.

Note: any change to the above configuration requires a service restart to take effect.

---

## FLV Live Distribution Gateway
### Overview
A lightweight traffic forwarding service that pulls HTTP‑FLV/WS‑FLV streams from the upstream origin, caches GOP key‑frames for instant player start‑up; supports horizontal scaling and multi‑level cascading to offload the origin.
The gateway can pull from either HTTP‑FLV or WS‑FLV sources and uniformly provides both HTTP‑FLV and WS‑FLV playback addresses.
### Startup Commands
```bash
# Basic single instance
php flvGateway.php 8080 http://127.0.0.1:8501
php flvGateway.php 8080 ws://127.0.0.1:8501

# Horizontal scaling with multiple instances on the same level
php flvGateway.php 8080 http://127.0.0.1:8501
php flvGateway.php 8081 http://127.0.0.1:8501
php flvGateway.php 8082 ws://127.0.0.1:8501

# Multi‑level cascading (not recommended beyond two levels)
php flvGateway.php 8080 http://127.0.0.1:8501    # Level 1 gateway
php flvGateway.php 8081 http://127.0.0.1:8080     # Level 2 gateway

# Run silently in background on Linux
php flvGateway.php 8080 http://127.0.0.1:8501 > /dev/null 2>&1 &
```

### Gateway Playback URL Format
```
http://<gateway_ip>:<port>/{app}/{stream}.flv
ws://<gateway_ip>:<port>/{app}/{stream}.flv
```
Example: `http://127.0.0.1:8080/live/stream.flv`

## Static File HTTP Gateway
### Overview
An independent static resource HTTP service that hosts HLS, MP4, FLV, and frontend pages, separating file I/O from live streaming to improve stability under high‑concurrency VOD loads.

### Startup Commands
```bash
# Single instance
php fileGateway.php 0.0.0.0 8100

# Multiple instances horizontal scaling
php fileGateway.php 0.0.0.0 8100
php fileGateway.php 0.0.0.0 8101
php fileGateway.php 0.0.0.0 8102

# Run silently in background on Linux
php fileGateway.php 0.0.0.0 8100 > /dev/null 2>&1 &
```

### Nginx Load‑Balancing Reverse Proxy Example
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

## Multi‑method Push/Pull Stream Access Tutorial
### RTMP Push
OBS, FFmpeg, and PHP clients all support the standard RTMP protocol. Address format: `rtmp://host:1935/{app}/{stream}`

### HTTP‑FLV Push
Suitable for command‑line and automated programmatic push. Address: `http://host:8501/{app}/{stream}`

### WebSocket‑FLV Push
Native browser push solution, with latency as low as 50ms. Use the built‑in `push.html` page.

### PHP Pull Script
For server‑side pull backup and cross‑server relay:
```bash
php puller.php http://127.0.0.1:8501/live/stream.flv output.flv
php puller.php ws://127.0.0.1:8501/live/stream.flv output.flv
```

## Live Relay/Forwarding Tutorial
This project provides live relay functionality, allowing you to forward a live stream to multiple servers, supporting `rtmp/ws‑flv/http‑flv` protocols for both pull and push. For detailed command usage, see `forward.php`. Example relay command:
```bash
php forward.php http://127.0.0.1:8501/a/b.flv "rtmp://127.0.0.1:1935/c/d,ws://127.0.0.1:8501/c/e,http://127.0.0.1:8501/c/f" 
```
The above command forwards the stream from `http://127.0.0.1:8501/a/b.flv` to `rtmp://127.0.0.1:1935/c/d`, `ws://127.0.0.1:8501/c/e`, and `http://127.0.0.1:8501/c/f`. You can also push to any other platform that supports RTMP, WS‑FLV, or HTTP‑FLV.

### Engineering Suggestions
`pusher.php` / `puller.php` / `forward.php` can be integrated into custom scripts to automate pull‑relay, backup recording, etc., without relying on third‑party tools, completing a full PHP live streaming business loop.

---

## Cluster Deployment Architecture for 100,000+ Concurrent Users

```
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│                                    【Layer 1: Multi‑streamer Push Layer】               │
│                                                                                         │
│     Streamer A (OBS/Web/FFmpeg)   Streamer B (OBS/Web/FFmpeg)   Streamer N (OBS/Web/FFmpeg) │
│            │                              │                             │               │
│      ┌─────┼─────┐                 ┌─────┼─────┐                ┌─────┼─────┐        │
│      ▼     ▼     ▼                 ▼     ▼     ▼                ▼     ▼     ▼        │
│    [Node1][Node2][Node3]         [Node1][Node2][Node3]        [Node1][Node2][Node3] │
│     (Simultaneously push to multiple origin nodes for streamer‑side disaster recovery)│
└─────────────────────────────────────────────────────────────────────────────────────────┘
                                          │
                                          │ RTMP/HTTP‑FLV/WS‑FLV push ingress
                                          ▼
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│                          【Layer 2: Origin Node Cluster (Stream Production Core)】      │
│                                                                                         │
│    ┌─────────────┐   ┌─────────────┐   ┌─────────────┐   ┌─────────────┐             │
│    │  Origin A   │   │  Origin B   │   │  Origin C   │   │  Origin D   │             │
│    │ server.php  │   │ server.php  │   │ server.php  │   │ server.php  │             │
│    │ (multi‑proc)│   │ (multi‑proc)│   │ (multi‑proc)│   │ (multi‑proc)│             │
│    │ record/slice│   │ record/slice│   │ record/slice│   │ record/slice│             │
│    └─────┬───────┘   └─────┬───────┘   └─────┬───────┘   └─────┬───────┘             │
│          │                 │                 │                 │                      │
│          └────────┬────────┴─────────────────┴────────┬────────┘                      │
│                   │                                   │                               │
│              ┌────▼────┐                         ┌────▼────┐                          │
│              │ forward │                         │ forward │  ← Auto sync live streams (pull→push) │
│              │ sync    │                         │ sync    │                          │
│              └────┬────┘                         └────┬────┘                          │
│                   └──────────────┬────────────────────┘                               │
│                                  │                                                    │
│                    (All origin nodes back each other up; if any fails, others continue service) │
└──────────────────────────────────┼────────────────────────────────────────────────────┘
                                   │
                                   │ forward pulls (from origin nodes, pushes to edge)
                                   ▼
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│                        【Layer 3: Edge Node Cluster (Stream Distribution & Cache)】     │
│                                                                                         │
│    ┌─────────────┐   ┌─────────────┐   ┌─────────────┐   ┌─────────────┐             │
│    │  Edge X     │   │  Edge Y     │   │  Edge Z     │   │  Edge W     │             │
│    │ server.php  │   │ server.php  │   │ server.php  │   │ server.php  │             │
│    │ (multi‑proc)│   │ (multi‑proc)│   │ (multi‑proc)│   │ (multi‑proc)│             │
│    │ record/slice│   │ record/slice│   │ record/slice│   │ record/slice│             │
│    └─────┬───────┘   └─────┬───────┘   └─────┬───────┘   └─────┬───────┘             │
│          │                 │                 │                 │                      │
│          └────────┬────────┴─────────────────┴────────┬────────┘                      │
│                   │                                   │                               │
│              ┌────▼────┐                         ┌────▼────┐                          │
│              │ forward │                         │ forward │  ← Auto pull from origins, cache │
│              │ sync    │                         │ sync    │                          │
│              └─────────┘                         └─────────┘                          │
│                                                                                         │
│  ★ Dynamic role switching: any node can be promoted to origin (accepting pushes) or demoted to edge at any time. │
│  ★ All nodes independently record, achieving multi‑copy backup and improving data reliability. │
└──────────────────────────────────┼────────────────────────────────────────────────────┘
                                   │
                     ┌─────────────┴─────────────┐
                     │                           │
                     ▼                           ▼
┌────────────────────────────┐ ┌────────────────────────────┐
│   【Layer 4: Gateway Distribution Layer】  │ │   【Layer 4: Gateway Distribution Layer】  │
│                            │ │                            │
│     flvGateway Cluster     │ │     fileGateway Cluster    │
│  ┌─────┐ ┌─────┐ ┌─────┐ │ │  ┌─────┐ ┌─────┐ ┌─────┐ │
│  │GW 1 │ │GW 2 │ │GW 3 │ │ │  │GW 1 │ │GW 2 │ │GW 3 │ │
│  └──┬──┘ └──┬──┘ └──┬──┘ │ │  └──┬──┘ └──┬──┘ └──┬──┘ │
│     │       │       │     │ │     │       │       │     │
│     └───────┼───────┘     │ │     └───────┼───────┘     │
│             │             │ │             │             │
│    (HTTP‑FLV/WS‑FLV)      │ │   (HLS/MP4/FLV VOD / static pages)│
└─────────────┼─────────────┘ └─────────────┼─────────────┘
              │                             │
              └─────────────┬───────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│                              【Layer 5: Viewer Terminals】                              │
│                                                                                         │
│   PC browsers (MSE/FLV.js)   Mobile (HLS)   ffplay/professional players   WebSocket players │
│                                                                                         │
│   ★ Viewers access the nearest edge gateway; load balancing (DNS or Nginx) automatically assigns to the optimal node. │
└─────────────────────────────────────────────────────────────────────────────────────────┘
```

---

### Core Architecture Design Notes

#### 1. Push Layer High Availability (Disaster Recovery)
- **Multi‑path push**: Streamers can push simultaneously to multiple origin nodes (e.g., nodes A, B, C); if any node fails, others still hold the stream, and viewers experience no interruption.
- **Automatic failover on pusher side**: OBS/FFmpeg can be configured with backup push URLs for active‑standby switching; web‑side can use JavaScript to push to multiple paths.

#### 2. Origin Node Cluster (Stream Production Core)
- **Active‑active deployment**: All origin nodes are active and can accept pushes; they synchronise stream data with each other via `forward.php`, ensuring each origin node holds a complete stream copy.
- **Automatic failover**: If any origin node goes down, others continue to serve, and the relay sync links automatically reconnect – business continues without interruption.
- **Parallel recording**: Each origin node independently performs FLV/fMP4/HLS recording, creating multiple physical backups to prevent single‑point storage loss.

#### 3. Edge Node Cluster (Stream Distribution & Cache)
- **Nearest‑pull**: Edge nodes pull live streams from origin nodes via `forward.php`, cache GOP key‑frames, and provide low‑latency, instant‑start playback to viewers.
- **Elastic scaling**: Edge nodes can be dynamically added or removed based on concurrent load, supporting horizontal scaling (e.g., for traffic spikes).
- **Flexible role switching**: Origin and edge nodes use the same codebase; they can be promoted to origin (accept pushes) or demoted to edge (pull‑only distribution) at any time via configuration, allowing on‑demand resource allocation.

#### 4. Gateway Distribution Layer
- **flvGateway cluster**: Designed specifically for HTTP‑FLV/WS‑FLV real‑time streams – no transcoding, pure forwarding, with GOP caching for instant start‑up; supports multi‑level cascading and horizontal scaling to handle massive player connections.
- **fileGateway cluster**: Independently hosts HLS segments, MP4 VOD files, static pages, etc., separating these from dynamic stream services to prevent file I/O from blocking live services.

#### 5. Viewer Terminals
- **Multi‑protocol coverage**: Supports RTMP, HTTP‑FLV, WS‑FLV, HLS, compatible with PC, mobile, Web, and all platforms.
- **Smart scheduling**: Uses DNS round‑robin, Nginx reverse proxy, or global load balancing (GSLB) to direct viewer requests to the nearest or least‑loaded edge node, ensuring optimal experience.

#### 6. Data Flow
1. **Push**: Streamer → (multi‑path) → Origin node cluster → `forward` sync to all origin nodes.
2. **Pull (edge)**: Edge node → `forward` pulls from any origin node → cache → serves local viewers.
3. **Playback**: Viewer → load balancer → flvGateway/fileGateway → edge node (or origin) → stream data.
4. **Recording**: All nodes (origin/edge) record according to configuration, eventually merging to MP4 for VOD playback.

#### 7. Disaster Recovery and Backup Mechanisms
- **Node‑level disaster recovery**: If any single node (origin or edge) fails, the forwarding client automatically reconnects to other live nodes – stream data is not interrupted.
- **Region‑level disaster recovery**: If an entire data centre goes down, DNS can be switched to a backup data centre (requires deploying multiple clusters) for cross‑region high availability.
- **Recording backup**: Each node stores its own recording files; for important streams, multiple nodes can record simultaneously to ensure data is not lost.

#### 8. Scalability and Concurrency Capability
- **Horizontal scaling**: All layers support horizontal scaling – adding nodes immediately shares the load without restarting existing services.
- **100k+ concurrency**: Edge nodes and gateway layers can scale horizontally on a large scale; combined with CDN edge acceleration, they can support 100,000+ concurrent viewers (bandwidth and server resources must be provisioned accordingly).
- **Performance optimisation**: driven by the event extension (epoll) on Linux; a single node can handle thousands of long‑lived connections (depending on actual server configuration), and multi‑node clusters linearly improve concurrency.

#### 9. Deployment Recommendations
- Stream synchronisation between nodes is accomplished via the built‑in `forward.php` relay client. This tool can pull RTMP/HTTP‑FLV/WS‑FLV streams from any source and push them to one or multiple target nodes simultaneously, carrying authentication parameters (e.g., key) when pushing. Developers can write scheduling scripts based on actual network topology and business needs (e.g., combining health checks, load‑balancing strategies, or business rules) to dynamically configure pull source addresses, target node lists, and forwarding parameters, thus achieving automated stream synchronisation between nodes. Role switching between origin and edge nodes also relies on external scheduling logic: it is recommended to monitor node system status (CPU load, memory usage, active connections, number of push streams, etc.) or external traffic allocation policies to trigger scripts that dynamically adjust node roles, enabling elastic scaling, failover, and disaster recovery. The entire scheduling system can be customised according to actual scenarios, providing a highly flexible production‑ready deployment solution.
---

## Multi‑bitrate Support

This project includes built‑in multi‑bitrate transcoding capability, supporting conversion of Baseline Profile FLV files into multi‑resolution HLS streams to adapt to different network environments and mobile devices.

> ⚠️ **Performance Limitation Notice**
- The current multi‑bitrate module is implemented in pure PHP and is **performance‑constrained** – suitable only for **small offline transcoding** or **functional validation**.
- Since H.264 re‑encoding is compute‑intensive and time‑consuming, it is **strictly prohibited** for use in production live streaming. For professional adaptive bitrate transcoding, please use mature tools like FFmpeg.
- 📌 This feature depends on the `xiaosongshu/flv2mp4` toolkit, which is already installed with this project – no extra action is needed.

---

### How to Use

Refer to the example in `encode.php` for detailed configuration. Run the following command to transcode FLV to HLS:

```bash
php encode.php
```

- 📌 **Version requirement**: this feature requires `xiaosongshu/flv2mp4` version **>= 1.4.4**.
- 📌 The `xiaosongshu/flv2mp4` toolkit supports FLV/MP4 and FLV‑to‑HLS re‑encoding with watermarking. For more usage, refer to its documentation.

---

### Applicable Scenarios

| Scenario | Recommended |
|----------|-------------|
| Local testing / functional validation | ✅ Recommended |
| Small offline file transcoding (< 10 MB) | ✅ Usable |
| Real‑time live stream transcoding | ❌ Not recommended |
| High‑concurrency / large‑scale production | ❌ Strictly prohibited |

---

**Note**: This module is for learning and communication only – do not use in production. For high‑performance transcoding, consider using FFmpeg or specialised transcoding services.

---

### Watermark Tool
You can use the built‑in tool to generate a text watermark. See `watermark.php` for detailed configuration. Generate a watermark file with:
```bash
php watermark.php
```
The system already provides a sample watermark file `watermark_80x16`.

---
## WEBRTC

This project includes a built‑in **standalone WebRTC service** based on pure PHP, implementing **WHIP (WebRTC HTTP Ingest Protocol)** push and **WHEP (WebRTC HTTP Egress Protocol)** pull, supporting zero‑plugin, ultra‑low‑latency (<500ms) real‑time audio/video transmission in browsers. It also provides **DataChannel chat** functionality for live interaction, messaging, etc.

---

| Access Method | Use Case | Protocol | DataChannel Support |
|---------------|----------|----------|---------------------|
| **WebSocket signaling** | Built‑in pages (`push.html` / `play.html` / `index.html`) | Custom JSON signaling + SRTP | ✅ Yes |
| **Standard WHIP/WHEP** | Third‑party clients (OBS, FFmpeg, etc.) and `whip.html` / `whep.html` | HTTP POST + SDP | ❌ No |

> ⚠️ **Note**: The WebRTC service and the RTMP main service are independent of each other and must be started as separate processes. Once the WebRTC service is started, it will automatically feed live data into the RTMP server. You can then directly pull the stream from the RTMP server for viewing, as well as utilize automatic screen recording and transcoding.
---

### Features

| Feature | Description |
|---------|-------------|
| **WebSocket signaling push** | Provides `webrtc/push.html`, uses WebSocket signaling + SRTP media, supports DataChannel chat |
| **WebSocket signaling pull** | Provides `webrtc/play.html`, uses WebSocket signaling + SRTP media, supports DataChannel chat |
| **DataChannel testing** | Provides `webrtc/index.html` for standalone DataChannel communication testing |
| **Standard WHIP push** | Supports standard WHIP (HTTP POST), compatible with third‑party clients; provides `whip.html` test page |
| **Standard WHEP pull** | Supports standard WHEP (HTTP POST), compatible with third‑party players; provides `whep.html` test page |
| **Transport protocol** | SRTP/SRTCP over UDP, low latency, packet‑loss resilient |
| **Audio/Video codecs** | Video H.264, audio Opus (natively supported by browsers) |
| **Signaling service** | Built‑in WebSocket signaling server (for WS method) and WHIP/WHEP HTTP endpoints (for standard protocols) |
| **Independent deployment** | Isolated ports from RTMP main service, lightweight resource usage, can be started/stopped independently |

---

### Starting the Service

In the project root, run:

```bash
php webrtc.php
```

Successful startup example:
```
WebSocket signaling server listening on ws://0.0.0.0:8088/
UDP media server listening on udp://0.0.0.0:8089
STUN server listening on udp://0.0.0.0:3478
```

**Run silently in background** (Linux):
```bash
nohup php webrtc.php > /dev/null 2>&1 &
```

---

### Service Ports and Configuration

WebRTC uses independent port constants defined in `config/app.php`:

```php
/** WebSocket signaling service port (for SDP/ICE exchange) */
define('WS_PORT', 8088);
/** WebRTC media transport UDP port */
define('UDP_PORT', 8089);
/** STUN service port (for NAT traversal) */
define('STUN_PORT', 3478);
/** Public IP address (keep 127.0.0.1 for internal testing; set to actual public IP for public deployment) */
define('PUBLIC_IP', '127.0.0.1');
```

> **Important**:
> - For public deployment, you **must** set `PUBLIC_IP` to the server's public IP; otherwise clients cannot connect correctly.
> - Ensure firewall allows `WS_PORT` (TCP) and `UDP_PORT` (UDP), otherwise media transport fails.
> - If clients and server are on the same private network, you can use the private IP or `127.0.0.1` for testing.

---

### Push Guide
(The following uses local testing; for production, replace with public addresses. The WS signaling server automatically handles WebRTC‑related HTTP requests on the default port 8088.)

#### 1. Browser Screen Push (Recommended)

Visit built‑in page: `http://{server_ip}:8088/push.html`

Steps:
- Enter the signaling address (default `ws://127.0.0.1:8088`) and a room ID (e.g., `stream_001`).
- Click "Start Push"; the browser will pop up a screen/desktop selection window; choose the screen or tab to share, and check "Share audio" if system audio is needed.
- After successful push, the page shows a local preview and prints `✅ 推流中` (or its English equivalent, but the page itself may be in Chinese).

#### 2. WHIP Standard Client Push

If using a third‑party WHIP client (e.g., OBS WebRTC plugin, FFmpeg WHIP output), the push address is:
```
http://127.0.0.1:8088/whip/stream_001
```
Refer to the respective client documentation for configuration. The built‑in `whip.html` page at `http://{server_ip}:8088/whip.html` can also be used.

#### 3. DataChannel Chat Integration

In the push page (`push.html`), after connection is established, a DataChannel is automatically created. You can send messages in the chat box at the bottom; messages will be forwarded by the server to all clients in the same room.

---

### Playback Guide

#### 1. Browser Low‑latency Pull

Visit built‑in page: `http://{server_ip}:8088/play.html`

Steps:
- Enter the signaling address (default `ws://127.0.0.1:8088`) and the room ID (must match the pusher).
- Click "Start Watching"; the page automatically initiates a WHEP pull request.
- After successful pull, the video renders automatically and displays playback statistics (bitrate, packet loss, etc.).

#### 2. Generic WHEP Client

Any WHEP‑compatible player can use the address:
```
http://127.0.0.1:8088/whep/stream_001
```
The built‑in `whep.html` page at `http://{server_ip}:8088/whep.html` can also be used.

#### 3. DataChannel Chat Interaction

In the playback page (`play.html`), after connection is established, a DataChannel is automatically created. You can send messages in the chat box at the bottom; messages will be forwarded by the server to all clients in the same room.

---

### Advanced Configuration and Tuning

#### 1. Multi‑process Support

The WebRTC service currently **does not** have built‑in multi‑process load balancing, but you can start multiple instances on different ports and use Nginx reverse proxy for horizontal scaling (pay attention to UDP port allocation).

#### 2. Public Deployment Considerations

- **Public IP setting**: Ensure `PUBLIC_IP` in `config/app.php` is set to the actual public IP; otherwise, the generated SDP will contain an internal IP, causing connection failures.
- **Firewall**: Open TCP port (WS_PORT) and the UDP port .
- **STUN server**: This project includes a simple built‑in STUN service that only supports basic NAT type detection. If clients are behind symmetric NAT, consider configuring a public TURN server (requires custom extension).

---

### Related Pages and Scripts

| File | Function | Access / Usage |
|---------------------|------------------------|-----------------------------------|
| `webrtc/push.html`  | Browser screen push (with DataChannel) | `http://127.0.0.1:8088/push.html` |
| `webrtc/play.html`  | Browser low‑latency pull (with DataChannel) | `http://127.0.0.1:8088/play.html` |
| `webrtc/whep.html`  | Browser WHEP pull player | `http://127.0.0.1:8088/whep.html` |
| `webrtc/whip.html`  | WHIP push test page (video only) | `http://127.0.0.1:8088/whip.html` |
| `webrtc/index.html` | DataChannel chat lobby | `http://127.0.0.1:8088/index.html` |
| `webrtc.php`        | WebRTC service startup script | `php webrtc.php` |

---

## FAQ
### Q1: What to do if Windows says the event extension is missing?
Windows does not have the event extension; the service automatically switches to the select I/O model. Only the `sockets` extension is required – no extra handling is needed.

### Q2: How to verify the service started successfully?
The terminal prints three listening logs: RTMP 1935, FLV 8501, and static port 80 – success.

### Q3: Push succeeds but playback stutters continuously?
1. The push bitrate or resolution is too high – lower them and test again.
2. The server CPU is saturated – enable multi‑process to utilise multiple cores.
3. Under high concurrency, the FLV gateway is not deployed – too many player connections consume origin resources.
4. Insufficient server upstream bandwidth – limit the number of concurrent viewers.

### Q4: How to stop the service?
Press `Ctrl + C` in the terminal to send a termination signal, or simply close the terminal window.

### Q5: Which third‑party push software is supported?
Fully compatible with standard RTMP clients: OBS Studio, FFmpeg, xSplit, mobile RTMP SDKs.

### Q6: How do the WebRTC and RTMP main services work together?
They are completely independent and do not affect each other. You can start or stop either service as needed, allowing both RTMP and WebRTC protocol stacks to coexist.

## License
This project is licensed under the **Apache License 2.0**.
The software is provided "as is", without warranty of any kind, express or implied. The developer shall not be liable for any direct, indirect, or consequential damages arising from the use of this program. For the full terms, see the `LICENSE` file in the project root.

## Companion Toolkits
The underlying codec and stream format conversion capabilities are independently packaged as [xiaosongshu/flv2mp4](https://github.com/2723659854/flv2mp4).
It provides FLV/MP4/fMP4/HLS inter‑conversion, standalone push/pull clients, and gateway components, and can be integrated into third‑party PHP projects.

## Contact
- Email: 2723659854@qq.com
- GitHub: https://github.com/2723659854