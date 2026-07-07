<?php


namespace MediaServer\PushServer;


/**
 * @purpose 全双工媒体流接口
 * @note 需实现推流和拉流接口
 * @author yanglong
 */
interface DuplexMediaStreamInterface extends PlayStreamInterface,PublishStreamInterface
{

}