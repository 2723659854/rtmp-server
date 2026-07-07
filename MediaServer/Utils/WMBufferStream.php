<?php

namespace MediaServer\Utils;

use Evenement\EventEmitterInterface;
use Evenement\EventEmitterTrait;
use Root\Protocols\Http;
use Root\Request;
use Root\Response;
use Root\rtmp\TcpConnection;

/**
 * @purpose 这个是rtmp推送的字节流数据
 * @author yanglong
 */
class WMBufferStream implements EventEmitterInterface
{
    use EventEmitterTrait;

    /** 指针位，当前读取位置 */
    private $_index = 0;
    /** 数据 */
    public $_data = '';

    /**
     * @var TcpConnection 标准tcp连接
     */
    public $connection;

    /**
     * WMStreamProtocol constructor.
     * @param $connection TcpConnection
     */
    public function __construct(TcpConnection $connection)
    {
        $this->connection = $connection;
        /** 当tcp数据传输进来之后，在这里更换为当前的wmbuffer协议，然后connection对象读取数据的时候会使用本协议的input方法 是tcpConnection.php 的 baseRead 方法调用的 */
        /** 就不更改协议了，默认的rtmp必须使用本协议 */
        if (!$this->connection->protocol) {
            $this->connection->protocol = $this;
        }
        /** 如果协议类型是hls 那么就需要处理hls数据，也可以处理其他普通的http请求 */
        if ($this->connection->protocol == Http::class) {
            $this->connection->onMessage = [$this, 'onHlsMessage'];
        }
        $this->connection->onClose = [$this, '_onClose'];
        $this->connection->onError = [$this, '_onError'];
    }

    /*    public function __destruct(){
            logger()->info("WMBufferStream destruct");
        }*/

    /**
     * 处理hls协议
     * @param TcpConnection $connection
     * @param Request $request
     */
    public function onHlsMessage(TcpConnection $connection, Request $request)
    {
        /** 获取文件路径 */
        $path = $request->path();

        // ========== 新增安全过滤 ==========
        // 第一次检查：原始路径
        if ($this->isUnsafePath($path)) {
            return $connection->send(new Response(404, ['Access-Control-Allow-Origin' => '*'], 'not found'));
        }

        // 如果包含百分号编码，解码后再次检查
        if (preg_match('/%[0-9a-f]{2}/i', $path)) {
            $decodedPath = urldecode($path);
            if ($this->isUnsafePath($decodedPath)) {
                return $connection->send(new Response(404, ['Access-Control-Allow-Origin' => '*'], 'not found'));
            }
        }
        /** 允许的扩展名 */
        $webExtension = ['html', 'ico', 'css', 'js', 'json'];
        $flvExtension = ['m3u8', 'ts', 'flv'];
        $mp4Extension = ['mp4', 'm4s'];

        $requestFileExtension = pathinfo($path, PATHINFO_EXTENSION);
        if (!in_array($requestFileExtension, array_merge($flvExtension, $webExtension, $mp4Extension))) {
            return $connection->send(new Response(404, ['Access-Control-Allow-Origin' => '*'], 'not found'));
        }

        /** 拼接文件路径 */
        $file = app_path($path);

        if (!is_file($file)) {
            return $connection->send(new Response(404, ['Access-Control-Allow-Origin' => '*'], 'not found'));
        }

        /** 允许跨域 */
        if (in_array($requestFileExtension, array_merge($flvExtension, $webExtension, $mp4Extension))) {
            $response = (new Response())
                ->withFile($file)
                ->withHeader('Access-Control-Allow-Origin', '*');
            $rangeHeader = $request->header('Range');   // 需确认你 Request 类的获取方法
            /** 此处兼容mp4文件的seek拖动播放 */
            if ($rangeHeader) {
                $response->withRange($rangeHeader);
            }
            return $connection->send($response);
        } else {
            return $connection->send(new Response(404, ['Access-Control-Allow-Origin' => '*'], 'not found'));
        }
    }



    /**
     * 检查路径是否包含危险的字符序列
     * @param string $path
     * @return bool
     */
    private function isUnsafePath($path): bool
    {
        return !$path ||
            strpos($path, '..') !== false ||
            strpos($path, '\\') !== false ||
            strpos($path, "\0") !== false;
    }

    /**
     * 关闭连接
     * @param $con
     * @return void
     */
    public function _onClose($con)
    {
        if ($this->connection){
            $this->connection->protocol = null;
            $this->connection = null;
        }
        $this->emit("onClose");
        $this->removeAllListeners();
    }

    /**
     * 触发错误
     * @param $con
     * @param $code
     * @param $msg
     * @return void
     */
    public function _onError($con, $code, $msg)
    {
        $this->emit("onError");
    }

    /**
     * @param $buffer string
     * @param $connection TcpConnection
     * @comment 这个方法在哪里被调用了？？
     */
    public static function input($buffer, $connection)
    {
        /** 这里传输的数据很多是二进制数据，需要转码才能看懂 */
        //logger()->info("[input_data]".$buffer);
        // 在这里切换了协议，调用当前协议处理类
        /** @var WMBufferStream $me */
        $me = $connection->protocol;
        /** 接收数据 */
        //reset recv buffer
        $me->recvBuffer($buffer);
        /** 触发onData函数 ，前面RtmpStream.php 在初始化的时候已经绑定了这个事件 */
        $me->emit("onData", [$me]);
        // clear connection recv buffer
        $me->clearConnectionRecvBuffer();
        /** 这里处理完成之后，返回0 ，tcpConnection方法不会就不会触发onmessage方法了 */
        return 0;
    }

    /**
     * 编码
     * @param $buffer
     * @param $connection
     * @return mixed
     * @comment  这个是tcpConnection编码需要调用的
     */
    public static function encode($buffer, $connection)
    {
        return $buffer;
    }

    /**
     * 解码
     * @param $buffer
     * @param $connection
     * @return mixed
     * @comment 这个是tcpConnection解码需要调用的
     */
    public static function decode($buffer, $connection)
    {
        return $buffer;
    }


    /**
     * 重置数据，指针归零
     * @return void
     */
    public function reset()
    {
        $this->_index = 0;
    }

    /**
     * 忽略指定长度
     * @param $length
     * @return void
     */
    public function skip($length)
    {
        $this->_index += $length;
    }

    /**
     * 刷新数据
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
     * 打印值
     * @return mixed|string
     */
    public function dump()
    {
        return $this->_data;
    }

    /**
     * 是否有指定长度的值
     * @param $len
     * @return bool
     */
    public function has($len)
    {
        $pos = $len - 1;
        return isset($this->_data[$this->_index + $pos]);
    }

    /**
     * 清空无用的数据
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
     * 结束，移动指针到末尾
     * @return $this
     */
    public function end()
    {
        $this->_index = strlen($this->_data);
        return $this;
    }

    /**
     * 接收数据
     * @param $data
     * @return $this
     */
    public function recvBuffer($data)
    {
        $this->_data = $data;
        return $this->begin();
    }

    /**
     * 获取当前接收数据长度
     * @return int
     */
    public function recvSize()
    {
        return strlen($this->_data);
    }

    /**
     * 获取当前已处理长度
     * @return int
     */
    public function handledSize()
    {
        return $this->_index;
    }

    /**
     * 清空链接上的缓存
     * @return void
     * @comment 这里是清空了tcp缓冲池上的缓存
     */
    public function clearConnectionRecvBuffer()
    {
        if ($this->connection) {
            $this->connection->consumeRecvBuffer($this->_index);
        }
    }

    /**
     * 添加数据
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
     * 写入字节
     * @param $value
     * @return void
     */
    public function writeByte($value)
    {
        $this->_data .= is_int($value) ? chr($value) : $value;
        $this->_index++;
    }

    /**
     * 写入int16
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
     * 写入int32le
     * @param $value
     * @return void
     * @comment Int32LE Int32LE 是一个数据类型的描述，表示一个 32 位的整数，且按照 Little-Endian 字节序进行编码。在 Little-Endian 字节序中，低位字节存储在内存中的低地址处，而高位字节存储在内存的高地址处。
     */
    public function writeInt32LE($value)
    {
        $this->_data .= pack("V", $value);
        $this->_index += 4;
    }

    /**
     * 写入
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
     * 读取一个字节
     * @return mixed|string
     */
    public function readByte()
    {
        return ($this->_data[$this->_index++]);
    }

    /**
     * 读取小整形 1个字节
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
     * 读取int16LE 感觉这个是小端序
     * @return int
     */
    public function readInt16LE()
    {
        return $this->readTinyInt() + ($this->readTinyInt() << 8);
    }

    /**
     * 读取int24
     * @return mixed
     */
    public function readInt24()
    {
        $m = unpack("N", "\x00" . substr($this->_data, $this->_index, 3));
        $this->_index += 3;
        return $m[1];
    }

    /**
     * 读取int32为并解码
     * @return mixed
     * @V 参数用于指定 AMF 消息的版本，而 N 参数用于指定 AMF 消息中空值的类型
     */
    public function readInt32()
    {
        return $this->read("N", 4);
    }

    /**
     * 使用int32读取并解码
     * @return mixed
     * @comment 解码成无符号整形 V 参数用于指定 AMF 消息的版本，而 N 参数用于指定 AMF 消息中空值的类型
     * @note LE 是小端序 Little-Endian 内存中数据的存储方式 不同处理器不一样
     */
    public function readInt32LE()
    {
        return $this->read("V", 4);
    }

    /**
     * 读取原始数据不解码
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
     * 读取数据并解码
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

    /** @var $tagPos 埋点 */
    protected $tagPos;

    /**
     * 埋点
     * @return void
     * @comment 这个就和MySQL的事务一样，对某一个时刻的数据埋点，方便恢复数据，这里是对数据的指针进行记录实现埋点的功能
     */
    public function tag()
    {
        $this->tagPos = $this->_index;
    }

    /**
     * 回滚
     * @return void
     * @comment 回滚到上一个埋点的指针位置
     */
    public function rollBack()
    {
        $this->_index = $this->tagPos;
    }

}
