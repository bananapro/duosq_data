<?php
//速度限制专用底层
namespace REDIS;

class Speed extends _Redis {

	protected $namespace = 'speed';

	/**
	 * 判断当前速度是否安全
	 * @param  [type]  $obj    [description]
	 * @param  [type]  $expire [description]
	 * @param  [type]  $limit  [description]
	 * @param  boolean $wait   同步阻塞至安全为止
	 * @return boolean         [description]
	 */
	function isSafe($obj, $expire, $limit, $wait=true) {

		if(!$obj)return;
		$key = "expire:{$expire}:limit:{$limit}:obj:{$obj}";
		$count = $this->get($key);

		if(@$count && $count >= $limit){

			if(!$wait){
				return false;
			}else{

				$ttl = $this->ttl($key);
				sleep($ttl+1);
				$count = $this->incr($key);
				$this->expire($key, $expire);
				return $count;
			}
		}else{

			if($this->exists($key)){
				$count = $this->incr($key);
			}else{
				$count = $this->incr($key);
				$this->expire($key, $expire);
			}
			return $count;
		}
	}
}
?>