# RTMP Server

<p align="center">
  <a href="./README.cn.md"><strong>🇨🇳 中文</strong></a> •
  <a href="./README.md"><strong>🇬🇧 English</strong></a>
</p>

> A lightweight RTMP live streaming server built with pure PHP. No Nginx, SRS or other external dependencies required to quickly set up a live streaming service.

## ✨ Features

- 🎥 **RTMP Push/Pull** – Full RTMP protocol support
- 📡 **HTTP-FLV / WebSocket-FLV** – Low-latency playback in browsers
- 🧩 **HLS Output** – Auto-generates M3U8 + TS segments, mobile-friendly
- 💾 **Auto Recording** – Automatically records during push, saves as FLV, MP4 (mixed/separate segments) and fMP4 segments
- 🖥️ **Built-in Player** – Ready-to-use web playback pages
- 🐳 **Docker Support** – One‑click development environment
- ⚡ **Pure PHP Implementation** – No need for Nginx, SRS or other streaming software

## 📋 Requirements

- PHP >= 8.1 (CLI mode)
- Enabled extensions:
  - `sockets`
  - `pcntl` (optional on Linux/macOS, recommended)

## 🚀 Quick Start

### Installation

```bash
composer create-project xiaosongshu/rtmp_server
```

### Start Server

```bash
php server.php
```

### Stop Server

| System        | Command   |
|---------------|-----------|
| Windows       | `Ctrl + C` |
| Linux/macOS   |  kill -9 PID |

## 🔧 Configuration

### Ports (can be modified in `server.php`)

| Port   | Protocol        | Purpose                                  |
|--------|-----------------|------------------------------------------|
| 1935   | RTMP            | Push / Pull                              |
| 8501   | HTTP/WebSocket  | FLV playback (live)                      |
| 80     | HTTP            | HLS playback + Web pages + static files  |

## 📡 Pushing Streams

### Push URL Format

```
rtmp://127.0.0.1:1935/{app_name}/{channel_name}
```

- `{app_name}`: e.g. `live`
- `{channel_name}`: e.g. `stream`
- Only English letters and numbers allowed

### Push Tools

#### Using OBS Studio

1. Download [OBS Studio](https://obsproject.com/)
2. Settings → Stream → Server: `rtmp://127.0.0.1:1935/live`
3. Stream Key: `stream`
4. Start streaming

#### Using FFmpeg

```bash
ffmpeg -re -stream_loop -1 -i "video.mp4" \
  -vcodec h264 -acodec aac -f flv \
  rtmp://127.0.0.1:1935/live/stream
```

## 📺 Pulling & Playing

### Live Stream URLs

| Protocol        | URL                                                           | Description                  |
|-----------------|---------------------------------------------------------------|------------------------------|
| RTMP            | `rtmp://127.0.0.1:1935/live/stream`                          | Native RTMP                  |
| HTTP-FLV        | `http://127.0.0.1:8501/live/stream.flv`                      | Low-latency browser playback |
| WebSocket-FLV   | `ws://127.0.0.1:8501/live/stream.flv`                        | WebSocket version            |
| HLS             | `http://127.0.0.1:80/hls/live/stream/index.m3u8`             | Recommended for mobile       |

### Built-in Web Pages

After starting the server, open the following addresses in a browser (modify the stream path according to your actual push channel):

#### 🔴 Live Test Pages

| Page                | URL                                 | Description                                   |
|---------------------|-------------------------------------|-----------------------------------------------|
| FLV live playback   | `http://127.0.0.1:80/index.html`    | HTTP-FLV playback, requires button click     |
| HLS live playback   | `http://127.0.0.1:80/play.html`     | HLS playback, mobile compatible              |

> Default push address is `rtmp://127.0.0.1:1935/live/stream`, corresponding to stream name `live/stream` in the pages.  
> If you use a different channel name, please update the stream address in the page accordingly.

#### 🔵 Static File Playback Pages

| Page             | URL                                   | Description                               |
|------------------|---------------------------------------|-------------------------------------------|
| MP4 playback     | `http://127.0.0.1:80/mp4.html`        | Play merged MP4 file                      |
| FLV playback     | `http://127.0.0.1:80/video.html`      | Play original FLV file                    |
| fMP4 playback    | `http://127.0.0.1:80/play_merge.html` | Play fMP4 segments (mixed / separate)     |

> Recorded files are saved under `./mp4/` and `./flv/` by default. Adjust the video path in the page as needed.

## 💾 Auto Recording

### How It Works

1. **Push starts** → starts recording original FLV stream
2. **Push ends** → automatically saves original FLV file and transcodes to MP4 related files

### Storage Paths

| Type                     | Path                                                           | Description                                                   |
|--------------------------|----------------------------------------------------------------|---------------------------------------------------------------|
| Original FLV             | `./flv/{app_name}/{channel_name}/`                             | Real‑time recorded FLV file                                   |
| MP4 mixed segments       | `./mp4/{app_name}/{channel_name}/output_merge/`                | Each segment contains both audio and video, ready for browser |
| MP4 separate segments    | `./mp4/{app_name}/{channel_name}/output_separate/`             | Audio and video in separate segments for advanced use cases   |
| Merged MP4               | `./mp4/{app_name}/{channel_name}/output_merge/{channel_name}_full.mp4` | Full MP4 file merged from all segments                       |

> **Notes**:
> - Mixed segments: each contains both audio and video, suitable for direct playback via `<video>` + MSE.
> - Separate segments: audio and video stored separately, can be used for flexible stream processing (e.g. selective loading).
> - The merged file is named `{channel_name}_full.mp4` (e.g. `stream_full.mp4` for channel `stream`).

### Important Notes

- ✅ Original FLV files can be played with VLC or other players
- ✅ MP4 mixed segments and merged files are standard fMP4 format, support seeking and drag‑to‑play
- ✅ Separate segments can be played via `play_merge.html` (browser MSE)
- ⚠️ Same push path will **overwrite** previous recordings (both FLV and MP4 files)
- ⚠️ The server does not automatically clean up files; please manage them as needed

### Manual FLV to MP4 Conversion (Optional)

If you need to convert a recorded FLV file to MP4, you can use the `xiaosongshu/flv2mp4` package:

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '512M');

$file = __DIR__ . "/test.flv";

// Method 1: Merge into a single MP4 (mixed mode)
$outputDir1 = __DIR__ . "/output_merge";
try {
    $res = \Xiaosongshu\Flv2mp4\Client::run($file, $outputDir1);
    echo "Conversion completed: " . $res . "\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Method 2: Generate separate audio/video segments
$outputDir2 = __DIR__ . "/output_separate";
try {
    $res = \Xiaosongshu\Flv2mp4\Client::runSeparate($file, $outputDir2);
    echo "Conversion completed! Generated files:\n";
    echo "  Audio init: " . ($res['audioInit'] ?? 'none') . "\n";
    echo "  Video init: " . ($res['videoInit'] ?? 'none') . "\n";
    echo "  Audio segments count: " . count($res['audioSegments']) . "\n";
    echo "  Video segments count: " . count($res['videoSegments']) . "\n";
    echo "  Metadata file: " . ($res['meta'] ?? 'none') . "\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
```

> During pushing, the server automatically converts the live stream to MP4, so manual conversion is usually not needed.

## 📁 Directory Structure

```
rtmp_server/
├── flv/                              # Original FLV recordings
│   └── {app}/{name}/
│       └── *.flv
├── mp4/                              # MP4 transcoding output
│   └── {app}/{name}/
│       ├── output_merge/             # Mixed segments (audio+video)
│       │   ├── init.mp4
│       │   ├── segment_1.m4s
│       │   ├── segment_2.m4s
│       │   └── {name}_full.mp4       # Merged full MP4 file
│       └── output_separate/          # Separate segments (audio/video split)
│           ├── audio_init.mp4
│           ├── audio_1.m4s
│           ├── video_init.mp4
│           └── video_1.m4s
├── hls/                              # HLS segments (TS + M3U8)
│   └── {app}/{name}/
├── MediaServer/                      # Core streaming service (protocol parsing, session management)
├── Root/                             # IO server (event loop, network communication)
├── SabreAMF/                         # RTMP command toolkit (AMF encoding/decoding)
├── server.php                        # Service entry point
├── index.html                        # FLV live playback page
├── play.html                         # HLS live playback page
├── mp4.html                          # MP4 playback page (merged file)
├── video.html                        # FLV playback page
├── play_merge.html                   # fMP4 segment playback page (mixed/separate)
└── README.md
```

> **Directory descriptions**:
> - `MediaServer`: Core streaming logic, handles RTMP protocol, session management, push/pull.
> - `Root`: IO server, responsible for low‑level socket event loop and network I/O.
> - `SabreAMF`: AMF0/AMF3 codec library for processing RTMP command messages (connect, publish, play, etc.).

## ❓ FAQ

### 1. Missing extension error when running

- **Cause**: PHP CLI and FPM may have different extension configurations
- **Solution**:
    - Run `php -m` to check enabled extensions
    - Install missing extensions (e.g. `sockets`)
    - Use the Docker environment to avoid this issue

### 2. Port already in use

- **Solution**:
    - Check port usage: `netstat -ano | findstr <port>`
    - Modify port configuration in `server.php`
    - Update the corresponding ports in the web pages

### 3. Playback page cannot connect

- **Solution**:
    - Make sure the server is running and the port is not blocked by a firewall
    - Verify that the playback address in the page matches the actual push path
    - If you changed the port, update it in the page as well

### 4. Recorded files being overwritten

- **Phenomenon**: Pushing to the same channel overwrites previous recordings
- **Solution**:
    - Use a different channel name for each push
    - Or implement your own backup / cleanup logic

## 📄 License

This project is for learning and communication only. Commercial use is at your own discretion.

## ⚠️ Disclaimer

- Some code may come from the internet; please contact for removal if any infringement
- This project is completely open source and intended for technical exchange
- Users assume all legal risks associated with using this project
- The author is not liable for any loss caused by using this project

## 📧 Contact

For questions or suggestions, please contact via email:

📧 **2723659854@qq.com**
