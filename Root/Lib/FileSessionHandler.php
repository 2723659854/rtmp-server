<?php

namespace Root\Lib;

/**
 * @purpose session处理函数
 * @author yanglong
 */
class FileSessionHandler
{

    /** 保存地址 */
    protected static $_sessionSavePath = null;

    /** 前缀 */
    protected static $_sessionFilePrefix = 'session_';

    /**
     * 初始化
     * @return void
     * @comment 设置保存路径
     */
    public static function init() {
        $save_path = @\session_save_path();
        if (!$save_path || \strpos($save_path, 'tcp://') === 0) {
            $save_path = \sys_get_temp_dir();
        }
        static::sessionSavePath($save_path);
    }


    /**
     * 设置保存路径
     * @param $config
     */
    public function __construct($config = array()) {
        if (isset($config['save_path'])) {
            static::sessionSavePath($config['save_path']);
        }
    }

    /**
     * 开启
     * @param $save_path
     * @param $name
     * @return true
     */
    public function open($save_path, $name)
    {
        return true;
    }


    /**
     * 读取session数据
     * @param $session_id
     * @return string
     */
    public function read($session_id)
    {
        /** 获取session保存文件 */
        $session_file = static::sessionFile($session_id);
        /** 清除文件状态缓存 */
        \clearstatcache();
        /** 如果存在这个文件 */
        if (\is_file($session_file)) {
            /** 如果已经过期，删除，返回空 */
            if (\time() - \filemtime($session_file) > Session::$lifetime) {
                \unlink($session_file);
                return '';
            }
            /** 读取内容并返回 */
            $data = \file_get_contents($session_file);
            return $data ? $data : '';
        }
        return '';
    }

    /**
     * 写入数据
     * @param $session_id
     * @param $session_data
     * @return bool
     * @throws \Random\RandomException
     */
    public function write($session_id, $session_data)
    {
        /** 临时文件路径 */
        $temp_file = static::$_sessionSavePath . uniqid(bin2hex(random_bytes(8)), true);
        /** 写入临时文件 */
        if (!\file_put_contents($temp_file, $session_data)) {
            return false;
        }
        /** 通过更换文件名称的方式保存到新的位置 */
        return \rename($temp_file, static::sessionFile($session_id));
    }


    /**
     * 更新session的有效时间
     * @param $id
     * @param $data
     * @return bool
     */
    public function updateTimestamp($id, $data = "")
    {
        $session_file = static::sessionFile($id);
        if (!file_exists($session_file)) {
            return false;
        }
        /** 使用touch更新最近修改时间 */
        // set file modify time to current time
        $set_modify_time = \touch($session_file);
        // clear file stat cache
        \clearstatcache();
        return $set_modify_time;
    }


    /**
     * 关闭
     * @return true
     */
    public function close()
    {
        return true;
    }


    /**
     * 销毁
     * @param $session_id
     * @return true
     */
    public function destroy($session_id)
    {
        /** 通过删除文件的方式清理session数据 */
        $session_file = static::sessionFile($session_id);
        if (\is_file($session_file)) {
            \unlink($session_file);
        }
        return true;
    }


    /**
     * 清理所有过期的数据
     * @param $maxlifetime
     * @return void
     */
    public function gc($maxlifetime) {
        $time_now = \time();
        foreach (\glob(static::$_sessionSavePath . static::$_sessionFilePrefix . '*') as $file) {
            if(\is_file($file) && $time_now - \filemtime($file) > $maxlifetime) {
                \unlink($file);
            }
        }
    }


    /**
     * 获取session存储文件名称
     * @param $session_id
     * @return string
     */
    protected static function sessionFile($session_id) {
        return static::$_sessionSavePath.static::$_sessionFilePrefix.$session_id;
    }


    /**
     * 创建并获取session存储路径
     * @param $path
     * @return string
     */
    public static function sessionSavePath($path) {
        if ($path) {
            if ($path[\strlen($path)-1] !== DIRECTORY_SEPARATOR) {
                $path .= DIRECTORY_SEPARATOR;
            }
            static::$_sessionSavePath = $path;
            if (!\is_dir($path)) {
                \mkdir($path, 0777, true);
            }
        }
        return $path;
    }
}
/** 初始化 */
FileSessionHandler::init();