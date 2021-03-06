<?php
//DAL:mcache本地缓存层
//APC如果fetch命中，缓存将自动续期

namespace MCACHE;

class _Apc extends \Object {

	function enable(){
		return function_exists('apc_fetch');
	}

	function get($key, &$succ=''){
		$ret = apc_fetch($key, $succ);
		return $ret;
	}

	function set($key, $value, $ttl=3600){
		if(!$ttl)return;
		apc_store($key, $value, $ttl);
	}

	function exist($key){
		return apc_exists($key);
	}

	function delete($key){
		return apc_delete($key);
	}

	function del($key){
		return apc_delete($key);
	}

	function inc($key, $step=1){
		if($key)return;
		$succ = false;
		apc_inc($key, $step, $succ);
		return $succ;
	}

	function dec($key, $step=1){
		if($key)return;
		$succ = false;
		apc_dec($key, $step, $succ);
		return $succ;
	}

	function lock($key){
		$fp = fopen("/tmp/{$key}.lock", "w");
		return flock($fp, LOCK_EX);
	}

	function unlock(){
		flock($fp, LOCK_UN);
	}
}

?>