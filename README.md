# RTMP Server

<p align="center">
  <a href="./README.cn.md"><strong>🇨🇳 中文</strong></a> •
  <a href="./README.md"><strong>🇬🇧 English</strong></a>
</p>

> A lightweight RTMP live streaming service written in pure PHP, **no third-party streaming media dependencies**, out-of-the-box for building private live streaming platforms.

## 🏗️ System Architecture

```
                                                    【Streaming Source】OBS/FFmpeg
                                                         │
                                                   RTMP Push(1935)
                                                         │
                                                         ▼
╔══════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════╗
║                                                      RTMP Origin Server (Core)                                                ║
║                                                                                                                              ║
║  📥 Stream Ingestion   RTMP Reception, Connection Authentication                                                            ║
║  🔄 Protocol Conversion RTMP → HTTP-FLV / WebSocket-FLV / HLS / fMP4 / MP4                                                   ║
║  💾 Real-time Recording ┌──────────────┬──────────────┬──────────────┐                                                       ║
║                        │  FLV Record  │  fMP4 Segment│  HLS Segment │  Three Independent Parallel Tasks                      ║
║                        │ (Raw Stream) │(Real-time)   │(Real-time)   │                                                        ║
║                        └──────────────┴──────────────┴──────────────┘                                                        ║
║  📤 Live Output        HTTP-FLV(8501) / WebSocket-FLV / HLS Live / fMP4 Live                                                 ║
║  📦 VOD Output         fMP4 Segments Generated in Real-time → Auto-merge into Complete MP4 After Stream Ends                 ║
║  📁 Static Service     Origin Built-in HTTP Service (Port 80), Direct Static File Access (For Low Concurrency Scenarios)     ║
╚══════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════╝
                                 │
        ┌───────────────────────┼───────────────────────┐
        │                       │                       │
        ▼                       ▼                       ▼
  HTTP-FLV(8501)Output     HLS Live(TS)          fMP4 Live(Segments)
        │                       │                       │
        │                       │                       │
        ▼                       ▼                       ▼
┌────────────────────────────────┐          ┌─────────────────────────────┐
│        FLV Live Gateway         │          │       Static File Gateway    │
│      (Traffic Distribution/     │          │    (Static Resources/        │
│       High Concurrency)         │          │     High Concurrency)        │
│                                │          │                             │
│   ┌────────────────────────┐   │          │   📁 HLS TS Segments         │
│   │     Level 1 Gateway     │   │          │   📁 fMP4 Segment Files      │
│   │    (Regional Node:8080) │   │          │   📁 Merged MP4 VOD Files    │
│   └─────────┬──────────────┘   │          │   📁 FLV Recorded Files      │
│             │                 │          │   📁 Web Players(HTML/CSS/JS) │
│    ┌────────┼────────┐        │          │                             │
│    ▼        ▼        ▼        │          └───────────┬─────────────────┘
│ ┌──────┐ ┌──────┐ ┌──────┐    │                      │
│ │Level2 │ │Level2│ │Level2│    │                 Direct User Access
│ │Gateway│ │Gateway│ │Gateway│    │            (Live/VOD/Player Pages)
│ │:8080 │ │:8080 │ │:8080 │    │
│ └──┬───┘ └──┬───┘ └──┬───┘    │
│    │        │        │        │
└────┼────────┼────────┼────────┘
     │        │        │
     ▼        ▼        ▼
    User     User     User
  (FLV Play)(FLV Play)(FLV Play)
```

### Architecture Description

- **Origin Server**: The唯一的 stream production node, responsible for RTMP push/pull ingestion and multi-protocol transcapsulation. **FLV recording, fMP4 segmentation, and HLS segmentation are three completely independent and parallel tasks**, non-blocking.

- **Origin Static Capability**: The origin has a built-in HTTP service (default port 80) that can directly serve static files. **No additional gateway deployment is needed for low-concurrency scenarios** - it works out of the box.

- **Real-time Recording Mechanism**:
    - **FLV Recording**: Saves the raw stream in real-time, resulting in a complete FLV file after the stream ends
    - **fMP4 Segmentation**: Generates audio/video fMP4 segments in real-time (supports both muxed and separate segment formats), automatically merged into a complete MP4 after the stream ends
    - **HLS Segmentation**: Generates TS segments + m3u8 index in real-time (mobile-compatible)
    - **Independent Switches**: Users can configure whether to enable each recording task independently in `server.php`

- **FLV Live Gateway**: A pure traffic forwarding service that pulls HTTP-FLV streams from upstream, caches stream headers and GOP keyframes for instant playback on new connections, and distributes stream data to clients or downstream gateways. **Specifically designed for high-concurrency live streaming scenarios**, supporting unlimited hierarchical cascading for capacity expansion.

- **Static File Gateway**: A lightweight HTTP static file server that uniformly hosts all static resources. **Specifically designed for high-concurrency VOD scenarios**, capable of handling millions of concurrent static resource accesses. Hosted content includes:
    - HLS TS segments + m3u8 indices
    - fMP4 segment files (muxed/separate)
    - Merged complete MP4 VOD files
    - FLV recorded files
    - Web player pages (`index.html`, `play.html`, `mp4.html`, `play_merge.html`, etc.)

- **Deployment Recommendations**:
    - **Low Concurrency** (< 1000 concurrent users): Use the origin's built-in HTTP service directly, no additional gateways needed
    - **High Concurrency** (> 1000 concurrent users):
        - Origin focuses on "stream ingestion, protocol conversion, real-time recording"
        - FLV Gateway cluster handles "live stream playback", horizontally scaling to handle millions of live concurrent users
        - Static File Gateway cluster handles "VOD playback resources + player pages", horizontally scaling to handle millions of VOD concurrent users

## ✨ Features

- 🎥 **Complete RTMP Push/Pull**: Full protocol implementation, supports standard publish/play commands
- 📡 **HTTP-FLV / WebSocket-FLV**: Low-latency browser live streaming solution
- 🧩 **HLS Automatic Segmentation**: Real-time generation of m3u8 + TS, compatible with all mobile platforms
- 📦 **fMP4 Real-time Segmentation + Auto-merge**: Real-time fMP4 segment generation during live streaming, automatically merged into complete MP4 after stream ends
- 🎬 **Dual fMP4 Format Support**: Supports both muxed (audio/video together) and separate (audio/video split) fMP4 segment formats
- 💾 **Independent FLV Recording**: Real-time saving of raw FLV streams, decoupled from fMP4/MP4 generation
- 🎛️ **Independent Task Switches**: FLV recording, fMP4 segmentation, and HLS segmentation can be independently enabled/disabled
- 🖥️ **Built-in Web Players**: Out-of-the-box support for FLV/HLS/MP4/muxed fMP4/separate fMP4 playback
- 🚀 **Cascadable FLV Gateway**: Unlimited hierarchical distribution, GOP caching for instant playback, auto-reconnection on upstream disconnect, supporting millions of live concurrent users
- 📁 **Static File Gateway**: Unified hosting of recorded resources + player pages, supporting millions of VOD concurrent users
- 🐳 **One-click Docker Deployment**: Quickly spin up test environments
- ⚡ **Pure PHP Implementation**: No dependencies on any third-party streaming media programs

## 📋 Requirements

- PHP >= 8.1 (CLI command line mode only)
- Required extension: `sockets`
- Optional recommended: `pcntl` (Linux/macOS, for improved process management)

## 🚀 Quick Start

### 1. Install the Project
```bash
composer create-project xiaosongshu/rtmp_server
```

### 2. Configure Recording Switches (`server.php`)
```php
// Three independent recording task switches, can be enabled/disabled as needed
define('FLV_TO_RECORD', true);   // Whether to record raw FLV files in real-time
define('FLV_TO_MP4', true);      // Whether to generate fMP4 segments and merge into MP4
define('FLV_TO_HLS', true);      // Whether to generate HLS (TS) segments in real-time
```

### 3. Start the Origin Server
```bash
php server.php
```

### 4. Access Playback (Use Origin Directly for Low Concurrency)
```bash
# Player page access (origin built-in HTTP service)
http://127.0.0.1/index.html      # FLV live page
http://127.0.0.1/play.html       # HLS live page
http://127.0.0.1/mp4.html        # MP4 VOD page
http://127.0.0.1/play_merge.html # fMP4 segment VOD page (supports both muxed/separate formats)
```

### 5. High Concurrency: Start Static File Gateway
```bash
# Default port 8100, hosts all static files in current directory
php fileGateway.php 0.0.0.0 8100
```

### 6. High Concurrency: Start FLV Live Gateway
```bash
# Level 1 gateway: Pull stream from origin
php flvGateway.php 8080 http://OriginIP:8501
```

### 7. Stop Services
| OS          | Stop Command   |
| ----------- | -------------- |
| Windows     | `Ctrl + C`     |
| Linux/macOS | `kill -9 PID`  |

## 🔧 Port Configuration (Modify in `server.php`)

| Port  | Protocol        | Purpose                                |
| ----- | --------------- | -------------------------------------- |
| 1935  | RTMP            | RTMP push, RTMP pull playback          |
| 8501  | HTTP/WebSocket  | HTTP-FLV / WS-FLV live playback        |
| 80    | HTTP            | Static file service + Web player pages |

## 🚀 FLV Live Gateway (High Concurrency Live Distribution)

### Gateway Overview

A lightweight traffic distribution component supporting unlimited hierarchical cascading deployment. Pulls HTTP-FLV from upstream origin/gateway, caches stream headers and GOP keyframes for instant playback on new connections, and replicates stream data to clients or downstream gateways. **Specifically designed for high-concurrency live streaming scenarios**, horizontally scalable to handle millions of live concurrent users.

### Gateway Core Capabilities

- 📡 Single instance concurrent forwarding of multiple streams, simultaneously handling different channel live distributions
- 🔄 Unlimited hierarchical cascading, Level 1 → Level 2 → Level 3 gateway chain expansion
- ⚡ GOP pre-caching, new connections don't wait for keyframes, enabling instant playback
- 🔁 Automatic reconnection on upstream stream disconnect, transparent to end users
- 📊 Built-in operational statistics, outputs online user count and upstream/downstream traffic every 10 seconds
- 🚀 Horizontal scaling: Add gateway nodes to linearly increase concurrent capacity

### FLV Gateway Startup Commands

```bash
# Level 1 gateway: Pull stream from origin
php flvGateway.php 8080 http://OriginIP:8501

# Level 2 gateway: Pull stream from Level 1 gateway
php flvGateway.php 8080 http://Level1GatewayIP:8080

# Level 3 gateway: Pull stream from Level 2 gateway
php flvGateway.php 8080 http://Level2GatewayIP:8080
```

### Gateway Playback URL Format

```
http://GatewayIP:8080/{AppName}/{ChannelName}.flv
```

Example:
```
# Level 1 gateway
http://127.0.0.1:8080/live/stream.flv
# Level 2 gateway
http://127.0.0.1:8081/live/stream.flv
```

### Debug Logging

Add `$gateway->debug = true;` to the gateway startup script to enable full detailed runtime logging.

## 📁 Static File Gateway (High Concurrency VOD Resource Hosting)

### Gateway Overview

A lightweight HTTP static file server that uniformly hosts all static resources. **Specifically designed for high-concurrency VOD scenarios**, horizontally scalable to handle millions of VOD concurrent users. Hosted content includes:
- Recording outputs: HLS TS segments, fMP4 segments (muxed/separate), merged MP4, FLV files
- Web player pages: `index.html`, `play.html`, `mp4.html`, `video.html`, `play_merge.html`, etc.

### Core Capabilities

- 📁 Unified hosting of all static resources (recorded files + player pages)
- 🔗 Supports multi-instance deployment, horizontal scaling linearly increases concurrent capacity
- 📊 Built-in access logs for statistical analysis
- 🚀 Pure PHP implementation, lightweight with no dependencies

### Startup Commands

```bash
# Basic startup (hosts current directory, port 8100)
php fileGateway.php 0.0.0.0 8100

# Specify hosting directory
php fileGateway.php 0.0.0.0 8101 /path/to/media --dir

# Multi-instance deployment (horizontal scaling)
php fileGateway.php 0.0.0.0 8100 &
php fileGateway.php 0.0.0.0 8101 &
php fileGateway.php 0.0.0.0 8102 &
```

### Access URL Format

```
http://GatewayIP:Port/{RelativeFilePath}
```

Example:
```
# Web player pages (accessed via static gateway)
http://127.0.0.1:8100/index.html      # FLV live page
http://127.0.0.1:8100/play.html       # HLS live page
http://127.0.0.1:8100/mp4.html        # MP4 VOD page
http://127.0.0.1:8100/video.html      # FLV VOD page
http://127.0.0.1:8100/play_merge.html # fMP4 segment VOD page (supports both muxed/separate formats)

# Recorded resource access
http://127.0.0.1:8100/hls/live/stream/index.m3u8
http://127.0.0.1:8100/mp4/live/stream/output_merge/init.mp4
http://127.0.0.1:8100/mp4/live/stream/output_merge/stream_full.mp4
http://127.0.0.1:8100/flv/live/stream/20240101_120000.flv
```

## 📡 Push Streaming Tutorial

### RTMP Push URL Format

```
rtmp://127.0.0.1:1935/{AppName}/{ChannelName}
```

- `AppName`: Example `live`
- `ChannelName`: Example `stream`
- Only supports alphanumeric naming

### Push Examples

#### OBS Studio Push
1. Download and install [OBS Studio](https://obsproject.com/)
2. Settings → Stream → Server: `rtmp://127.0.0.1:1935/live`
3. Stream Key: `stream`
4. Start streaming

#### FFmpeg Loop Push
```bash
ffmpeg -re -stream_loop -1 -i "video.mp4" \
  -vcodec h264 -acodec aac -f flv \
  rtmp://127.0.0.1:1935/live/stream
```

## 📺 Playback URL Summary

### Live Streaming URLs

| Protocol       | Access Link                                            | Description                                    |
| -------------- | ------------------------------------------------------ | ---------------------------------------------- |
| RTMP           | `rtmp://127.0.0.1:1935/live/stream`                    | Native RTMP player                             |
| HTTP-FLV       | `http://127.0.0.1:8501/live/stream.flv`                | Low-latency browser playback                   |
| WebSocket-FLV  | `ws://127.0.0.1:8501/live/stream.flv`                  | WebSocket streaming playback                   |
| HLS            | `http://127.0.0.1:80/hls/live/stream/index.m3u8`       | Preferred for Android/iOS mobile (origin)      |

### VOD Playback URLs (After Recording)

| File Type                      | Access URL (Origin/Gateway)                                                  |
| ------------------------------ | ---------------------------------------------------------------------------- |
| Merged MP4 VOD                 | `http://127.0.0.1:80/mp4/live/stream/output_merge/stream_full.mp4`          |
| Muxed fMP4 Segment VOD (MSE)   | `http://127.0.0.1:80/mp4/live/stream/output_merge/init.mp4`                 |
| Separate fMP4 Segment VOD      | `http://127.0.0.1:80/mp4/live/stream/output_separate/audio_init.mp4`        |
| Raw FLV VOD                    | `http://127.0.0.1:80/flv/live/stream/20240101_120000.flv`                   |

> For high-concurrency scenarios, replace `127.0.0.1:80` with the Static File Gateway address (e.g., `127.0.0.1:8100`).

### Web Player Pages

| Page Purpose                  | Access URL (Origin/Gateway)                   | Description                                                       |
| ----------------------------- | --------------------------------------------- | ----------------------------------------------------------------- |
| FLV Live Playback             | `http://127.0.0.1/index.html`                 | HTTP-FLV low-latency live playback                                |
| HLS Live Playback             | `http://127.0.0.1/play.html`                  | HLS mobile-compatible live playback                               |
| Merged MP4 VOD                | `http://127.0.0.1/mp4.html`                   | Complete MP4 file VOD playback                                    |
| Raw FLV VOD                   | `http://127.0.0.1/video.html`                 | FLV native file VOD playback                                      |
| **fMP4 Segment VOD**          | `http://127.0.0.1/play_merge.html`            | **Supports both muxed and separate segment playback**             |

> **`play_merge.html` Capability Description**: This player page is built on MSE (Media Source Extensions) and perfectly supports both fMP4 formats:
> - **Muxed Segments** (`output_merge/`): Audio and video in the same segment, single SourceBuffer playback
> - **Separate Segments** (`output_separate/`): Independent audio/video segments, dual SourceBuffer synchronized playback

## 💾 Real-time Recording Description

### Recording Mechanism (Three Independent Parallel Tasks)

After push streaming starts, the origin simultaneously launches three **independent and parallel** recording tasks, non-blocking:

```
                    ┌─────────────────────────────────────────────────┐
                    │                 RTMP Push Stream                 │
                    └─────────────────────┬───────────────────────────┘
                                          │
                    ┌─────────────────────┼───────────────────────────┐
                    │                     │                           │
                    ▼                     ▼                           ▼
            ┌───────────────┐     ┌───────────────┐           ┌───────────────┐
            │   FLV Record  │     │  fMP4 Segment │           │   HLS Segment │
            │  (Raw Stream) │     │ (Real-time)   │           │ (Real-time)   │
            └───────┬───────┘     └───────┬───────┘           └───────┬───────┘
                    │                     │                           │
                    ▼                     ▼                           ▼
            ┌───────────────┐     ┌───────────────┐           ┌───────────────┐
            │ Complete FLV  │     │  fMP4 Segment │           │  TS Segment   │
            │     File      │     │     Set       │           │  + m3u8 Index │
            │ (After Stream)│     │ (During Live) │           │ (During Live) │
            └───────────────┘     └───────┬───────┘           └───────────────┘
                                          │
                                          │ Auto-merge after stream ends
                                          ▼
                                    ┌───────────────┐
                                    │ Complete MP4  │
                                    │     File      │
                                    │   (VOD)       │
                                    └───────────────┘
```

### Task Independence Description

| Recording Task    | Real-time | Output                                    | Purpose                                    | Independent Switch    |
| ----------------- | --------- | ----------------------------------------- | ------------------------------------------ | --------------------- |
| **FLV Recording** | Yes       | Complete FLV file                         | Raw format backup, VLC playback            | `FLV_TO_RECORD`       |
| **fMP4 Segment**  | Yes       | fMP4 segments → merged to MP4 after stream| Browser MSE playback, VOD                  | `FLV_TO_MP4`          |
| **HLS Segment**   | Yes       | TS segments + m3u8                        | Mobile compatibility, HLS live             | `FLV_TO_HLS`          |

> The three tasks are completely independent and do not affect each other. For example, you can enable only HLS segmentation without recording FLV, or only record FLV without generating fMP4.

### fMP4 Dual Format Support

| Format Type          | Storage Path                   | Characteristics                                    | Use Case                                   |
| -------------------- | ------------------------------ | -------------------------------------------------- | ------------------------------------------ |
| **Muxed Segments**   | `output_merge/`                | Audio/video in same segment, single SourceBuffer  | Simple implementation, good compatibility  |
| **Separate Segments**| `output_separate/`             | Independent audio/video segments, dual SourceBuffer sync | Fine-grained control, independent audio/video processing |

> The `play_merge.html` player page supports both formats above, automatically detecting and selecting the corresponding playback strategy.

### File Storage Directory

| File Type                    | Storage Path                                                 | Notes                                      |
| ---------------------------- | ------------------------------------------------------------ | ------------------------------------------ |
| Raw FLV files                | `./flv/{AppName}/{ChannelName}/`                             | Real-time recorded raw stream              |
| Muxed audio/video fMP4       | `./mp4/{AppName}/{ChannelName}/output_merge/`                | Real-time segments, merged to MP4 after stream |
| Separate audio/video fMP4    | `./mp4/{AppName}/{ChannelName}/output_separate/`             | Audio and video segments isolated          |
| Complete merged MP4          | `./mp4/{AppName}/{ChannelName}/output_merge/{ChannelName}_full.mp4` | **Automatically merged after stream ends** |
| HLS TS segments              | `./hls/{AppName}/{ChannelName}/`                             | Real-time segments for HLS live            |

> All recorded files can be accessed via the origin's built-in HTTP service or the Static File Gateway.

### Recording Notes

- ✅ **Independent Tasks**: Three recording tasks can be enabled/disabled independently without interfering with each other
- ✅ **Real-time Segmentation**: fMP4 and HLS segments are generated in real-time during streaming, supporting mid-stream playback
- ✅ **Auto-merge**: After the live stream ends, fMP4 segments are automatically merged into a complete MP4 file
- ✅ **Dual Format Support**: fMP4 supports both muxed and separate segment formats
- ✅ Muxed fMP4 and complete MP4 are standard formats, supporting seek and progress bar jumping
- ⚠️ **Repeated push to the same AppName + ChannelName will overwrite old recorded files**
- ⚠️ The service does not automatically clean up expired files; you need to implement your own scheduled cleanup scripts

### Offline Manual Transcoding (Optional)

The project depends on the `xiaosongshu/flv2mp4` component, supporting offline file conversion (as a supplement to real-time recording):

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '512M');
$file = __DIR__."/test.flv";

// Example 1: FLV → Muxed fMP4 + Complete MP4
$outputDir1 = __DIR__."/output_merge";
try{
    $res = \Xiaosongshu\Flv2mp4\Client::runFlv2mp4($file, $outputDir1);
    echo "Conversion complete: " . $res;
}catch (\Exception $e){
    echo "Error: " . $e->getMessage();
}

// Example 2: FLV → Separate audio/video fMP4
$outputDir2 = __DIR__."/output_separate";
try{
    $res = \Xiaosongshu\Flv2mp4\Client::runFlv2mp4Separate($file, $outputDir2);
}catch (\Exception $e){}
```

## 📁 Project Directory Structure

```
rtmp_server/
├── flv/                              # Raw FLV recorded files (FLV_TO_RECORD)
│   └── {AppName}/{ChannelName}/
│       └── *.flv
├── mp4/                              # MP4/fMP4 transcoding outputs (FLV_TO_MP4)
│   └── {AppName}/{ChannelName}/
│       ├── output_merge/             # Muxed audio/video segments
│       │   ├── init.mp4
│       │   ├── segment_1.m4s
│       │   └── {ChannelName}_full.mp4       # Automatically merged after stream ends
│       └── output_separate/          # Separate audio/video segments
│           ├── audio_init.mp4
│           ├── audio_1.m4s
│           └── video_1.m4s
├── hls/                              # HLS TS segments + m3u8 index (FLV_TO_HLS)
│   └── {AppName}/{ChannelName}/
├── MediaServer/                      # RTMP core protocol, push/pull session logic
├── Root/                             # Low-level async IO, Socket event driver
├── SabreAMF/                         # AMF0/AMF3 encoding/decoding (RTMP command parsing)
├── server.php                        # Origin startup entry (with three independent recording switches + built-in HTTP service)
├── fileGateway.php                   # Static File Gateway (high concurrency VOD resource hosting)
├── flvGateway.php                    # FLV Live Gateway (high concurrency live distribution)
├── index.html / play.html / mp4.html # Web player pages (FLV/HLS/MP4)
├── play_merge.html                   # fMP4 segment VOD page (supports both muxed/separate formats)
└── README.md / README.cn.md
```

## ❓ FAQ

### 1. Missing extension on startup

- **Cause**: PHP-CLI and PHP-FPM use different `php.ini` files
- **Solution**: Run `php -m` to check installed extensions, add `sockets`; Docker environment recommended to avoid environment issues.

### 2. Port already in use

- **Check**: Windows `netstat -ano | findstr PORT` / Linux `lsof -i:PORT`
- **Solution**: Modify port configuration in `server.php` and update hardcoded addresses in frontend pages accordingly.

### 3. Web player cannot pull stream

1. Verify services are running properly and firewall ports are open
2. Check that the playback URL in the page matches the push `app/stream` name
3. For low concurrency, use origin address directly (`http://127.0.0.1/xxx.html`)
4. For high concurrency, access player pages through the Static File Gateway

### 4. How to configure recording tasks independently?

Modify the three constants in `server.php`:
```php
define('FLV_TO_RECORD', true);   // Whether to record raw FLV files
define('FLV_TO_MP4', true);      // Whether to generate fMP4 segments and merge into MP4
define('FLV_TO_HLS', true);      // Whether to generate HLS (TS) segments
```
Each task can be enabled/disabled independently without affecting others.

### 5. When is the MP4 file generated?

- **Real-time**: fMP4 segments are generated in real-time during streaming (for MSE playback)
- **Merge timing**: The complete MP4 file is **automatically merged after the live stream ends**
- If you need MP4 during the live stream, it's recommended to use FLV or HLS format instead

### 6. What's the difference between muxed and separate fMP4 segments?

| Feature           | Muxed Segments (output_merge)               | Separate Segments (output_separate)                 |
| ----------------- | ------------------------------------------- | --------------------------------------------------- |
| File Structure    | Single file containing audio and video      | Independent audio and video segments                |
| Playback Method   | Single SourceBuffer                         | Dual SourceBuffer synchronized                      |
| Use Case          | Simple implementation, good compatibility   | Fine-grained control, independent audio/video processing |
| Player Page       | `play_merge.html` (auto-adapts both formats)| `play_merge.html` (auto-adapts both formats)        |

### 7. Gateway playback lag or frequent disconnections

1. Check upstream origin network stability
2. Recommended gateway hierarchy ≤ 3 levels; more levels increase latency
3. Enable gateway debug logging to locate issues: `$gateway->debug = true;`
4. For high concurrency, add more gateway nodes horizontally

### 8. When do I need to deploy gateways?

| Concurrency Scenario        | Deployment Solution                                           |
| --------------------------- | ------------------------------------------------------------- |
| **Low Concurrency** (< 1000) | Origin only, origin built-in HTTP service serves directly    |
| **High Concurrency** (> 1000) | Origin + FLV Gateway cluster + Static File Gateway cluster   |
| **Million-level Concurrency** | Multi-level FLV Gateway + multi-instance Static File Gateway, horizontal scaling |

### 9. What's the difference between FLV Gateway and Static File Gateway?

| Gateway Type            | Purpose                    | Resource Types Handled                           | Concurrent Capacity               |
| ----------------------- | -------------------------- | ------------------------------------------------ | --------------------------------- |
| **FLV Live Gateway**    | Live stream distribution   | HTTP-FLV real-time streams                       | Horizontal scaling to millions    |
| **Static File Gateway** | Unified static hosting     | HLS/fMP4/MP4/FLV static files + Web player pages | Horizontal scaling to millions    |

## 📄 License

This project is for learning and technical research purposes only. Commercial use risks are borne by the user.

## ⚠️ Disclaimer

1. Some open source code is sourced from the open source community; if copyright is involved, please contact the author for removal
2. This project is completely open source and free, intended only for technical communication
3. The author assumes no liability for any legal consequences arising from commercial or illegal use by users

## 📧 Contact

Technical support and questions: **2723659854@qq.com**