<?php

namespace Root\rtmp;

/**
 * @purpose 这个是IO模型的事件接口
 * @comment 这里只需要两个读和写事件
 * @author yanglong
 */
interface EventInterface
{
    /**
     * 可读事件
     * @var int
     */
    const EV_READ = 1;

    /**
     * 可写事件
     * @var int
     */
    const EV_WRITE = 2;
}
