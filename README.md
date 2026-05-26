# rtmp_server

一个基于纯 PHP 开发的 RTMP 直播服务器。支持 RTMP 推流与拉流、FLV 格式拉流（HTTP/WS）、HLS 格式拉流，以及简单的前端播放页面。

## 功能特点

- 🎥 支持 RTMP 推流与拉流
- 📡 支持 HTTP-FLV 和 WebSocket-FLV 拉流
- 🧩 支持 HLS 输出（M3U8 + TS 切片）
- 🖥️ 内置简易网页播放器（FLV / HLS）
- 🐳 提供 Docker 开发环境（含 FFmpeg 和 PHP 扩展）
- ⚡ 纯 PHP 实现，无需 Nginx 或其他流媒体服务器

## 环境要求

- PHP >= 8.1（CLI 模式）
- 需要开启 `sockets`等扩展

## 快速开始

### 安装

```bash
composer create-project xiaosongshu/rtmp_server
```

### 配置与启动

#### 方式一：使用 phpstudy 集成环境（推荐新手）

1. 下载并安装 [phpstudy](https://m.xp.cn/)
2. 将 PHP 版本切换至 **8.1**
3. 在 phpstudy 的软件管理中安装必要的扩展（根据运行报错提示）
4. 进入项目根目录，启动服务

#### 方式二：使用 Docker（推荐，已包含所有依赖）

项目根目录下执行：

```bash
docker-compose up -d
```

自带的 Docker 环境已经集成了 PHP 8.1、相关扩展以及 FFmpeg（用于推流或者检测数据包或者转码）。

### 启动服务

```bash
php server.php
```

### 关闭服务

- **Windows**：`Ctrl + C`
- **Linux / macOS**：`kill -9 <进程ID>`（可通过 `ps aux | grep server.php` 查找 PID）

## 端口说明

默认使用以下三个端口（可在入口文件中修改）：

- **1935**：RTMP 服务
- **8501**：FLV 服务（HTTP & WebSocket）
- **80**：Web 服务（HLS + 静态页面）

## 推流

### 推流地址

```
rtmp://127.0.0.1:1935/a/b
```

- `a` 为应用名称
- `b` 为频道名称  
  （可自定义，仅支持英文和数字）

### 推流工具

#### OBS Studio

- 下载：[obsproject.com](https://obsproject.com/)
- 使用教程：[OBS 推流至自定义 RTMP 服务器](https://www.tencentcloud.com/zh/document/product/267/31569)

#### FFmpeg

```bash
ffmpeg -re -stream_loop 1 -i "movie.mp4" -vcodec h264 -acodec aac -f flv rtmp://127.0.0.1/a/b
```

参数说明：

- `-re`：实时速率读取输入
- `-stream_loop 1`：循环播放一次
- `-i "movie.mp4"`：指定输入视频文件
- `-vcodec h264`：视频编码为 H.264
- `-acodec aac`：音频编码为 AAC
- `-f flv`：输出封装格式为 FLV（RTMP 要求）

## 拉流

### 可用拉流地址

| 协议 | 地址 |
|------|------|
| RTMP | `rtmp://127.0.0.1/a/b` |
| HTTP-FLV | `http://127.0.0.1:8501/a/b.flv` |
| WebSocket-FLV | `ws://127.0.0.1:8501/a/b.flv` |
| HLS (M3U8) | `http://127.0.0.1:80/hls/a/b/index.m3u8` |

### 播放工具

- [VLC media player](https://www.videolan.org/)：打开网络串流
- 内置网页播放器：
    - FLV 测试页：[http://127.0.0.1:80/index.html](http://127.0.0.1:80/index.html)
    - HLS 测试页：[http://127.0.0.1:80/play.html](http://127.0.0.1:80/play.html)

## 常见问题

- **运行时缺少扩展**：本项目运行于 PHP CLI 模式，与传统 FPM 模式不同。请根据报错信息安装对应扩展；若使用 Docker 则已内置。
- **端口冲突**：请检查 1935、8501、80 端口是否被占用，或修改入口文件中的端口配置。若修改了端口，则需要修改内置播放页的代码。

## ⚠️ 免责声明

本项目 **仅供学习交流** 使用，严禁用于任何商业用途。

- 项目中的部分代码、资料可能来源于网络，若涉及侵权，请及时联系我进行删除。
- 本项目完全开源，旨在促进技术分享与共同进步。因使用者个人行为导致的任何法律风险或商业纠纷，均与作者无关。
- 使用者应自行承担使用本项目所带来的一切后果，包括但不限于版权、合规等问题。

如有任何疑问或建议，欢迎通过下方邮箱与我取得联系。

## 联系我

📧 Email: [2723659854@qq.com](mailto:2723659854@qq.com)
