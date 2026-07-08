<?php

namespace MediaServer;


use Evenement\EventEmitter;
use MediaServer\Flv\FlvRecorder;
use MediaServer\Flv\FlvPusher;
use MediaServer\HLS\FLVToHLSConverter;
use MediaServer\MediaReader\MediaFrame;
use MediaServer\MP4\Mp4Converter;
use MediaServer\PushServer\PlayStreamInterface;
use MediaServer\PushServer\PublishStreamInterface;
use MediaServer\PushServer\VerifyAuthStreamInterface;


/**
 * @purpose 媒体中心服务
 * @author yanglong
 * @note 直播调度核心服务
 */
class MediaServer
{

    /**
     * 事件触发器
     * @var EventEmitter
     */
    static protected $eventEmitter;


    /**
     * 魔术方法，可以调用本对象的任意方法
     * @param $name
     * @param $arguments
     * @return mixed
     */
    static function __callStatic($name, $arguments)
    {
        /** 初始化事件触发器 */
        if (!self::$eventEmitter) {
            self::$eventEmitter = new EventEmitter();
        }
        return call_user_func_array([self::$eventEmitter, $name], $arguments);
    }


    /**
     * 保存本项目下的所有推流资源
     * @var PublishStreamInterface[]
     */
    static public $publishStream = [];

    /**
     * 调用本对象的api
     * @param $name
     * @param $args
     * @return array|false
     */
    static public function callApi($name, $args = [])
    {
        switch ($name) {
            case 'listPushStream':
                return self::listPushStream(...$args);
            default:
                return false;
        }
    }

    /**
     * 列出路径下的推流资源
     * @param $path
     * @return array
     */
    static public function listPushStream($path = null)
    {
        if ($path) {
            return isset(self::$publishStream[$path]) ? [
                self::$publishStream[$path]->getPublishStreamInfo()
            ] : [];
        }
        return array_map(function ($stream) {
            return $stream->getPublishStreamInfo();
        }, array_values(self::$publishStream));
    }

    /**
     * 是否某一路推流资源
     * @param $path
     * @return bool
     */
    static public function hasPublishStream($path)
    {
        return isset(self::$publishStream[$path]);
    }

    /**
     * 获取某一路推流资源
     * @param $path
     * @return PublishStreamInterface
     */
    static public function getPublishStream($path)
    {
        return self::$publishStream[$path];
    }

    /**
     * 添加某一路推流资源
     * @param $stream PublishStreamInterface
     */
    static protected function addPublishStream($stream)
    {
        $path = $stream->getPublishPath();
        self::$publishStream[$path] = $stream;
    }

    /**
     * 删除某一路资源
     * @param $path
     * @return void
     */
    static protected function delPublishStream($path)
    {
        unset(self::$publishStream[$path]);

        if (FLV_TO_HLS) {
            /** 关闭hls转码 */
            try {
                if (!empty(self::$hlsConverter[$path])) {
                    self::$hlsConverter[$path]->close();
                    unset(self::$hlsConverter[$path]);
                }
            } catch (\Exception $e) {
            }
        }


        if (FLV_TO_MP4) {
            /** 关闭mp4转码 */
            try {
                if (!empty(self::$mp4Converter[$path])) {
                    self::$mp4Converter[$path]->close();
                    unset(self::$hasSendStartFrameForMp4[$path], self::$mp4Converter[$path]);
                }
            } catch (\Exception $e) {
            }
        }

        if (FLV_TO_RECORD) {
            /** 关闭flv录屏 */
            try {
                if (!empty(self::$flvRecorder[$path])) {
                    self::$flvRecorder[$path]->close();
                    unset(self::$flvRecorder[$path], self::$hasSendStartFrameForFlvRecord[$path]);
                }
            } catch (\Exception $e) {
            }
        }

        if (FLV_TO_PUSH) {
            /** 关闭flv推流 */
            try {
                if (!empty(self::$flvPusher[$path])) {
                    self::$flvPusher[$path]->close();
                    unset(self::$flvPusher[$path], self::$hasSendStartFrameForFlvPusher[$path]);
                }
            } catch (\Exception $e) {
            }
        }

    }

    /**
     * 播放资源
     * @var PlayStreamInterface[][]
     */
    static public $playerStream = [];

    /**
     * 获取某一路播放资源
     * @param $path
     * @return array|PlayStreamInterface[]
     */
    static public function getPlayStreams($path)
    {
        return self::$playerStream[$path] ?? [];
    }


    /**
     * 删除某一路播放资源
     * @param $path
     * @param $objId
     * @comment  从这里的代码逻辑可以知道，只要有播放设备接入，才会转发数据
     */
    static protected function delPlayerStream($path, $objId)
    {
        unset(self::$playerStream[$path][$objId]);
        //一个播放设备都没有，这里不可直接关闭播放器，因为推流和拉流之间存在延迟，缓冲区还有数据，不可强制关闭播放器，应该由播放器自己处理
//        if (self::hasPublishStream($path) && count(self::getPlayStreams($path)) == 0) {
//            /** 获取这个路径下的推流资源 */
//            $p_stream = self::getPublishStream($path);
//            /** 移除事件 */
//            $p_stream->removeListener('on_frame', self::class . '::publisherOnFrame');
//            $p_stream->is_on_frame = false;
//        }

        // 因为开启自动录屏和转码转播功能，所以只可以关闭当前播放器，不可以关闭数据转发。
    }

    /**
     * 有播放设备接入，添加播放流媒体源
     * @param $playerStream PlayStreamInterface
     */
    static protected function addPlayerStream($playerStream)
    {
        /** 获取播放路径 */
        $path = $playerStream->getPlayPath();
        /** 获取对象id 获取这个播放源的hash值 */
        $objIndex = spl_object_id($playerStream);

        /** 初始化这个路径下的播放设备数据 */
        if (!isset(self::$playerStream[$path])) {
            self::$playerStream[$path] = [];
        }
        /** 加入当前的播放设备 */
        self::$playerStream[$path][$objIndex] = $playerStream;

        /** 如果这一路媒体已经推流了 */
        if (self::hasPublishStream($path)) {
            /** 获取推流的流媒体资源 */
            $p_stream = self::getPublishStream($path);
            if (!$p_stream->is_on_frame) {
                /** 这一路流媒体资源开始推流 转发流量数据 */
                $p_stream->on('on_frame', self::class . '::publisherOnFrame');
                $p_stream->is_on_frame = true;
            }
        }

    }


    /**
     * 转发流媒体数据
     * @param $publisher PublishStreamInterface 推流连接
     * @param $frame MediaFrame 这个是流媒体数据包（音频/视频/mete）
     * @comment 所有数据帧都经过此方法转发，原理就是foreach遍历
     */
    static function publisherOnFrame($frame, $publisher)
    {
        /** 获取这个媒体路径下的所有播放设备 */
        foreach (self::getPlayStreams($publisher->getPublishPath()) as $playStream) {
            /** 如果播放器不是空闲状态 */
            if (!$playStream->isPlayerIdling()) {
                /** 转发数据包给播放器 */
                $playStream->frameSend($frame);
            }
        }

        /** 以下所有服务和播放器独立，防止污染拉流 */
        if (isset($publisher->isCopy) && $publisher->isCopy) {
           // 复制流仅提供推拉流，不处理录频，转码，复制流
        }else{
            // 原始流需要负责转码，录屏，复制流
            if (FLV_TO_HLS) {
                /** hls处理数据 */
                try {
                    $path = $publisher->getPublishPath();
                    if (empty(self::$hlsConverter[$path])) {
                        self::$hlsConverter[$path] = new FLVToHLSConverter($path, [
                            'segmentDuration' => 4,  // 4秒切片
                            'maxSegments' => 21600      // 保留最新的5个切片
                        ]);
                    }
                    /** 直接转码mp4 */
                    self::$hlsConverter[$path]->processFrame($frame);
                } catch (\Exception $e) {
                }
            }

            if (FLV_TO_MP4) {
                /** mp4转码 */
                try {
                    $path = $publisher->getPublishPath();
                    if (empty(self::$mp4Converter[$path])) {
                        self::$mp4Converter[$path] = new MP4Converter($path);
                    }
                    if (empty(self::$hasSendStartFrameForMp4[$path])) {
                        $publishStream = MediaServer::getPublishStream($path);
                        /** 只有序列帧准备好后，才可以发送数据，否则mp4缺少格式参数，无法初始化 */
                        if ($publishStream->isMetaData() && $publishStream->isAVCSequence() && $publishStream->isAACSequence()) {
                            /** 发送解码桢 */
                            self::$mp4Converter[$path]->startPlay($path);
                            /** 标记当前节目已发送解码桢 */
                            self::$hasSendStartFrameForMp4[$path] = true;
                        }
                    } else {
                        /** 已标记则直接推送数据转码 */
                        self::$mp4Converter[$path]->frameSend($frame);
                    }

                } catch (\Exception $e) {
                }
            }

            if (FLV_TO_RECORD) {
                /** flv录屏 */
                try {
                    $path = $publisher->getPublishPath();
                    if (empty(self::$flvRecorder[$path])) {
                        self::$flvRecorder[$path] = new FlvRecorder($path);
                    }
                    if (empty(self::$hasSendStartFrameForFlvRecord[$path])) {
                        $publishStream = MediaServer::getPublishStream($path);
                        /** 只有序列帧准备好后，才可以发送数据，否则mp4缺少格式参数，无法初始化 */
                        if ($publishStream->isMetaData() && $publishStream->isAVCSequence() && $publishStream->isAACSequence()) {
                            /** 发送解码桢 */
                            self::$flvRecorder[$path]->startPlay($path);
                            /** 标记当前节目已发送解码桢 */
                            self::$hasSendStartFrameForFlvRecord[$path] = true;
                        }
                    } else {
                        /** 已标记则直接推送数据转码 */
                        self::$flvRecorder[$path]->frameSend($frame);
                    }
                } catch (\Exception $e) {
                }
            }

            if (FLV_TO_PUSH) {
                /** flv推流到远程服务器，当前仅自动推流到本地服务器的其他进程 */
                try {
                    $path = $publisher->getPublishPath();
                    $pushConfig = self::getPushConfig($path);
                    if ($pushConfig && !empty($pushConfig['enabled'])) {
                        /** 检查是否初始化 */
                        if (empty(self::$flvPusher[$path])) {
                            $pushUrls = !empty($pushConfig['urls']) ? $pushConfig['urls'] : [$pushConfig['url']];
                            $resolvedUrls = array_map(function ($url) use ($path) {
                                return str_replace('{path}', $path, $url);
                            }, $pushUrls);
                            self::$flvPusher[$path] = new FlvPusher($path, $resolvedUrls);
                        }
                        /** 是否发送了启动命令 */
                        if (empty(self::$hasSendStartFrameForFlvPusher[$path])) {
                            self::$flvPusher[$path]->startPlay($path);
                            self::$hasSendStartFrameForFlvPusher[$path] = true;
                            /** 补发当前帧 ，秒开播 */
                            self::$flvPusher[$path]->frameSend($frame);
                        } else {
                            self::$flvPusher[$path]->frameSend($frame);
                        }
                        /** 立即刷新缓冲区，防止其他进程出现推流延迟，理论上来说，本机不同服务器之间推流延迟可以忽略不计，默认是同步直播，当然如果服务器太过拉胯当我没说 */
                        self::$flvPusher[$path]->flush();
                    }
                } catch (\Exception $e) {
                    logger()->error('flv push error: ' . $e->getMessage());
                }
            }
        }
    }

    /** 是否给当前节目发送mp4启动命令 */
    public static $hasSendStartFrameForMp4 = [];

    /** 是否给flv录屏工具发送了启动命令 */
    public static $hasSendStartFrameForFlvRecord = [];

    /** 是否给flv推流工具发送了启动命令 */
    public static $hasSendStartFrameForFlvPusher = [];

    /**
     * 添加推流
     * @param PublishStreamInterface $stream
     * @return bool
     * @comment 有推流数据加入进来，绑定推流设备
     */
    static public function addPublish(PublishStreamInterface $stream): bool
    {
        /** 获取推流路径  */
        $path = $stream->getPublishPath();
        /** warning：这里屏蔽错误处理 */
        \set_error_handler(function () {
        });
        /** 初始化尚未开始推流 */
        $stream->is_on_frame = false;
        /** warning：恢复错误处理 */
        \restore_error_handler();
        /** 绑定事件推流准备事件  */
        $stream->on('on_publish_ready', function () use ($path) {
            /** 获取所有的播放设备 */
            foreach (self::getPlayStreams($path) as $playStream) {
                /** 如果设备出于空闲状态 */
                if ($playStream->isPlayerIdling()) {
                    /** 通知设备开始播放，发送播放命令 */
                    $playStream->startPlay();
                }
            }
        });

        /** 如果当前已有播放设备链接 */
        if (count(self::getPlayStreams($path)) > 0) {
            /** 绑定推流事件 */
            $stream->on('on_frame', self::class . '::publisherOnFrame');
            $stream->is_on_frame = true;
        }

        /** 绑定关闭事件 当推流设备关闭后，给所有的播放客户端发送关闭命令 */
        $stream->on('on_close', function () use ($path) {
            foreach (self::getPlayStreams($path) as $playStream) {
                $playStream->playClose();
            }
            /** 删除本路推流资源 */
            self::delPublishStream($path);

        });
        /** 保存当前推流资源 */
        self::addPublishStream($stream);

        logger()->info(" add publisher {path}", ['path' => $path]);


        try {

            if (isset($stream->isCopy) && $stream->isCopy) {
                // 复制流不继续推送，防止循环，复制流也不转码，不录制节目
            } else {
                // 只有原始流才转码和录屏以及复制流
                if (FLV_TO_HLS) {
                    /** 开启hls转码 */
                    try {
                        if (empty(self::$hlsConverter[$path])) {
                            self::$hlsConverter[$path] = new FLVToHLSConverter($path, [
                                'segmentDuration' => 4,  // 4秒切片，这是理论参数，实际上切片是根据（I帧位置 + 切片间隔）综合判断处理
                                'maxSegments' => 21600   // 保留最新的21600个切片，默认保存24小时的切片文件，这是一个近似值，实际值会因为切片时长变大，若有备份需求请自行手动处理
                            ]);
                        }
                    } catch (\Exception $e) {
                    }
                }

                if (FLV_TO_MP4) {
                    /** 开启mp4转码 */
                    try {
                        if (empty(self::$mp4Converter[$path])) {
                            self::$mp4Converter[$path] = new MP4Converter($path);
                        }
                    } catch (\Exception $e) {
                    }
                }


                if (FLV_TO_RECORD) {
                    /** 开启flv录屏 */
                    try {
                        if (empty(self::$flvRecorder[$path])) {
                            self::$flvRecorder[$path] = new FlvRecorder($path);
                        }
                    } catch (\Exception $e) {
                    }
                }

                if (FLV_TO_PUSH) {
                    /** 开启ws-flv推流 */
                    try {
                        $pushConfig = self::getPushConfig($path);
                        if ($pushConfig && !empty($pushConfig['enabled']) && empty(self::$flvPusher[$path])) {
                            $pushUrls = !empty($pushConfig['urls']) ? $pushConfig['urls'] : [$pushConfig['url']];
                            $resolvedUrls = array_map(function ($url) use ($path) {
                                return str_replace('{path}', $path, $url);
                            }, $pushUrls);
                            self::$flvPusher[$path] = new FlvPusher($path, $resolvedUrls);
                        }

                    } catch (\Exception $e) {
                        logger()->error('flv push init error: ' . $e->getMessage());
                    }
                }
            }
        } catch (\Exception $e) {}
        /** 推流开始后，强制开启数据转发，目的是自动推流录屏转播转码 */
        $p_stream = self::getPublishStream($path);
        if (!$p_stream->is_on_frame) {
            /** 这一路流媒体资源开始推流 转发流量数据 */
            $p_stream->on('on_frame', self::class . '::publisherOnFrame');
            $p_stream->is_on_frame = true;
        }
        return true;

    }

    /** flv录制器，每一个节目一个 */
    static $flvRecorder = [];

    /** mp4转码器，每个节目一个转码器 */
    static $mp4Converter = [];

    /** hls协议转码器，每个节目一个转码器 */
    static $hlsConverter = [];

    /** flv推流器，每个节目一个推流器 */
    static $flvPusher = [];

    /**
     * 添加播放器
     * @param PlayStreamInterface $playerStream
     * @comment 有播放器接入，绑定播放器
     */
    static public function addPlayer($playerStream)
    {
        /** 获取流媒体对象的hash值 */
        $objIndex = spl_object_id($playerStream);
        /** 获取播放路径 */
        $path = $playerStream->getPlayPath();
        /** 播放器绑定关闭事件 */
        //on close event
        $playerStream->on("on_close", function () use ($path, $objIndex) {
            /** 删除播放器媒体资源 */
            //echo "play on close", PHP_EOL;
            self::delPlayerStream($path, $objIndex);
        });
        /** 保存播放器资源 */
        self::addPlayerStream($playerStream);

        /** 判断当前是否有对应的推流设备 */
        if (self::hasPublishStream($path)) {
            $playerStream->startPlay();
        }

        logger()->info(" add player {path}", ['path' => $path]);

    }

    /**
     * 权限配置
     * @var null
     */
    static protected $authConfig = null;

    /**
     * 获取鉴权配置
     * @return array|mixed|null
     */
    static protected function loadAuthConfig()
    {
        if (self::$authConfig === null) {
            $configPath = app_path('/config/auth.php');
            if (file_exists($configPath)) {
                self::$authConfig = require $configPath;
            } else {
                self::$authConfig = [
                    'enabled' => false,
                    'publish' => ['require_auth' => false, 'stream_keys' => []],
                    'play' => ['require_auth' => false],
                    'global' => []
                ];
            }
        }
        return self::$authConfig;
    }

    /**
     * 鉴权
     * @param $stream VerifyAuthStreamInterface
     * @return bool
     */
    static public function verifyAuth($stream)
    {
        $config = self::loadAuthConfig();
        if (!$config['enabled']) return true;

        $ip = $stream->ip ?? '';
        $appName = $stream->appName ?? '';
        // 是否是允许创建的app
        if (!empty($config['global']['allowed_apps']) && !in_array($appName, $config['global']['allowed_apps'])) {
            logger()->warning("[auth] App not allowed: {$appName} ip={$ip}");
            return false;
        }

        // 是否是被禁止创建的app
        if (!empty($config['global']['deny_apps']) && in_array($appName, $config['global']['deny_apps'])) {
            logger()->warning("[auth] App denied: {$appName} ip={$ip}");
            return false;
        }

        /** 只有推流才需要鉴权 */
        if ($stream->is_publish) {
            $publishConfig = $config['publish'] ?? [];
            if (!$publishConfig['require_auth']) return true;

            $args = $stream->publishArgs ?? [];
            $path = $stream->publishStreamPath ?? '';

            // 验证秘钥
            $streamKey = $args['key'] ?? $args['streamKey'] ?? $args['secret'] ?? '';
            if (!empty($publishConfig['stream_keys']) && in_array($streamKey, $publishConfig['stream_keys'])) {
                logger()->info("[auth] Publish allowed by stream key: ip={$ip} path={$path}");
                return true;
            }

            logger()->warning("[auth] Publish denied: ip={$ip} path={$path} args=" . json_encode($args));
            return false;
        }

        return true;

    }

    /**
     * 获取推流配置
     * @param $path
     * @return array|null
     */
    static public function getPushConfig($path)
    {
        $autoPushUrls = [];

        /** 是否开启了多进程，只有多进程才需要进程之间推流 */
        $enableCopyPort = defined('ENABLE_MULTI_PROCESS') ? ENABLE_MULTI_PROCESS : (getenv('ENABLE_MULTI_PROCESS') === 'true');
        /** 是否是子进程 ，在开启多进程的场景，只有子进程才是工作进程负责直播 ，主进程负责管理子进程 */
        $isWorker = defined('IS_WORKER') ? IS_WORKER : (getenv('IS_WORKER') === 'true');

        if ($enableCopyPort && $isWorker) {
            $autoPushUrls = self::getAutoPushUrls();
        }

        if (!empty($autoPushUrls)) {
            return [
                'enabled' => true,
                'urls' => $autoPushUrls,
                'autoCopy' => true
            ];
        }

        return null;
    }

    /**
     * 此方法确定推流目标地址
     * @return array
     * @note 可以修改此方法向其他服务器推流，当前只向本服务器的其他进程推流，但是不建议使用此服务向其它服务器推流，
     * 应为外部服务器的网络状况未知，可能会阻塞本地直播，你可以使用forward.php广播客户端向其它服务器推流。
     */
    static public function getAutoPushUrls()
    {
        $urls = [];

        $currentWorkerId = defined('WORKER_ID') ? WORKER_ID : (int)(getenv('WORKER_ID') ?: \Root\Io\RtmpDemo::getWorkerId());
        $workerCount = defined('WORKER_COUNT') ? WORKER_COUNT : (int)(getenv('WORKER_COUNT') ?: \Root\Io\RtmpDemo::getWorkerCount());

        /** 如果需要向其他服务器推流，那么需要修改为其他服务器IP */
        $host = '127.0.0.1';
        $copyPortStart = defined('COPY_PORT_START') ? COPY_PORT_START : (int)(getenv('COPY_PORT_START') ?: 8502);

        $authConfig = self::loadAuthConfig();
        $key = $authConfig['publish']['stream_keys'][0]??"";
        for ($i = 1; $i <= $workerCount; $i++) {
            if ($i == $currentWorkerId) {
                continue;
            }
            $targetCopyPort = $copyPortStart + $i - 1;
            $urls[] = "ws://{$host}:{$targetCopyPort}{path}?is_copy=true&key=".$key;
        }

        return $urls;
    }


}
