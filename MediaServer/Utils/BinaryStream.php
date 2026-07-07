<?php

namespace MediaServer\Utils;

/**
 * 读取二进制
 * @purpose 字节流
 * @author yanglong
 */
class BinaryStream
{
    /** 指针 */
    private $_index = 0;
    /** 原始数据 */
    private $_data;

    /**
     * 原始数据
     * @param $data
     */
    public function __construct($data = "")
    {
        $this->_data = $data;
    }

    /**
     * 重置
     * @return void
     */
    public function reset()
    {
        $this->_index = 0;
    }

    /**
     * 跳过指定长度
     * @param $length
     * @return void
     */
    public function skip($length)
    {
        $this->_index += $length;
    }

    /**
     * 刷新数据
     * 截取指定长度数据，并更新原始数据
     * @param $length
     * @return false|mixed|string
     */
    public function flush($length = -1)
    {
        if ($length == -1) {
            $d = $this->_data;
            $this->_data = "";
        } else {
            $d = substr($this->_data, 0, $length);
            $this->_data = substr($this->_data, $length);
        }
        $this->_index = 0;
        return $d;
    }

    /**
     * 打印
     * @return mixed|string
     */
    public function dump()
    {
        return $this->_data;
    }

    /**
     * 是否有指定长度的数据
     * @param $len
     * @return bool
     */
    public function has($len)
    {
        $pos = $len - 1;
        return isset($this->_data[$this->_index + $pos]);
    }

    /**
     * 清空数据
     * @return void
     */
    public function clear()
    {
        $this->_data = substr($this->_data, $this->_index);
        $this->_index = 0;
    }

    /**
     * 初始化
     * @return $this
     */
    public function begin()
    {
        $this->_index = 0;
        return $this;
    }

    /**
     * 移动指针
     * @param $pos
     * @return $this
     */
    public function move($pos)
    {
        $this->_index = max(array(0, min(array($pos, strlen($this->_data)))));
        return $this;
    }

    /**
     * 移动指针到末尾
     * @return $this
     */
    public function end()
    {
        $this->_index = strlen($this->_data);
        return $this;
    }

    /**
     * 追加数据
     * @param $data
     * @return $this
     */
    public function push($data)
    {
        $this->_data .= $data;
        return $this;
    }
    //--------------------------------
    //		Writer
    //--------------------------------

    /**
     * 写入二进制
     * @param $value
     * @return void
     */
    public function writeByte($value)
    {
        $this->_data .= is_int($value) ? chr($value) : $value;
        $this->_index++;
    }

    /**
     * 写入Int16
     * @param $value
     * @return void
     */
    public function writeInt16($value)
    {
        $this->_data .= pack("s", $value);
        $this->_index += 2;
    }

    /**
     * 写入int24
     * @param $value
     * @return void
     */
    public function writeInt24($value)
    {
        $this->_data .= substr(pack("N", $value), 1);
        $this->_index += 3;
    }

    /**
     * 写入int32
     * @param $value
     * @return void
     */
    public function writeInt32($value)
    {
        $this->_data .= pack("N", $value);
        $this->_index += 4;
    }

    /**
     * 写入无符号int32
     * @param $value
     * @return void
     */
    public function writeInt32LE($value)
    {
        $this->_data .= pack("V", $value);
        $this->_index += 4;
    }

    /**
     * 直接写入数据
     * @param $value
     * @return void
     */
    public function write($value)
    {
        $this->_data .= $value;
        $this->_index += strlen($value);
    }

    //-------------------------------
    //		Reader
    //-------------------------------

    /**
     * 读取二进制
     * @return mixed|string
     */
    public function readByte()
    {
        return ($this->_data[$this->_index++]);
    }

    /**
     * 读取tinyInt
     * @return int
     */
    public function readTinyInt()
    {
        return ord($this->readByte());
    }

    /**
     * 读取int16
     * @return int
     */
    public function readInt16()
    {
        return ($this->readTinyInt() << 8) + $this->readTinyInt();
    }

    /**
     * 读取无符号Int16
     * @return int
     */
    public function readInt16LE()
    {
        return $this->readTinyInt() + ($this->readTinyInt() << 8);
    }

    /**
     * 读物int24
     * @return mixed
     */
    public function readInt24()
    {
        $m = unpack("N", "\x00" . substr($this->_data, $this->_index, 3));
        $this->_index += 3;
        return $m[1];
    }

    /**
     * 读取int32
     * @return mixed
     */
    public function readInt32()
    {
        return $this->read("N", 4);
    }

    /**
     * 读取无符号int32
     * @return mixed
     */
    public function readInt32LE()
    {
        return $this->read("V", 4);
    }

    /**
     * 读物原始数据
     * @param $length
     * @return false|string
     */
    public function readRaw($length = 0)
    {
        if ($length == 0)
            $length = strlen($this->_data) - $this->_index;
        $datas = substr($this->_data, $this->_index, $length);
        $this->_index += $length;
        return $datas;
    }

    /**
     * 按指定方式读取指定长度的数据
     * @param $type
     * @param $size
     * @return mixed
     */
    private function read($type, $size)
    {
        $m = unpack("$type", substr($this->_data, $this->_index, $size));
        $this->_index += $size;
        return $m[1];
    }

    //-------------------------------
    //		Tag & rollback
    //-------------------------------
    /** 保存点，类似于MySQL的事务 */
    protected $tagPos;
    /** 设置保存点 */
    public function tag()
    {
        $this->tagPos = $this->_index;
    }

    /**
     * 回滚
     * @return void
     */
    public function rollBack()
    {
        $this->_index = $this->tagPos;
    }


}
