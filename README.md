# RTMP Server
<p align="center">
  <a href="./README.cn.md"><strong>🇨🇳 中文文档</strong></a> •
  <a href="./README.md"><strong>🇬🇧 English Docs</strong></a>
</p>

> A lightweight RTMP live streaming server built purely in PHP, **zero FFmpeg, Nginx, or other third-party streaming media dependencies**, ready to quickly set up a private live streaming platform out of the box.
> Automatically enables the `event` extension with epoll event-driven mode on Linux; automatically degrades to the select IO model on Windows, ensuring full platform compatibility.
> **Project positioning as underlying infrastructure**: fully self-developed RTMP/HTTP-FLV/WS-FLV protocol stacks, asynchronous network engine; business management, permissions, replay management, and other upper-layer applications require developers to extend and develop on their own.

---

## Table of Contents
- [Environment Dependencies](#environment-dependencies)
- [Quick Start](#quick-start)
- [Push and Pull Stream Address Specification](#push-and-pull-stream-address-specification)
- [Live & VOD Access Addresses](#live--vod-access-addresses)
- [Web Page Usage Instructions](#web-page-usage-instructions)
- [Project Directory Structure](#project-directory-structure)
- [System Overall Architecture](#system-overall-architecture)
- [Port Constants Configuration](#port-constants-configuration)
- [Recording Task Switch Configuration](#recording-task-switch-configuration)
- [Multi-Process Worker Configuration (IPC Stream Synchronization Core)](#multi-process-worker-configuration-ipc-stream-synchronization-core)
- [Push Stream Authentication Configuration](#push-stream-authentication-configuration)
- [FLV Live Distribution Gateway](#flv-live-distribution-gateway)
- [Static File HTTP Gateway](#static-file-http-gateway)
- [Multi-Method Push/Pull Stream Access Tutorial](#multi-method-pushpull-stream-access-tutorial)
- [FAQ](#faq)
- [Open Source License](#open-source-license)
- [Auxiliary Toolkit](#auxiliary-toolkit)
- [Contact Information](#contact-information)

---

## Environment Dependencies
| Dependency | Hard Requirement Description |
|--------|------------|
| PHP | >= 8.1, CLI command-line mode only, FPM not supported |
| sockets extension | **Strictly required**, underlying TCP/WS/RTMP communication foundation |
| event extension | Strongly recommended on Linux, enables epoll high-concurrency event model; unavailable on Windows, automatically degrades to select |

> Quick environment deployment: The project includes a `docker-compose.yml`, execute `docker-compose up -d` to start the complete runtime environment with one click.

---

## Quick Start
### 1. Project Installation
```bash
composer create-project 2723659854/rtmp_server
cd rtmp_server
```

### 2. Start the Origin Server Main Service
```bash
php server.php
```

Example of successful startup output:
```
[INFO] RTMP Server started on 0.0.0.0:1935
[INFO] HTTP-FLV/WS-FLV Server started on 0.0.0.0:8501
[INFO] HTTP Static Server started on 0.0.0.0:80
```

### 3. Quick Push Stream Test
#### Method 1: Browser-based No-Software Push
- Real-time screen push: `http://127.0.0.1/push.html`
- Local MP4/FLV file loop push: `http://127.0.0.1/flv_push.html`

#### Method 2: FFmpeg Standard Push
```bash
ffmpeg -re -stream_loop -1 -i video.mp4 -c:v libx264 -c:a aac -f flv rtmp://127.0.0.1:1935/live/stream
```

#### Method 3: OBS Studio Push
- Server: `rtmp://127.0.0.1:1935/live/`
- Stream Key: `stream`

#### Method 4: Project Built-in PHP Push Client
```bash
php pusher.php test.mp4 http://127.0.0.1:8501/live/stream
```

### 4. Quick Live Viewing
Open in browser: `http://127.0.0.1/index.html`

---

## Push and Pull Stream Address Specification
### Push Stream Addresses (Unified Format for OBS/FFmpeg/PHP/Web)
| Protocol | Standard Format | Example Address |
|------|---------|---------|
| RTMP | `rtmp://host:1935/{app}/{stream}` | `rtmp://127.0.0.1:1935/live/stream` |
| HTTP-FLV | `http://host:8501/{app}/{stream}` | `http://127.0.0.1:8501/live/stream` |
| WebSocket-FLV | `ws://host:8501/{app}/{stream}` | `ws://127.0.0.1:8501/live/stream` |

> Field constraints: `{app}` application name and `{stream}` channel name only allow English letters, numbers, and underscores; special symbols and Chinese characters are prohibited.

### Live and VOD Access Addresses
#### Real-Time Live Playback Addresses
| Protocol | Access Address | Applicable Scenario |
|------|---------|---------|
| RTMP | `rtmp://127.0.0.1:1935/live/stream` | ffplay, desktop professional players |
| HTTP-FLV | `http://127.0.0.1:8501/live/stream.flv` | PC browser low-latency live streaming |
| WebSocket-FLV | `ws://127.0.0.1:8501/live/stream.flv` | Browser native WebSocket MSE playback |
| HLS | `http://127.0.0.1:80/hls/live/stream/index.m3u8` | Mobile devices, WeChat built-in browser |

#### Recorded VOD Replay Addresses
Recording files are persistently stored in the project root directory, with complete files automatically generated when the live stream ends:

| File Type | Storage Path | Access Example |
|---------|---------|---------|
| Complete merged MP4 | `mp4/live/stream/output_merge/stream_full.mp4` | `http://127.0.0.1/mp4/live/stream/output_merge/stream_full.mp4` |
| Raw FLV recording file | `flv/live/stream/index.flv` | `http://127.0.0.1/flv/live/stream/index.flv` |
| HLS TS segment directory | `hls/live/stream/` | Play directly using the m3u8 index address |

---

## Web Page Usage Instructions
### Live Playback Pages
| Page File | Function Description | Access Address |
|---------|---------|---------|
| index.html | HTTP-FLV low-latency live player | http://127.0.0.1/index.html |
| play.html | HLS mobile-adapted player | http://127.0.0.1/play.html |
| mp4.html | MP4 VOD dedicated page | http://127.0.0.1/mp4.html |
| video.html | FLV VOD player | http://127.0.0.1/video.html |
| play_merge.html | fMP4 segment VOD page | http://127.0.0.1/play_merge.html |

### Web Push Stream Pages
| Page File | Function Description | Access Address |
|---------|---------|---------|
| push.html | Browser screen capture push (WS-FLV) | http://127.0.0.1/push.html |
| flv_push.html | Local MP4/FLV file loop push | http://127.0.0.1/flv_push.html |
| push_merge.html | Multi-channel live stream merging push | http://127.0.0.1/push_merge.html |
| push_transcode.html | Frontend multi-bitrate transcoding push, adapted for weak networks | http://127.0.0.1/push_transcode.html |

### PHP Built-in Push/Pull Client Scripts
| Script | Function | Command Example |
|------|------|---------|
| pusher.php | Command-line file push client | `php pusher.php video.mp4 http://127.0.0.1:8501/live/stream` |
| puller.php | Command-line pull stream recording client | `php puller.php http://127.0.0.1:8501/live/stream.flv output.flv` |

---

## Project Directory Structure
```
rtmp_server/
├── config/                     # Global configuration files: ports, multi-process, recording, push authentication
├── flv/                        # Real-time recording FLV raw stream storage directory
├── mp4/                        # fMP4 segments & post-live merged complete MP4
├── hls/                        # HLS TS segments, m3u8 index file directory
├── MediaServer/                # RTMP/FLV/WS-FLV core protocol stack, session management
├── Root/                       # Underlying asynchronous IO, Socket event-driven engine
├── record/                     # Client-side supporting static page resources
├── server.php                  # RTMP origin server main service startup entry
├── flvGateway.php              # FLV live distribution gateway startup script
├── fileGateway.php             # HLS/MP4/static resource HTTP gateway
├── pusher.php                  # PHP push stream client
├── puller.php                  # PHP pull stream client
├── auth_config.php             # Push stream authentication independent configuration
├── *.html                      # All Web push/pull stream and playback pages
├── docker-compose.yml          # Docker one-click deployment configuration
└── LICENSE                     # Apache 2.0 open source license file
```

---

## System Overall Architecture
```
                                                    【External Push Sources】OBS / FFmpeg / Web
                                                         │
                                   RTMP(1935) / HTTP-FLV/WS-FLV(8501) Push Stream Access
                                                         │
                                                         ▼
╔══════════════════════════════════════════════════════════════════════════════════════╗
║                              RTMP Origin Server (Stream Production Core)              ║
║                                                                                      ║
║  📥 Push/Pull Stream Access: RTMP / HTTP-FLV / WS-FLV triple protocol compatibility,  ║
║     built-in push stream authentication verification                                 ║
║  🔄 Protocol Re-encapsulation: Raw stream output to HTTP-FLV / WS-FLV / HLS / fMP4 / MP4 ║
║  💾 Parallel Recording Tasks (completely non-blocking, individually switchable)       ║
║        ┌──────────┬──────────┬──────────┐                                            ║
║        │ FLV Raw Stream Recording │ fMP4 Real-time Segmentation │ HLS TS Segmentation │        ║
║        └──────────┴──────────┴──────────┘                                            ║
║  📤 Real-time Stream Output: External distribution of HTTP-FLV, WS-FLV, HLS live streams ║
║  📦 VOD Products: fMP4 segment cache, automatic merging into complete MP4 file at live end ║
║  📁 Built-in Static HTTP Service (Port 80): No additional gateway needed for low concurrency ║
║     scenarios, directly provides page and VOD file access                             ║
╚══════════════════════════════════════════════════════════════════════════════════════╝
│
┌───────────────────┼───────────────────┐
│                   │                   │
▼                   ▼                   ▼
HTTP-FLV Real-time Stream    HLS Static Segment Files      fMP4 Static Segment Files
│                   │                   │
▼                   ▼                   ▼
┌─────────────┐    ┌──────────────────────────────────────────┐
│ FLV Live Gateway Cluster │    │        Static File Gateway Cluster (fileGateway)    │
│             │    │     Hosted Resources: HLS/fMP4/MP4/FLV/Web Static Resources │
│ ┌─────────┐ │    │                                          │
│ │Level-1 Gateway│ │    │ ┌───────┐ ┌───────┐ ┌───────┐           │
│ │(Port 8080)│ │    │ │Gateway 1│ │Gateway 2│ │Gateway 3│         │
│ └───┬─────┘ │    │ │(8100) │ │(8101) │ │(8102) │           │
│     │       │    │ └──┬────┘ └──┬────┘ └──┬────┘           │
│ ┌───┴───┐   │    │    │        │        │                 │
│ ▼   ▼   ▼   │    │    ▼        ▼        ▼                 │
│ ┌─┐ ┌─┐ ┌─┐ │    │ ┌──────────────────────────────────┐   │
│ │Sub│ │Sub│ │Sub│ │    │ │ End-User Player Clients                │   │
│ │GW │ │GW │ │GW │ │    │ │ MSE/HLS Player/ffplay/Browser    │   │
│ └┬─┘ └┬─┘ └┬─┘ │    │ └──────────────────────────────────┘   │
│  │    │    │   │    │                                          │
│  ▼    ▼    ▼   │    └──────────────────────────────────────────┘
│ ┌────────────┐ │
│ │ Live Viewing Clients │ │
│ │ FLV Player    │ │
│ └────────────┘ │
└─────────────────┘
```

### Architecture Detailed Description
1. **Origin Server Main Service (Sole Stream Producer)**
   All external push streams are uniformly accessed through the origin server, completing protocol parsing, authentication, multi-channel re-encapsulation, and parallel recording; the three recording tasks (FLV recording, fMP4 segmentation, HLS segmentation) are completely isolated and do not block each other.
   In low concurrency scenarios, the built-in port 80 static service can be used directly without deploying additional gateways.

2. **FLV Live Distribution Gateway**
   No transcoding logic, only traffic forwarding and GOP keyframe caching for instant player startup; supports horizontal scaling and multi-level cascading (in production environments, a maximum of two levels is recommended; more levels increase latency); Linux epoll for high concurrency, Windows for testing only.
   In high concurrency scenarios, all player pull requests go through the gateway, reducing connection pressure on the origin server main process.

3. **Static File Gateway Cluster**
   Dedicated to hosting static resources such as HLS, MP4, FLV, and frontend pages, achieving read-write separation; must be deployed for large-scale VOD scenarios to prevent the origin server from being overwhelmed by file IO requests.

### Deployment Recommendations by Concurrency Scale
| Concurrency Scale | Recommended Deployment Plan |
|---------|------------|
| Low Concurrency (Online Viewers < 1000) | Only start origin server `server.php`, use built-in ports 80 and 8501, no gateway needed |
| Medium Concurrency (1000 ~ 5000 Online) | Origin server + single-layer FLV gateway cluster + single-layer static file gateway cluster, Nginx load balancing |
| High Concurrency/Large-Scale Live Events (>5000 Online) | Origin server + multi-level FLV gateway, static gateway clusters, frontend load balancing; for events with tens of thousands of viewers, must integrate commercial CDN edge distribution; do not let a single server bear all traffic |

---

## Port Constants Configuration
Modify `config/app.php` to adjust global service ports, with built-in constant definitions:
```php
/** HTTP-FLV / WebSocket-FLV main service port */
define('BASE_FLV_PORT', 8501);
/** RTMP standard 1935 port */
define('BASE_RTMP_PORT', 1935);
/** Built-in static web page, VOD file HTTP port */
define('BASE_WEB_PORT', 80);
```

## Recording Task Switch Configuration
`config/app.php` independently controls three types of recording tasks without mutual interference:
```php
define('FLV_TO_RECORD', true);   // Enable real-time raw FLV stream recording
define('FLV_TO_MP4', true);      // Enable fMP4 segmentation, automatically merge into complete MP4 after live stream ends
define('FLV_TO_HLS', true);      // Enable HLS TS segment generation
```

## Multi-Process Worker Configuration (IPC Stream Synchronization Core)
### Principle Description
Under the PHP CLI multi-process model, each Worker process has completely isolated memory. When a single process receives a push stream, other Workers cannot read the stream data, therefore **IPC (Inter-Process Communication) must be used to synchronize live streams**.
This project does not use traditional system IPC such as shared memory or pipes, but instead employs a self-developed local TCP Socket IPC solution: allocate a set of internal communication ports, and the receiving Worker actively uses a built-in TCP client to copy and forward complete stream data to all other Workers, achieving full process stream data sharing.

### Configuration Code `config/app.php`
```php
/** Main switch: whether to enable multi-process Worker mode */
define('ENABLE_MULTI_PROCESS', true);
/** Number of Worker processes, recommended not to exceed the number of physical CPU cores */
define('WORKER_COUNT', 3);
/** Starting value for inter-process TCP communication ports, automatically assigned sequentially as 8502, 8503... */
define('COPY_PORT_START', 8502);
```
> When multi-process is disabled (`ENABLE_MULTI_PROCESS=false`), the process count and internal communication port configurations are all invalid, and the service runs in single-process mode without needing IPC stream synchronization.

### Multi-Process Port Load Balancing Rules
1. Linux: The system supports port reuse, multiple Workers can simultaneously listen on the main FLV port 8501, and the kernel automatically distributes player connections evenly to each Worker;
2. Windows: Although the system supports `SO_REUSEADDR` port reuse, new TCP connections will only be assigned to the process that first bound to port 8501, unable to achieve native load balancing; Nginx reverse proxy to internal communication ports (8502+) can be used to achieve traffic distribution;
3. Internal IPC ports can be directly accessed externally for pull streaming, used for manual load balancing in Windows environments.

### Platform Performance Limitation Notes
- Linux: epoll IO model, single process supports thousands of concurrent long connections, multi-process can fully utilize multi-core CPUs, preferred for production environments;
- Windows: Underlying select model has extremely low concurrency limits (approximately 256 connections per process), only for local development and debugging, prohibited for online production deployment.

## Push Stream Authentication Configuration
### Function Description
Prevents unauthorized stream overwriting of live rooms; only push requests carrying a valid stream key are allowed access; player pull streaming does not require authentication.
Configuration file `config/auth.php`
```php
<?php
return [
    'enabled' => false, // Authentication master switch
    'publish' => [
        'require_auth' => true, // Mandatory key verification for push streaming
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

### Authenticated Push Stream Address Format
Carry the key via the URL parameter `key`:
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
1. Replace default keys with random strings of 32 characters or more;
2. Enable HTTPS/WSS for public network deployments to prevent plaintext key capture;
3. Rotate stream keys regularly to reduce leakage risk.
4. Authentication is disabled by default in the system; enable it if needed.

## FLV Live Distribution Gateway
### Function Introduction
A lightweight traffic forwarding service that pulls HTTP-FLV/WS-FLV streams from the upstream origin server, caches GOP keyframes for instant player startup; supports horizontal scaling and multi-level cascading distribution to share the origin server's concurrency pressure.

### Startup Commands
```bash
# Basic single instance startup
php flvGateway.php 8080 http://127.0.0.1:8501
php flvGateway.php 8080 ws://127.0.0.1:8501

# Horizontal scaling with multiple instances at the same level
php flvGateway.php 8080 http://127.0.0.1:8501
php flvGateway.php 8081 http://127.0.0.1:8501
php flvGateway.php 8082 ws://127.0.0.1:8501

# Multi-level cascading (not recommended to exceed two levels)
php flvGateway.php 8080 http://127.0.0.1:8501    # Level-1 gateway
php flvGateway.php 8081 http://127.0.0.1:8080     # Level-2 gateway

# Linux background silent operation
php flvGateway.php 8080 http://127.0.0.1:8501 > /dev/null 2>&1 &
```

### Gateway Playback Address Format
```
http://GatewayIP:Port/{app}/{stream}.flv
ws://GatewayIP:Port/{app}/{stream}.flv
```
Example: `http://127.0.0.1:8080/live/stream.flv`

## Static File HTTP Gateway
### Function Introduction
An independent static resource HTTP service that hosts HLS, MP4, FLV, and frontend pages, separating file IO from live stream business to improve high-concurrency VOD stability.

### Startup Commands
```bash
# Single instance startup
php fileGateway.php 0.0.0.0 8100

# Horizontal scaling with multiple instances
php fileGateway.php 0.0.0.0 8100
php fileGateway.php 0.0.0.0 8101
php fileGateway.php 0.0.0.0 8102

# Linux background operation
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

### Resource Access Address Examples
```
http://127.0.0.1:8100/index.html
http://127.0.0.1:8100/hls/live/stream/index.m3u8
http://127.0.0.1:8100/mp4/live/stream/output_merge/stream_full.mp4
```

## Multi-Method Push/Pull Stream Access Tutorial
### RTMP Push Stream
OBS, FFmpeg, and PHP clients are all compatible with the standard RTMP protocol, address format: `rtmp://host:1935/{app}/{stream}`

### HTTP-FLV Push Stream
Suitable for command-line and automated program push streaming, address: `http://host:8501/{app}/{stream}`

### WebSocket-FLV Push Stream
Browser native push streaming solution, latency as low as within 50ms, use the built-in `push.html` page.

### PHP Pull Stream Script
Used for server-side pull stream backup and cross-server stream relay:
```bash
php puller.php http://127.0.0.1:8501/live/stream.flv output.flv
php puller.php ws://127.0.0.1:8501/live/stream.flv output.flv
```

### Engineering Recommendations
`pusher.php`/`puller.php` can be integrated into backend scheduled tasks to achieve automated pull stream relay and backup recording, without relying on third-party tools, completing a full PHP live streaming business loop.

## FAQ
### Q1 What to do if Windows prompts that the event extension is missing?
Windows does not have the event extension; the service automatically switches to the select IO model. Only the `sockets` extension needs to be installed for normal operation, no additional handling is required.

### Q2 How to confirm the service has started normally?
Three listening log lines in the terminal indicate successful startup: RTMP 1935, FLV 8501, Static Port 80.

### Q3 Push stream is successful but the player continuously stutters?
1. Push stream bitrate or resolution is too high, test by lowering bitrate/frame rate;
2. Server CPU is at full load, enable multi-process to fully utilize multi-core;
3. FLV gateway not deployed for high concurrency, a large number of player connections occupy origin server resources;
4. Insufficient server upstream bandwidth, limit the number of concurrent online viewers.

### Q4 How to stop the service?
Press `Ctrl + C` in the terminal to send a termination signal, or simply close the running terminal window.

### Q5 Which third-party push stream software is supported?
Fully compatible with standard RTMP clients: OBS Studio, FFmpeg, xSplit, mobile RTMP push stream SDKs.

## Open Source License
This project is licensed under the **Apache License 2.0**.
The software is provided as-is, without any express or implied warranty. The developer assumes no responsibility for direct, indirect, or consequential damages resulting from the use of this program. See the `LICENSE` file in the project root directory for full terms.

## Auxiliary Toolkit
The project's underlying encoding/decoding and stream encapsulation capabilities have been independently separated into a toolkit: [2723659854/flv2mp4](https://github.com/2723659854/flv2mp4)
Provides FLV/MP4/fMP4/HLS interconversion, independent push/pull stream clients, and gateway components that can be individually integrated into third-party PHP projects.

## Contact Information
- Email: 2723659854@qq.com
- GitHub: https://github.com/2723659854