<?php

namespace Root\Lib;

/**
 * @purpose 响应基类
 * @author yanglong
 */
class BaseResponse
{

    /**
     * @var string|null 客户端发送的 Range 头原始值
     */
    protected $rangeHeader = null;

    /**
     * @var int 文件总大小（在 withFile 时计算并缓存，避免重复 stat）
     */
    protected $fileSize = 0;

    protected $rangeStart = null;
    protected $rangeEnd   = null;
    protected $rangeLength = null;

    /**
     * 头部.
     *
     * @var array
     */
    protected $_header = null;

    /**
     * Http 状态
     *
     * @var int
     */
    protected $_status = null;

    /**
     * Http reason.
     *
     * @var string
     */
    protected $_reason = null;

    /**
     * Http 版本
     *
     * @var string
     */
    protected $_version = '1.1';

    /**
     * 需要发送的body内容
     *
     * @var string
     */
    protected $_body = null;

    /**
     * 需要发送的文件
     *
     * @var array
     */
    public $file = null;

    /**
     * Mine type map.
     * @var array
     */
    protected static $_mimeTypeMap = null;

    /**
     * 状态码
     *
     * @var array
     */
    protected static $_phrases = array(
        100 => 'Continue',
        101 => 'Switching Protocols',
        102 => 'Processing',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        207 => 'Multi-status',
        208 => 'Already Reported',
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        306 => 'Switch Proxy',
        307 => 'Temporary Redirect',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Time-out',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Request Entity Too Large',
        414 => 'Request-URI Too Large',
        415 => 'Unsupported Media Type',
        416 => 'Requested range not satisfiable',
        417 => 'Expectation Failed',
        418 => 'I\'m a teapot',
        422 => 'Unprocessable Entity',
        423 => 'Locked',
        424 => 'Failed Dependency',
        425 => 'Unordered Collection',
        426 => 'Upgrade Required',
        428 => 'Precondition Required',
        429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large',
        451 => 'Unavailable For Legal Reasons',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Time-out',
        505 => 'HTTP Version not supported',
        506 => 'Variant Also Negotiates',
        507 => 'Insufficient Storage',
        508 => 'Loop Detected',
        511 => 'Network Authentication Required',
    );

    /**
     *  初始化
     *
     * @return void
     */
    public static function init() {
        static::initMimeTypeMap();
    }

    /**
     * Response constructor.
     *
     * @param int $status
     * @param array $headers
     * @param string $body
     */
    public function __construct(
        $status = 200,
        $headers = array(),
        $body = ''
    ) {
        $this->_status = $status;
        $this->_header = $headers;
        $this->_body = (string)$body;
        static::initMimeTypeMap();
    }

    /**
     * 设置头部
     *
     * @param string $name
     * @param string $value
     * @return $this
     */
    public function header($name, $value) {
        $this->_header[$name] = $value;
        return $this;
    }

    /**
     * 设置头部
     *
     * @param string $name
     * @param string $value
     * @return BaseResponse
     */
    public function withHeader($name, $value) {
        return $this->header($name, $value);
    }

    /**
     * 批量设置头部
     *
     * @param array $headers
     * @return $this
     */
    public function withHeaders($headers) {
        $this->_header = \array_merge_recursive($this->_header, $headers);
        return $this;
    }

    /**
     * 移除头部
     *
     * @param string $name
     * @return $this
     */
    public function withoutHeader($name) {
        unset($this->_header[$name]);
        return $this;
    }

    /**
     * 获取头部
     *
     * @param string $name
     * @return null|array|string
     */
    public function getHeader($name) {
        if (!isset($this->_header[$name])) {
            return null;
        }
        return $this->_header[$name];
    }

    /**
     * 获取所有头部
     *
     * @return array
     */
    public function getHeaders() {
        return $this->_header;
    }

    /**
     * 设置状态码
     *
     * @param int $code
     * @param string|null $reason_phrase
     * @return $this
     */
    public function withStatus($code, $reason_phrase = null) {
        $this->_status = $code;
        $this->_reason = $reason_phrase;
        return $this;
    }

    /**
     * 获取状态码
     *
     * @return int
     */
    public function getStatusCode() {
        return $this->_status;
    }

    /**
     * 获取原有解析
     *
     * @return string
     */
    public function getReasonPhrase() {
        return $this->_reason;
    }

    /**
     * 设置版本号
     *
     * @param int $version
     * @return $this
     */
    public function withProtocolVersion($version) {
        $this->_version = $version;
        return $this;
    }

    /**
     * 设置body数据
     *
     * @param string $body
     * @return $this
     */
    public function withBody($body) {
        $this->_body = $body;
        return $this;
    }

    /**
     * 获取body部分原始数据
     *
     * @return string
     */
    public function rawBody() {
        return $this->_body;
    }

    /**
     * 发送文件
     *
     * @param string $file
     * @param int $offset
     * @param int $length
     * @return $this
     */
    public function withFile($file, $offset = 0, $length = 0) {
        if (!\is_file($file)) {
            return $this->withStatus(404)->withBody('<h3>404 Not Found</h3>');
        }
        $this->file = array('file' => $file, 'offset' => $offset, 'length' => $length);
        $this->fileSize = filesize($file);   // 缓存文件大小
        $this->header('Content-Length', $this->fileSize);
        $this->header('Accept-Ranges', 'bytes');
        return $this;
    }

    /**
     * 设置cookie
     *
     * @param $name
     * @param string $value
     * @param int $max_age
     * @param string $path
     * @param string $domain
     * @param bool $secure
     * @param bool $http_only
     * @param bool $same_site
     * @return $this
     */
    public function cookie($name, $value = '', $max_age = null, $path = '', $domain = '', $secure = false, $http_only = false, $same_site  = false)
    {
        $this->_header['Set-Cookie'][] = $name . '=' . \rawurlencode($value)
            . (empty($domain) ? '' : '; Domain=' . $domain)
            . ($max_age === null ? '' : '; Max-Age=' . $max_age)
            . (empty($path) ? '' : '; Path=' . $path)
            . (!$secure ? '' : '; Secure')
            . (!$http_only ? '' : '; HttpOnly')
            . (empty($same_site) ? '' : '; SameSite=' . $same_site);
        return $this;
    }

    // BaseResponse.php 中需修改/新增的部分

    /**
     * 生成文件下载的 HTTP 头部（不含 body）
     */
    protected function createHeadForFile($file_info)
    {
        $file = $file_info['file'];
        $reason = $this->_reason ?: static::$_phrases[$this->_status];
        $head = "HTTP/{$this->_version} {$this->_status} $reason\r\n";

        $headers = $this->_header;
        if (!isset($headers['Server'])) {
            $head .= "Server: xiaosongshu\r\n";
        }
        foreach ($headers as $name => $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $head .= "$name: $item\r\n";
                }
                continue;
            }
            $head .= "$name: $value\r\n";
        }
        if (!isset($headers['Connection'])) {
            $head .= "Connection: keep-alive\r\n";
        }

        // Content-Type
        $pathinfo = pathinfo($file);
        $extension = $pathinfo['extension'] ?? '';
        if (!isset($headers['Content-Type'])) {
            if (isset(self::$_mimeTypeMap[$extension])) {
                $head .= "Content-Type: " . self::$_mimeTypeMap[$extension] . "\r\n";
            } else {
                $head .= "Content-Type: application/octet-stream\r\n";
            }
        }
        // 非已知类型才加 attachment
        if (!isset($headers['Content-Disposition']) && !isset(self::$_mimeTypeMap[$extension])) {
            $head .= "Content-Disposition: attachment; filename=\"" . ($pathinfo['basename'] ?? 'unknown') . "\"\r\n";
        }
        if (!isset($headers['Last-Modified']) && ($mtime = filemtime($file))) {
            $head .= 'Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . " GMT\r\n";
        }

        $head .= "\r\n";
        return $head;   // 只返回头部
    }

    /**
     * 获取 Range 信息（供 Http::encode 等外部使用）
     * @return array|null  [start, end, length] 或 null
     */
    public function getRangeInfo()
    {
        if ($this->rangeStart !== null) {
            return [$this->rangeStart, $this->rangeEnd, $this->rangeLength];
        }
        return null;
    }


    /**
     * 解析 Range 头，返回 [start, end, length] 或 false
     *
     * @param string $rangeHeader
     * @param int $fileSize
     * @return array|false
     */
    protected function parseRange($rangeHeader, $fileSize)
    {
        if (!preg_match('/bytes\s*=\s*(\d*)\s*-\s*(\d*)/i', $rangeHeader, $matches)) {
            return false;
        }
        $start = $matches[1] !== '' ? (int)$matches[1] : 0;
        $end   = $matches[2] !== '' ? (int)$matches[2] : ($fileSize - 1);
        if ($start > $end || $start >= $fileSize) {
            return false;
        }
        if ($end >= $fileSize) {
            $end = $fileSize - 1;
        }
        $length = $end - $start + 1;
        return [$start, $end, $length];
    }

    /**
     * 设置 Range 请求头，用于支持断点续传/seek
     *
     * @param string|null $rangeHeader 形如 "bytes=0-1023"
     * @return $this
     */
    public function withRange($rangeHeader)
    {
        $this->rangeHeader = $rangeHeader;
        /** 确保文件大小已正确设置 */
        if ($rangeHeader && $this->fileSize > 0) {
            $rangeData = $this->parseRange($rangeHeader, $this->fileSize);
            if ($rangeData !== false) {
                list($this->rangeStart, $this->rangeEnd, $this->rangeLength) = $rangeData;
                $this->withStatus(206);
                // 覆盖 Content-Length 为片段长度
                $this->header('Content-Length', $this->rangeLength);
                $this->header('Content-Range', "bytes {$this->rangeStart}-{$this->rangeEnd}/{$this->fileSize}");
            } else {
                $this->withStatus(416);
                $this->header('Content-Range', "bytes */{$this->fileSize}");
                $this->header('Content-Length', 0);   // 416 不应有 body
            }
        }
        return $this;
    }

    /**
     * __toString.
     * 将整个对象 转 字符串
     * @return string
     */
    public function __toString()
    {
        if (isset($this->file)) {
            return $this->createHeadForFile($this->file);
        }
        $reason = $this->_reason ? $this->_reason : static::$_phrases[$this->_status];
        $body_len = \strlen($this->_body);
        if (empty($this->_header)) {
            return "HTTP/{$this->_version} {$this->_status} $reason\r\nServer: xiaosongshu\r\nContent-Type: text/html;charset=utf-8\r\nContent-Length: $body_len\r\nConnection: keep-alive\r\n\r\n{$this->_body}";
        }

        $head = "HTTP/{$this->_version} {$this->_status} $reason\r\n";
        $headers = $this->_header;
        if (!isset($headers['Server'])) {
            $head .= "Server: xiaosongshu\r\n";
        }

        foreach ($headers as $name => $value) {
            if (\is_array($value)) {
                foreach ($value as $item) {
                    $head .= "$name: $item\r\n";
                }
                continue;
            }
            $head .= "$name: $value\r\n";
        }

        if (!isset($headers['Connection'])) {
            $head .= "Connection: keep-alive\r\n";
        }

        if (!isset($headers['Content-Type'])) {
            $head .= "Content-Type: text/html;charset=utf-8\r\n";
        } else if ($headers['Content-Type'] === 'text/event-stream') {
            return $head . $this->_body;
        }

        if (!isset($headers['Transfer-Encoding'])) {
            /** 只有在没有设置 Content-Length 时才添加，避免重复 */
            if (!isset($headers['Content-Length'])) {
                $head .= "Content-Length: $body_len\r\n";
            }
            $head .= "\r\n";
        } else {
            return $body_len ? "$head\r\n" . dechex($body_len) . "\r\n{$this->_body}\r\n" : "$head\r\n";
        }

        // The whole http package
        return $head . $this->_body;
    }

    /**
     * Init mime map.
     * mime类型
     * @return void
     */
    public static function initMimeTypeMap()
    {
        $mime_file = __DIR__ . '/mime.types';
        $items = \file($mime_file, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        foreach ($items as $content) {
            if (\preg_match("/\s*(\S+)\s+(\S.+)/", $content, $match)) {
                $mime_type       = $match[1];
                $extension_var   = $match[2];
                $extension_array = \explode(' ', \substr($extension_var, 0, -1));
                foreach ($extension_array as $file_extension) {
                    static::$_mimeTypeMap[$file_extension] = $mime_type;
                }
            }
        }
    }
}