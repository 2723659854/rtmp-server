<?php
namespace Root\Lib;

/**
 * @purpose 请求类基类
 * @author yanglong
 */
class BaseRequest
{
    /**
     * 链接
     * @var
     */
    public $connection = null;

    /**
     * Session
     * @var
     */
    public $session = null;

    /**
     * 属性
     * @var array
     */
    public $properties = array();

    /**
     * @var int 最大上传数量
     */
    public static $maxFileUploads = 1024;

    /**
     * Http buffer. 缓存的原始数据
     *
     * @var string
     */
    protected $_buffer = null;

    /**
     * Request data. 携带的数据
     *
     * @var array
     */
    protected $_data = null;

    /**
     * Enable cache. 开启缓存
     *
     * @var bool
     */
    protected static $_enableCache = true;

    /**
     * 是否安全
     *
     * @var bool
     */
    protected $_isSafe = true;

    /** 客户端IP地址信息 */
    public $remote_address = null;

    /**
     * 构造函数，初始化
     *
     * @param string $buffer 原始数据
     * @param string $remote_address 客户端地址
     */
    public function __construct($buffer='',$remote_address='')
    {
        $this->_buffer = $buffer;
        $this->remote_address = $remote_address;
    }

    /**
     * $_GET.
     * 获取get方法传递的参数
     * @param string|null $name
     * @param mixed|null $default
     * @return mixed|null
     */
    public function get($name = null, $default = null)
    {
        if (!isset($this->_data['get'])) {
            $this->parseGet();
        }
        if (null === $name) {
            return $this->_data['get'];
        }
        return isset($this->_data['get'][$name]) ? $this->_data['get'][$name] : $default;
    }

    /**
     * $_POST.
     * 获取post方法传递的某一个参数
     * @param string|null $name
     * @param mixed|null $default
     * @return mixed|null
     */
    public function post($name = null, $default = null)
    {
        if (!isset($this->_data['post'])) {
            $this->parsePost();
        }
        if (null === $name) {
            return $this->_data['post'];
        }
        return isset($this->_data['post'][$name]) ? $this->_data['post'][$name] : $default;
    }

    /**
     * 获取get + post 的所有参数
     * @return mixed|null
     */
    public function all(){
        return $this->get()+$this->post();
    }

    /**
     * 获取header头部传递的数据
     * @param string|null $name
     * @param mixed|null $default
     * @return array|string|null
     */
    public function header($name = null, $default = null)
    {
        if (!isset($this->_data['headers'])) {
            $this->parseHeaders();
        }
        if (null === $name) {
            return $this->_data['headers'];
        }
        $name = \strtolower($name);
        return isset($this->_data['headers'][$name]) ? $this->_data['headers'][$name] : $default;
    }

    /**
     * 获取cookie
     * @param string|null $name
     * @param mixed|null $default
     * @return array|string|null
     */
    public function cookie($name = null, $default = null)
    {
        if (!isset($this->_data['cookie'])) {
            $this->_data['cookie'] = array();
            \parse_str(\preg_replace('/; ?/', '&', $this->header('cookie', '')), $this->_data['cookie']);
        }
        if ($name === null) {
            return $this->_data['cookie'];
        }
        return isset($this->_data['cookie'][$name]) ? $this->_data['cookie'][$name] : $default;
    }


    /**
     * 获取请求方法
     * @return string
     */
    public function method()
    {
        if (!isset($this->_data['method'])) {
            $this->parseHeadFirstLine();
        }
        return $this->_data['method'];
    }

    /**
     * 获取协议版本号
     * @return string
     */
    public function protocolVersion()
    {
        if (!isset($this->_data['protocolVersion'])) {
            $this->parseProtocolVersion();
        }
        return $this->_data['protocolVersion'];
    }

    /**
     * 获取host
     * @param bool $without_port
     * @return string
     */
    public function host($without_port = false)
    {
        $host = $this->header('host');
        if ($host && $without_port) {
            return preg_replace('/:\d{1,5}$/', '', $host);
        }
        return $host;
    }

    /**
     * 获取uri
     * @return mixed
     */
    public function uri()
    {
        if (!isset($this->_data['uri'])) {
            $this->parseHeadFirstLine();
        }
        return $this->_data['uri'];
    }

    /**
     * 获取请求path
     * @return mixed
     */
    public function path()
    {
        if (!isset($this->_data['path'])) {
            $this->_data['path'] = (string)\parse_url($this->uri(), PHP_URL_PATH);
        }
        return $this->_data['path'];
    }

    /**
     * 获取query参数
     * @return mixed
     */
    public function queryString()
    {
        if (!isset($this->_data['query_string'])) {
            $this->_data['query_string'] = (string)\parse_url($this->uri(), PHP_URL_QUERY);
        }
        return $this->_data['query_string'];
    }

    /**
     * 获取所有的session
     * @return
     */
    public function session()
    {
        if ($this->session === null) {
            $session_id = $this->sessionId();
            if ($session_id === false) {
                return false;
            }
            $this->session = new Session($session_id);
        }
        return $this->session;
    }

    /**
     * 获取或者设置session
     * @param $session_id
     * @return string
     */
    public function sessionId($session_id = null)
    {
        if ($session_id) {
            unset($this->sid);
        }
        if (!isset($this->sid)) {
            $session_name = Session::$name;
            $sid = $session_id ? '' : $this->cookie($session_name);
            if ($sid === '' || $sid === null) {
                if ($this->connection === null) {
                    echo 'Request->session() fail, header already send';
                    return false;
                }
                $sid = $session_id ? $session_id : static::createSessionId();
                $cookie_params = Session::getCookieParams();
                $this->connection->__header['Set-Cookie'] = array($session_name . '=' . $sid
                    . (empty($cookie_params['domain']) ? '' : '; Domain=' . $cookie_params['domain'])
                    . (empty($cookie_params['lifetime']) ? '' : '; Max-Age=' . $cookie_params['lifetime'])
                    . (empty($cookie_params['path']) ? '' : '; Path=' . $cookie_params['path'])
                    . (empty($cookie_params['samesite']) ? '' : '; SameSite=' . $cookie_params['samesite'])
                    . (!$cookie_params['secure'] ? '' : '; Secure')
                    . (!$cookie_params['httponly'] ? '' : '; HttpOnly'));
            }
            $this->sid = $sid;
        }
        return $this->sid;
    }

    /**
     * header 头部原始数据
     * @return string
     */
    public function rawHead()
    {
        if (!isset($this->_data['head'])) {
            $this->_data['head'] = \strstr($this->_buffer, "\r\n\r\n", true);
        }
        return $this->_data['head'];
    }

    /**
     * 获取请求的body部分原始数据
     * @return string
     */
    public function rawBody()
    {
        return \substr($this->_buffer, \strpos($this->_buffer, "\r\n\r\n") + 4);
    }

    /**
     * 获取整个请求的原始数据
     * @return string
     */
    public function rawBuffer()
    {
        return $this->_buffer;
    }

    /**
     * 开启缓存
     * @param mixed $value
     */
    public static function enableCache($value)
    {
        static::$_enableCache = (bool)$value;
    }

    /**
     * 解析header第一行，用于获取请求方法和路由
     * @return void
     */
    protected function parseHeadFirstLine()
    {
        $first_line = \strstr($this->_buffer, "\r\n", true);
        $tmp = \explode(' ', $first_line, 3);
        $this->_data['method'] = $tmp[0];
        $this->_data['uri'] = isset($tmp[1]) ? $tmp[1] : '/';
    }

    /**
     * 解析协议版本
     * @return void
     */
    protected function parseProtocolVersion()
    {
        $first_line = \strstr($this->_buffer, "\r\n", true);
        $protoco_version = substr(\strstr($first_line, 'HTTP/'), 5);
        $this->_data['protocolVersion'] = $protoco_version ? $protoco_version : '1.0';
    }

    /**
     * 解析header
     * @return void
     */
    protected function parseHeaders()
    {
        static $cache = [];
        $this->_data['headers'] = array();
        $raw_head = $this->rawHead();
        $end_line_position = \strpos($raw_head, "\r\n");
        if ($end_line_position === false) {
            return;
        }
        $head_buffer = \substr($raw_head, $end_line_position + 2);
        $cacheable = static::$_enableCache && !isset($head_buffer[2048]);
        if ($cacheable && isset($cache[$head_buffer])) {
            $this->_data['headers'] = $cache[$head_buffer];
            return;
        }
        $head_data = \explode("\r\n", $head_buffer);
        foreach ($head_data as $content) {
            if (false !== \strpos($content, ':')) {
                list($key, $value) = \explode(':', $content, 2);
                $key = \strtolower($key);
                $value = \ltrim($value);
            } else {
                $key = \strtolower($content);
                $value = '';
            }
            if (isset($this->_data['headers'][$key])) {
                $this->_data['headers'][$key] = "{$this->_data['headers'][$key]},$value";
            } else {
                $this->_data['headers'][$key] = $value;
            }
        }
        if ($cacheable) {
            $cache[$head_buffer] = $this->_data['headers'];
            if (\count($cache) > 128) {
                unset($cache[key($cache)]);
            }
        }
    }

    /**
     * 解析get参数
     * @return void
     */
    protected function parseGet()
    {
        static $cache = [];
        $query_string = $this->queryString();
        $this->_data['get'] = array();
        if ($query_string === '') {
            return;
        }
        $cacheable = static::$_enableCache && !isset($query_string[1024]);
        if ($cacheable && isset($cache[$query_string])) {
            $this->_data['get'] = $cache[$query_string];
            return;
        }
        \parse_str($query_string, $this->_data['get']);
        if ($cacheable) {
            $cache[$query_string] = $this->_data['get'];
            if (\count($cache) > 256) {
                unset($cache[key($cache)]);
            }
        }
    }

    /**
     * 解析post参数
     * @return void
     */
    protected function parsePost()
    {
        static $cache = [];
        $this->_data['post'] = $this->_data['files'] = array();
        $content_type = $this->header('content-type', '');
        if (\preg_match('/boundary="?(\S+)"?/', $content_type, $match)) {
            //todo 删除解析文件
            return;
        }
        $body_buffer = $this->rawBody();
        if ($body_buffer === '') {
            return;
        }
        $cacheable = static::$_enableCache && !isset($body_buffer[1024]);
        if ($cacheable && isset($cache[$body_buffer])) {
            $this->_data['post'] = $cache[$body_buffer];
            return;
        }
        if (\preg_match('/\bjson\b/i', $content_type)) {
            $this->_data['post'] = (array) json_decode($body_buffer, true);
        } else {
            \parse_str($body_buffer, $this->_data['post']);
        }
        if ($cacheable) {
            $cache[$body_buffer] = $this->_data['post'];
            if (\count($cache) > 256) {
                unset($cache[key($cache)]);
            }
        }
    }



    /**
     * 创建一个sessionID
     * @return string
     */
    protected static function createSessionId()
    {
        return \bin2hex(\pack('d', \microtime(true)) . random_bytes(8));
    }

    /**
     * 设置参数
     * @param string $name
     * @param mixed $value
     * @return void
     */
    public function __set($name, $value)
    {
        $this->properties[$name] = $value;
    }

    /**
     * 获取参数
     * @param string $name
     * @return mixed|null
     */
    public function __get($name)
    {
        return isset($this->properties[$name]) ? $this->properties[$name] : null;
    }

    /**
     * 是否有某个参数
     * @param string $name
     * @return bool
     */
    public function __isset($name)
    {
        return isset($this->properties[$name]);
    }

    /**
     * 删除某个参数
     * @param string $name
     * @return void
     */
    public function __unset($name)
    {
        unset($this->properties[$name]);
    }

    /**
     * __toString.
     */
    public function __toString()
    {
        return $this->_buffer;
    }

    /**
     * __wakeup.
     * 反序列化的时候
     * @return void
     */
    public function __wakeup()
    {
        $this->_isSafe = false;
    }

    /**
     * __destruct.
     *
     * @return void
     */
    public function __destruct()
    {
        if (isset($this->_data['files']) && $this->_isSafe) {
            \clearstatcache();
            \array_walk_recursive($this->_data['files'], function($value, $key){
                if ($key === 'tmp_name') {
                    if (\is_file($value)) {
                        \unlink($value);
                    }
                }
            });
        }
    }
}
