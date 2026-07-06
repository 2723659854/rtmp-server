<?php

namespace MediaServer\Utils;


/**
 * @purpose 字节阅读器
 */
class BitReader
{
    public $data;
    public $currentBytes = 0;
    public $currentBits = 0;
    public $isError=false;


    /**
     * 初始化数据
     * @param $data
     */
    public  function __construct(&$data)
    {
        $this->data=$data;
    }

    /**
     * 跳过字节数
     * @param int $bits
     */
    public function skipBits($bits) {
        $newBits = $this->currentBits + $bits;
        /** 当前指针 */
        $this->currentBytes += (int)floor($newBits / 8);
        $this->currentBits = $newBits % 8;
    }

    /**
     * 读取一个字节
     * @return int
     */
    public function getBit() {
        if(!isset($this->data[$this->currentBytes])){
            $this->isError=true;
            return 0;
        }
        /** 获取当前指针上的数据 */
        $result = (ord($this->data[$this->currentBytes]) >> (7 - $this->currentBits)) & 0x01;
        /** 更新指针 */
        $this->skipBits(1);
        return $result;
    }

    /**
     * 获取指定长度的数据
     * @param $bits
     * @return int
     */
    public function getBits($bits){
        $result = 0;
        for ($i = 0; $i < $bits; $i++) {
            $result = ($result << 1) + $this->getBit();
        }
        return $result;
    }

    /**
     * 读取到不为0的第一个数据
     * @return int
     */
    public function expGolombUe()
    {
        for ($n = 0; $this->getBit() == 0 && !$this->isError; $n++) ;
        return (1 << $n) + $this->getBits($n) - 1;
    }

    public function expGolombSe()
    {
        $codeNum = $this->expGolombUe();
        if ($codeNum % 2 == 0) {
            return -$codeNum / 2;
        } else {
            return ($codeNum + 1) / 2;
        }
    }

}