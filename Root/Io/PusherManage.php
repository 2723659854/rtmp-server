<?php

namespace Root\Io;

use Xiaosongshu\Flv2mp4\manage\FlvPusherAll;
use Xiaosongshu\Flv2mp4\manage\Mp4PusherAll;
use Xiaosongshu\Flv2mp4\SabreAMF\RtmpPushFlvClient;
use Xiaosongshu\Flv2mp4\SabreAMF\RtmpPushMp4Client;

/**
 * @purpose 推流客户端管理器
 * @author yanglong
 * @time 2026年6月12日14:04:11
 */
class PusherManage
{

    /** 推流客户端 */
    protected $pusher = null;

    /**
     * 初始化推流客户端
     * @param string $filename 文件路径，支持mp4,flv
     * @param string $pushUrl 推流目标地址，支持http-flv,ws-flv,rtmp
     * @param mixed $speed 推流速度倍数 (0.1-10.0, default: 1.0)
     * @param bool $autoReconnect 自动重连 --no-reconnect
     * @throws \RuntimeException
     */
    public function __construct(string $filename, string $pushUrl, mixed $speed, bool $autoReconnect)
    {
        if (!file_exists($filename)) {
            throw new \RuntimeException($filename .'  does not exist');
        }

        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $extension = strtolower($extension);
        if (!in_array($extension, ['mp4', 'flv'])) {
            throw new \RuntimeException('Invalid extension');
        }

        $urlParts = parse_url($pushUrl);
        $scheme = strtolower($urlParts['scheme'] ?? 'http');

        if ($scheme == 'rtmp'){
            if ($extension == "mp4"){
                $this->pusher = new RtmpPushMp4Client($filename,$pushUrl,$speed,$autoReconnect);
            }else{
                $this->pusher = new RtmpPushFlvClient($filename,$pushUrl,$speed,$autoReconnect);
            }
        }else{
            if ($extension == 'flv') {
                $this->pusher = new FlvPusherAll($filename, $pushUrl, $speed, $autoReconnect);
            }else{
                $this->pusher = new Mp4PusherAll($filename, $pushUrl, $speed, $autoReconnect);
            }
        }
    }

    /**
     * 开始推流
     * @return void
     */
    public function start():void
    {
        if ($this->pusher) {
            $this->pusher->start();
        }
    }
}