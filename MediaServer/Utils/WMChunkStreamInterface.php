<?php


namespace MediaServer\Utils;


use Evenement\EventEmitterInterface;

/**
 * @purpose 数据分块接口
 * @author yanglong
 */
interface WMChunkStreamInterface extends  EventEmitterInterface
{

    public function write($data);

    public function end($data = null);

    public function close();

}