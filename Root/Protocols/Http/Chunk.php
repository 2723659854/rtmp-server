<?php

namespace Root\Protocols\Http;


/**
 * @purpose 数据切片类
 * @author yanglong
 */
class Chunk
{
    /**
     * 切片中的数据
     *
     * @var string
     */
    protected $_buffer = null;

    /**
     * 初始化
     * @param string $buffer
     */
    public function __construct($buffer)
    {
        $this->_buffer = $buffer;
    }

    /**
     * __toString
     * 当发送数据的时候，拼接数据的长度
     * @return string
     * @comment 长度是10进制转16进制
     */
    public function __toString()
    {
        return \dechex(\strlen($this->_buffer))."\r\n$this->_buffer\r\n";
    }
}