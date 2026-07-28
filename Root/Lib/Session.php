<?php

namespace Root\Lib;

/**
 * @purpose session管理
 * @author yanglong
 */
class Session
{
    /**
     * Session andler class which implements SessionHandlerInterface.
     * session处理类
     * @var string
     */
    protected static $_handlerClass = 'Root\Lib\FileSessionHandler';

    /**
     * Parameters of __constructor for session handler class.
     * 配置
     * @var null
     */
    protected static $_handlerConfig = null;

    /**
     * 名称
     * @var string
     */
    public static $name = 'PHPSID';

    /**
     * 是否自动更新session有效时间
     * @var bool
     */
    public static $autoUpdateTimestamp = false;

    /**
     * 生命周期
     * @var int
     */
    public static $lifetime = 1440;

    /**
     * cookie 生命周期
     * @var int
     */
    public static $cookieLifetime = 1440;

    /**
     * cookie存储路径
     * @var string
     */
    public static $cookiePath = '/';

    /**
     * Session cookie domain.
     *
     * @var string
     */
    public static $domain = '';

    /**
     * HTTPS only cookies.
     * https 仅用
     * @var bool
     */
    public static $secure = false;

    /**
     * HTTP access only.
     *
     * @var bool
     */
    public static $httpOnly = true;

    /**
     * Same-site cookies.
     *
     * @var string
     */
    public static $sameSite = '';

    /**
     * Gc probability.
     *
     * @var int[]
     */
    public static $gcProbability = [1, 1000];

    /**
     * Session handler instance.
     *
     * @var
     */
    protected static $_handler = null;

    /**
     * Session data.
     *
     * @var array
     */
    protected $_data = [];

    /**
     * Session changed and need to save.
     *
     * @var bool
     */
    protected $_needSave = false;

    /**
     * Session id.
     *
     * @var null
     */
    protected $_sessionId = null;

    /**
     * Is safe.
     *
     * @var bool
     */
    protected $_isSafe = true;

    /**
     * Session constructor.
     *
     * @param string $session_id
     */
    public function __construct($session_id)
    {
        static::checkSessionId($session_id);
        /** 设置操作类 */
        if (static::$_handler === null) {
            static::initHandler();
        }
        $this->_sessionId = $session_id;
        /** 读取数据，并反序列化数据 */
        if ($data = static::$_handler->read($session_id)) {
            $this->_data = \unserialize($data);
        }
    }

    /**
     * 获取sessionId
     * @return string
     */
    public function getId()
    {
        return $this->_sessionId;
    }

    /**
     * 获取某一个值
     * @param string $name
     * @param mixed|null $default
     * @return mixed|null
     */
    public function get($name, $default = null)
    {
        return isset($this->_data[$name]) ? $this->_data[$name] : $default;
    }

    /**
     * 设置某一个值
     * @param string $name
     * @param mixed $value
     */
    public function set($name, $value)
    {
        $this->_data[$name] = $value;
        $this->_needSave = true;
    }

    /**
     * 删除某一个值
     * @param string $name
     */
    public function delete($name)
    {
        unset($this->_data[$name]);
        $this->_needSave = true;
    }

    /**
     * 获取并删除某一个值
     * @param string $name
     * @param mixed|null $default
     * @return mixed|null
     */
    public function pull($name, $default = null)
    {
        $value = $this->get($name, $default);
        $this->delete($name);
        return $value;
    }

    /**
     * 设置某一个值
     * @param string|array $key
     * @param mixed|null $value
     */
    public function put($key, $value = null)
    {
        if (!\is_array($key)) {
            $this->set($key, $value);
            return;
        }

        foreach ($key as $k => $v) {
            $this->_data[$k] = $v;
        }
        $this->_needSave = true;
    }

    /**
     * 清除某一个值
     * @param string $name
     */
    public function forget($name)
    {
        if (\is_scalar($name)) {
            $this->delete($name);
            return;
        }
        if (\is_array($name)) {
            foreach ($name as $key) {
                unset($this->_data[$key]);
            }
        }
        $this->_needSave = true;
    }

    /**
     * 获取所有的数据
     * @return array
     */
    public function all()
    {
        return $this->_data;
    }

    /**
     * 删除所有的数据
     * @return void
     */
    public function flush()
    {
        $this->_needSave = true;
        $this->_data = [];
    }

    /**
     * 是否有某一个session
     * @param string $name
     * @return bool
     */
    public function has($name)
    {
        return isset($this->_data[$name]);
    }

    /**
     * 判断是否存在某一个值，及时为null
     * @param string $name
     * @return bool
     */
    public function exists($name)
    {
        return \array_key_exists($name, $this->_data);
    }

    /**
     * 保存session
     * @return void
     */
    public function save()
    {
        if ($this->_needSave) {
            if (empty($this->_data)) {
                static::$_handler->destroy($this->_sessionId);
            } else {
                static::$_handler->write($this->_sessionId, \serialize($this->_data));
            }
        } elseif (static::$autoUpdateTimestamp) {
            static::refresh();
        }
        $this->_needSave = false;
    }

    /**
     * 刷新有效期
     * @return bool
     */
    public function refresh()
    {
        static::$_handler->updateTimestamp($this->getId());
    }

    /**
     * 初始化
     * @return void
     */
    public static function init()
    {
        if (($gc_probability = (int)\ini_get('session.gc_probability')) && ($gc_divisor = (int)\ini_get('session.gc_divisor'))) {
            static::$gcProbability = [$gc_probability, $gc_divisor];
        }

        if ($gc_max_life_time = \ini_get('session.gc_maxlifetime')) {
            self::$lifetime = (int)$gc_max_life_time;
        }

        $session_cookie_params = \session_get_cookie_params();
        static::$cookieLifetime = $session_cookie_params['lifetime'];
        static::$cookiePath = $session_cookie_params['path'];
        static::$domain = $session_cookie_params['domain'];
        static::$secure = $session_cookie_params['secure'];
        static::$httpOnly = $session_cookie_params['httponly'];
    }

    /**
     * 设置操作类
     * @param mixed|null $class_name
     * @param mixed|null $config
     * @return string
     */
    public static function handlerClass($class_name = null, $config = null)
    {
        if ($class_name) {
            static::$_handlerClass = $class_name;
        }
        if ($config) {
            static::$_handlerConfig = $config;
        }
        return static::$_handlerClass;
    }

    /**
     * 获取cookie参数
     * @return array
     */
    public static function getCookieParams()
    {
        return [
            'lifetime' => static::$cookieLifetime,
            'path' => static::$cookiePath,
            'domain' => static::$domain,
            'secure' => static::$secure,
            'httponly' => static::$httpOnly,
            'samesite' => static::$sameSite,
        ];
    }

    /**
     * 初始化操作类
     * @return void
     */
    protected static function initHandler()
    {
        if (static::$_handlerConfig === null) {
            static::$_handler = new static::$_handlerClass();
        } else {
            static::$_handler = new static::$_handlerClass(static::$_handlerConfig);
        }
    }

    /**
     * 刷新数据
     * @return void
     */
    public function gc()
    {
        static::$_handler->gc(static::$lifetime);
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
     * 对象被摧毁的时候保存数据
     * @return void
     */
    public function __destruct()
    {
        if (!$this->_isSafe) {
            return;
        }
        $this->save();
        if (\random_int(1, static::$gcProbability[1]) <= static::$gcProbability[0]) {
            $this->gc();
        }
    }

    /**
     * 检查sessionID是否合法
     * @param string $session_id
     */
    protected static function checkSessionId($session_id)
    {
        if (!\preg_match('/^[a-zA-Z0-9"]+$/', $session_id)) {
            throw new \Exception("session_id $session_id is invalid");
        }
    }
}