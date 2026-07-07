<?php
namespace MediaServer\Http;

use Root\rtmp\TcpConnection;
use Root\Protocols\Http;
use Root\Protocols\Websocket;
/**
 * @purpose 自定义http协议的input方法
 * @author yanglong
 */
class ExtHttpProtocol extends Http
{

    /**
     * 只负责获取包数据长度
     * @param $recv_buffer
     * @param TcpConnection $connection
     * @return float|int|mixed|string
     */
    public static function input($recv_buffer, TcpConnection $connection)
    {
        static $input = [];
        /** 返回缓存 */
        if (!isset($recv_buffer[512]) && isset($input[$recv_buffer])) {
            return $input[$recv_buffer];
        }
        /** 数据太大 */
        $crlf_pos = \strpos($recv_buffer, "\r\n\r\n");
        if (false === $crlf_pos) {
            // Judge whether the package length exceeds the limit.
            if (\strlen($recv_buffer) >= 16384) {
                $connection->close("HTTP/1.1 413 Request Entity Too Large\r\n\r\n", true);
                return 0;
            }
            return 0;
        }
        /** 获取输入数据长度 */
        $length = $crlf_pos + 4;
        /** 使用strstr 方法获取method 如果strstr加入第三个参数设置为TRUE，则会返回被搜索字符第一次出现前面的字符串 */
        $method = \strstr($recv_buffer, ' ', true);

        if (!\in_array($method, ['GET', 'POST', 'OPTIONS', 'HEAD', 'DELETE', 'PUT', 'PATCH'])) {
            $connection->close("HTTP/1.1 400 Bad Request\r\n\r\n", true);
            return 0;
        }
        /** 解析头部 */
        $header = \substr($recv_buffer, 0, $crlf_pos);
        /** 如果对面是要建立ws链接，那么升级为ws链接 */
        if(\preg_match("/\r\nUpgrade: websocket/i", $header)){
            /** 切换为ws协议 */
            //upgrade websocket
            $connection->protocol = Websocket::class;
            return Websocket::input($recv_buffer,$connection);
        }
        
        // POST请求特殊处理：支持流式处理和chunked编码，这里是支持http-flv的关键
        if ($method === 'POST') {
            // 检查是否使用chunked编码，推流使用分块传输
            if (\stripos($header, "\r\nTransfer-Encoding: chunked") !== false) {
                // 设置流式模式，支持chunked编码
                if (!isset($connection->context)) {
                    $connection->context = new \stdClass();
                }
                $connection->context->streamingMode = true;
                $connection->context->chunkedTransfer = true;
                // 返回头部长度，后续数据通过流式处理
                return $length;
            }
            
            // 处理 Expect: 100-continue ，客户端嗅探服务器是否支持上传大文件，此处用于处理不分块的flv推流
            if (\stripos($header, "\r\nExpect: 100-continue") !== false) {
                // 允许的，兄嘚
                $connection->send("HTTP/1.1 100 Continue\r\n\r\n", true);
            }
            
            // 设置流式模式
            if (!isset($connection->context)) {
                $connection->context = new \stdClass();
            }
            $connection->context->streamingMode = true;
            // 返回头部长度，不检查总长度
            return $length;
        }
        
        /** 解析包长度 */
        if ($pos = \strpos($header, "\r\nContent-Length: ")) {
            $length = $length + (int)\substr($header, $pos + 18, 10);
            $has_content_length = true;
        } else if (\preg_match("/\r\ncontent-length: ?(\d+)/i", $header, $match)) {
            $length = $length + (int)$match[1];
            $has_content_length = true;
        } else {
            // get请求，不允许传输chchunked分块数据
            $has_content_length = false;
            if (false !== stripos($header, "\r\nTransfer-Encoding:")) {
                $connection->close("HTTP/1.1 400 Bad Request\r\n\r\n", true);
                return 0;
            }
        }
        /** 数据长度过大 */
        if ($has_content_length) {
            if ($length > $connection->maxPackageSize) {
                $connection->close("HTTP/1.1 413 Request Entity Too Large\r\n\r\n", true);
                return 0;
            }
        }
        /** 如果保存的数据还没有超过512个 */
        if (!isset($recv_buffer[512])) {
            //部分相同请求做缓存 相同请求做缓存
            $input[$recv_buffer] = $length;
            /** 已经超过512个，则清空 ，防止内存占用过大 */
            if (\count($input) > 512) {
                unset($input[key($input)]);
            }
        }

        return $length;
    }

    /**
     * 解析chunked编码的数据
     * @param string $data
     * @return array [decoded_data, remaining_data, is_complete]
     */
    public static function parseChunkedData($data)
    {
        $decoded = '';
        $pos = 0;
        $length = strlen($data);
        
        while ($pos < $length) {
            // 查找chunk大小行的结尾
            $crlf_pos = strpos($data, "\r\n", $pos);
            if ($crlf_pos === false) {
                // 还没有完整的chunk头
                return [$decoded, substr($data, $pos), false];
            }
            
            // 解析chunk大小（十六进制）
            $chunk_size_str = substr($data, $pos, $crlf_pos - $pos);
            // 移除可能的分号和扩展信息
            if (($semicolon_pos = strpos($chunk_size_str, ';')) !== false) {
                $chunk_size_str = substr($chunk_size_str, 0, $semicolon_pos);
            }
            $chunk_size = hexdec(trim($chunk_size_str));
            
            $pos = $crlf_pos + 2; // 跳过 "\r\n"
            
            if ($chunk_size == 0) {
                // 结束chunk，查找最后的 "\r\n\r\n"
                if (substr($data, $pos, 4) === "\r\n\r\n") {
                    return [$decoded, '', true];
                } else {
                    return [$decoded, substr($data, $pos), true];
                }
            }
            
            // 检查是否有足够的数据
            if ($pos + $chunk_size > $length) {
                return [$decoded, substr($data, $pos - 2 - strlen($chunk_size_str)), false];
            }
            
            // 获取chunk数据
            $decoded .= substr($data, $pos, $chunk_size);
            $pos += $chunk_size + 2; // 跳过chunk数据和后面的 "\r\n"
        }
        
        return [$decoded, '', false];
    }
}