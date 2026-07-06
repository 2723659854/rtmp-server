<?php


namespace MediaServer\Rtmp;

/**
 * @purpose 权限验证
 * @author yanglong
 */
trait RtmpAuthorizeTrait
{

    public function verifyAuth(){
        return true;
    }

}