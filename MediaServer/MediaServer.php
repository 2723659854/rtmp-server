<?php

namespace MediaServer;


use Evenement\EventEmitter;
use MediaServer\Flv\FlvRecorder;
use MediaServer\HLS\FLVToHLSConverter;
use MediaServer\MediaReader\MediaFrame;
use MediaServer\MP4\Mp4Converter;
use MediaServer\PushServer\PlayStreamInterface;
use MediaServer\PushServer\PublishStreamInterface;
use MediaServer\PushServer\VerifyAuthStreamInterface;


/**
 * @purpose 媒体中心服务
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
        return call_user_func_array([self::$eventEmitter,$name],$arguments);
    }


    /**
     * 保存本项目下的所有推流资源
     * @var PublishStreamInterface[]
     * @comment 从代码中可以看出，所有的推流资源都存放在内存中，所以直播比较消耗内存
     */
    static public $publishStream = [];

    /**
     * 调用本对象的api
     * @param $name
     * @param $args
     * @return array|false
     */
    static public function callApi($name,$args = []){
        switch ($name){
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
    static public function  listPushStream($path = null){
        if($path){
            return isset(self::$publishStream[$path])?[
                self::$publishStream[$path]->getPublishStreamInfo()
            ]:[];
        }
        return array_map(function($stream){
            return $stream->getPublishStreamInfo();
        },array_values(self::$publishStream));
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

        if (FLV_TO_HLS){
            /** 关闭hls转码 */
            try{
                if (!empty(self::$hlsConverter[$path])) {
                    self::$hlsConverter[$path]->close();
                    unset(self::$hlsConverter[$path]);
                }
            }catch (\Exception $e){}
        }


        if (FLV_TO_MP4){
            /** 关闭mp4转码 */
            try{
                if (!empty(self::$mp4Converter[$path])) {
                    self::$mp4Converter[$path]->close();
                    unset(self::$hasSendStartFrameForMp4[$path],self::$mp4Converter[$path]);
                }
            }catch (\Exception $e){}
        }

        if (FLV_TO_RECORD){
            /** 关闭flv录屏 */
            try {
                if (!empty(self::$flvRecorder[$path])) {
                    self::$flvRecorder[$path]->close();
                    unset(self::$flvRecorder[$path],self::$hasSendStartFrameForFlvRecord[$path]);
                }
            }catch (\Exception $e){}
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
        //一个播放设备都没有
        if (self::hasPublishStream($path) && count(self::getPlayStreams($path)) == 0) {
            /** 获取这个路径下的推流资源 */
            $p_stream = self::getPublishStream($path);
            /** 移除事件 */
            $p_stream->removeListener('on_frame', self::class . '::publisherOnFrame');
            $p_stream->is_on_frame = false;
        }
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
     * @param $publisher PublishStreamInterface 发布者 可以是音频，可以是视频
     * @param $frame MediaFrame 这个是流媒体数据包，比如音频或者视频
     * @comment rtmp服务端转发数据的关键就是这个方法
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

        if (FLV_TO_HLS) {
            /** hls处理数据 */
            try{
                $path = $publisher->getPublishPath();
                if (empty(self::$hlsConverter[$path])) {
                    self::$hlsConverter[$path] = new FLVToHLSConverter($path, [
                        'segmentDuration' => 4,  // 4秒切片
                        'maxSegments' => 100      // 保留最新的5个切片
                    ]);
                }
                /** 直接转码mp4 */
                self::$hlsConverter[$path]->processFrame($frame);
            }catch (\Exception $e){}
        }

        if (FLV_TO_MP4) {
            /** mp4转码 */
            try{
                $path = $publisher->getPublishPath();
                if (empty(self::$mp4Converter[$path])) {
                    self::$mp4Converter[$path] = new MP4Converter($path);
                }
                if (empty(self::$hasSendStartFrameForMp4[$path])) {
                    $publishStream = MediaServer::getPublishStream($path);
                    /** 只有序列帧准备好后，才可以发送数据，否则mp4缺少格式参数，无法初始化 */
                    if ($publishStream->isMetaData() && $publishStream->isAVCSequence() && $publishStream->isAACSequence()){
                        /** 发送解码桢 */
                        self::$mp4Converter[$path]->startPlay($path);
                        /** 标记当前节目已发送解码桢 */
                        self::$hasSendStartFrameForMp4[$path] = true;
                    }
                }else{
                    /** 已标记则直接推送数据转码 */
                    self::$mp4Converter[$path]->frameSend($frame);
                }

            }catch (\Exception $e){}
        }

        if (FLV_TO_RECORD) {
            /** flv录屏 */
            try{
                $path = $publisher->getPublishPath();
                if (empty(self::$flvRecorder[$path])) {
                    self::$flvRecorder[$path] = new FlvRecorder($path);
                }
                if (empty(self::$hasSendStartFrameForFlvRecord[$path])) {
                    $publishStream = MediaServer::getPublishStream($path);
                    /** 只有序列帧准备好后，才可以发送数据，否则mp4缺少格式参数，无法初始化 */
                    if ($publishStream->isMetaData() && $publishStream->isAVCSequence() && $publishStream->isAACSequence()){
                        /** 发送解码桢 */
                        self::$flvRecorder[$path]->startPlay($path);
                        /** 标记当前节目已发送解码桢 */
                        self::$hasSendStartFrameForFlvRecord[$path] = true;
                    }
                }else{
                    /** 已标记则直接推送数据转码 */
                    self::$flvRecorder[$path]->frameSend($frame);
                }
            }catch (\Exception $e){}
        }

    }

    /** 是否给当前节目发送mp4启动命令 */
    public static $hasSendStartFrameForMp4 = [];

    /** 是否给flv录屏工具发送了启动命令 */
    public static $hasSendStartFrameForFlvRecord = [];

    /**
     * 添加推流
     * @param PublishStreamInterface $stream
     * @return bool
     * @comment 有推流数据加入进来
     */
    static public function addPublish(PublishStreamInterface $stream): bool
    {
        /** 获取推流路径  */
        $path = $stream->getPublishPath();
        /** warning：这里屏蔽错误处理 */
        \set_error_handler(function(){});
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


        try{
            if (FLV_TO_HLS) {
                /** 开启hls转码 */
                try{
                    if (empty(self::$hlsConverter[$path])){
                        self::$hlsConverter[$path] = new FLVToHLSConverter($path, [
                            'segmentDuration' => 4,  // 4秒切片
                            'maxSegments' => 100      // 保留最新的5个切片
                        ]);
                    }
                }catch (\Exception $e){}
            }

            if(FLV_TO_MP4) {
                /** 开启mp4转码 */
                try{
                    if (empty(self::$mp4Converter[$path])){
                        self::$mp4Converter[$path] = new MP4Converter($path);
                    }
                }catch (\Exception $e){}
            }

            if (FLV_TO_RECORD){
                /** 开启flv录屏 */
                try{
                    if (empty(self::$flvRecorder[$path])){
                        self::$flvRecorder[$path] = new FlvRecorder($path);
                    }
                }catch (\Exception $e){}
            }

            /** 推流开始后，强制开启数据转发 */
            $p_stream = self::getPublishStream($path);
            if (!$p_stream->is_on_frame) {
                /** 这一路流媒体资源开始推流 转发流量数据 */
                $p_stream->on('on_frame', self::class . '::publisherOnFrame');
                $p_stream->is_on_frame = true;
            }
        }catch (\Exception $e){}

        return true;

    }

    /** flv录制器，每一个节目一个 */
    static $flvRecorder = [];

    /** mp4转码器，每个节目一个转码器 */
    static $mp4Converter = [];

    /** hls协议转码器，每个节目一个转码器 */
    static $hlsConverter = [];

    /**
     * 添加播放器
     * @param PlayStreamInterface $playerStream
     * @comment 有播放器接入
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

    static protected $authConfig = null;

    static protected function loadAuthConfig()
    {
        if (self::$authConfig === null) {
            $configPath = app_path('/auth_config.php') ;
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
        if ($stream->is_publish){
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


}
