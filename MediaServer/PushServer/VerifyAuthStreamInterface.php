<?php

namespace MediaServer\PushServer;

/**
 * @purpose 权限验证接口
 * @author yanglong
 */
interface VerifyAuthStreamInterface
{
    /**
     * 获取客户端ip
     * @return string
     */
    public function getClientIp(): string;

    /**
     * 获取app名称
     * @return string
     */
    public function getAppName(): string;

    /**
     * 是否推流中
     * @return bool
     */
    public function isPublishing(): bool;

    /**
     * 是否播放拉流中
     * @return bool
     */
    public function isPlaying(): bool;

    /**
     * 获取推流参数
     * @return array
     */
    public function getPublishArgs(): array;

    /**
     * 获取拉流参数
     * @return array
     */
    public function getPlayArgs(): array;

    /**
     * 获取推流节目地址
     * @return string
     */
    public function getPublishStreamPath(): string;

    /**
     * 获取拉流节目地址
     * @return string
     */
    public function getPlayStreamPath(): string;
}