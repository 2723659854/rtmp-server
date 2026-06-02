<?php

namespace Root;

use Throwable;
use function filemtime;
use function gmdate;
use Root\Lib\BaseResponse;
class Response extends BaseResponse
{
    /**
     * @var Throwable
     */
    protected $exception = null;

    /**
     * File
     * @param string $file
     * @return $this
     */
    public function file(string $file): Response
    {
        if ($this->notModifiedSince($file)) {
            return $this->withStatus(304);
        }
        return $this->withFile($file);
    }

    /**
     * Download
     * @param string $file
     * @param string $downloadName
     * @return $this
     */
    public function download(string $file, string $downloadName = ''): Response
    {
        $this->withFile($file);
        if ($downloadName) {
            $this->header('Content-Disposition', "attachment; filename=\"$downloadName\"");
        }
        return $this;
    }

    /**
     * NotModifiedSince
     * @param string $file
     * @return bool
     */
    protected function notModifiedSince(string $file): bool
    {
        return false;
    }

    /**
     * Exception
     * @param Throwable|null $exception
     * @return Throwable|null
     */
    public function exception(?Throwable $exception = null): ?Throwable
    {
        if ($exception) {
            $this->exception = $exception;
        }
        return $this->exception;
    }
}