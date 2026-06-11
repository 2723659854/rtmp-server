<?php


namespace MediaServer\Http;

use MediaServer\Flv\FlvPlayStream;
use MediaServer\Flv\FlvPublisherStream;
use MediaServer\MediaServer;
use MediaServer\Utils\WMHttpChunkStream;
use MediaServer\Utils\WMWsChunkStream;
use Root\Io\RtmpDemo;
use Root\rtmp\TcpConnection;
use Root\Request;
use Root\Response;
use Root\Protocols\Websocket;

/**
 * @purpose flv服务
 */
class HttpWMServer
{
    /** @var string $publicPath 资源路径 */
    static $publicPath = "";

    /**
     * 初始化
     */
    public function __construct()
    {
        self::$publicPath = app_path();
    }

    /**
     * 定义ws请求响应方法
     * @param TcpConnection $connection
     * @param string $headerData
     * @return void
     */
    public function onWebsocketRequest(TcpConnection $connection, string $headerData)
    {
        $request = new Request($headerData);
        $request->connection = $connection;
        //ignore connection message
        $connection->onMessage = null;
        if ($this->findFlv($request, $request->path())) {
            return;
        }
        $request->connection->close();
        return;
    }


    /**
     * http响应请求
     * @param TcpConnection $connection
     * @param Request $request
     */
    public function onHttpRequest(TcpConnection $connection, Request $request)
    {
        switch ($request->method()) {
            case "GET":
                return $this->getHandler($request);
            case "POST":
                return $this->postHandler($request);
            case "HEAD":
                return $connection->send(new Response(200));
            default:
                logger()->warning("unknown method", ['method' => $request->method(), 'path' => $request->path()]);
                return $connection->send(new Response(405));
        }
    }


    /**
     * 处理http的get请求
     * @param Request $request
     */
    public function getHandler(Request $request)
    {
        $path = $request->path();

        //api
        if ($path === '/api') {
            $name = $request->get('name');
            $args = $request->get('args', []);
            /** 调用媒体服务的接口 */
            $data = MediaServer::callApi($name, $args);
            if (!is_null($data)) {
                $request->connection->send(new Response(200, ['Content-Type' => "application/json"], json_encode($data)));
            } else {
                $request->connection->send(new Response(404, [], '404 Not Found'));
            }
            return;
        }
        //flv
        if (
            $this->unsafeUri($request, $path) ||
            $this->findFlv($request, $path) ||
            $this->findStaticFile($request, $path)
        ) {
            return;
        }

        //api

        //404
        $request->connection->send(new Response(404, [], '404 Not Found'));
        return;
    }


    /**
     * 处理post请求
     * @param Request $request
     * @return Promise|Response
     * @comment 貌似是发布流媒体，这里应该是http的播放器发送的请求
     * @comment 是这里和mediaServer产生关系的
     */
    public function postHandler(Request $request)
    {
        $path = $request->uri();
        $connection = $request->connection;
        
        if (MediaServer::hasPublishStream($path)) {
            //publishStream already
            logger()->warning("Stream {path} exists", ['path' => $path]);
            return new Response(
                400,
                ['Content-Type' => 'text/plain'],
                "Stream {$path} exists."
            );
        }
        
        // 创建适配器来桥接 TCP 连接和 FlvPublisherStream
        $adapter = new HttpPublishAdapter($connection, $request->rawBody());
        
        // 创建 FLV 推流
        $flvReadStream = new FlvPublisherStream($adapter, $path);
        
        // 添加到 MediaServer
        MediaServer::addPublish($flvReadStream);
        logger()->info("stream {path} created", ['path' => $path]);
        
        // 绑定关闭事件
        $flvReadStream->on('on_close', function () use ($connection) {
            $connection->close();
        });
        
        // 绑定错误事件
        $flvReadStream->on('error', function (\Exception $exception) use ($connection) {
            $connection->send((string)(new Response(400, ['Content-Type' => 'text/plain'], $exception->getMessage())));
            $connection->close();
        });
        
        // 启动适配器，开始处理数据流
        $adapter->start();
        
        // 立即发送200响应，表示服务器已接受请求
        $connection->send((string)(new Response(200)));
    }

    /**
     * 是否安全url
     * @param Request $request
     * @param $path
     * @return bool
     */
    public function unsafeUri(Request $request, $path): bool
    {
        if (
            !$path ||
            strpos($path, '..') !== false ||
            strpos($path, "\\") !== false ||
            strpos($path, "\0") !== false
        ) {
            $request->connection->send(new Response(404, [], '404 Not Found.'));
            return true;
        }
        return false;
    }

    /**
     * 返回静态资源
     * @param Request $request
     * @param $path
     * @return bool
     */
    public function findStaticFile(Request $request, $path)
    {

        if (preg_match('/%[0-9a-f]{2}/i', $path)) {
            $path = urldecode($path);
            if ($this->unsafeUri($request, $path)) {
                return true;
            }
        }

        /** 规范化路径，避免双斜杠问题 */
        $file = self::$publicPath . $path;
        if (!is_file($file)) {
            return false;
        }

        $request->connection->send((new Response())->withFile($file));

        return true;
    }

    /**
     * 播放flv
     * @param Request $request
     * @param $path
     * @return bool
     */
    public function findFlv(Request $request, $path)
    {
        if (!preg_match('/(.*)\.flv$/', $path, $matches)) {
            return false;
        } else {
            list(, $flvPath) = $matches;
            $this->playMediaStream($request, $flvPath);
            return true;
        }
    }


    /**
     * 播放flv资源
     * @param Request $request
     * @param $flvPath
     * @return void
     * @comment 是这里实现flv播放的 和mediaServer产生关系的
     */
    public function playMediaStream(Request $request, $flvPath)
    {
        /** 检查是否已经有发布这个流媒体 */
        //check stream
        if (MediaServer::hasPublishStream($flvPath)) {
            /** 如果是ws协议 */
            if ($request->connection->protocol === Websocket::class) {
                /** 修改ws缓冲区数据类型 为数组  */
                $request->connection->websocketType = Websocket::BINARY_TYPE_ARRAYBUFFER;
                /** 数据包 ws */
                $throughStream = new WMWsChunkStream($request->connection);
            } else {
                /** 数据包 http */
                $throughStream = new WMHttpChunkStream($request->connection);
            }
            /** 实例化flv播放资源 */
            $playerStream = new FlvPlayStream($throughStream, $flvPath);
            //echo "m3u8播放地址：". $playerStream->getHlsUrl()."\r\n";
            /** 是否关闭声音 */
            $disableAudio = $request->get('disableAudio', false);
            if ($disableAudio) {
                $playerStream->setEnableAudio(false);
            }
            /** 是否关闭视频 */
            $disableVideo = $request->get('disableVideo', false);
            if ($disableVideo) {
                $playerStream->setEnableVideo(false);
            }
            /** 是否要连续帧 */
            $disableGop = $request->get('disableGop', false);
            if ($disableGop) {
                $playerStream->setEnableGop(false);
            }
            /** 添加播放器 */
            MediaServer::addPlayer($playerStream);
        } else {
            /** 没有这一路推流资源 直接关闭链接或者发送404 */
            logger()->warning("Stream {path} not found", ['path' => $flvPath]);
            if ($request->connection->protocol === Websocket::class) {
                $request->connection->close();
            } else {
                $request->connection->send(
                    new Response(
                        404,
                        ['Content-Type' => 'text/plain'],
                        "Stream not found."
                    )
                );
            }

        }
    }
}