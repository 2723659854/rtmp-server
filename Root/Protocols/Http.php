<?php

namespace Root\Protocols;

use Root\rtmp\TcpConnection;
use Root\Request;
use Root\Response;
use Root\Lib\Session;
/**
 * Class Http.
 *
 */
class Http
{
    /**
     * 默认解析类
     * @var string
     */
    protected static $_requestClass = 'Root\Request';

    /**
     * Upload tmp dir.
     *
     * @var string
     */
    protected static $_uploadTmpDir = '';

    /**
     * Open cache.
     *
     * @var bool.
     */
    protected static $_enableCache = true;

    /**
     * 设置获取session名
     * @param string|null $name
     * @return string
     */
    public static function sessionName($name = null)
    {
        if ($name !== null && $name !== '') {
            Session::$name = (string)$name;
        }
        return Session::$name;
    }

    /**
     * 获取或者设置请求解析类
     * @param string|null $class_name
     * @return string
     */
    public static function requestClass($class_name = null)
    {
        if ($class_name) {
            static::$_requestClass = $class_name;
        }
        return static::$_requestClass;
    }

    /**
     * 开启或者关闭缓存
     * @param mixed $value
     */
    public static function enableCache($value)
    {
        static::$_enableCache = (bool)$value;
    }

    /**
     * Check the integrity of the package.
     * 检查包的完整性
     * @param string $recv_buffer
     * @param TcpConnection $connection
     * @return int
     */
    public static function input($recv_buffer, TcpConnection $connection)
    {
        static $input = [];
        /** 长度没有超过512，并且已经有解析，直接返回缓存的解析数据 */
        if (!isset($recv_buffer[512]) && isset($input[$recv_buffer])) {
            return $input[$recv_buffer];
        }
        /** 读取分隔符位置 */
        $crlf_pos = \strpos($recv_buffer, "\r\n\r\n");
        if (false === $crlf_pos) {
            /** 包太大了 */
            // Judge whether the package length exceeds the limit.
            if (\strlen($recv_buffer) >= 16384) {
                $connection->close("HTTP/1.1 413 Request Entity Too Large\r\n\r\n", true);
                return 0;
            }
            return 0;
        }
        /** 计算长度 */
        $length = $crlf_pos + 4;
        /** 获取请求方法 */
        $method = \strstr($recv_buffer, ' ', true);
        /** 不支持的请求方法 */
        if (!\in_array($method, ['GET', 'POST', 'OPTIONS', 'HEAD', 'DELETE', 'PUT', 'PATCH'])) {
            $connection->close("HTTP/1.1 400 Bad Request\r\n\r\n", true);
            return 0;
        }
        /** 解析头部 */
        $header = \substr($recv_buffer, 0, $crlf_pos);
        /** 获取body长度 */
        if ($pos = \strpos($header, "\r\nContent-Length: ")) {
            $length = $length + (int)\substr($header, $pos + 18, 10);
            $has_content_length = true;
        } else if (\preg_match("/\r\ncontent-length: ?(\d+)/i", $header, $match)) {
            $length = $length + $match[1];
            $has_content_length = true;
        } else {
            $has_content_length = false;
            if (false !== stripos($header, "\r\nTransfer-Encoding:")) {
                $connection->close("HTTP/1.1 400 Bad Request\r\n\r\n", true);
                return 0;
            }
        }
        /** 包长度大于协议允许的最大长度 */
        if ($has_content_length) {
            if ($length > $connection->maxPackageSize) {
                $connection->close("HTTP/1.1 413 Request Entity Too Large\r\n\r\n", true);
                return 0;
            }
        }
        /** 如果长度大于512 ,清空缓存 */
        if (!isset($recv_buffer[512])) {
            $input[$recv_buffer] = $length;
            if (\count($input) > 512) {
                unset($input[key($input)]);
            }
        }

        return $length;
    }

    /**
     * Http decode.
     * http数据解码
     * @param string $recv_buffer
     * @param TcpConnection $connection
     * @return Request
     */
    public static function decode($recv_buffer, TcpConnection $connection)
    {
        static $requests = array();
        /** 如果有缓存，直接返回缓存 */
        $cacheable = static::$_enableCache && !isset($recv_buffer[512]);
        if (true === $cacheable && isset($requests[$recv_buffer])) {
            $request = $requests[$recv_buffer];
            $request->connection = $connection;
            $connection->__request = $request;
            $request->properties = array();
            return $request;
        }
        /** 解码数据并缓存 */
        $request = new static::$_requestClass($recv_buffer);
        $request->connection = $connection;
        $connection->__request = $request;
        if (true === $cacheable) {
            $requests[$recv_buffer] = $request;
            if (\count($requests) > 512) {
                unset($requests[key($requests)]);
            }
        }
        return $request;
    }

    /**
     * Http encode.
     * 发送http数据，编码
     * @param string|Response $response 响应内容
     * @param TcpConnection $connection 客户端链接
     * @return string
     */
    public static function encode($response, TcpConnection $connection)
    {
        /** 清空客户端请求的session和链接 */
        if (isset($connection->__request)) {
            $connection->__request->session = null;
            $connection->__request->connection = null;
            $connection->__request = null;
        }
        /** 如果响应内容不是对象，那就是数组 */
        if (!\is_object($response)) {
            $ext_header = '';
            /** 发送header部分 */
            if (isset($connection->__header)) {
                foreach ($connection->__header as $name => $value) {
                    if (\is_array($value)) {
                        foreach ($value as $item) {
                            $ext_header = "$name: $item\r\n";
                        }
                    } else {
                        $ext_header = "$name: $value\r\n";
                    }
                }
                unset($connection->__header);
            }
            $body_len = \strlen((string)$response);
            return "HTTP/1.1 200 OK\r\nServer: xiaosongshu\r\n{$ext_header}Connection: keep-alive\r\nContent-Type: text/html;charset=utf-8\r\nContent-Length: $body_len\r\n\r\n$response";
        }

        /** 如果是对象 设置头部 */
        if (isset($connection->__header)) {
            $response->withHeaders($connection->__header);
            unset($connection->__header);
        }
        /** 如果还要发送文件 */
        // Root\Protocols\Http.php 的 encode 方法中，文件处理部分替换为：

        if (isset($response->file)) {
            $file   = $response->file['file'];
            clearstatcache();
            $file_size = (int)filesize($file);

            // 确定要读取的片段
            $rangeInfo = $response->getRangeInfo();  // 确保该方法已存在（见后）
            if ($rangeInfo) {
                $offset = $rangeInfo[0];
                $readLength = $rangeInfo[2];
            } else {
                $offset = $response->file['offset'];
                $length = $response->file['length'];
                $readLength = $length > 0 ? $length : $file_size - $offset;
            }

            // 不再覆盖任何头部信息（Content-Length / Content-Range 已由 withRange 或 withFile 设置）
            // 仅补充可能缺失的通用头
            if (!$response->getHeader('Accept-Ranges')) {
                $response->header('Accept-Ranges', 'bytes');
            }

            // 生成头部字符串（不含 body）
            $head = (string)$response;

            // 发送文件片段
            if ($readLength < 2 * 1024 * 1024) {
                $content = file_get_contents($file, false, null, $offset, $readLength);
                $connection->send($head . $content, true);
            } else {
                $connection->send($head, true);
                $handler = fopen($file, 'rb');
                if (!$handler) {
                    $connection->close(new Response(403, null, '403 Forbidden'));
                    return '';
                }
                static::sendStream($connection, $handler, $offset, $readLength);
            }
            return '';
        }

        return (string)$response;
    }

    /**
     * Send remainder of a stream to client.
     * 发送数据流到客户端
     * @param TcpConnection $connection 客户端链接
     * @param resource $handler 文件操作类
     * @param int $offset 偏移量
     * @param int $length 发送长度
     */
    protected static function sendStream(TcpConnection $connection, $handler, $offset = 0, $length = 0)
    {
        $connection->bufferFull = false;
        if ($offset !== 0) {
            fseek($handler, $offset);
        }
        $offset_end = $offset + $length;
        $totalSent = 0;   // 记录已发送字节

        $do_write = function () use ($connection, $handler, $length, $offset_end, &$totalSent) {
            while ($connection->bufferFull === false) {
                $tell = ftell($handler);
                $remain_size = $offset_end - $tell;
                if ($remain_size <= 0) {
                    //logger()->info("sendStream complete, total sent: $totalSent");
                    fclose($handler);
                    $connection->onBufferDrain = null;
                    return;
                }
                $size = min(1024 * 1024, $remain_size);
                $buffer = fread($handler, $size);
                if ($buffer === '' || $buffer === false) {
                    //logger()->warning("sendStream read error at $tell");
                    fclose($handler);
                    $connection->onBufferDrain = null;
                    return;
                }
                $connection->send($buffer, true);
                $totalSent += strlen($buffer);
            }
        };

        // 绑定缓冲区满 / 空事件
        $connection->onBufferFull = function ($connection) {
            $connection->bufferFull = true;
        };
        $connection->onBufferDrain = function ($connection) use ($do_write) {
            $connection->bufferFull = false;
            $do_write();
        };

        $do_write();
    }

    /**
     * 设置上传临时目录
     * @return bool|string
     */
    public static function uploadTmpDir($dir = null)
    {
        if (null !== $dir) {
            static::$_uploadTmpDir = $dir;
        }
        if (static::$_uploadTmpDir === '') {
            if ($upload_tmp_dir = \ini_get('upload_tmp_dir')) {
                static::$_uploadTmpDir = $upload_tmp_dir;
            } else if ($upload_tmp_dir = \sys_get_temp_dir()) {
                static::$_uploadTmpDir = $upload_tmp_dir;
            }
        }
        return static::$_uploadTmpDir;
    }
}
