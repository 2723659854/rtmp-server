# RTMP Server
<p align="center">
  <a href="./README.cn.md"><strong>🇨🇳 中文文档</strong></a> •
  <a href="./README.md"><strong>🇬🇧 English Docs</strong></a>
</p>

> 纯 PHP 自研轻量级 RTMP 直播服务，**零 FFmpeg、Nginx 等第三方流媒体依赖**，开箱快速搭建私有化直播平台。
> Linux 环境自动启用 `event` 扩展 epoll 事件驱动；Windows 环境自动降级 select IO 模型，全平台兼容。
> **项目定位底层基础设施**：完整自研 RTMP/HTTP-FLV/WS-FLV 协议栈、异步网络引擎；业务管理、权限、回放管理等上层应用需开发者自行扩展开发。

---

## 目录
- [环境依赖](#环境依赖)
- [快速开始](#快速开始)
- [推拉流地址规范](#推拉流地址规范)
- [直播&点播访问地址](#直播点播访问地址)
- [Web 页面使用说明](#web-页面使用说明)
- [项目目录结构](#项目目录结构)
- [系统整体架构](#系统整体架构)
- [端口常量配置](#端口常量配置)
- [录制任务开关配置](#录制任务开关配置)
- [多进程 Worker 配置（IPC 流同步核心）](#多进程-worker-配置ipc-流同步核心)
- [推流鉴权配置](#推流鉴权配置)
- [FLV 直播分发网关](#flv-直播分发网关)
- [静态文件 HTTP 网关](#静态文件-http-网关)
- [多方式推拉流接入教程](#多方式推拉流接入教程)
- [直播转发教程](#直播转发教程)
- [常见问题 FAQ](#常见问题-faq)
- [开源协议](#开源协议)
- [附属工具包](#附属工具包)
- [联系方式](#联系方式)

---

## 环境依赖
| 依赖项 | 硬性要求说明 |
|--------|------------|
| PHP | >= 8.1，仅支持 CLI 命令行模式运行，不支持 FPM |
| sockets 扩展 | **强制必需**，底层 TCP/WS/RTMP 通信基础 |
| event 扩展 | Linux 强烈推荐安装，启用 epoll 高并发事件模型；Windows 无此扩展，自动降级 select |

> 快速环境部署：项目内置 `docker-compose.yml`，执行 `docker-compose up -d` 一键启动完整运行环境。

---

## 快速开始
### 1. 项目安装
```bash
composer create-project 2723659854/rtmp_server
cd rtmp_server
```

### 2. 启动源站主服务
```bash
php server.php
```

启动成功输出示例：
```
[INFO] RTMP Server started on 0.0.0.0:1935
[INFO] HTTP-FLV/WS-FLV Server started on 0.0.0.0:8501
[INFO] HTTP Static Server started on 0.0.0.0:80
```

### 3. 快速推流测试
#### 方式1：浏览器无软件推流
- 屏幕实时推流：`http://127.0.0.1/push.html`
- 本地 MP4/FLV 文件循环推流：`http://127.0.0.1/flv_push.html`

#### 方式2：FFmpeg 标准推流
```bash
ffmpeg -re -stream_loop -1 -i video.mp4 -c:v libx264 -c:a aac -f flv rtmp://127.0.0.1:1935/live/stream
```

#### 方式3：OBS Studio 推流
- 服务器：`rtmp://127.0.0.1:1935/live/`
- 串流密钥：`stream`

#### 方式4：项目内置 PHP 推流客户端
```bash
php pusher.php test.mp4 http://127.0.0.1:8501/live/stream
```

### 4. 快速观看直播
浏览器访问：`http://127.0.0.1/index.html`

---

## 推拉流地址规范
### 推流地址（OBS/FFmpeg/PHP/Web 统一格式）
| 协议 | 标准格式 | 示例地址 |
|------|---------|---------|
| RTMP | `rtmp://host:1935/{app}/{stream}` | `rtmp://127.0.0.1:1935/live/stream` |
| HTTP-FLV | `http://host:8501/{app}/{stream}` | `http://127.0.0.1:8501/live/stream` |
| WebSocket-FLV | `ws://host:8501/{app}/{stream}` | `ws://127.0.0.1:8501/live/stream` |

> 字段约束：`{app}` 应用名、`{stream}` 频道名仅允许英文、数字、下划线，禁止特殊符号、中文。

### 直播点播访问地址
#### 实时直播播放地址
| 协议 | 访问地址 | 适用场景 |
|------|---------|---------|
| RTMP | `rtmp://127.0.0.1:1935/live/stream` | ffplay、桌面专业播放器 |
| HTTP-FLV | `http://127.0.0.1:8501/live/stream.flv` | PC 浏览器低延迟直播 |
| WebSocket-FLV | `ws://127.0.0.1:8501/live/stream.flv` | 浏览器原生 WebSocket MSE 播放 |
| HLS | `http://127.0.0.1:80/hls/live/stream/index.m3u8` | 移动端、微信内置浏览器 |

#### 录制点播回放地址
录制文件持久化存储于项目根目录，直播结束自动生成完整文件：

| 文件类型 | 存储路径 | 访问示例 |
|---------|---------|---------|
| 完整合并 MP4 | `mp4/live/stream/output_merge/stream_full.mp4` | `http://127.0.0.1/mp4/live/stream/output_merge/stream_full.mp4` |
| 原始 FLV 录制文件 | `flv/live/stream/index.flv` | `http://127.0.0.1/flv/live/stream/index.flv` |
| HLS TS 分片目录 | `hls/live/stream/` | 直接使用 m3u8 索引地址播放 |

---

## Web 页面使用说明
### 直播播放页面
| 页面文件 | 功能说明 | 访问地址 |
|---------|---------|---------|
| index.html | HTTP-FLV 低延迟直播播放器 | http://127.0.0.1/index.html |
| play.html | HLS 移动端适配播放器 | http://127.0.0.1/play.html |
| mp4.html | MP4 点播专用页面 | http://127.0.0.1/mp4.html |
| video.html | FLV 点播播放器 | http://127.0.0.1/video.html |
| play_merge.html | fMP4 分片点播页面 | http://127.0.0.1/play_merge.html |

### Web 端推流页面
| 页面文件 | 功能说明 | 访问地址 |
|---------|---------|---------|
| push.html | 浏览器屏幕采集推流（WS-FLV） | http://127.0.0.1/push.html |
| flv_push.html | 本地 MP4/FLV 文件循环推流 | http://127.0.0.1/flv_push.html |
| push_merge.html | 多路直播画面合并推流 | http://127.0.0.1/push_merge.html |
| push_transcode.html | 前端多码率转码推流，适配弱网 | http://127.0.0.1/push_transcode.html |

### PHP 内置推拉流客户端脚本
| 脚本 | 功能 | 命令示例 |
|------|------|---------|
| pusher.php | 命令行文件推流客户端 | `php pusher.php video.mp4 http://127.0.0.1:8501/live/stream` |
| puller.php | 命令行拉流录制客户端 | `php puller.php http://127.0.0.1:8501/live/stream.flv output.flv` |

---

## 项目目录结构
```
rtmp_server/
├── config/                     # 全局配置文件：端口、多进程、录制、推流鉴权
├── flv/                        # 实时录制 FLV 原始流存储目录
├── mp4/                        # fMP4 分片 & 直播结束合并完整 MP4
├── hls/                        # HLS TS 分片、m3u8 索引文件目录
├── MediaServer/                # RTMP/FLV/WS-FLV 核心协议栈、会话管理
├── Root/                       # 底层异步 IO、Socket 事件驱动引擎
├── record/                     # 客户端配套静态页面资源
├── server.php                  # RTMP 源站主服务启动入口
├── flvGateway.php              # FLV 直播分发网关启动脚本
├── fileGateway.php             # HLS/MP4/静态资源 HTTP 网关
├── forward.php                 # 直播转发客户端
├── pusher.php                  # PHP 推流客户端
├── puller.php                  # PHP 拉流客户端
├── auth_config.php             # 推流鉴权独立配置
├── *.html                      # 全部 Web 推拉流、播放页面
├── docker-compose.yml          # Docker 一键部署配置
└── LICENSE                     # Apache 2.0 开源协议文件
```

---

## 系统整体架构
```
                                                    【外部推流端】OBS / FFmpeg / Web端
                                                         │
                                   RTMP(1935) / HTTP-FLV/WS-FLV(8501) 推流接入
                                                         │
                                                         ▼
╔══════════════════════════════════════════════════════════════════════════════════════╗
║                              RTMP 源站主服务（流生产核心）                              ║
║                                                                                      ║
║  📥 推拉流接入：RTMP / HTTP-FLV / WS-FLV 三协议兼容，内置推流鉴权校验                  ║
║  🔄 协议转封装：原始流转输出 HTTP-FLV / WS-FLV / HLS / fMP4 / MP4                     ║
║  💾 并行录制任务（完全互不阻塞，可单独开关）                                           ║
║        ┌──────────┬──────────┬──────────┐                                            ║
║        │ FLV裸流录制 │ fMP4实时分片 │ HLS TS分片 │                                      ║
║        └──────────┴──────────┴──────────┘                                            ║
║  📤 实时流输出：对外分发 HTTP-FLV、WS-FLV、HLS 直播流                                 ║
║  📦 点播产物：fMP4分片缓存，直播结束自动拼接完整MP4文件                                ║
║  📁 内置静态HTTP服务(80端口)：低并发场景无需额外网关，直接提供页面、点播文件访问          ║
╚══════════════════════════════════════════════════════════════════════════════════════╝
│
┌───────────────────┼───────────────────┐
│                   │                   │
▼                   ▼                   ▼
HTTP-FLV实时流     HLS静态分片文件      fMP4静态分片文件
│                   │                   │
▼                   ▼                   ▼
┌─────────────┐    ┌──────────────────────────────────────────┐
│ FLV直播网关集群 │    │        静态文件网关集群(fileGateway)    │
│             │    │    托管资源：HLS/fMP4/MP4/FLV/网页静态资源 │
│ ┌─────────┐ │    │                                          │
│ │一级网关  │ │    │ ┌───────┐ ┌───────┐ ┌───────┐           │
│ │(8080端口)│ │    │ │网关1 │ │网关2 │ │网关3 │           │
│ └───┬─────┘ │    │ │(8100) │ │(8101) │ │(8102) │           │
│     │       │    │ └──┬────┘ └──┬────┘ └──┬────┘           │
│ ┌───┴───┐   │    │    │        │        │                 │
│ ▼   ▼   ▼   │    │    ▼        ▼        ▼                 │
│ ┌─┐ ┌─┐ ┌─┐ │    │ ┌──────────────────────────────────┐   │
│ │子│ │子│ │子│ │    │ │终端播放器客户端                │   │
│ │网│ │网│ │网│ │    │ │MSE/HLS播放器/ffplay/浏览器    │   │
│ │关│ │关│ │关│ │    │ └──────────────────────────────────┘   │
│ └┬─┘ └┬─┘ └┬─┘ │    │                                          │
│  │    │    │   │    └──────────────────────────────────────────┘
│  ▼    ▼    ▼   │
│ ┌────────────┐ │
│ │直播观看客户端│ │
│ │FLV播放器    │ │
│ └────────────┘ │
└─────────────────┘
```

### 架构详细说明
1. **源站主服务（唯一流生产者）**
   所有外部推流统一接入源站，完成协议解析、鉴权、多路转封装、并行录制；FLV录制、fMP4切片、HLS切片三个任务线程完全隔离，互不阻塞。
   低并发场景可直接使用内置80端口静态服务，无需部署额外网关。

2. **FLV 直播分发网关**
   无转码逻辑，仅做流量转发、GOP关键帧缓存实现播放器秒开；支持横向扩容、多级级联（生产环境建议最多两级，层级越多延迟越高）；Linux epoll高并发，Windows仅用于测试。
   高并发场景全部播放器拉流请求走网关，减轻源站主进程连接压力。

3. **静态文件网关集群**
   专门托管HLS、MP4、FLV、前端页面等静态资源，实现读写分离；大规模点播场景必须部署，避免源站被文件IO请求占满。

4. **直播一体化工具**
   本项目支持纯PHP客户端推流，拉流，以及直播转发功能，并提供web前端推流，播放，转码，合流。支持单进程/多进程切换，以及个性化媒体资源工具包`xiaosongshu/flv2mp4`。

### 分并发部署建议
| 并发规模 | 推荐部署方案 |
|---------|------------|
| 低并发（在线播放 < 1000） | 仅启动源站`server.php`，使用内置80、8501端口，无需网关 |
| 中等并发（1000 ~ 5000在线） | 源站 + 单层FLV网关集群 + 单层静态文件网关集群，Nginx负载均衡 |
| 高并发/大型活动直播（>5000在线） | 源站 + 多层级FLV网关、静态网关集群，前置负载均衡；万人级活动必须接入商用CDN边缘分发，禁止单服务器承载全部流量 |

---

## 端口常量配置
修改 `config/app.php` 调整全局服务端口，内置常量定义：
```php
/** HTTP-FLV / WebSocket-FLV 主服务端口 */
define('BASE_FLV_PORT', 8501);
/** RTMP 标准1935端口 */
define('BASE_RTMP_PORT', 1935);
/** 内置静态网页、点播文件HTTP端口 */
define('BASE_WEB_PORT', 80);
```

## 录制任务开关配置
`config/app.php` 独立控制三类录制任务，互不干扰：
```php
define('FLV_TO_RECORD', true);   // 开启实时原始FLV流录制
define('FLV_TO_MP4', true);      // 开启fMP4分片，直播结束自动合并完整MP4
define('FLV_TO_HLS', true);      // 开启HLS TS分片生成
```

## 多进程 Worker 配置（IPC 流同步核心）
### 原理说明
PHP CLI 多进程模型下，每个 Worker 进程内存完全隔离，单个进程收到推流后，其他 Worker 无法读取流数据，因此**必须通过 IPC（进程间通信）同步直播流**。
本项目不使用共享内存、管道等传统系统IPC，自研本地TCP Socket IPC方案：分配一组内部通信端口，收流Worker主动通过内置TCP客户端，将完整流数据复制转发给全部其他Worker，实现全进程流数据共享。

### 配置代码 `config/app.php`
```php
/** 总开关：是否启用多进程Worker模式 */
define('ENABLE_MULTI_PROCESS', true);
/** Worker进程数量，建议不超过服务器CPU物理核心数 */
define('WORKER_COUNT', 3);
/** 进程间TCP通信端口起始值，自动依次分配 8502、8503... */
define('COPY_PORT_START', 8502);
```
> 关闭多进程（`ENABLE_MULTI_PROCESS=false`）时，进程数量、内部通信端口配置全部失效，服务运行在单进程模式，无需IPC流同步。

### 多进程端口负载均衡规则
1. Linux：系统支持端口复用，多个Worker可同时监听8501主FLV端口，内核自动将播放器连接均衡分发至各Worker；
2. Windows：系统虽支持`SO_REUSEADDR`端口复用，但新TCP连接只会固定分配给最先绑定8501的进程，无法原生负载均衡；可借助Nginx反向代理内部通信端口（8502+）实现流量均分；
3. 内部IPC端口对外可直接访问拉流，用于Windows环境手动负载均衡。

### 平台性能限制说明
- Linux：epoll IO模型，单进程支持数千并发长连接，多进程可充分利用多核CPU，生产环境首选；
- Windows：底层select模型并发上限极低（单进程约256连接），仅用于本地开发调试，禁止线上生产部署。

## 推流鉴权配置
### 功能说明
防止非法流覆盖直播间，仅携带合法stream key的推流请求允许接入；播放器拉流无需鉴权。
配置文件 `config/auth.php`
```php
<?php
return [
    'enabled' => false, // 鉴权总开关
    'publish' => [
        'require_auth' => true, // 推流强制校验密钥
        'stream_keys' => [
            'live_123456',
            'stream_key_abc',
        ],
    ],
    'global' => [
        'allowed_apps' => ['live'], // 允许的应用名
        'deny_apps' => [],
    ],
];
```

### 鉴权推流地址写法
通过URL参数`key`携带密钥：
1. RTMP
```bash
ffmpeg -re -i video.mp4 -f flv rtmp://127.0.0.1:1935/live/stream?key=live_123456
```
2. OBS串流密钥：`stream?key=live_123456`
3. HTTP-FLV
```bash
ffmpeg -re -i video.mp4 -f flv http://127.0.0.1:8501/live/stream?key=live_123456
```
4. WS-FLV PHP客户端
```bash
php pusher.php test.flv "ws://127.0.0.1:8501/live/stream?key=live_123456"
```

### 安全最佳实践
1. 替换默认密钥，使用32位以上随机字符串；
2. 公网环境部署启用HTTPS/WSS，避免密钥明文抓包；
3. 定期轮换stream key，降低泄露风险。
4. 系统默认关闭了鉴权，若有需要请自行开启

## FLV 直播分发网关
### 功能简介
轻量化流量转发服务，向上游源站拉取HTTP-FLV/WS-FLV流，缓存GOP关键帧实现播放器秒开；支持横向扩容、多级级联分发，分担源站并发压力。

### 启动命令
```bash
# 基础单实例启动
php flvGateway.php 8080 http://127.0.0.1:8501
php flvGateway.php 8080 ws://127.0.0.1:8501

# 同层横向扩容多实例
php flvGateway.php 8080 http://127.0.0.1:8501
php flvGateway.php 8081 http://127.0.0.1:8501
php flvGateway.php 8082 ws://127.0.0.1:8501

# 多级级联（不建议超过两级）
php flvGateway.php 8080 http://127.0.0.1:8501    # 一级网关
php flvGateway.php 8081 http://127.0.0.1:8080     # 二级网关

# Linux后台静默运行
php flvGateway.php 8080 http://127.0.0.1:8501 > /dev/null 2>&1 &
```

### 网关播放地址格式
```
http://网关IP:端口/{app}/{stream}.flv
ws://网关IP:端口/{app}/{stream}.flv
```
示例：`http://127.0.0.1:8080/live/stream.flv`

## 静态文件 HTTP 网关
### 功能简介
独立静态资源HTTP服务，托管HLS、MP4、FLV、前端页面，分离文件IO与直播流业务，提升高并发点播稳定性。

### 启动命令
```bash
# 单实例启动
php fileGateway.php 0.0.0.0 8100

# 多实例横向扩容
php fileGateway.php 0.0.0.0 8100
php fileGateway.php 0.0.0.0 8101
php fileGateway.php 0.0.0.0 8102

# Linux后台运行
php fileGateway.php 0.0.0.0 8100 > /dev/null 2>&1 &
```

### Nginx 负载均衡反向代理示例
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

### 资源访问地址示例
```
http://127.0.0.1:8100/index.html
http://127.0.0.1:8100/hls/live/stream/index.m3u8
http://127.0.0.1:8100/mp4/live/stream/output_merge/stream_full.mp4
```

## 多方式推拉流接入教程
### RTMP 推流
OBS、FFmpeg、PHP客户端均兼容标准RTMP协议，地址格式：`rtmp://host:1935/{app}/{stream}`

### HTTP-FLV 推流
适合命令行、程序自动化推流，地址：`http://host:8501/{app}/{stream}`

### WebSocket-FLV 推流
浏览器原生推流方案，延迟最低可达50ms内，使用内置`push.html`页面即可。

### PHP 拉流脚本
用于服务端拉流备份、跨服务器流转：
```bash
php puller.php http://127.0.0.1:8501/live/stream.flv output.flv
php puller.php ws://127.0.0.1:8501/live/stream.flv output.flv
```

## 直播转发教程
本项目提供直播转发功能，可以将直播转发到多路服务器，支持`rtmp/ws-flv/http-flv`协议推拉流。详细命令见`forward.php`详解，转发命令示例如下：
```bash
php forward.php http://127.0.0.1:8501/a/b.flv "rtmp://127.0.0.1:1935/c/d,ws://127.0.0.1:8501/c/e,http://127.0.0.1:8501/c/f" 
```
上面的命令表示将直播流`http://127.0.0.1:8501/a/b.flv` 转发推流到 `rtmp://127.0.0.1:1935/c/d`,`ws://127.0.0.1:8501/c/e`和`http://127.0.0.1:8501/c/f` 
，当然你也可以推流到其他任意支持rtmp,ws-flv,http-flv的平台。

### 工程化建议
`pusher.php`/`puller.php`可集成至后端定时任务，实现自动拉流转推、备份录制，不依赖第三方工具，完成全PHP直播业务闭环。

## 常见问题 FAQ
### Q1 Windows启动提示缺失event扩展怎么办？
Windows无event扩展，服务自动切换select IO模型，仅需安装`sockets`扩展即可正常运行，无需额外处理。

### Q2 如何确认服务正常启动？
终端输出三条监听日志即代表启动成功：RTMP 1935、FLV 8501、静态80端口。

### Q3 推流成功但播放器持续卡顿？
1. 推流码率、分辨率过高，降低码率/帧率测试；
2. 服务器CPU满载，开启多进程充分利用多核；
3. 高并发未部署FLV网关，大量播放器连接占用源站资源；
4. 服务器上行带宽不足，限制并发在线人数。

### Q4 如何停止服务？
终端按下`Ctrl + C`发送终止信号，或直接关闭运行终端窗口。

### Q5 支持哪些第三方推流软件？
全兼容标准RTMP客户端：OBS Studio、FFmpeg、xSplit、移动端RTMP推流SDK。

## 开源协议
本项目采用 **Apache License 2.0** 开源协议。
软件按现状提供，不提供任何明示或隐含担保，开发者不对使用本程序产生的直接、间接、衍生损失承担责任，完整条款查看项目根目录`LICENSE`文件。

## 附属工具包
项目底层编解码、流转封装能力独立拆分工具包：[2723659854/flv2mp4](https://github.com/2723659854/flv2mp4)
提供FLV/MP4/fMP4/HLS互转、独立推拉流客户端、网关组件，可单独引入第三方PHP项目使用。

## 联系方式
- 邮箱：2723659854@qq.com
- GitHub：https://github.com/2723659854