# RTMP Server

<p align="center">
  <a href="./README.cn.md"><strong>🇨🇳 中文</strong></a> •
  <a href="./README.md"><strong>🇬🇧 English</strong></a>
</p>

> 一款纯 PHP 实现的轻量级 RTMP 直播服务器，无需 Nginx、SRS 等外部依赖，即可快速搭建直播服务。

## ✨ 功能特性

- 🎥 **RTMP 推流/拉流** – 完整支持 RTMP 协议
- 📡 **HTTP-FLV / WebSocket-FLV** – 浏览器低延迟播放
- 🧩 **HLS 输出** – 自动生成 M3U8 + TS 切片，移动端友好
- 💾 **自动录制** – 推流时自动录制，同时保存为 FLV、MP4（混合切片/分离切片）及 fMP4 切片
- 🖥️ **内置播放器** – 多种 Web 播放页面，开箱即用
- 🐳 **Docker 支持** – 一键启动开发环境
- ⚡ **纯 PHP 实现** – 不依赖 Nginx、SRS 等第三方流媒体软件

## 📋 环境要求

- PHP >= 8.1（CLI 模式）
- 已启用的扩展：
  - `sockets`
  - `pcntl`（Linux/macOS 可选，建议开启）

## 🚀 快速开始

### 安装

```bash
composer create-project xiaosongshu/rtmp_server
```

### 启动服务

```bash
php server.php
```

### 停止服务

| 系统          | 命令                               |
|-------------|------------------------------------|
| Windows     | `Ctrl + C`                         |
| Linux/macOS | kill -9 PID |

## 🔧 配置说明

### 端口配置（可在 `server.php` 中修改）

| 端口   | 协议            | 用途                          |
|------|---------------|-----------------------------|
| 1935 | RTMP          | 推流 / 拉流                     |
| 8501 | HTTP/WebSocket | FLV 播放（直播）                  |
| 80   | HTTP          | HLS 播放 + Web 页面 + 静态文件回放 |

## 📡 推流指南

### 推流地址格式

```
rtmp://127.0.0.1:1935/{应用名}/{频道名}
```

- `{应用名}`：例如 `live`
- `{频道名}`：例如 `stream`
- 仅支持英文和数字

### 推流工具示例

#### 使用 OBS Studio

1. 下载 [OBS Studio](https://obsproject.com/)
2. 设置 → 推流 → 服务器：`rtmp://127.0.0.1:1935/live`
3. 串流密钥：`stream`
4. 开始推流

#### 使用 FFmpeg

```bash
ffmpeg -re -stream_loop -1 -i "video.mp4" \
  -vcodec h264 -acodec aac -f flv \
  rtmp://127.0.0.1:1935/live/stream
```

## 📺 拉流与播放指南

### 直播流地址（实时播放）

| 协议            | 地址                                                         | 说明               |
|---------------|------------------------------------------------------------|------------------|
| RTMP          | `rtmp://127.0.0.1:1935/live/stream`                        | 原生 RTMP          |
| HTTP-FLV      | `http://127.0.0.1:8501/live/stream.flv`                    | 浏览器低延迟播放         |
| WebSocket-FLV | `ws://127.0.0.1:8501/live/stream.flv`                      | WebSocket 版      |
| HLS           | `http://127.0.0.1:80/hls/live/stream/index.m3u8`           | 移动端推荐            |

### 内置 Web 播放页面

启动服务器后，在浏览器中打开以下地址（请根据实际推流频道修改页面中的流地址）：

#### 🔴 直播测试页面

| 页面             | 地址                                | 说明                         |
|----------------|-----------------------------------|----------------------------|
| FLV 直播播放      | `http://127.0.0.1:80/index.html`  | HTTP-FLV 播放，需点击按钮开始       |
| HLS 直播播放      | `http://127.0.0.1:80/play.html`   | HLS 播放，移动端兼容              |

> 默认推流地址为 `rtmp://127.0.0.1:1935/live/stream`，页面对应流名 `live/stream`。  
> 如使用不同频道名，请手动修改页面中的流地址。

#### 🔵 静态文件回放页面

| 页面         | 地址                                  | 说明                |
|------------|-------------------------------------|-------------------|
| MP4 回放     | `http://127.0.0.1:80/mp4.html`      | 播放合并后的 MP4 文件       |
| FLV 回放     | `http://127.0.0.1:80/video.html`    | 播放原始 FLV 文件       |
| fMP4 回放    | `http://127.0.0.1:80/play_merge.html` | 播放 fMP4 切片（混合/分离）     |

> 录制文件默认保存在 `./mp4/` 和 `./flv/` 目录下，请根据页面提示修改视频路径。

## 💾 自动录制

### 工作原理

1. **推流开始** → 自动开始录制原始 FLV 流
2. **推流结束** → 自动保存原始 FLV 文件，并转码生成 MP4 相关文件

### 文件存储路径

| 类型         | 路径                                         | 说明                           |
|------------|--------------------------------------------|------------------------------|
| 原始 FLV     | `./flv/{应用名}/{频道名}/`                    | 推流时实时录制的原始 FLV 文件            |
| MP4 混合切片   | `./mp4/{应用名}/{频道名}/output_merge/`       | 每个切片同时包含音视频，可直接用于浏览器播放       |
| MP4 分离切片   | `./mp4/{应用名}/{频道名}/output_separate/`    | 音频和视频分开切片，适用于高级自定义播放场景       |
| 合并后的 MP4   | `./mp4/{应用名}/{频道名}/output_merge/{频道名}_full.mp4` | 所有切片合并后的完整 MP4 文件 |

> **说明**：
> - 混合切片：每片包含完整的音视频，适合直接通过 `<video>` 标签 + MSE 播放。
> - 分离切片：音频和视频分开存储，可用于更灵活的流式处理（如选择性加载）。
> - 合并后的文件命名为 `{频道名}_full.mp4`，例如推流频道为 `stream`，则文件名为 `stream_full.mp4`。

### 注意事项

- ✅ 原始 FLV 文件可用 VLC 等播放器直接打开
- ✅ MP4 混合切片和合并后的文件均为标准 fMP4 格式，支持拖拽播放和 seek 操作
- ✅ 分离切片可通过 `play_merge.html` 页面播放（浏览器 MSE）
- ⚠️ 相同推流路径会**覆盖**之前的录制文件（包括 FLV 和 MP4 系列文件）
- ⚠️ 服务器不会自动清理文件，请按需自行管理

### 手动 FLV 转 MP4（可选）

若需将已录制的 FLV 转为 MP4，可调用 `xiaosongshu/flv2mp4` 包：

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '512M');

$file = __DIR__ . "/test.flv";

// 方式1：合并为单个 MP4（混合模式）
$outputDir1 = __DIR__ . "/output_merge";
try {
    $res = \Xiaosongshu\Flv2mp4\Client::run($file, $outputDir1);
    echo "转换完成: " . $res . "\n";
} catch (\Exception $e) {
    echo "错误: " . $e->getMessage() . "\n";
}

// 方式2：生成分离的音视频切片
$outputDir2 = __DIR__ . "/output_separate";
try {
    $res = \Xiaosongshu\Flv2mp4\Client::runSeparate($file, $outputDir2);
    echo "转换完成！生成的文件:\n";
    echo "  音频初始化: " . ($res['audioInit'] ?? '无') . "\n";
    echo "  视频初始化: " . ($res['videoInit'] ?? '无') . "\n";
    echo "  音频切片数: " . count($res['audioSegments']) . "\n";
    echo "  视频切片数: " . count($res['videoSegments']) . "\n";
    echo "  元数据文件: " . ($res['meta'] ?? '无') . "\n";
} catch (\Exception $e) {
    echo "错误: " . $e->getMessage() . "\n";
}
```

> 推流过程中服务器会自动将直播流转为 MP4，一般无需手动操作。

## 📁 目录结构

```
rtmp_server/
├── flv/                              # 原始 FLV 录制文件
│   └── {app}/{name}/
│       └── *.flv
├── mp4/                              # MP4 转码输出目录
│   └── {app}/{name}/
│       ├── output_merge/             # 混合切片（音视频合一）
│       │   ├── init.mp4
│       │   ├── segment_1.m4s
│       │   ├── segment_2.m4s
│       │   └── {name}_full.mp4       # 合并后的完整 MP4 文件
│       └── output_separate/          # 分离切片（音视频分开）
│           ├── audio_init.mp4
│           ├── audio_1.m4s
│           ├── video_init.mp4
│           └── video_1.m4s
├── hls/                              # HLS 切片（TS + M3U8）
│   └── {app}/{name}/
├── MediaServer/                      # 核心流媒体服务（协议解析、会话管理）
├── Root/                             # IO 服务器（事件驱动、网络通信）
├── SabreAMF/                         # RTMP 命令工具包（AMF 编解码）
├── server.php                        # 服务入口
├── index.html                        # FLV 直播播放页
├── play.html                         # HLS 直播播放页
├── mp4.html                          # MP4 回放页（合并文件）
├── video.html                        # FLV 回放页
├── play_merge.html                   # fMP4 切片播放页（支持混合/分离切片）
└── README.md
```

> **目录说明**：
> - `MediaServer`：核心流媒体逻辑，处理 RTMP 协议、会话管理、推拉流等。
> - `Root`：IO 服务器，负责底层 Socket 事件循环、网络收发。
> - `SabreAMF`：AMF0/AMF3 编解码库，用于处理 RTMP 命令消息（如 connect, publish, play）。

## ❓ 常见问题

### 1. 运行时报缺少扩展

- **原因**：PHP CLI 模式与 FPM 模式的扩展配置可能不一致
- **解决**：
    - 执行 `php -m` 检查扩展列表
    - 安装缺失扩展（如 `sockets`）
    - 推荐使用 Docker 环境避免此问题

### 2. 端口被占用

- **解决**：
    - 查看端口占用：`netstat -ano | findstr <端口号>`
    - 修改 `server.php` 中的端口配置
    - 同步修改播放页面中的对应端口

### 3. 播放页面无法连接

- **解决**：
    - 确认服务器已启动，且端口未被防火墙拦截
    - 检查页面中的播放地址是否与推流路径一致
    - 如修改了端口，请同步更新页面中的端口号

### 4. 录制文件被覆盖

- **现象**：相同频道的推流会覆盖之前的录制文件
- **解决**：
    - 每次推流使用不同的频道名
    - 或自行实现文件备份/清理逻辑
   
### 5. 不能录制文件

- **现象**：推流结束后发现没有录屏文件
- **解决**：
    - 检查启动文件中配置参数，是否开启了录屏
    - 系统默认只开启hls协议转换，默认关闭了mp4录屏和flv录屏

## 📄 开源协议

本项目仅供学习交流使用，商用请自行斟酌。

## ⚠️ 免责声明

- 部分代码来源于网络，如有版权问题请联系删除
- 项目完全开源，仅用于技术交流
- 使用者需自行承担法律风险
- 因使用本项目造成的任何损失，作者不承担责任

## 📧 联系方式

如有问题或建议，欢迎通过邮件联系：

📧 **2723659854@qq.com**
