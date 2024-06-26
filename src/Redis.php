<?php
namespace Yurun\Until\Lock;

class Redis extends Base
{
	/**
	 * 等待锁超时时间，单位：毫秒，0为不限制
	 * @var int
	 */
	public $waitTimeout;

	/**
	 * 获得锁每次尝试间隔，单位：毫秒
	 * @var int
	 */
	public $waitSleepTime;

	/**
	 * 锁超时时间，单位：秒
	 * @var int
	 */
	public $lockExpire;

	/**
	 * Redis操作对象
	 * @var \Redis
	 */
	public $handler;

	public $guid;

	public $lockValue;

	/**
	 * 构造方法
	 * @param string $name 锁名称
	 * @param array $params 连接参数
	 * @param integer $waitTimeout 获得锁等待超时时间，单位：毫秒，0为不限制
	 * @param integer $waitSleepTime 获得锁每次尝试间隔，单位：毫秒
	 * @param integer $lockExpire 锁超时时间，单位：秒
	 */
	public function __construct($name, $params = array(), $waitTimeout = 0, $waitSleepTime = 1000, $lockExpire = 10)
	{
		parent::__construct($name, $params);
		if(!class_exists('\Redis'))
		{
			throw new Exception('未找到 Redis 扩展', LockConst::EXCEPTION_EXTENSIONS_NOT_FOUND);
		}
		$this->waitTimeout = $waitTimeout;
		$this->waitSleepTime = $waitSleepTime;
		$this->lockExpire = $lockExpire;
		if($params instanceof \Redis)
		{
			$this->handler = $params;
			$this->isInHandler = true;
		}
		else
		{
			$host = isset($params['host']) ? $params['host'] : '127.0.0.1';
			$port = isset($params['port']) ? $params['port'] : 6379;
			$timeout = isset($params['timeout']) ? $params['timeout'] : 0;
			$pconnect = isset($params['pconnect']) ? $params['pconnect'] : false;
			$this->handler = new \Redis;
			if($pconnect)
			{
				$result = $this->handler->pconnect($host, $port, $timeout);
			}
			else
			{
				$result = $this->handler->connect($host, $port, $timeout);
			}
			if(!$result)
			{
				throw new Exception('Redis连接失败');
			}
			// 密码验证
			if(isset($params['password']) && !$this->handler->auth($params['password']))
			{
				throw new Exception('Redis密码验证失败');
			}
			// 选择库
			if(isset($params['select']))
			{
				$this->handler->select($params['select']);
			}
		}
		$this->guid = uniqid('', true);
	}

	/**
	 * 加锁
	 * @return bool
	 */
	protected function __lock()
	{
		$time = microtime(true);
		$sleepTime = $this->waitSleepTime * 1000;
		$waitTimeout = $this->waitTimeout / 1000;
		while(true)
		{
			$value = json_decode($this->handler->get($this->name), true);
			$this->lockValue = array(
				'expire'	=>	time() + $this->lockExpire,
				'guid'		=>	$this->guid,
			);
			if(null === $value)
			{
				// 无值
				$result = $this->handler->setnx($this->name, json_encode($this->lockValue));
				if($result)
				{
					$this->handler->expire($this->name, $this->lockExpire);
					return true;
				}
			}
			else
			{
				// 有值
				if($value['expire'] < time())
				{
					$result = $this->handler->setnx($this->name, json_encode($this->lockValue));
					if($result)
					{
						$this->handler->expire($this->name, $this->lockExpire);
						return true;
					}
				}
			}
			if(0 === $this->waitTimeout || microtime(true) - $time < $waitTimeout)
			{
				usleep($sleepTime);
			}
			else
			{
				break;
			}
		}
		return false;
	}

	/**
	 * 释放锁
	 * @return bool
	 */
	protected function __unlock()
	{
		if($this->isLocked())
        {
            $this->handler->del($this->name);
        }
        return true;
	}

	/**
	 * 不阻塞加锁
	 * @return bool
	 */
	protected function __unblockLock()
	{
		$value = json_decode($this->handler->get($this->name), true);
		$this->lockValue = array(
			'expire'	=>	time() + $this->lockExpire,
			'guid'		=>	$this->guid,
		);
		if(null === $value)
		{
			// 无值
			$result = $this->handler->setnx($this->name, json_encode($this->lockValue));
			if($result)
			{
				$this->handler->expire($this->name, $this->lockExpire);
				return true;
			}
		}
		else
		{
			// 有值
			if($value['expire'] < time())
			{
				$result = $this->handler->setnx($this->name, json_encode($this->lockValue));
				if($result)
				{
					$this->handler->expire($this->name, $this->lockExpire);
					return false;
				}
			}
		}
		return false;
	}

	/**
	 * 关闭锁对象
	 * @return bool
	 */
	protected function __close()
	{
		if(null !== $this->handler)
		{
			$result = $this->handler->close();
			$this->handler = null;
			return $result;
		}
	}
}
