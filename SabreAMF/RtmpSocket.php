<?php

class RtmpSocket
{
	
	private $host;
	private $port;
	private $socket;
	private $buffer = '';
	
	public $timeout = 15;
	
	public function __construct()
	{
		
	}
	
	/**
	 * Init socket
	 *
	 * @return bool
	 */
	public function connect($host, $port)
	{
		$this->close();
		$this->host = $host;
		$this->port = $port;
		
		$errno = 0;
		$errstr = '';
		$this->socket = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);
		
		if (!$this->socket) {
			throw new Exception("Could not connect to $this->host:$this->port - $errstr ($errno)");
		}
		
		stream_set_timeout($this->socket, $this->timeout);
		return $this->socket != null;
	}
	/**
	 * Close socket
	 *
	 */
	public function close()
	{
		if ($this->socket) {
			fclose($this->socket);
			$this->socket = null;
		}
	}
	/**
	 * Read socket
	 *
	 * @param int $length
	 * @return RtmpStream
	 */
	public function read($length)
	{
		$buff = '';
		$remaining = $length;
		$maxAttempts = 1000; // 防止无限循环
		$attempts = 0;
		
		while ($remaining > 0 && !feof($this->socket) && $attempts < $maxAttempts) {
			$chunk = fread($this->socket, $remaining);
			
			if ($chunk === false) {
				throw new Exception("Could not read socket");
			}
			
			if ($chunk === '' || $chunk === '0') {
				$info = stream_get_meta_data($this->socket);
				if ($info['timed_out']) {
					throw new Exception("Timeout, could not read socket");
				}
				// 非阻塞模式下空读是正常的，继续等待
				usleep(1000);
				$attempts++;
				continue;
			}
			
			$buff .= $chunk;
			$remaining = $length - strlen($buff);
			$attempts = 0; // 重置计数
		}
		
		return new RtmpStream($buff);
	}
	/**
	 * Write data 
	 *
	 * @param RtmpStream $data
	 * @param int $n
	 * @return bool
	 */
	public function write(RtmpStream $data, $n = -1)
	{
		$buffer = $data->flush($n);
		$n = strlen($buffer);
		
		while ($n > 0) {
			$nBytes = fwrite($this->socket, $buffer);
			
			if ($nBytes === false) {
				$this->close();
				return false;
			}
			
			if ($nBytes == 0) {
				$info = stream_get_meta_data($this->socket);
				if ($info['timed_out']) {
					$this->close();
					return false;
				}
				break;
			}
			
			$n -= $nBytes;
			$buffer = substr($buffer, $nBytes);
		}
		
		return true;
	}
}
