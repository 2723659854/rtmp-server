# RTMP Server
<p align="center">
  <a href="./README.cn.md"><strong>🇨🇳 Chinese</strong></a> •
  <a href="./README.md"><strong>🇬🇧 English</strong></a>
</p>

> A lightweight RTMP live streaming server written purely in PHP. **No third-party streaming middleware such as Nginx or SRS required**, deploy private live platform quickly out of the box.

## 🏗️ System Architecture
> Full-width layout, auto fill container on GitHub preview, fixed-width font keeps alignment intact
```
                                                    [Publisher Side] OBS/FFmpeg
                                                         │
                                               RTMP Publish (1935)
                                                         │
                                                         ▼
╔══════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════╗
║                                                Core RTMP Origin Server                                                      ║
║                                                                                                                            ║
║  📥 Stream Ingest     RTMP connection accept & authentication                                                               ║
║  🔄 Protocol Convert  RTMP → HTTP-FLV / WebSocket-FLV / HLS / fMP4 / MP4                                                    ║
║  💾 Record Storage   Raw FLV recording, MP4 transcoding, fMP4 chunk generation                                             ║
║  📤 Stream Output    HTTP-FLV(8501) / HLS / fMP4 / MP4 VOD                                                                ║
╚══════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════╝
                                 │
        ┌───────────────────────┼───────────────────────┐
        │                       │                       │
        ▼                       ▼                       ▼
HTTP-FLV Output(8501)      HLS TS Resources        MP4/fMP4 VOD Files
        │                       │                       │
        │                       └───────────┬─────────────┘
        │                                   │
        ▼                                   ▼
┌────────────────────────────┐       ┌───────────────────────┐
│       FLV Gateway Network │       │    Nginx Static Service │
│      (Traffic Distribution)│      │     Static Asset Host │
│                           │       └───────────┬─────────────┘
│   ┌────────────────────┐  │                  │
│   │    Tier-1 Gateway  │  │          End User Access HLS/fMP4/MP4 VOD
│   │ (Region Node :8080)│  │
│   └─────────┬──────────┘  │
│             │             │
│    ┌────────┼────────┐    │
│    ▼        ▼        ▼    │
│ ┌──────┐ ┌──────┐ ┌──────┐│
│ │Tier2 │ │Tier2 │ │Tier2 ││
│ │:8080 │ │:8080 │ │:8080 ││
│ └──┬───┘ └──┬───┘ └──┬───┘│
│    │         │         │   │
└────┼─────────┼─────────┼───┘
     │         │         │
     ▼         ▼         ▼
   Client    Client    Client
  (FLV Play)(FLV Play)(FLV Play)
```

### Architecture Description
- **Origin Server**: Exclusive stream producer, handles RTMP publish/play access, multi-protocol remuxing and local recording storage.
- **FLV Gateway**: Pure traffic forwarding service, pulls HTTP-FLV from upstream, caches GOP keyframes for instant playback, distributes streams to end clients or subordinate gateways.
- **Nginx**: Built-in simple HTTP server supports direct HLS/FLV/MP4 playback; production environment recommends Nginx for static chunk hosting to reduce origin load.
- **Deployment Suggestion**: Origin focuses on publish, transcoding and recording; gateways undertake client playback. Single origin works under low concurrency; add multi-layer gateways for high-traffic horizontal scaling.

## ✨ Features
- 🎥 **Full RTMP Publish & Play**: Complete protocol implementation with standard publish / play commands
- 📡 **HTTP-FLV / WebSocket-FLV**: Low-latency browser live streaming solution
- 🧩 **Auto HLS Chunking**: Generate m3u8 + TS segments in real time, fully compatible with mobile devices
- 💾 **Auto Recording**: Toggle recording on publish, auto generate raw FLV, merged fMP4, split A/V fMP4 and full concatenated MP4
- 🖥️ **Built-in Multiple Web Players**: Ready-to-use without extra frontend development
- 🚀 **Cascadable FLV Streaming Gateway**: Unlimited layered relay, GOP cache instant start, auto-reconnect on upstream disconnect
- 🐳 **Docker One-Click Deployment**: Rapidly spin up test environment
- ⚡ **Pure Native PHP**: No dependency on Nginx, SRS, LiveGO or other third-party streaming software

## 📋 Environment Requirements
- PHP >= 8.1 (Run only in CLI mode)
- Mandatory Extension: `sockets`
- Optional Recommended Extension: `pcntl` (Linux/macOS for optimized process management)

## 🚀 Quick Start
### 1. Install Project
```bash
composer create-project xiaosongshu/rtmp_server
```

### 2. Start Origin Service
```bash
php server.php
```

### 3. Stop Service
| OS          | Stop Command |
| ----------- | ------------ |
| Windows     | `Ctrl + C`   |
| Linux/macOS | `kill -9 PID`|

## 🔧 Port Configuration (Edit in `server.php`)
| Port | Protocol       | Usage |
|------|----------------|-------|
| 1935 | RTMP           | RTMP publish & RTMP playback |
| 8501 | HTTP/WebSocket | HTTP-FLV / WS-FLV live playback |
| 80   | HTTP           | HLS playback + Web Player + VOD access |

## 🚀 FLV Streaming Gateway
### Gateway Introduction
Lightweight traffic distribution component supporting unlimited hierarchical cascading. Pull HTTP-FLV from origin / upper gateway, cache stream header & GOP keyframes for instant playback on new client connection, duplicate and forward stream to end clients or child gateways.

### Core Gateway Capabilities
- 📡 Single instance forwards multiple independent live streams simultaneously
- 🔄 Unlimited cascading: Tier1 → Tier2 → Tier3 chain expansion
- ⚡ Pre-cached GOP enables instant playback without waiting for incoming keyframe
- 🔁 Auto reconnection when upstream stream drops, transparent to end users
- 📊 Built-in runtime stats: output online clients & traffic every 10 seconds

### Start Gateway Instance
```bash
# Tier 1 Gateway: pull stream from origin server
php gateway.php 8080 http://OriginIP:8501

# Tier 2 Gateway: pull stream from Tier1 gateway
php gateway.php 8080 http://Tier1IP:8080

# Tier 3 Gateway: pull stream from Tier2 gateway
php gateway.php 8080 http://Tier2IP:8080
```

### Gateway Playback URL Format
```
http://GatewayIP:8080/{AppName}/{StreamName}.flv
```
Examples:
```bash
# Tier1 Gateway Example
http://127.0.0.1:8080/live/stream.flv
# Tier2 Gateway Example
http://127.0.0.1:8081/live/stream.flv
```

### Debug Log
Enable full verbose log by adding `$gateway->debug = true;` inside gateway startup script.

## 📡 Publish Guide
### RTMP Publish URL Format
```
rtmp://127.0.0.1:1935/{AppName}/{StreamName}
```
- `AppName`: example `live`
- `StreamName`: example `stream`
- Only alphanumeric characters allowed

### Publish Examples
#### OBS Studio Publish
1. Download [OBS Studio](https://obsproject.com/)
2. Settings → Stream → Server: `rtmp://127.0.0.1:1935/live`
3. Stream Key: `stream`
4. Start streaming

#### FFmpeg Looping Publish
```bash
ffmpeg -re -stream_loop -1 -i "video.mp4" \
  -vcodec h264 -acodec aac -f flv \
  rtmp://127.0.0.1:1935/live/stream
```

## 📺 Playback Address List
### Live Stream Endpoints
| Protocol       | URL                                                      | Description |
|----------------|----------------------------------------------------------|-------------|
| RTMP           | `rtmp://127.0.0.1:1935/live/stream`                      | Native RTMP player |
| HTTP-FLV       | `http://127.0.0.1:8501/live/stream.flv`                  | Low-latency browser playback |
| WebSocket-FLV  | `ws://127.0.0.1:8501/live/stream.flv`                    | WebSocket streaming |
| HLS            | `http://127.0.0.1:80/hls/live/stream/index.m3u8`        | Preferred for Android/iOS |

### Built-in Web Play Pages
> Default stream path: `live/stream`, modify embedded URL for custom app/stream name

#### 🔴 Live Play Pages
| Page Usage     | Access URL |
|----------------|------------|
| FLV Live Player| `http://127.0.0.1:80/index.html` |
| HLS Live Player| `http://127.0.0.1:80/play.html` |

#### 🔵 Recorded VOD Pages (Available after recording finished)
| Page Usage               | Access URL |
|--------------------------|------------|
| Merged MP4 VOD           | `http://127.0.0.1:80/mp4.html` |
| Raw FLV VOD              | `http://127.0.0.1:80/video.html` |
| fMP4 Chunk VOD (MSE)     | `http://127.0.0.1:80/play_merge.html` |

> Recorded files stored under `./flv/` and `./mp4/`, adjust file path inside pages as needed.

## 💾 Auto Recording Specification
### Recording Workflow
1. **Publish Start** → Real-time raw FLV stream recording
2. **Publish Stop** → Persist original FLV file & auto transcode to multiple MP4/fMP4 formats

### File Storage Structure
| File Type                | Path | Remark |
|--------------------------|------|--------|
| Raw FLV File             | `./flv/{AppName}/{StreamName}/` | Original live recorded stream |
| Merged A/V fMP4 Chunks   | `./mp4/{AppName}/{StreamName}/output_merge/` | Combined A/V chunks for browser MSE |
| Split A/V fMP4 Chunks    | `./mp4/{AppName}/{StreamName}/output_separate/` | Separated audio & video chunks |
| Full Concatenated MP4    | `./mp4/{AppName}/{StreamName}/output_merge/{StreamName}_full.mp4` | Final merged full MP4 |

> Merged chunk: single segment contains both audio & video, compatible with HTML5 Video + MSE.
> Split chunk: audio/video stored separately for customized advanced playback logic.

### Recording Notes
- ✅ Raw FLV playable directly with VLC, PotPlayer and mainstream media players
- ✅ Merged fMP4 & full MP4 follow standard spec, support drag-drop and timeline seek
- ✅ Split chunks playable via `play_merge.html` with browser MSE decoding
- ⚠️ Republish with identical App+Stream name overwrites existing recording files
- ⚠️ Server does not auto-clean expired files; implement custom cleanup script by yourself

### Offline Manual Transcoding (Optional)
Toggle auto-recording switches inside `server.php`. Project relies on `xiaosongshu/flv2mp4` component for offline format conversion:
```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '512M');
$file = __DIR__."/test.flv";

// Example1: FLV → Merged fMP4 + Full MP4
$outputDir1 = __DIR__."/output_merge";
try{
    $res = \Xiaosongshu\Flv2mp4\Client::runFlv2mp4($file, $outputDir1);
    echo "Convert finished: " . $res;
}catch (\Exception $e){
    echo "Error: " . $e->getMessage();
}

// Example2: FLV → Separated Audio/Video fMP4
$outputDir2 = __DIR__."/output_separate";
try{
    $res = \Xiaosongshu\Flv2mp4\Client::runFlv2mp4Separate($file, $outputDir2);
}catch (\Exception $e){}

// Example3: FLV ↔ HLS, MP4 ↔ FLV conversion see source comments
```
> `xiaosongshu/flv2mp4` is standalone split-off project supporting full conversion between FLV/MP4/HLS; real-time live transcoding already integrated inside this RTMP server.

## 📁 Project Directory Structure
```
rtmp_server/
├── flv/                              # Raw recorded FLV files
│   └── {app}/{name}/
│       └── *.flv
├── mp4/                              # MP4/fMP4 transcoding output
│   └── {app}/{name}/
│       ├── output_merge/             # Merged audio+video fMP4 chunks
│       │   ├── init.mp4
│       │   ├── segment_1.m4s
│       │   └── {name}_full.mp4       # Final full merged MP4
│       └── output_separate/          # Split independent A/V fMP4 chunks
│           ├── audio_init.mp4
│           ├── audio_1.m4s
│           ├── video_init.mp4
│           └── video_1.m4s
├── hls/                              # HLS TS segments + m3u8 index files
│   └── {app}/{name}/
├── MediaServer/                      # Core RTMP protocol & session logic
├── Root/                             # Low-level async IO & Socket event driver
├── SabreAMF/                         # AMF0/AMF3 codec for RTMP command parsing
├── server.php                        # Origin server entry
├── gateway.php                       # FLV gateway entry
├── index.html / play.html / mp4.html # Frontend player pages
└── README.md / README.cn.md
```

## ❓ FAQ
### 1. Missing PHP Extension on Startup
- Reason: PHP-CLI and FPM load different `php.ini` configurations
- Solution: Check loaded extensions via `php -m`, install missing `sockets`; Docker deployment recommended to avoid environment conflicts.

### 2. Port Occupied Error
- Check port usage: Windows `netstat -ano | findstr PORT` / Linux `lsof -i:PORT`
- Solution: Modify port values in `server.php`, sync changes inside frontend HTML files.

### 3. Web Player Cannot Connect Stream
1. Confirm service running & port not blocked by firewall
2. Verify playback URL matches publish App/Stream name
3. Update hardcoded port inside HTML after customization

### 4. Existing Recording Overwritten on Republish
- Solution: Use unique stream name for every publish; implement custom archive script.

### 5. No Generated Recording Files After Publish
**Default setting: Only HLS conversion enabled; FLV & MP4 recording disabled by default.**
Toggle constants inside `server.php`:
- `FLV_TO_HLS`: Enable real-time HLS chunking
- `FLV_TO_MP4`: Enable fMP4/MP4 file generation
- `FLV_TO_RECORD`: Enable raw FLV capture

### 6. Stuttering / Disconnect on Gateway Playback
1. Check upstream origin network stability
2. Limit gateway cascade layers ≤3 to reduce latency
3. Enable debug log: `$gateway->debug = true;` for troubleshooting

## 📄 Open Source License
This project is for learning & research only; commercial usage risks are borne entirely by end users.

## ⚠️ Disclaimer
1. Partial code sourced from open community; contact author for copyright removal request if needed.
2. Full open-source project for technical communication only.
3. End users take full legal responsibility for any project usage consequences; author bears no related liabilities.

## 📧 Contact
For feedback & technical support via Email:
📧 **2723659854@qq.com**