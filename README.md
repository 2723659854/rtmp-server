# RTMP Server

<p align="center">
  <a href="./README.cn.md"><strong>🇨🇳 中文</strong></a> •
  <a href="./README.md"><strong>🇬🇧 English</strong></a>
</p>

> A lightweight, pure PHP RTMP live streaming server. Build your own live streaming service without external dependencies like Nginx or SRS.

## ✨ Features

- 🎥 **RTMP Push/Pull** – Full RTMP protocol support
- 📡 **HTTP-FLV / WebSocket-FLV** – Low-latency browser playback
- 🧩 **HLS Output** – Auto-generate M3U8 + TS segments, mobile friendly
- 💾 **Automatic Recording** – Record live streams as FLV, MP4 (mixed/separate fMP4 segments)
- 🖥️ **Built-in Players** – Multiple ready-to-use web playback pages
- 🐳 **Docker Support** – One-click development environment
- ⚡ **Pure PHP** – Zero dependency on Nginx, SRS, or other third-party streaming software

## 📋 Requirements

- PHP >= 8.1 (CLI mode)
- Enabled extensions:
  - `sockets`
  - `pcntl` (optional for Linux/macOS, recommended)

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

| System       | Command          |
|-------------|------------------|
| Windows     | `Ctrl + C`       |
| Linux/macOS | `kill -9 PID`   |

## 🔧 Configuration

### Ports (modifiable in `server.php`)

| Port | Protocol       | Purpose                                |
|------|---------------|----------------------------------------|
| 1935 | RTMP          | Push / Pull                            |
| 8501 | HTTP/WebSocket | FLV playback (live)                    |
| 80   | HTTP          | HLS playback + Web UI + static file replay |

## 📡 Pushing a Stream

### Push URL Format

```
rtmp://127.0.0.1:1935/{app}/{stream}
```

- `{app}`: e.g. `live`
- `{stream}`: e.g. `stream`
- Only alphanumeric characters are supported

### Example Encoders

#### OBS Studio

1. Download [OBS Studio](https://obsproject.com/)
2. Settings → Stream → Server: `rtmp://127.0.0.1:1935/live`
3. Stream Key: `stream`
4. Start Streaming

#### FFmpeg

```bash
ffmpeg -re -stream_loop -1 -i "video.mp4" \
  -vcodec h264 -acodec aac -f flv \
  rtmp://127.0.0.1:1935/live/stream
```

## 📺 Playback & Pulling Streams

### Live Stream URLs (real-time)

| Protocol       | URL                                                         | Description                       |
|---------------|------------------------------------------------------------|-----------------------------------|
| RTMP          | `rtmp://127.0.0.1:1935/live/stream`                        | Native RTMP                       |
| HTTP-FLV      | `http://127.0.0.1:8501/live/stream.flv`                    | Low-latency browser playback      |
| WebSocket-FLV | `ws://127.0.0.1:8501/live/stream.flv`                      | WebSocket version                 |
| HLS           | `http://127.0.0.1:80/hls/live/stream/index.m3u8`           | Recommended for mobile            |

### Built-in Web Players

After starting the server, open the following URLs in your browser (update the stream path in the pages if you used a different channel name):

#### 🔴 Live Test Pages

| Page             | URL                                | Description                            |
|----------------|-----------------------------------|----------------------------------------|
| FLV Live Player  | `http://127.0.0.1:80/index.html`  | HTTP-FLV playback, click button to start |
| HLS Live Player  | `http://127.0.0.1:80/play.html`   | HLS playback, mobile compatible        |

> The default push address is `rtmp://127.0.0.1:1935/live/stream`, matching the stream name `live/stream` in the pages.  
> If you use a different stream name, modify the stream URL in the page accordingly.

#### 🔵 Static File Replay Pages

| Page         | URL                                  | Description                           |
|------------|-------------------------------------|---------------------------------------|
| MP4 Replay   | `http://127.0.0.1:80/mp4.html`      | Play merged MP4 files                 |
| FLV Replay   | `http://127.0.0.1:80/video.html`    | Play raw FLV files                    |
| fMP4 Replay  | `http://127.0.0.1:80/play_merge.html` | Play fMP4 segments (mixed/separate) |

> Recorded files are saved in `./mp4/` and `./flv/` by default. Adjust the video path in the page as needed.

## 💾 Automatic Recording

### How It Works

1. **Streaming starts** → begins recording the raw FLV stream automatically
2. **Streaming ends** → saves the raw FLV and transcodes it into MP4-related files

### Output Paths

| Type                      | Path                                                  | Description                                                                 |
|---------------------------|-------------------------------------------------------|-----------------------------------------------------------------------------|
| Raw FLV                   | `./flv/{app}/{stream}/`                              | The original FLV recorded in real time                                      |
| Mixed MP4 Segments        | `./mp4/{app}/{stream}/output_merge/`                  | Each segment contains both audio and video, ready for browser playback     |
| Separate MP4 Segments     | `./mp4/{app}/{stream}/output_separate/`               | Audio and video separated, for advanced custom playback                     |
| Merged MP4 File           | `./mp4/{app}/{stream}/output_merge/{stream}_full.mp4` | All segments merged into one full MP4 file                                 |

> **Notes**:
> - **Mixed segments**: each piece has both audio and video, ideal for `<video>` tag + MSE playback.
> - **Separate segments**: audio and video are stored independently, allowing flexible streaming (e.g., selective loading).
> - The merged file is named `{stream}_full.mp4`. For example, if you pushed to `stream`, the file will be `stream_full.mp4`.

### Important

- ✅ Raw FLV files can be played directly with VLC or other players
- ✅ Mixed MP4 segments and the merged file follow the standard fMP4 format, supporting drag-and-seek
- ✅ Separate segments can be played via `play_merge.html` (browser MSE)
- ⚠️ Streaming again with the same path **overwrites** previous recordings (both FLV and MP4 series files)
- ⚠️ The server does not clean up files automatically; manage them manually as needed

### Manual Transcoding (optional)

If you need to transcode existing files, use the `xiaosongshu/flv2mp4` package (already extracted from this project):

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '512M');

$file = __DIR__."/test.flv";


echo "=== Example 1: Segment FLV into fMP4 and merge to MP4 ===\n";
$outputDir1 = __DIR__."/output_merge";
try{
    $res = \Xiaosongshu\Flv2mp4\Client::runFlv2mp4($file, $outputDir1);
    echo "\nDone: " . $res . "\n\n";
}catch (\Exception $e){
    echo "Error: " . $e->getMessage() . "\n\n";
}


echo "=== Example 2: Generate separate audio/video fMP4 segments ===\n";
$outputDir2 = __DIR__."/output_separate";
try{
    $res = \Xiaosongshu\Flv2mp4\Client::runFlv2mp4Separate($file, $outputDir2);
    echo "\nDone! Generated files:\n";
    echo "  Audio init: " . ($res['audioInit'] ?? 'none') . "\n";
    echo "  Video init: " . ($res['videoInit'] ?? 'none') . "\n";
    echo "  Audio segments: " . count($res['audioSegments']) . "\n";
    echo "  Video segments: " . count($res['videoSegments']) . "\n";
    echo "  Metadata file: " . ($res['meta'] ?? 'none') . "\n";
}catch (\Exception $e){
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\n === Example 3: Convert FLV to HLS === \n";
$outputDir1 = __DIR__ . "/hls";
try {
    $res = \Xiaosongshu\Flv2mp4\Client::runFlv2Hls($file, $outputDir1);
    echo "\n HLS conversion done: index = {$res['index']} dir = {$res['outputDir']}\n\n";

    echo "\n === Example 4: Merge HLS back to FLV === \n";
    $outputFlv = __DIR__ . "/output_from_hls.flv";
    try {
        $res2 = \Xiaosongshu\Flv2mp4\Client::runHls2Flv($res['index'], $outputFlv);
        echo "\n HLS → FLV done: {$res2}\n\n";
    } catch (\Exception $e) {
        echo "Error: " . $e->getMessage() . "\n\n";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n\n";
}


echo "\n === Example 5: Convert MP4 to FLV === \n";
$mp4File = __DIR__ . "/test.mp4";
$flvFromMp4 = __DIR__ . "/output_from_mp4.flv";
try {
    if (file_exists($mp4File)) {
        $res3 = \Xiaosongshu\Flv2mp4\Client::runMp42Flv($mp4File, $flvFromMp4);
        echo "\n MP4 → FLV done: {$res3}\n\n";
    } else {
        echo "Skipped: test file not found {$mp4File}\n\n";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n\n";
}
```

> The `xiaosongshu/flv2mp4` package is a standalone conversion tool extracted from this project. It supports bidirectional conversion among FLV, MP4, and HLS. The examples above are for static files; live stream transcoding is already handled internally by this server.

## 📁 Directory Structure

```
rtmp_server/
├── flv/                              # Raw FLV recording files
│   └── {app}/{name}/
│       └── *.flv
├── mp4/                              # MP4 transcoded output
│   └── {app}/{name}/
│       ├── output_merge/             # Mixed segments (audio+video together)
│       │   ├── init.mp4
│       │   ├── segment_1.m4s
│       │   ├── segment_2.m4s
│       │   └── {name}_full.mp4       # Merged complete MP4 file
│       └── output_separate/          # Separate segments (audio/video apart)
│           ├── audio_init.mp4
│           ├── audio_1.m4s
│           ├── video_init.mp4
│           └── video_1.m4s
├── hls/                              # HLS segments (TS + M3U8)
│   └── {app}/{name}/
├── MediaServer/                      # Core streaming service (protocol handling, session management)
├── Root/                             # I/O server (event-driven, network communication)
├── SabreAMF/                         # RTMP command toolkit (AMF encoding/decoding)
├── server.php                        # Entry point
├── index.html                        # FLV live player page
├── play.html                         # HLS live player page
├── mp4.html                          # MP4 replay page (merged file)
├── video.html                        # FLV replay page
├── play_merge.html                   # fMP4 segment player (supports mixed/separate segments)
└── README.md
```

> **Directory notes**:
> - `MediaServer`: Core streaming logic, handles RTMP protocol, sessions, publish/play.
> - `Root`: I/O server responsible for low-level socket event loop, network I/O.
> - `SabreAMF`: AMF0/AMF3 encoding/decoding library for processing RTMP command messages (e.g., connect, publish, play).

## ❓ FAQ

### 1. Missing Extensions at Runtime

- **Reason**: PHP CLI and FPM may have different extension configurations.
- **Solution**:
    - Run `php -m` to list enabled extensions
    - Install missing ones (e.g., `sockets`)
    - Using Docker is recommended to avoid this issue

### 2. Port Already in Use

- **Solution**:
    - Check port usage: `netstat -ano | findstr <port>`
    - Modify the port configuration in `server.php`
    - Update the corresponding ports in the player pages

### 3. Playback Page Cannot Connect

- **Solution**:
    - Ensure the server is running and ports are not blocked by a firewall
    - Verify that the stream path in the page matches your actual push path
    - If you changed the port, also update the port in the page

### 4. Recordings Are Overwritten

- **Symptom**: Pushing to the same stream name overwrites previous recordings.
- **Solution**:
    - Use a different stream name each time
    - Or implement your own file backup/cleanup logic

### 5. No Recording Files Generated

- **Symptom**: After the stream ends, no recording files exist.
- **Solution**:
    - Check the configuration in `server.php` – recording may be disabled
    - By default, only HLS conversion is enabled; MP4 and FLV recording are turned off

## 📄 License

This project is for learning and communication purposes. For commercial use, please evaluate accordingly.

## ⚠️ Disclaimer

- Some code may originate from the internet. If any copyright issues arise, please contact the author for removal.
- This project is fully open-source and intended only for technical exchange.
- Users assume full legal responsibility for any risks, disputes, or damages arising from its use.
- The author is not liable for any loss or damage caused by using this project.

## 📧 Contact

For questions or suggestions, feel free to contact via email:

📧 **2723659854@qq.com**