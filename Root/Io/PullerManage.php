<?php

namespace Root\Io;

use Xiaosongshu\Flv2mp4\SabreAMF\RtmpPullerClient;
use Xiaosongshu\Flv2mp4\Flv\FlvPullerClient;

/**
 * @purpose 直播拉流客户端 支持http-flv,ws-flv,rtmp协议
 * @author yanglong
 */
class PullerManage
{
    protected $puller = null;

    public function __construct(string $pullUrl, string $outputFlv, int $duration = 0, bool $autoReconnect = true)
    {
        if (empty($pullUrl)) {
            throw new \RuntimeException('Pull URL cannot be empty');
        }

        if (empty($outputFlv)) {
            throw new \RuntimeException('Output FLV path cannot be empty');
        }

        $outputFlv = app_path(DIRECTORY_SEPARATOR."record".DIRECTORY_SEPARATOR.trim($outputFlv, DIRECTORY_SEPARATOR));
        if (file_exists($outputFlv)) {
            @unlink($outputFlv);
        }
        $urlParts = parse_url($pullUrl);
        $scheme = strtolower($urlParts['scheme'] ?? 'http');

        if ($scheme === 'rtmp') {
            $this->puller = new RtmpPullerClient($pullUrl, $outputFlv, $duration, $autoReconnect);
        } else {
            $this->puller = new FlvPullerClient($pullUrl, $outputFlv, $duration, $autoReconnect);
        }
    }

    public function start(): void
    {
        if ($this->puller) {
            $this->puller->start();
        }
    }
}
