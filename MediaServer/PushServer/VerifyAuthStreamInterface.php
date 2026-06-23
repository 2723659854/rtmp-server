<?php

namespace MediaServer\PushServer;

interface VerifyAuthStreamInterface
{
    public function getClientIp(): string;

    public function getAppName(): string;

    public function isPublishing(): bool;

    public function isPlaying(): bool;

    public function getPublishArgs(): array;

    public function getPlayArgs(): array;

    public function getPublishStreamPath(): string;

    public function getPlayStreamPath(): string;
}